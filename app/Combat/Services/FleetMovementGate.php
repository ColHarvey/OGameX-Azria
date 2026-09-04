<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use RuntimeException;

/**
 * La porte unique par laquelle passe tout mouvement decide pour une flotte liee a un combat.
 *
 * ## Pourquoi une seule porte
 *
 * Trois chemins decident du sort d'une meme flotte, et ils tournent en meme temps : le **rappel**
 * lance par le joueur, le **demi-tour** d'une flotte que le combat refuse, et l'**expiration** du
 * stationnement. Chacun lisait ses faits sans verrou, puis ecrivait.
 *
 * Le defaut n'etait pas theorique. Une Defense ACS refusee et un rappel simultane pouvaient creer
 * **deux missions retour pour une seule flotte** : le rappel ne regarde pas la disposition, et la
 * disposition ne regarde pas le rappel. Les vaisseaux existaient deux fois. De meme, un rappel qui
 * lisait la mission juste avant que la retenue lui pose son `combat_instance_id` laissait partir une
 * flotte deja comptee dans la photographie.
 *
 * ## Ce que la porte fait, et ce qu'elle ne fait pas
 *
 * Elle **ne decide rien** : les regles restent ou elles sont — `EngagedFleetCheck` pour le rappel,
 * `AcsDefendMission` pour la retenue et le demi-tour, `FleetDispositionRegistry` pour le mouvement
 * ecrit. Elle ouvre la section critique, prend les verrous **dans l'ordre global du systeme** et
 * **relit la mission sous verrou** avant de laisser decider.
 *
 * La relecture est le coeur : un modele charge avant la porte decrit un passe. C'est en decidant sur
 * lui qu'un rappel accordait un second retour a une flotte deja renvoyee.
 *
 * ## L'ordre des verrous
 *
 * Celui que la migration de barriere fixe et que le reglement, la fermeture et l'annulation suivent
 * deja : **barriere -> instance -> union -> missions**, chaque famille par identifiant croissant.
 * Deux transactions qui prennent les memes lignes dans le meme ordre ne peuvent pas s'attendre
 * mutuellement.
 *
 * `lockForUpdate()` ne compile a rien sous SQLite : ce que les essais locaux montrent est la
 * **relecture** et la forme des requetes. La preuve d'interblocage et de stabilite est MariaDB.
 */
final class FleetMovementGate
{
    /**
     * Ouvre la section critique, puis laisse decider sur une mission relue sous verrou.
     *
     * @template TValeur
     * @param Closure(FleetMission): TValeur $decider Ce que l'appelant veut decider, sur la mission tenue.
     * @return TValeur
     */
    public function decideUnderLock(FleetMission $mission, Closure $decider): mixed
    {
        return DB::transaction(function () use ($mission, $decider): mixed {
            // 1. La barriere du corps vise. Elle est le « ce corps est pris » du systeme, et le
            // reglement la prend en premier : la prendre ailleurs en second remettrait les deux
            // sens de rotation que l'ordre global existe pour interdire.
            $barriere = $mission->planet_id_to === null
                ? null
                : CelestialBodyCombatBarrier::query()
                    ->where('target_body_id', $mission->planet_id_to)
                    ->lockForUpdate()
                    ->first();

            // 2. Les instances qui lient cette flotte, par identifiant croissant.
            $instances = CombatInstance::query()
                ->whereIn('id', $this->combatsThatBindIt($mission, $barriere))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // 3. Les unions, par identifiant croissant. Celle de la flotte compte autant que celles
            // des combats : un rappel retire la flotte de son union, et une union videe se supprime.
            $unions = [];

            if ($mission->union_id !== null) {
                $unions[(int)$mission->union_id] = true;
            }

            foreach ($instances as $instance) {
                if ($instance->union_id !== null) {
                    $unions[(int)$instance->union_id] = true;
                }
            }

            $identifiants = array_keys($unions);
            sort($identifiants);

            FleetUnion::query()->whereIn('id', $identifiants)->orderBy('id')->lockForUpdate()->get();

            // 4. La mission, relue sous verrou. **C'est elle que l'appelant doit lire**, pas celle
            // qu'il tenait en entrant : entre les deux, un demi-tour a pu la renvoyer, une retenue a
            // pu l'inscrire, un reglement a pu la traiter.
            $tenue = FleetMission::query()->whereKey($mission->id)->lockForUpdate()->first();

            if (!$tenue instanceof FleetMission) {
                throw new RuntimeException('La mission ' . $mission->id . ' a disparu avant que son mouvement soit decide.');
            }

            return ($decider)($tenue);
        });
    }

    /**
     * Les combats dont l'etat decide du sort de cette flotte.
     *
     * Les memes liens que les regles existantes lisent — le combat qui tient le corps vise, celui
     * que l'arrivee a pose sur la mission, ceux ou la fermeture l'a inscrite — sans filtre d'etat :
     * c'est justement l'etat qui peut changer sous les pieds de l'appelant.
     *
     * @return array<int, int>
     */
    private function combatsThatBindIt(FleetMission $mission, CelestialBodyCombatBarrier|null $barriere): array
    {
        $identifiants = [];

        if ($barriere !== null) {
            $identifiants[(int)$barriere->combat_instance_id] = true;
        }

        if ($mission->combat_instance_id !== null) {
            $identifiants[(int)$mission->combat_instance_id] = true;
        }

        $inscriptions = CombatParticipant::query()
            ->where('fleet_mission_id', $mission->id)
            ->pluck('combat_instance_id')
            ->all();

        foreach ($inscriptions as $identifiant) {
            $identifiants[(int)$identifiant] = true;
        }

        $liste = array_keys($identifiants);
        sort($liste);

        return $liste;
    }
}
