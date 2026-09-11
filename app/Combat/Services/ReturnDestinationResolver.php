<?php

namespace OGame\Combat\Services;

use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Exceptions\ReturnDestinationMoved;
use OGame\Combat\Support\ForeseenReturn;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet;

/**
 * Le protocole en deux passes qui decide ou une flotte se pose, et le seul du systeme.
 *
 * ## Pourquoi deux passes
 *
 * Choisir une destination demande de lire des corps qu'on ne tient pas encore : c'est justement
 * cette lecture qui dit **quelles lignes verrouiller**. La premiere passe pressent ; le verrou est
 * pose ; la seconde reprend la decision, ces lignes tenues. Si elle differe, un corps a bouge entre
 * les deux et l'operation s'arrete plutot que d'ecrire un plan jamais verifie sous verrou.
 *
 * ## Ce qui est tenu : ce qui decide, pas seulement le gagnant
 *
 * Le recours suit un ordre — corps d'origine, planete associee, planete mere. Tenir le seul corps
 * retenu rendrait sa ligne stable sans figer la raison pour laquelle il a gagne : une origine
 * absente qui reapparait, une planete associee qui change de mains, une planete plus ancienne qui
 * redevient eligible deplaceraient le verdict sans qu'aucune ligne tenue n'ait change. L'ensemble
 * lui-meme est donc recompare.
 *
 * **Ce que cela ne couvre pas** : l'apparition d'une ligne qui n'existait pas a la premiere lecture.
 * Verrouiller une ligne existante n'empeche pas un fantome ; il y faut un verrou de portee, et
 * c'est une epreuve MariaDB.
 *
 * ## Deux entrees, un seul protocole
 *
 * Une operation qui rend **une** flotte verrouille ses propres corps : `resolveUnderLock()`. Une
 * operation qui en rend **plusieurs** doit verrouiller l'union de tous les corps decisifs par
 * identifiant croissant — verrouiller mission par mission romprait l'ordre global — et compose donc
 * elle-meme les trois etapes. Le code de decision reste le meme dans les deux cas ; c'est ce qui
 * empeche deux protocoles de destination de diverger.
 */
final class ReturnDestinationResolver
{
    public function __construct(private readonly ReturnPlanner $planner = new ReturnPlanner())
    {
    }

    /**
     * Premiere passe : ce que l'on pressent, et les lignes qu'il faudra tenir pour le confirmer.
     */
    public function foreseeFor(FleetMission $mission): ForeseenReturn
    {
        return new ForeseenReturn(
            $this->planner->planFor($mission),
            $this->planner->bodiesThatDecideFor($mission),
            $this->planner->patrolsThatDecideFor($mission)
        );
    }

    /**
     * Tient les corps qui decident, par identifiant croissant.
     *
     * @param array<int, int> $identifiants
     */
    public function holdTheDecidingBodies(array $identifiants): void
    {
        if ($identifiants === []) {
            return;
        }

        Planet::query()->whereIn('id', $identifiants)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Tient les patrouilles qui decident, **apres les corps** et par identifiant croissant.
     *
     * ## L ordre n est pas une preference
     *
     * `planets` vient avant `patrols`, partout, sans exception. C est l ordre mesure sur les chemins
     * existants : aucun ne tient deja une patrouille avant un corps. L inverser ici ferait un
     * interblocage que SQLite ne montrerait **jamais** — `lockForUpdate()` n y compile a rien — et
     * qui n apparaitrait qu en production, sous MariaDB, entre deux annulations concurrentes.
     *
     * @param array<int, int> $identifiants
     */
    public function holdTheDecidingPatrols(array $identifiants): void
    {
        if ($identifiants === []) {
            return;
        }

        Patrol::query()->whereIn('id', $identifiants)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Seconde passe : la decision reprise sous verrou, ou un refus.
     *
     * @throws ReturnDestinationMoved Si l'ensemble decisif ou le verdict a bouge entre les passes.
     * @throws FleetHasNowhereToReturn Si aucun recours ne reste.
     */
    public function confirm(FleetMission $mission, ForeseenReturn $pressenti, int $combatInstanceId): ResolvedReturnDestination
    {
        // **L'ensemble des faits decisifs a-t-il bouge ?** Un corps apparu ou disparu entre les deux
        // passes deplacerait le verdict sans qu'aucune ligne tenue n'ait change.
        if ($this->planner->bodiesThatDecideFor($mission) !== $pressenti->decidingBodyIds) {
            throw new ReturnDestinationMoved($combatInstanceId, $mission->id, $pressenti->plan->planetId, null);
        }

        // **La patrouille decide autant qu un corps**, et son ensemble se recompare pareillement :
        // dissoute ou apparue entre les deux passes, elle deplacerait le verdict sans qu aucune
        // ligne tenue n ait bouge.
        if ($this->planner->patrolsThatDecideFor($mission) !== $pressenti->decidingPatrolIds) {
            throw new ReturnDestinationMoved(
                $combatInstanceId,
                $mission->id,
                $pressenti->plan->planetId,
                null,
                'La patrouille nommee par la mission a change entre les deux passes.'
            );
        }

        $plan = $this->planner->planFor($mission);

        if (!$plan->isPossible()) {
            throw new FleetHasNowhereToReturn($combatInstanceId, $mission->id, $plan->reason?->value);
        }

        // **Le genre se compare avant la destination**, parce qu il decide de ce qu il faut
        // comparer. Un plan qui passe d un corps a un point garde `planetId` a `null` des deux
        // cotes : comparer les identifiants d abord aurait laisse passer ce glissement.
        if ($plan->kind !== $pressenti->plan->kind) {
            throw new ReturnDestinationMoved(
                $combatInstanceId,
                $mission->id,
                $pressenti->plan->planetId,
                $plan->planetId,
                'Le genre de destination a change : ' . $pressenti->plan->kind->value . ' puis ' . $plan->kind->value . '.'
            );
        }

        if ($plan->landsOnAPoint()) {
            $avant = $pressenti->plan;

            // **Le point compte autant que la patrouille.** Une patrouille qui se deplace entre les
            // deux passes garde son identifiant et change de position : ne comparer que
            // l identifiant ferait poser la flotte a l ancien point.
            if ($plan->patrolId !== $avant->patrolId || $avant->point === null || $plan->point === null || !$plan->point->equals($avant->point)) {
                throw new ReturnDestinationMoved(
                    $combatInstanceId,
                    $mission->id,
                    null,
                    null,
                    'Le point de la patrouille a bouge entre les deux passes.'
                );
            }

            return ResolvedReturnDestination::from($plan, $mission);
        }

        if ($plan->planetId === null) {
            throw new FleetHasNowhereToReturn($combatInstanceId, $mission->id, $plan->reason?->value);
        }

        if ($plan->planetId !== $pressenti->plan->planetId) {
            throw new ReturnDestinationMoved($combatInstanceId, $mission->id, $pressenti->plan->planetId, $plan->planetId);
        }

        return ResolvedReturnDestination::from($plan, $mission);
    }

    /**
     * Les trois etapes, pour une flotte rendue seule.
     *
     * @throws ReturnDestinationMoved
     * @throws FleetHasNowhereToReturn
     */
    public function resolveUnderLock(FleetMission $mission, int $combatInstanceId): ResolvedReturnDestination
    {
        $pressenti = $this->foreseeFor($mission);

        $this->holdTheDecidingBodies($pressenti->decidingBodyIds);
        $this->holdTheDecidingPatrols($pressenti->decidingPatrolIds);

        return $this->confirm($mission, $pressenti, $combatInstanceId);
    }
}
