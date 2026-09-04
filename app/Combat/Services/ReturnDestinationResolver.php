<?php

namespace OGame\Combat\Services;

use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Exceptions\ReturnDestinationMoved;
use OGame\Combat\Support\ForeseenReturn;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\FleetMission;
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
            $this->planner->bodiesThatDecideFor($mission)
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

        $plan = $this->planner->planFor($mission);

        if (!$plan->isPossible() || $plan->planetId === null) {
            throw new FleetHasNowhereToReturn($combatInstanceId, $mission->id, $plan->reason?->value);
        }

        if ($plan->planetId !== $pressenti->plan->planetId || $plan->kind !== $pressenti->plan->kind) {
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

        return $this->confirm($mission, $pressenti, $combatInstanceId);
    }
}
