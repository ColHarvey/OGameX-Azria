<?php

namespace OGame\Military;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Services\PlanetService;

/**
 * Le raccordement des cumuls militaires a une frappe de missiles.
 *
 * Appele par `MissileMission::processArrival()` — le point unique ou une frappe s applique, la porte d arrivee ayant
 * deja decide qu elle s applique —, **dans la transaction de la frappe**, une fois les antimissiles consommes et les
 * defenses retirees. L instant du fait est l arrivee des missiles.
 *
 * Deux evenements, un par participant classe, dans l espace `missile:mission:<mission>:<participant>` : l attaquant
 * porte la clef de sa mission, la cible celle de son corps. Une attente — unite inconnue, incoherence — s inscrit
 * pour les deux avec les faits entiers, et la frappe elle-meme suit son cours.
 */
final class MilitaryMissileTally
{
    public const string KIND = 'missile';

    public function __construct(
        private MilitaryTallyRecorder $recorder,
        private MissileTallyEvaluation $evaluation,
    ) {
    }

    public function record(FleetMission $mission, PlanetService $target, int $intercepted, UnitCollection $destroyed, int $echeance): BattleTallyOutcome|null
    {
        $depuis = $this->recorder->collectingSince();

        if ($depuis === null || $echeance < $depuis) {
            return null;
        }

        $tireur = (int)$mission->user_id;
        $vise = (int)($target->getPlayer()?->getId() ?? 0);
        $npc = NpcAccounts::among([$tireur, $vise]);

        $faits = MissileTallyFacts::of(
            (int)$mission->id,
            $echeance,
            ['key' => CombatParticipantKey::forFleet((int)$mission->id), 'owner' => $tireur > 0 ? $tireur : null, 'npc' => in_array($tireur, $npc, true)],
            ['key' => CombatParticipantKey::forBody($target), 'owner' => $vise > 0 ? $vise : null, 'npc' => in_array($vise, $npc, true)],
            $intercepted,
            $destroyed,
        );
        $issue = $this->evaluation->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        if ($issue->isPending()) {
            foreach ([$faits->attacker, $faits->defender] as $participant) {
                if ($participant['owner'] === null || $participant['npc']) {
                    continue;
                }

                $this->recorder->defer($faits->eventKeyFor($participant['key']), $participant['owner'], $echeance, (string)$issue->reason, ['kind' => self::KIND, 'participant' => $participant['key'], 'detail' => $issue->detail, 'facts' => $faits->toStorage()]);
            }

            return $issue;
        }

        foreach ($issue->credits() as $clef => $credit) {
            $this->recorder->credit($faits->eventKeyFor($clef), $credit['owner'], $echeance, 0, $credit['destroyed'], $credit['lost']);
        }

        return $issue;
    }
}
