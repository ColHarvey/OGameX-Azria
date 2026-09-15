<?php

namespace Tests\Unit\Military;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\MilitaryValue;
use OGame\Military\MissileTallyEvaluation;
use OGame\Military\MissileTallyFacts;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * L evaluation d une frappe de missiles : pure, versionnee, entiere ou en attente avec sa raison.
 */
final class MissileTallyEvaluationTest extends UnitTestCase
{
    public function testAStrikeSplitsTheInterceptionAndTheDestructionBetweenTheTwoSides(): void
    {
        $faits = $this->uneFrappe(intercepted: 2, destroyed: ['rocket_launcher' => 30, 'light_laser' => 5]);
        $issue = (new MissileTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->reason . ' ' . $issue->detail);
        $detruites = $this->valeur(['rocket_launcher' => 30, 'light_laser' => 5]);
        $interceptes = $this->valeur([MissileTallyFacts::MISSILE => 2]);
        $this->assertGreaterThan(0, $detruites);
        $this->assertGreaterThan(0, $interceptes);
        $this->assertSame(
            [
                CombatParticipantKey::forFleet(41) => ['owner' => 5, 'destroyed' => $detruites, 'lost' => 0],
                CombatParticipantKey::forPlanet(7) => ['owner' => 6, 'destroyed' => $interceptes, 'lost' => $detruites],
            ],
            $issue->credits()
        );
    }

    public function testAStrikeThatDestroysNothingAndIsNotInterceptedCreditsNobody(): void
    {
        $issue = (new MissileTallyEvaluation())->evaluate($this->uneFrappe(intercepted: 0, destroyed: []), MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending());
        $this->assertSame([], $issue->credits(), 'Une frappe sans effet a credite quelqu un.');
        $this->assertCount(2, $issue->classedOutcomes(), 'Les deux participants classes doivent exister a zero, pour que la reprise les retrouve.');
    }

    /**
     * **Un PNJ avec compte** — une base pirate — est calcule, jamais credite : c est la regle PNJ qui l ecarte, pas
     * l absence de compte.
     */
    public function testAnNpcDefenderIsComputedButNeverCredited(): void
    {
        $faits = $this->uneFrappe(intercepted: 1, destroyed: ['rocket_launcher' => 10], defenderOwner: 9, defenderNpc: true);
        $issue = (new MissileTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->reason);
        $this->assertSame([CombatParticipantKey::forFleet(41)], array_keys($issue->credits()));
        $this->assertSame($this->valeur(['rocket_launcher' => 10]), $issue->computed[CombatParticipantKey::forPlanet(7)]['lost'], 'Le PNJ n est plus calcule.');
    }

    public function testEveryAnomalyLeavesTheStrikePendingWithItsReason(): void
    {
        $evaluation = new MissileTallyEvaluation();

        $sansProprietaire = $this->uneFrappe(intercepted: 1, destroyed: [], defenderOwner: null);
        $this->assertSame(MissileTallyEvaluation::PARTICIPANT_WITHOUT_OWNER, $evaluation->evaluate($sansProprietaire, MilitaryValue::WEIGHTING_VERSION)->reason);

        $memeClef = $this->uneFrappe(intercepted: 1, destroyed: []);
        $memeClef = new MissileTallyFacts($memeClef->id, $memeClef->echeance, $memeClef->attacker, $memeClef->attacker, 1, [], $memeClef->prices);
        $this->assertSame(MissileTallyEvaluation::INCOHERENT_STRIKE, $evaluation->evaluate($memeClef, MilitaryValue::WEIGHTING_VERSION)->reason);

        $inconnue = $this->uneFrappe(intercepted: 0, destroyed: ['rocket_launcher' => 1]);
        $inconnue = new MissileTallyFacts($inconnue->id, $inconnue->echeance, $inconnue->attacker, $inconnue->defender, 0, ['tourelle_inconnue' => 1], $inconnue->prices + ['tourelle_inconnue' => 1]);
        $this->assertSame(MissileTallyEvaluation::UNKNOWN_UNIT_FAMILY, $evaluation->evaluate($inconnue, MilitaryValue::WEIGHTING_VERSION)->reason);

        $sansPrix = $this->uneFrappe(intercepted: 0, destroyed: ['rocket_launcher' => 1]);
        $sansPrix = new MissileTallyFacts($sansPrix->id, $sansPrix->echeance, $sansPrix->attacker, $sansPrix->defender, 0, ['rocket_launcher' => 1], []);
        $this->assertSame(MissileTallyEvaluation::INCOHERENT_STRIKE, $evaluation->evaluate($sansPrix, MilitaryValue::WEIGHTING_VERSION)->reason);

        $this->assertSame(MissileTallyEvaluation::UNKNOWN_UNIT_FAMILY, $evaluation->evaluate($this->uneFrappe(intercepted: 1, destroyed: []), 'v-inconnue')->reason, 'Une version inconnue doit laisser en attente : l unite n y a pas de famille.');
    }

    public function testTheFactsSurviveTheirStorageAndRefuseADeformedDocument(): void
    {
        $faits = $this->uneFrappe(intercepted: 2, destroyed: ['rocket_launcher' => 3]);
        $document = $faits->toStorage();

        $relus = MissileTallyFacts::fromStorage(json_decode((string)json_encode($document), true));
        $this->assertNotNull($relus);
        $this->assertSame($document, $relus->toStorage());
        $this->assertSame('missile:mission:900:' . CombatParticipantKey::forFleet(41), $faits->eventKeyFor(CombatParticipantKey::forFleet(41)));

        foreach ([
            'schema inconnu' => ['schema' => 99] + $document,
            'identifiant nul' => ['id' => 0] + $document,
            'interception negative' => ['intercepted' => -1] + $document,
            'clef malformee' => ['attacker' => ['key' => 'nimporte', 'owner' => 5, 'npc' => false]] + $document,
            'proprietaire nul' => ['defender' => ['key' => CombatParticipantKey::forPlanet(7), 'owner' => 0, 'npc' => false]] + $document,
            'champ de trop' => ['defender' => ['key' => CombatParticipantKey::forPlanet(7), 'owner' => 6, 'npc' => false, 'extra' => 1]] + $document,
            'detruite a zero' => ['destroyed' => ['rocket_launcher' => 0]] + $document,
            'prix negatif' => ['prices' => ['rocket_launcher' => -1]] + $document,
        ] as $nom => $deforme) {
            $this->assertNull(MissileTallyFacts::fromStorage($deforme), "Un document deforme (« $nom ») a ete relu.");
        }
    }

    /**
     * @param array<string, int> $destroyed
     */
    private function uneFrappe(int $intercepted, array $destroyed, int|null $defenderOwner = 6, bool $defenderNpc = false): MissileTallyFacts
    {
        $detruites = new UnitCollection();

        foreach ($destroyed as $nom => $nombre) {
            $detruites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return MissileTallyFacts::of(
            900,
            1_700_000_000,
            ['key' => CombatParticipantKey::forFleet(41), 'owner' => 5, 'npc' => false],
            ['key' => CombatParticipantKey::forPlanet(7), 'owner' => $defenderOwner, 'npc' => $defenderNpc],
            $intercepted,
            $detruites,
        );
    }

    /**
     * @param array<string, int> $unites
     */
    private function valeur(array $unites): int
    {
        $valeur = 0;

        foreach ($unites as $nom => $nombre) {
            $valeur += MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $valeur;
    }
}
