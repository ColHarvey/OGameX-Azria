<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Exceptions\CorruptedBattleResult;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Replay\CombatResultIdentity;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\ResourceDiagnostic;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le resultat d'une bataille traverse JSON et revient identique — ou est refuse en nommant le champ.
 *
 * ## Pourquoi un resultat synthetique
 *
 * Les refus se prouvent en alterant un document juste, champ par champ. Un resultat de moteur
 * changerait a chaque execution ; celui-ci est ecrit a la main, petit, et couvre chaque forme que
 * le codec sait ecrire : ressources, unites, tableaux par flotte, diagnostics, unites en fuite.
 * La traversee d'un vrai resultat de moteur est l'objet de `BattleResultRoundTripTest`.
 */
class BattleResultCodecTest extends UnitTestCase
{
    /**
     * Un document ecrit, encode, decode et relu redonne exactement le meme document.
     */
    public function testTheDocumentSurvivesJsonAndComesBackIdentical(): void
    {
        $original = $this->aSyntheticResult();
        $document = BattleResultCodec::toStorage($original, $this->anIdentity());

        $encode = json_encode($document);
        $this->assertIsString($encode);

        $relu = BattleResultCodec::fromStorage(json_decode($encode, true));

        $this->assertSame($document, BattleResultCodec::toStorage($relu, $this->anIdentity()), 'The result read back is not the one that was written.');

        // Quelques faits relus directement, pour que l'egalite des documents ne soit pas la seule preuve.
        $this->assertSame(1000.0, $relu->loot->metal->get());
        $this->assertSame(['light_fighter' => 8, 'small_cargo' => 2], $relu->attackerUnitsResult->toArray());
        $this->assertCount(2, $relu->rounds);
        $this->assertSame(['light_fighter' => 1], $relu->rounds[0]->attackerLossesPerFleet[41]->toArray());
        // Cumul et perte du round se distinguent : un ecrivain qui les echangerait ecrirait un
        // document que le lecteur relirait a l'identique, echange compris.
        $this->assertSame(['light_fighter' => 2], $relu->rounds[1]->attackerLosses->toArray());
        $this->assertSame(['light_fighter' => 1], $relu->rounds[1]->attackerLossesInRound->toArray());
        $this->assertSame(['rocket_launcher' => 4], $relu->rounds[1]->defenderLosses->toArray());
        $this->assertSame(['rocket_launcher' => 2], $relu->rounds[1]->defenderLossesInRound->toArray());
        $this->assertSame([41 => 7], $relu->rounds[1]->hitsPerAttackerFleet);
        $this->assertSame(3, $relu->rounds[1]->hitsDefender);
        $this->assertSame(['light_fighter' => 8, 'small_cargo' => 2], $relu->attackerFleetResults[0]->unitsResult->toArray());
        $this->assertSame(['light_fighter' => 2], $relu->attackerFleetResults[0]->unitsLost->toArray());
        $this->assertSame(['rocket_launcher' => 1], $relu->defenderFleetResults[0]->unitsResult->toArray());
        $this->assertSame(41, $relu->attackerFleetResults[0]->fleetMissionId);
        $this->assertSame(298.0, $relu->attackerFleetResults[0]->survivingCargo->deuterium->get());
        $this->assertSame(1, $relu->resourceDiagnostics->count());
        $this->assertNull($relu->tacticalRetreatFleeingUnits);
    }

    /**
     * Les unites en fuite, quand il y en a, reviennent aussi.
     */
    public function testFleeingUnitsComeBack(): void
    {
        $original = $this->aSyntheticResult();
        $original->tacticalRetreatDefenderFled = true;
        $original->tacticalRetreatFleeingUnits = $this->units(['light_fighter' => 3]);

        $relu = BattleResultCodec::fromStorage($this->throughJson(BattleResultCodec::toStorage($original, $this->anIdentity())));

        $this->assertNotNull($relu->tacticalRetreatFleeingUnits);
        $this->assertSame(['light_fighter' => 3], $relu->tacticalRetreatFleeingUnits->toArray());
        $this->assertTrue($relu->tacticalRetreatDefenderFled);
    }

