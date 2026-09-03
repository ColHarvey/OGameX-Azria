<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Exceptions\FalsifiedLootContext;
use OGame\Combat\Exceptions\UnknownFingerprintSchema;
use OGame\Combat\Exceptions\UnknownLootAllocatorVersion;
use OGame\Combat\Exceptions\UnknownLootPolicyVersion;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\Combat\Policies\LootRateRule;
use OGame\Combat\Policies\NoLootV1;
use OGame\Combat\Policies\NpcBaseV1;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\AttackerFleetSnapshot;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\LootPolicy;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use ReflectionClass;
use Tests\UnitTestCase;

/**
 * Les faits de pillage geles : ce qu'ils garantissent, et ce qu'ils refusent.
 *
 * Un contexte porte a la fois des faits — inactivite, fret engage, composition des flottes — et ce
 * qui en decoule : le taux, les versions. Cette redondance sert l'audit, et elle ouvre une porte :
 * deux valeurs qui ne se correspondent plus. Ces essais verrouillent cette porte.
 */
class LootContextTest extends UnitTestCase
{
    private const int INSTANT = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetPlanetModel([]);
        $this->createAndSetUserTechModel([]);
    }

    /**
     * Les faits observes produisent le taux, et le contexte les conserve tels quels.
     */
    public function testObservedFactsProduceTheRateAndAreKept(): void
    {
        $contexte = $this->contextFor(new LootPolicy(true, new AttackerCargoShare(50_000, 100_000)));

        $this->assertSame(6_250, $contexte->rateInBasisPoints, 'Half the cargo being Discoverer must give 62,5 %.');
        $this->assertSame(CargoWeightedV1::VERSION, $contexte->policyVersion);
        $this->assertSame(ExactLootAllocationV1::VERSION, $contexte->allocatorVersion);
        $this->assertTrue($contexte->targetIsInactive);
        $this->assertSame(50_000, $contexte->discovererCargo);
        $this->assertSame(100_000, $contexte->totalCargo);
        $this->assertSame(self::INSTANT, $contexte->observedAt);
        $this->assertFalse($contexte->grantsNoLoot());
    }

    /**
     * Un refus nomme donne un taux nul, sous sa propre version.
     */
    public function testANamedRefusalYieldsNoRateUnderItsOwnVersion(): void
    {
        $contexte = $this->contextFor(LootPolicy::noLoot(NoLootReason::NpcEncounter));

        $this->assertSame(0, $contexte->rateInBasisPoints);
        $this->assertSame(
            NoLootV1::VERSION,
            $contexte->policyVersion,
            'A refusal must not be recorded as a player rule that happened to yield zero.'
        );
        $this->assertTrue($contexte->grantsNoLoot());
        $this->assertSame(NoLootReason::NpcEncounter, $contexte->noLootBecause);
    }

    /**
     * Un camp pilote par le serveur releve de sa propre version, au meme taux.
     */
    public function testAServerDrivenSideHasItsOwnVersionAtTheSameRate(): void
    {
        $contexte = $this->contextFor(LootPolicy::forNpcAttacker(true, new AttackerCargoShare(0, 40_000)));

        $this->assertSame(5_000, $contexte->rateInBasisPoints, 'An inactive target must not raise a pirate rate.');
        $this->assertSame(NpcBaseV1::VERSION, $contexte->policyVersion);
    }

    /**
     * Les faits font l'aller-retour sans rien perdre.
     */
    public function testTheFactsSurviveTheRoundTrip(): void
    {
        foreach ([
            'pillage ordinaire' => new LootPolicy(true, new AttackerCargoShare(3, 8)),
            'camp pirate' => LootPolicy::forNpcAttacker(false, new AttackerCargoShare(0, 8)),
            'refus nomme' => LootPolicy::noLoot(NoLootReason::CounterEspionage),
        ] as $quoi => $politique) {
            $contexte = $this->contextFor($politique);
            $relu = LootContext::fromFrozenFacts($contexte->toFrozenFacts());

            $this->assertSame($contexte->toFrozenFacts(), $relu->toFrozenFacts(), "The round trip lost something for « {$quoi} ».");
            $this->assertSame($contexte->rateInBasisPoints, $relu->rateInBasisPoints);
            $this->assertSame($contexte->snapshotFingerprint, $relu->snapshotFingerprint);
        }
    }

    /**
     * Une regle ancienne reste applicable apres l'arrivee de la suivante.
     *
     * ## Le test qui justifie tout le registre
     *
     * Un contexte est ouvert sous `cargo_weighted_v1`. Une v2 devient ensuite la version par defaut.
     * Le contexte doit alors etre relu **sous sa propre regle**, avec le meme taux et la meme
     * empreinte — et surtout pas recalcule sous la v2, qui donnerait un chiffre que personne n'a
     * applique.
     *
     * Le registre est construit ici, pour cet essai seul : rien n'est modifie globalement, et
     * l'essai suivant n'en herite pas.
     */
    public function testAnOlderRuleStaysApplicableAfterANewDefaultArrives(): void
    {
        $contexte = $this->contextFor(new LootPolicy(true, new AttackerCargoShare(1, 4)));
        $faits = $contexte->toFrozenFacts();

        $this->assertSame(5_625, $contexte->rateInBasisPoints, 'A quarter of Discoverer cargo gives a quarter of the bonus.');

        $registre = LootPolicyRegistry::of(
            [new CargoWeightedV1(), new NpcBaseV1(), new NoLootV1(), new ADifferentRuleV2()],
            ADifferentRuleV2::VERSION
        );

        $this->assertSame(ADifferentRuleV2::VERSION, $registre->currentVersion());

        $relu = LootContext::fromFrozenFacts($faits, $registre);

        $this->assertSame(5_625, $relu->rateInBasisPoints, 'The v1 combat was recomputed under v2.');
        $this->assertSame(CargoWeightedV1::VERSION, $relu->policyVersion);
        $this->assertSame($contexte->snapshotFingerprint, $relu->snapshotFingerprint);
    }

    /**
     * Une version de regle inconnue est refusee, sans repli.
     */
    public function testAnUnknownPolicyVersionIsRefused(): void
    {
        $faits = $this->contextFor(new LootPolicy(false, new AttackerCargoShare(0, 10)))->toFrozenFacts();
        $registre = LootPolicyRegistry::of([new NoLootV1()], NoLootV1::VERSION);

        $this->expectException(UnknownLootPolicyVersion::class);

        LootContext::fromFrozenFacts($faits, $registre);
    }

    /**
     * Une version d'allocateur inconnue est refusee, et par une erreur distincte.
     */
    public function testAnUnknownAllocatorVersionIsRefusedByItsOwnError(): void
    {
        $faits = $this->contextFor(new LootPolicy(false, new AttackerCargoShare(0, 10)))->toFrozenFacts();
        $faits['allocator_version'] = 'exact_loot_pipeline_v9';

        $this->expectException(UnknownLootAllocatorVersion::class);

        LootContext::fromFrozenFacts($faits);
    }

    /**
     * Une version de schema d'empreinte inconnue est refusee, et par une troisieme erreur.
     *
     * Une regle inconnue veut dire : je ne sais pas quel taux appliquer. Un schema inconnu veut
     * dire : je ne sais pas quels faits ont ete photographies. Les confondre ferait chercher au
     * mauvais endroit.
     */
    public function testAnUnknownFingerprintSchemaIsRefusedByAThirdError(): void
    {
        $faits = $this->contextFor(new LootPolicy(false, new AttackerCargoShare(0, 10)))->toFrozenFacts();
        $faits['fingerprint_schema'] = 99;

        $this->expectException(UnknownFingerprintSchema::class);

        LootContext::fromFrozenFacts($faits);
    }

    /**
     * Un taux falsifie est refuse, jamais corrige en silence.
     */
    public function testAFalsifiedRateIsRefused(): void
    {
        $faits = $this->contextFor(new LootPolicy(true, new AttackerCargoShare(0, 100)))->toFrozenFacts();
        $faits['rate_in_basis_points'] = 7_500;

        $this->expectException(FalsifiedLootContext::class);

        LootContext::fromFrozenFacts($faits);
    }

    /**
     * Un fret falsifie est refuse par le meme controle.
     */
    public function testFalsifiedCargoIsRefused(): void
    {
        $faits = $this->contextFor(new LootPolicy(true, new AttackerCargoShare(0, 100)))->toFrozenFacts();
        $faits['discoverer_cargo'] = 100;

        $this->expectException(FalsifiedLootContext::class);

        LootContext::fromFrozenFacts($faits);
    }

    /**
     * Une empreinte qui ne correspond plus a ses faits est refusee.
     */
    public function testAFingerprintThatNoLongerMatchesItsFactsIsRefused(): void
    {
        $faits = $this->contextFor(new LootPolicy(false, new AttackerCargoShare(0, 10)))->toFrozenFacts();
        $faits['snapshot']['fleets'][0]['units']['small_cargo'] = 999;

        $this->expectException(FalsifiedLootContext::class);

        LootContext::fromFrozenFacts($faits);
    }

    /**
     * Un champ manquant arrete la relecture au lieu d'etre comble.
     */
    public function testAMissingFieldIsRefusedRatherThanFilledIn(): void
    {
        $complets = $this->contextFor(new LootPolicy(false, new AttackerCargoShare(0, 10)))->toFrozenFacts();

        foreach (array_keys($complets) as $champ) {
            $ampute = $complets;
            unset($ampute[$champ]);

            try {
                LootContext::fromFrozenFacts($ampute);
                $this->fail("The context was rebuilt without « {$champ} », so a missing fact was invented.");
            } catch (FalsifiedLootContext | UnknownFingerprintSchema) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Une raison de refus inconnue n'est pas devinee.
     */
    public function testAnUnknownRefusalIsNotGuessed(): void
    {
        $faits = $this->contextFor(LootPolicy::noLoot(NoLootReason::NpcEncounter))->toFrozenFacts();
        $faits['no_loot_because'] = 'parce_que';

        $this->expectException(InvalidArgumentException::class);

        LootContext::fromFrozenFacts($faits);
    }

    /**
     * Le constructeur n'est pas accessible : seules les fabriques construisent un contexte.
     */
    public function testOnlyTheFactoriesCanBuildAContext(): void
    {
        $constructeur = (new ReflectionClass(LootContext::class))->getConstructor();

        $this->assertNotNull($constructeur);
        $this->assertTrue($constructeur->isPrivate(), 'The constructor is reachable, so a contradictory context can be written.');
    }

    /**
     * Un taux persiste sous forme de chaine numerique ou de flottant n'est pas relu.
     *
     * La relecture etait deja stricte — `is_int()` sur chaque fait entier. Cet essai le prouve
     * plutot que de le supposer : c'est la garde des relectures persistantes qui l'exige, pour
     * chaque porte qui entre dans l'empreinte.
     */
    public function testANumericStringRateIsRefused(): void
    {
        foreach (['5000', 5000.0] as $valeur) {
            $faits = $this->contextFor(new LootPolicy(false, new AttackerCargoShare(0, 10)))->toFrozenFacts();
            $faits['rate_in_basis_points'] = $valeur;

            try {
                LootContext::fromFrozenFacts($faits);

                $this->fail('A ' . get_debug_type($valeur) . ' rate was accepted at rehydration.');
            } catch (FalsifiedLootContext) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Un contexte bati sur une composition de reference.
     *
     * @param LootPolicy $politique
     * @return LootContext
     */
    private function contextFor(LootPolicy $politique): LootContext
    {
        return LootContext::fromObservedFacts(
            $politique,
            [
                $this->snapshotOf(101, 7, true),
                $this->snapshotOf(102, 3, false),
            ],
            ['body_key' => 'corps-vise', 'owner_id' => 42],
            self::INSTANT,
            LootAllocatorRegistry::default()->currentVersion(),
        );
    }

    /**
     * La photographie d'une flotte de petits transporteurs.
     *
     * @param int $missionId
     * @param int $transporteurs
     * @param bool $estDecouvreur
     * @return AttackerFleetSnapshot
     */
    private function snapshotOf(int $missionId, int $transporteurs, bool $estDecouvreur): AttackerFleetSnapshot
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), $transporteurs);

        $flotte = new AttackerFleet();
        $flotte->units = $unites;
        $flotte->player = $this->playerService;
        $flotte->fleetMissionId = $missionId;
        $flotte->ownerId = 7;
        $flotte->cargoResources = new Resources(0, 0, 0, 0);
        $flotte->isInitiator = $missionId === 101;
        $flotte->fleetMission = null;

        return AttackerFleetSnapshot::of($flotte, ActorKind::Player, $estDecouvreur, $transporteurs * 5_000);
    }
}

/**
 * Une regle de remplacement, pour demontrer qu'une v1 survit a l'arrivee d'une v2.
 *
 * Elle donne un taux volontairement different, pour qu'un recalcul sous elle se voie immediatement.
 */
final class ADifferentRuleV2 implements LootRateRule
{
    public const string VERSION = 'cargo_weighted_v2';

    public function version(): string
    {
        return self::VERSION;
    }

    public function rateInBasisPoints(LootPolicy $facts): int
    {
        return 9_999;
    }
}
