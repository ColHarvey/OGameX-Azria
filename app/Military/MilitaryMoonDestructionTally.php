<?php

namespace OGame\Military;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Services\PlanetService;

/**
 * Le raccordement des cumuls militaires a une tentative de destruction de lune.
 *
 * La bataille prealable est comptee comme toute bataille, par `MilitaryBattleTally` ; ici ne compte que ce qui vient
 * apres : les Etoiles perdues dans la catastrophe, et ce que la lune emportait avec elle. Appele **avant** que la lune
 * disparaisse, dans la transaction de l appelant : sur le chemin instantane par `MoonDestructionMission`, sur le chemin
 * durable par `MoonDestructionSettlement`, apres la levee de la barriere.
 *
 * Espaces de clefs : `moon:mission:<mission>:<participant>` et `moon:combat:<instance>:<participant>`.
 */
final class MilitaryMoonDestructionTally
{
    public const string KIND = 'moon';

    public function __construct(
        private MilitaryTallyRecorder $recorder,
        private MoonDestructionTallyEvaluation $evaluation,
    ) {
    }

    /**
     * @param list<array{mission: FleetMission, deathstars_lost: int, destroyed_the_moon: bool}> $attempts
     */
    public function record(string $space, int $id, int $echeance, PlanetService $moon, array $attempts, UnitCollection $destroyedWithMoon): BattleTallyOutcome|null
    {
        $depuis = $this->recorder->collectingSince();

        if ($depuis === null || $echeance < $depuis) {
            return null;
        }

        $proprietaire = (int)($moon->getPlayer()?->getId() ?? 0);
        $comptes = [$proprietaire];

        foreach ($attempts as $tentative) {
            $comptes[] = (int)$tentative['mission']->user_id;
        }

        $npc = NpcAccounts::among($comptes);
        $tentatives = [];

        foreach ($attempts as $tentative) {
            $attaquant = (int)$tentative['mission']->user_id;
            $tentatives[] = [
                'key' => CombatParticipantKey::forFleet((int)$tentative['mission']->id),
                'owner' => $attaquant > 0 ? $attaquant : null,
                'npc' => in_array($attaquant, $npc, true),
                'deathstars_lost' => max(0, $tentative['deathstars_lost']),
                'destroyed_the_moon' => $tentative['destroyed_the_moon'],
            ];
        }

        $faits = MoonDestructionTallyFacts::of(
            $space,
            $id,
            $echeance,
            ['key' => CombatParticipantKey::forBody($moon), 'owner' => $proprietaire > 0 ? $proprietaire : null, 'npc' => in_array($proprietaire, $npc, true)],
            $tentatives,
            $destroyedWithMoon,
        );
        $issue = $this->evaluation->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        if ($issue->isPending()) {
            foreach (array_merge([$faits->moon], $faits->attempts) as $participant) {
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

    /**
     * Tout ce qu un corps porte — vaisseaux et defenses — a l instant ou on le lit.
     */
    public static function unitsOn(PlanetService $body): UnitCollection
    {
        $unites = new UnitCollection();

        foreach ([$body->getShipUnits(), $body->getDefenseUnits()] as $collection) {
            foreach ($collection->units as $unite) {
                if ($unite->amount > 0) {
                    $unites->addUnit($unite->unitObject, $unite->amount);
                }
            }
        }

        return $unites;
    }
}