    /**
     * Un entier devenu chaine est refuse, et le refus nomme le champ.
     */
    public function testANumericStringIsRefused(): void
    {
        $document = $this->aDocument();
        $document['attacker_planet_id'] = '9';

        $this->assertRefused($document, 'attacker_planet_id');
    }

    public function testAFloatWhereAnIntegerIsExpectedIsRefused(): void
    {
        $document = $this->aDocument();
        $document['moon_chance'] = 3.0;

        $this->assertRefused($document, 'moon_chance');
    }

    public function testAMissingFieldIsRefused(): void
    {
        $document = $this->aDocument();
        unset($document['rounds']);

        $this->assertRefused($document, 'rounds');
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $document = $this->aDocument();
        $document['engine'] = 'rust';

        $this->assertRefused($document, 'engine');
    }

    public function testAnUnknownSchemaIsRefused(): void
    {
        $document = $this->aDocument();
        $document['schema'] = 3;

        $this->assertRefused($document, 'schema 3');
    }

    public function testAnUnknownUnitIsRefused(): void
    {
        $document = $this->aDocument();
        $document['attacker_units_start'] = ['warp_cruiser' => 1];

        $this->assertRefused($document, 'warp_cruiser');
    }

    public function testANegativeAmountIsRefused(): void
    {
        $document = $this->aDocument();
        $document['rounds'][1]['defender_ships'] = ['rocket_launcher' => -1];

        $this->assertRefused($document, 'rounds[1].defender_ships.rocket_launcher');
    }

    /**
     * Une clef de flotte qui n'est pas un identifiant : JSON en ferait une chaine, PHP la rendrait
     * telle quelle, et la resolution chercherait une mission qui n'existe pas.
     */
    public function testAFleetKeyThatIsNotAnIdentifierIsRefused(): void
    {
        $document = $this->aDocument();
        $document['rounds'][0]['hits_per_attacker_fleet'] = ['initiator' => 7];

        $this->assertRefused($document, 'hits_per_attacker_fleet');
    }

    public function testAResourceStructureWithAnUnknownKeyIsRefused(): void
    {
        $document = $this->aDocument();
        $document['loot']['dark_matter'] = 1;

        $this->assertRefused($document, 'dark_matter');
    }

    public function testADiagnosticMissingAFieldIsRefused(): void
    {
        $document = $this->aDocument();
        unset($document['resource_diagnostics'][0]['units']);

        $this->assertRefused($document, 'resource_diagnostics[0].units');
    }

    public function testADocumentThatIsNotAStructureIsRefused(): void
    {
        $this->assertRefused('{"schema":1}', 'structure');
    }

    /**
     * Refuse, et dit ou.
     */
    /**
     * L'enveloppe d'identite traverse avec le resultat et se relit telle quelle.
     */
    public function testTheIdentityTravelsWithTheResult(): void
    {
        $document = $this->aDocument();

        $this->assertSame($this->anIdentityDocument(), $document['identity']);
        $this->assertSame($this->anIdentityDocument(), BattleResultCodec::identityOf($document)->toStorage());
    }

    public function testADocumentWithoutAnIdentityIsRefused(): void
    {
        $document = $this->aDocument();
        unset($document['identity']);

        $this->assertRefused($document, 'identity');
    }

    public function testAnIdentityWithANumericStringCombatIsRefused(): void
    {
        $document = $this->aDocument();
        $document['identity']['combat_instance_id'] = '42';

        $this->assertRefused($document, 'combat_instance_id');
    }

    /**
     * La liste des participants est canonique : triee, sans doublon. Deux ecritures du meme ensemble
     * donneraient deux enveloppes differentes pour un meme combat.
     */
    public function testAnIdentityWhoseParticipantsAreNotCanonicalIsRefused(): void
    {
        $document = $this->aDocument();
        $document['identity']['participants'] = [
            CombatParticipantKey::forFleet(1_101),
            CombatParticipantKey::forFleet(1_100),
        ];

        $this->assertRefused($document, 'canonique');

        $document['identity']['participants'] = [
            CombatParticipantKey::forFleet(1_100),
            CombatParticipantKey::forFleet(1_100),
        ];

        $this->assertRefused($document, 'canonique');
    }

