<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Exceptions\ContradictoryRefundClaim;
use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Exceptions\ReturnDestinationMoved;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\FleetMission;
use RuntimeException;

/**
 * Les missiles dus a un joueur dont l'annulation n'a rien trouve a crediter.
 *
 * ## Pourquoi la creance existe
 *
 * Un missile parti par une course serveur est **rendu**, jamais detruit. Le plus souvent la
 * restitution a lieu sur-le-champ, et rien n'est ecrit ici. Mais si le corps de depart a disparu et
 * que le protocole canonique ne designe aucune destination **a cet instant**, il reste deux exigences
 * qui ne se contredisent pas :
 *
 * - l'annulation doit etre **definitive** — sinon le missile frappe des que la barriere disparait ;
 * - les actifs ne doivent **pas** disparaitre — un avertissement au journal ne se recupere pas.
 *
 * La creance concilie les deux : la mission est marquee traitee, et ce qui est du reste inscrit,
 * exploitable **apres la fin du combat**, jusqu'a ce qu'une destination existe.
 *
 * ## Deux idempotences
 *
 * L'identite est la mission : une annulation rejouee ne cree pas une seconde creance, et une creance
 * qui contredirait la premiere — autre proprietaire, autre quantite — est refusee plutot qu'ecrasee.
 * Le credit, lui, est garde par `credited_at` sous verrou : deux reglements concurrents en font un.
 *
 * ## Ce que « transitoire » veut dire
 *
 * `ReturnDestinationMoved` signale qu'un corps est apparu ou disparu entre deux passes, pas qu'il n'y
 * a nulle part : le reglement reessaiera. C'est pourquoi la creance ne se referme jamais toute seule.
 */
final class MissileRefundClaims
{
    /**
     * Inscrit ce qui est du, une fois. Sans effet si la creance existe deja a l'identique.
     */
    public function record(FleetMission $mission, int $combatInstanceId, int $ownerId, int $missiles, string $reason, int $claimedAt): void
    {
        $existante = DB::table('combat_missile_refunds')->where('fleet_mission_id', $mission->id)->first(['owner_id', 'missiles', 'reason']);

        if ($existante !== null) {
            if ((int)$existante->owner_id !== $ownerId || (int)$existante->missiles !== $missiles) {
                throw new ContradictoryRefundClaim((int)$mission->id, (int)$existante->owner_id, (int)$existante->missiles, $ownerId, $missiles);
            }

            return;
        }

        DB::table('combat_missile_refunds')->insert([
            'fleet_mission_id' => $mission->id,
            'combat_instance_id' => $combatInstanceId,
            'owner_id' => $ownerId,
            'missiles' => $missiles,
            'reason' => $reason,
            'claimed_at' => $claimedAt,
            'credited_at' => null,
            'credited_body_id' => null,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        Log::warning('Missiles dus a un joueur : aucune destination de restitution a cet instant, une creance est inscrite.', [
            'fleet_mission_id' => $mission->id,
            'combat_instance_id' => $combatInstanceId,
            'owner_id' => $ownerId,
            'missiles' => $missiles,
            'reason' => $reason,
        ]);
    }

    /**
     * Ce qui reste du, par ordre d'inscription.
     *
     * @return array<int, PendingMissileRefund>
     */
    public function pending(): array
    {
        return DB::table('combat_missile_refunds')
            ->whereNull('credited_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $ligne): PendingMissileRefund => PendingMissileRefund::fromRow((array)$ligne))
            ->all();
    }

    /**
     * Rend ce qui peut l'etre, une fois par creance. Les autres restent dues.
     *
     * @return array{credited: int, waiting: int}
     */
    public function settlePending(int $now): array
    {
        $rendues = 0;
        $enAttente = 0;

        foreach ($this->pending() as $creance) {
            $mission = FleetMission::query()->whereKey($creance->fleetMissionId)->first();
            if (!$mission instanceof FleetMission) {
                // La mission a ete effacee : la creance ne peut plus nommer de destination par le
                // protocole, et personne ne doit deviner a sa place. Elle reste due, et se voit.
                $enAttente++;

                continue;
            }

            $corps = $this->bodyThatCanTakeThem($mission, $creance->combatInstanceId);
            if ($corps === null) {
                $enAttente++;

                continue;
            }

            // **Le credit est garde par la ligne, pas par la lecture** : deux reglements concurrents
            // se croisent ici, et un seul passe.
            $prise = DB::table('combat_missile_refunds')
                ->where('id', $creance->id)
                ->whereNull('credited_at')
                ->update(['credited_at' => $now, 'credited_body_id' => $corps, 'updated_at' => Date::now()]);
            if ($prise !== 1) {
                continue;
            }

            $silo = resolve(PlanetServiceFactory::class)->make($corps, true);
            if ($silo === null) {
                throw new RuntimeException('Le corps ' . $corps . ' a disparu entre la designation et le credit de la creance ' . $creance->id . '.');
            }
            $silo->addUnit('interplanetary_missile', $creance->missiles);
            $rendues++;

            Log::info('Missiles dus rendus a leur proprietaire.', [
                'fleet_mission_id' => $creance->fleetMissionId,
                'owner_id' => $creance->ownerId,
                'missiles' => $creance->missiles,
                'credited_body_id' => $corps,
            ]);
        }

        return ['credited' => $rendues, 'waiting' => $enAttente];
    }

    /**
     * Le corps qui peut reprendre les missiles, par le protocole canonique de destination — corps de
     * depart, planete associee, planete mere, puis refus. Aucune destination inventee.
     */
    private function bodyThatCanTakeThem(FleetMission $mission, int $combatInstanceId): int|null
    {
        $planetes = resolve(PlanetServiceFactory::class);
        if ($mission->planet_id_from !== null && $planetes->make((int)$mission->planet_id_from, true) !== null) {
            return (int)$mission->planet_id_from;
        }

        try {
            return resolve(ReturnDestinationResolver::class)->resolveUnderLock($mission, $combatInstanceId)->bodyId;
        } catch (FleetHasNowhereToReturn|ReturnDestinationMoved) {
            // Rien **a cet instant**. `ReturnDestinationMoved` est transitoire par nature : la creance
            // reste due, et le prochain reglement reessaiera.
            return null;
        }
    }
}
