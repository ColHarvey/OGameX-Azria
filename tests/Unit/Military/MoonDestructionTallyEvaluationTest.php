<?php

namespace Tests\Unit\Military;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\BattleTallyFacts;
use OGame\Military\MilitaryValue;
use OGame\Military\MoonDestructionTallyEvaluation;
use OGame\Military\MoonDestructionTallyFacts;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * L evaluation d une destruction de lune : les Etoiles perdues dans la catastrophe sont perdues sans destructeur ;
 * ce que la lune emporte est perdu par son proprietaire et detruit par l attaquant qui l a detruite.
 */
final class MoonDestructionTallyEvaluationTest extends UnitTestCase
{
    public function testACatastrophicAttemptLosesTheDeathstarsWithoutCreditingAnyDestroyer(): void
    {
        $faits = $this->uneDestruction(tentatives: [[41, 5, 4, false]], emportees: []);
        $issue = (new MoonDestructionTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->reason . ' ' . $issue->detail);
        $this->assertSame(
            [CombatParticipantKey::forFleet(41) => ['owner' => 5, 'destroyed' => 0, 'lost' => $this->valeur(['deathstar' => 4])]],
            $issue->credits(),
            'Une catastrophe doit debiter l attaquant de ses Etoiles et ne crediter personne.'
        );
        $this->assertSame(0, $issue->computed[CombatParticipantKey::forPlanet(7)]['destroyed'], 'La lune a ete creditee des Etoiles perdues.');
    }

    public function testADestroyedMoonLosesWhatItCarriedAndTheDestroyerIsCreditedOnce(): void
    {
        $faits = $this->uneDestruction(tentatives: [[41, 5, 0, true], [42, 8, 1, false]], emportees: ['light_fighter' => 12, 'rocket_launcher' => 3]);
        $issue = (new MoonDestructionTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->reason . ' ' . $issue->detail);
        $emporte = $this->valeur(['light_fighter' => 12, 'rocket_launcher' => 3]);
        $this->assertSame(
            [
                CombatParticipantKey::forFleet(41) => ['owner' => 5, 'destroyed' => $emporte, 'lost' => 0],
                CombatParticipantKey::forFleet(42) => ['owner' => 8, 'destroyed' => 0, 'lost' => $this->valeur(['deathstar' => 1])],
                CombatParticipantKey::forPlanet(7) => ['owner' => 6, 'destroyed' => 0, 'lost' => $emporte],
            ],
            $issue->credits()
        );
    }

    /**
     * **Un PNJ avec compte** — une base pirate — est calcule, jamais credite : c est la regle PNJ qui l ecarte, pas
     * l absence de compte.
     */
    public function testAnNpcMoonOwnerIsComputedButNeverCredited(): void
    {
        $faits = $this->uneDestruction(tentatives: [[41, 5, 0, true]], emportees: ['rocket_launcher' => 3], moonOwner: 9, moonNpc: true);
        $issue = (new MoonDestructionTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->reason);
        $this->assertSame([CombatParticipantKey::forFleet(41)], array_keys($issue->credits()));
        $this->assertSame($this->valeur(['rocket_launcher' => 3]), $issue->computed[CombatParticipantKey::forPlanet(7)]['lost'], 'Le PNJ n est plus calcule.');
    }

