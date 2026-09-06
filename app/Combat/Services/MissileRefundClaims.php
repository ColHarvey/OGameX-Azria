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
    private const string CREDITED = 'credited';

    private const string WAITING = 'waiting';

    /** Prise par un autre rembourseur : ni rendue par nous, ni due. */
    private const string TAKEN = 'taken';

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
            $issue = $this->settleOne($creance, $now);

            if ($issue === self::CREDITED) {
                $rendues++;
            } elseif ($issue === self::WAITING) {
                $enAttente++;
            }
        }

        return ['credited' => $rendues, 'waiting' => $enAttente];
    }

    /**
     * Une creance, reglee **entierement ou pas du tout**.
     *
     * ## Pourquoi une transaction, et pas seulement un bon ordre
     *
     * L'ecriture etait en deux temps sans enveloppe : acquitter, puis crediter. Une panne entre les
     * deux — ou un corps disparu — laissait la creance close et les missiles jamais rendus, c'est-a-dire
     * exactement la destruction d'actifs que la creance existe pour empecher. Inverser les deux
     * ecritures n'aurait fait que deplacer le risque : le credit sans acquittement se rejoue, et le
     * joueur est paye deux fois. Seule l'enveloppe ferme les deux sens.
     *
     * ## L'ordre a l'interieur
     *
     * Le verrou de la creance vient **avant** toute lecture : deux rembourseurs concurrents s'y
     * serialisent, et le second lit un etat acquitte au lieu de decider sur une lecture perimee. La
     * destination est ensuite designee **dans cette transaction** — `resolveUnderLock()` ne verrouille
     * rien en autocommit —, le silo est credite, puis la creance acquittee. La mise a jour
     * conditionnelle sur `credited_at` reste comme seconde ceinture, la ou `lockForUpdate()` ne
     * compile a rien.
     *
     * Une creance qu'on ne peut pas regler **maintenant** reste due : rien n'est ecrit, et le passage
     * suivant reessaiera. C'est un retour, jamais une exception — un corps disparu ne doit pas
     * empecher le reglement des creances suivantes.
     */
    private function settleOne(PendingMissileRefund $creance, int $now): string
    {
        return DB::transaction(function () use ($creance, $now): string {
            $ligne = DB::table('combat_missile_refunds')
                ->where('id', $creance->id)
                ->lockForUpdate()
                ->first(['credited_at']);

            if ($ligne === null || $ligne->credited_at !== null) {
                // Un autre rembourseur l'a prise, ou elle a disparu : ni rendue par nous, ni due.
                return self::TAKEN;
            }

            $mission = FleetMission::query()->whereKey($creance->fleetMissionId)->first();

            if (!$mission instanceof FleetMission) {
                // La mission a ete effacee : la creance ne peut plus nommer de destination par le
                // protocole, et personne ne doit deviner a sa place. Elle reste due, et se voit.
                return self::WAITING;
            }

            $corps = $this->bodyThatCanTakeThem($mission, $creance->combatInstanceId, $creance->ownerId);

            if ($corps === null) {
                return self::WAITING;
            }

            $silo = resolve(PlanetServiceFactory::class)->make($corps, true);

            if ($silo === null) {
                // Disparu entre sa designation et le credit. Rien n'est ecrit, la creance reste due.
                return self::WAITING;
            }

            // **Le credit porte lui-meme le controle du proprietaire.** Le verrou pris plus haut
            // tient la creance, pas le corps : il empeche deux reglements de la meme creance, mais
            // ni deux creances distinctes vers le meme silo, ni un changement de mains concurrent.
            // Une seule instruction conditionnelle ferme les deux — elle verrouille la ligne, lit
            // son etat courant, et n'ajoute que si le proprietaire concorde encore.
            if (!$silo->addUnitAtomicIfStillOwnedBy('interplanetary_missile', $creance->missiles, $creance->ownerId)) {
                // Le corps a change de mains entre sa designation et le credit. Rien n'est ecrit,
                // rien n'est acquitte : la creance reste due et le prochain reglement redesignera.
                return self::WAITING;
            }

            $prise = DB::table('combat_missile_refunds')
                ->where('id', $creance->id)
                ->whereNull('credited_at')
                ->update(['credited_at' => $now, 'credited_body_id' => $corps, 'updated_at' => Date::now()]);

            if ($prise !== 1) {
                // Sous notre propre verrou personne d'autre n'a pu acquitter : si la ligne resiste,
                // l'etat n'est pas celui qu'on croit, et le credit doit repartir avec la transaction.
                throw new RuntimeException('La creance ' . $creance->id . ' a resiste a son acquittement sous son propre verrou.');
            }

            // Le journal suit la validation : une ligne « rendus » apres un retour en arriere
            // affirmerait un credit qui n'a pas eu lieu.
            DB::afterCommit(static function () use ($creance, $corps): void {
                Log::info('Missiles dus rendus a leur proprietaire.', [
                    'fleet_mission_id' => $creance->fleetMissionId,
                    'owner_id' => $creance->ownerId,
                    'missiles' => $creance->missiles,
                    'credited_body_id' => $corps,
                ]);
            });

            return self::CREDITED;
        });
    }

    /**
     * Ce corps existe-t-il encore, et appartient-il toujours a ce joueur ?
     *
     * Lu sur la ligne, dans la transaction du reglement : un service charge plus tot repondrait sur
     * un etat perime.
     *
     * **Ce controle designe un candidat, il ne garantit rien.** C'est une lecture d'instantane :
     * elle ne verrouille aucune ligne, et le corps peut changer de mains entre elle et le credit.
     * La garantie est portee par le credit lui-meme, qui refait la condition dans son ecriture.
     */
    private function belongsTo(int $bodyId, int $ownerId): bool
    {
        $proprietaire = DB::table('planets')->where('id', $bodyId)->value('user_id');

        return $proprietaire !== null && (int)$proprietaire === $ownerId;
    }

    /**
     * Le corps qui peut reprendre les missiles, par le protocole canonique de destination — corps de     * depart, planete associee, planete mere, puis refus. Aucune destination inventee.
     */
    private function bodyThatCanTakeThem(FleetMission $mission, int $combatInstanceId, int $ownerId): int|null
    {
        // **Le corps de depart ne suffit pas : il doit encore appartenir au creancier.** Une planete
        // abandonnee puis recolonisee porte le meme identifiant et un autre proprietaire ; crediter
        // la donnerait les missiles a un inconnu. Le protocole canonique, lui, verifie deja la
        // concordance du proprietaire.
        if ($mission->planet_id_from !== null && $this->belongsTo((int)$mission->planet_id_from, $ownerId)) {
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