    public function testAnIdentityWithAMissingRuleVersionIsRefused(): void
    {
        $document = $this->aDocument();
        unset($document['identity']['versions']['projection']);

        $this->assertRefused($document, 'versions');
    }

    public function testAnIdentityWithoutAFingerprintIsRefused(): void
    {
        $document = $this->aDocument();
        $document['identity']['frozen_facts_fingerprint'] = '';

        $this->assertRefused($document, 'frozen_facts_fingerprint');
    }

    private function assertRefused(mixed $document, string $attendu): void
    {
        try {
            BattleResultCodec::fromStorage($document);
            $this->fail('A corrupted document was read as a battle result (expected a refusal naming « ' . $attendu . ' »).');
        } catch (CorruptedBattleResult $refus) {
            $this->assertStringContainsString($attendu, $refus->defect, 'The refusal does not name what is wrong.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function aDocument(): array
    {
        return $this->throughJson(BattleResultCodec::toStorage($this->aSyntheticResult(), $this->anIdentity()));
    }

    /**
     * Ce que la colonne JSON rendrait : les entiers restent entiers, les flottants flottants.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function throughJson(array $document): array
    {
        $encode = json_encode($document);
        $this->assertIsString($encode);

        $decode = json_decode($encode, true);
        $this->assertIsArray($decode);

        return $decode;
    }

    /**
     * Un resultat petit mais complet : chaque forme que le codec ecrit y figure.
     */
    private function aSyntheticResult(): BattleResult
    {
        $result = new BattleResult();
        $result->loot = new Resources(1000, 500, 250, 0);
        $result->debris = new Resources(300, 150, 0, 0);
        $result->wreckField = ['light_fighter' => 2];
        $result->moonExisted = false;
        $result->moonChance = 3;
        $result->moonCreated = false;
        $result->lootPercentage = 50;
        $result->lootRateInBasisPoints = 5000;
        $result->lootPolicyVersion = CargoWeightedV1::VERSION;
        $result->lootAllocatorVersion = ExactLootAllocationV1::VERSION;
        $result->lootFrozenFacts = ['inactive' => false, 'rate_in_basis_points' => 5000];
        $result->lootSnapshotFingerprint = 'empreinte';
        $result->resourceDiagnostics = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            ExactLootAllocationV1::PHASE_TARGET_LOOT,
            '',
            'metal',
            9007199254740994
        ));
        $result->attackerUnitsStart = $this->units(['light_fighter' => 10, 'small_cargo' => 2]);
        $result->attackerUnitsResult = $this->units(['light_fighter' => 8, 'small_cargo' => 2]);
        $result->attackerUnitsLost = $this->units(['light_fighter' => 2]);
        $result->attackerResourceLoss = new Resources(6000, 2000, 0, 0);
        $result->defenderUnitsStart = $this->units(['rocket_launcher' => 5]);
        $result->defenderUnitsResult = $this->units(['rocket_launcher' => 1]);
        $result->defenderUnitsLost = $this->units(['rocket_launcher' => 4]);
        $result->defenderResourceLoss = new Resources(8000, 0, 0, 0);

        $defenseur = new DefenderFleetResult(0, 7, $this->units(['rocket_launcher' => 5]));
        $defenseur->unitsResult = $this->units(['rocket_launcher' => 1]);
        $defenseur->unitsLost = $this->units(['rocket_launcher' => 4]);
        $defenseur->completelyDestroyed = false;
        $result->defenderFleetResults = [$defenseur];

        $attaquant = new AttackerFleetResult(41, 3, $this->units(['light_fighter' => 10, 'small_cargo' => 2]));
        $attaquant->unitsResult = $this->units(['light_fighter' => 8, 'small_cargo' => 2]);
        $attaquant->unitsLost = $this->units(['light_fighter' => 2]);
        $attaquant->resourceLoss = new Resources(6000, 2000, 0, 0);
        $attaquant->lootShare = new Resources(1000, 500, 250, 0);
        $attaquant->survivingCargo = new Resources(0, 0, 298, 0);
        $attaquant->survivingCargoCapacity = 10000;
        $attaquant->completelyDestroyed = false;
        $result->attackerFleetResults = [$attaquant];

        $result->attackerWeaponLevel = 1;
        $result->attackerShieldLevel = 2;
        $result->attackerArmorLevel = 3;
        $result->defenderWeaponLevel = 4;
        $result->defenderShieldLevel = 5;
        $result->defenderArmorLevel = 6;

        $result->rounds = [
            $this->aRound(['light_fighter' => 1], ['light_fighter' => 1], ['rocket_launcher' => 2], ['rocket_launcher' => 2], 5, 120),
            $this->aRound(['light_fighter' => 2], ['light_fighter' => 1], ['rocket_launcher' => 4], ['rocket_launcher' => 2], 7, 90),
        ];

        $result->repairedDefenses = $this->units(['rocket_launcher' => 1]);
        $result->attackerPlanetId = 9;

        return $result;
    }

    /**
     * @param array<string, int> $pertesAttaquant
     * @param array<string, int> $pertesAttaquantDuRound
     * @param array<string, int> $pertesDefenseur
     * @param array<string, int> $pertesDefenseurDuRound
     */
    private function aRound(array $pertesAttaquant, array $pertesAttaquantDuRound, array $pertesDefenseur, array $pertesDefenseurDuRound, int $coups, int $degats): BattleResultRound
    {
        $round = new BattleResultRound();
        $round->attackerLosses = $this->units($pertesAttaquant);
        $round->attackerLossesInRound = $this->units($pertesAttaquantDuRound);
        $round->attackerLossesPerFleet = [41 => $this->units($pertesAttaquant)];
        $round->attackerLossesInRoundPerFleet = [41 => $this->units($pertesAttaquantDuRound)];
        $round->attackerShipsPerFleet = [41 => $this->units(['light_fighter' => 10 - array_sum($pertesAttaquant), 'small_cargo' => 2])];
        $round->hitsPerAttackerFleet = [41 => $coups];
        $round->damagePerAttackerFleet = [41 => $degats];
        $round->defenderLosses = $this->units($pertesDefenseur);
        $round->defenderLossesInRound = $this->units($pertesDefenseurDuRound);
        $round->hitsAttacker = $coups;
        $round->hitsDefender = 3;
        $round->absorbedDamageAttacker = 40;
        $round->absorbedDamageDefender = 60;
        $round->fullStrengthAttacker = 1000;
        $round->fullStrengthDefender = 400;
        $round->attackerShips = $this->units(['light_fighter' => 10 - array_sum($pertesAttaquant), 'small_cargo' => 2]);
        $round->defenderShips = $this->units(['rocket_launcher' => 5 - array_sum($pertesDefenseur)]);

        return $round;
    }

    /**
     * @param array<string, int> $unites
     */
    private function units(array $unites): UnitCollection
    {
        $collection = new UnitCollection();

        foreach ($unites as $machine => $montant) {
            $collection->addUnit(ObjectService::getUnitObjectByMachineName($machine), $montant);
        }

        return $collection;
    }

    /**
     * Une identite ecrite a la main : cet essai n'a pas d'instance a lire.
     */
    private function anIdentity(): CombatResultIdentity
    {
        return CombatResultIdentity::fromStorage($this->anIdentityDocument());
    }

    /**
     * @return array<string, mixed>
     */
    private function anIdentityDocument(): array
    {
        return [
            'combat_instance_id' => 42,
            'target_body_id' => 7,
            'initiator_mission_id' => 1_100,
            'participants' => [CombatParticipantKey::forFleet(1_100), CombatParticipantKey::forFleet(1_101)],
            'frozen_facts_fingerprint' => 'abc123',
            'versions' => [
                'causal_order' => 'v1',
                'loot_allocator' => 'v1',
                'loot_policy' => 'v1',
                'moon_destruction' => 'v1',
                'projection' => 'v1',
            ],
        ];
    }
}