    public function testEveryAnomalyLeavesTheDestructionPendingWithItsReason(): void
    {
        $evaluation = new MoonDestructionTallyEvaluation();

        $sansProprietaire = $this->uneDestruction(tentatives: [[41, 5, 1, false]], emportees: [], moonOwner: null);
        $this->assertSame(MoonDestructionTallyEvaluation::PARTICIPANT_WITHOUT_OWNER, $evaluation->evaluate($sansProprietaire, MilitaryValue::WEIGHTING_VERSION)->reason);

        $deuxDestructeurs = $this->uneDestruction(tentatives: [[41, 5, 0, true], [42, 8, 0, true]], emportees: []);
        $this->assertSame(MoonDestructionTallyEvaluation::INCOHERENT_DESTRUCTION, $evaluation->evaluate($deuxDestructeurs, MilitaryValue::WEIGHTING_VERSION)->reason);

        $memeClef = $this->uneDestruction(tentatives: [[41, 5, 0, false], [41, 5, 0, false]], emportees: []);
        $this->assertSame(MoonDestructionTallyEvaluation::INCOHERENT_DESTRUCTION, $evaluation->evaluate($memeClef, MilitaryValue::WEIGHTING_VERSION)->reason);

        $emporteesSansDestructeur = $this->uneDestruction(tentatives: [[41, 5, 0, false]], emportees: ['rocket_launcher' => 1]);
        $this->assertSame(MoonDestructionTallyEvaluation::INCOHERENT_DESTRUCTION, $evaluation->evaluate($emporteesSansDestructeur, MilitaryValue::WEIGHTING_VERSION)->reason);

        $base = $this->uneDestruction(tentatives: [[41, 5, 0, true]], emportees: ['rocket_launcher' => 1]);
        $inconnue = new MoonDestructionTallyFacts($base->space, $base->id, $base->echeance, $base->moon, $base->attempts, ['tourelle_inconnue' => 1], $base->prices + ['tourelle_inconnue' => 1]);
        $this->assertSame(MoonDestructionTallyEvaluation::UNKNOWN_UNIT_FAMILY, $evaluation->evaluate($inconnue, MilitaryValue::WEIGHTING_VERSION)->reason);

        $sansPrix = new MoonDestructionTallyFacts($base->space, $base->id, $base->echeance, $base->moon, $base->attempts, $base->destroyedWithMoon, []);
        $this->assertSame(MoonDestructionTallyEvaluation::INCOHERENT_DESTRUCTION, $evaluation->evaluate($sansPrix, MilitaryValue::WEIGHTING_VERSION)->reason);

        $this->assertSame(MoonDestructionTallyEvaluation::UNKNOWN_UNIT_FAMILY, $evaluation->evaluate($base, 'v-inconnue')->reason);
    }

    public function testTheFactsSurviveTheirStorageAndRefuseADeformedDocument(): void
    {
        $faits = $this->uneDestruction(tentatives: [[41, 5, 2, true]], emportees: ['rocket_launcher' => 3]);
        $document = $faits->toStorage();

        $relus = MoonDestructionTallyFacts::fromStorage(json_decode((string)json_encode($document), true));
        $this->assertNotNull($relus);
        $this->assertSame($document, $relus->toStorage());
        $this->assertSame('moon:combat:77:' . CombatParticipantKey::forPlanet(7), $faits->eventKeyFor(CombatParticipantKey::forPlanet(7)));

        $tentative = $document['attempts'][0];

        foreach ([
            'schema inconnu' => ['schema' => 99] + $document,
            'espace inconnu' => ['space' => 'expedition'] + $document,
            'tentatives non listees' => ['attempts' => ['a' => $tentative]] + $document,
            'Etoiles perdues negatives' => ['attempts' => [['deathstars_lost' => -1] + $tentative]] + $document,
            'destruction non booleenne' => ['attempts' => [['destroyed_the_moon' => 1] + $tentative]] + $document,
            'champ de trop' => ['attempts' => [$tentative + ['extra' => 1]]] + $document,
            'lune malformee' => ['moon' => ['key' => 'nimporte', 'owner' => 6, 'npc' => false]] + $document,
            'emportee a zero' => ['destroyed_with_moon' => ['rocket_launcher' => 0]] + $document,
        ] as $nom => $deforme) {
            $this->assertNull(MoonDestructionTallyFacts::fromStorage($deforme), "Un document deforme (« $nom ») a ete relu.");
        }
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: bool}> $tentatives [mission, proprietaire, Etoiles perdues, a detruit la lune]
     * @param array<string, int> $emportees
     */
    private function uneDestruction(array $tentatives, array $emportees, int|null $moonOwner = 6, bool $moonNpc = false): MoonDestructionTallyFacts
    {
        $unites = new UnitCollection();

        foreach ($emportees as $nom => $nombre) {
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        $attempts = [];

        foreach ($tentatives as [$mission, $proprietaire, $perdues, $detruite]) {
            $attempts[] = ['key' => CombatParticipantKey::forFleet($mission), 'owner' => $proprietaire, 'npc' => false, 'deathstars_lost' => $perdues, 'destroyed_the_moon' => $detruite];
        }

        return MoonDestructionTallyFacts::of(
            BattleTallyFacts::SPACE_COMBAT,
            77,
            1_700_000_000,
            ['key' => CombatParticipantKey::forPlanet(7), 'owner' => $moonOwner, 'npc' => $moonNpc],
            $attempts,
            $unites,
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
