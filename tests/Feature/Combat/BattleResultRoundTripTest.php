<?php

namespace Tests\Feature\Combat;

use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\FrozenLootPotential;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Replay\CombatResultIdentity;
use OGame\Combat\Services\CombatDurationEstimator;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\Combat\Support\ResourceDiagnostic;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Un vrai resultat de moteur traverse la colonne JSON et revient identique.
 *
 * ## Ce que le rejeu exige
 *
 * Le combat durable calcule sa bataille a la cloture et la regle des heures plus tard sur le
 * document relu. Tout ce qui en decoule — la duree, le potentiel de butin, le rapport — doit etre
 * le meme qu'il vienne de l'objet calcule ou du document relu. Le resultat synthetique du codec
 * prouve la forme ; celui-ci prouve qu'un resultat du moteur, avec ses rounds reels, ses flottes
 * partiellement survivantes et ses diagnostics, ne perd rien en chemin.
 */
class BattleResultRoundTripTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 60);
        $this->planetAddUnit('light_fighter', 400);
        $this->playerSetResearchLevel('computer_technology', object_level: 1);

        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('fleet_speed_holding', 1);
        $settingsService->set('fleet_speed_peaceful', 1);
        $settingsService->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * Le document relu redonne le meme document, la meme duree et le meme potentiel de butin.
     */
    public function testAnEngineResultComesBackWithTheSameDurationAndPotential(): void
    {
        $original = $this->aRealResult();
        $identite = CombatResultIdentity::fromStorage([
            'combat_instance_id' => 1,
            'target_body_id' => 2,
            'initiator_mission_id' => 3,
            'participants' => [CombatParticipantKey::forFleet(3)],
            'frozen_facts_fingerprint' => 'empreinte',
            'versions' => [
                'causal_order' => 'v1',
                'loot_allocator' => 'v1',
                'loot_policy' => 'v1',
                'moon_destruction' => 'v1',
                'projection' => 'v1',
            ],
        ]);
        $document = BattleResultCodec::toStorage($original, $identite);

        $this->assertNotSame([], $document['rounds'], 'The battle had no round: the round trip would prove little.');
        $this->assertGreaterThan(0, $document['loot']['metal'] + $document['loot']['crystal'] + $document['loot']['deuterium'], 'No loot: the potential could not be compared.');

        $relu = BattleResultCodec::fromStorage($this->throughJson($document));

        // **Les deux documents sont compares sous leur forme stockee.** Les structures opaques du
        // moteur — le champ d'epaves — portent des flottants entiers (`36000.0`) que JSON rend en
        // entiers selon la precision de serialisation du php.ini ; c'est une propriete de la
        // colonne, pas du codec, et les champs types du codec acceptent l'un comme l'autre.
        $this->assertSame(
            $this->throughJson($document),
            $this->throughJson(BattleResultCodec::toStorage($relu, $identite)),
            'The engine result read back is not the one that was written.'
        );

        $estimateur = new CombatDurationEstimator();
        $this->assertSame(
            $estimateur->estimate($original)->seconds,
            $estimateur->estimate($relu)->seconds,
            'The duration computed from the document differs from the one computed from the result.'
        );

        $versions = FrozenCombatVersionSet::chosenAtOpening();
        $this->assertTrue(
            FrozenLootPotential::frozenFrom($original, $versions)->amounts->equals(FrozenLootPotential::frozenFrom($relu, $versions)->amounts),
            'The loot potential frozen from the document differs from the one frozen from the result.'
        );

        $this->assertSame(
            $original->resourceDiagnostics->count(),
            $relu->resourceDiagnostics->count(),
            'The diagnostics did not survive the round trip.'
        );
    }

    /**
     * Ce que la colonne JSON rendrait.
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
     * Une vraie bataille, avec un diagnostic depose pour que cette forme-la traverse aussi.
     */
    private function aRealResult(): BattleResult
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

        $mission = FleetMission::where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        // Un stock connu : sans butin, le potentiel n'aurait rien a comparer. Et une defense :
        // sans elle, la bataille n'a aucun round, et le document n'aurait rien a faire traverser.
        $cible->addResources(new Resources(500_000, 300_000, 100_000, 0));
        $cible->addUnit('rocket_launcher', 80);

        $attaquant = AttackerFleet::fromFleetMission(
            $mission,
            resolve(FleetMissionService::class),
            resolve(PlayerServiceFactory::class),
            true
        );

        $flottes = [$attaquant];
        $defenseurs = [DefenderFleet::fromPlanet($cible)];

        $moteur = new PhpBattleEngine(
            $flottes,
            $cible,
            $defenseurs,
            resolve(SettingsService::class),
            LiveLootContextFactory::forBattle($flottes, $cible, FrozenLootAllocation::atOperationStart())
        );

        $resultat = $moteur->simulateBattle();
        $resultat->attackerPlanetId = (int)$mission->planet_id_from;

        // Un diagnostic depose, comme dans l'essai d'invariance : produire un vrai diagnostic
        // demanderait de gouverner l'issue de la bataille, et ce n'est pas ce qu'on prouve.
        $resultat->resourceDiagnostics = $resultat->resourceDiagnostics->mergedWith(ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            ExactLootAllocationV1::PHASE_TARGET_LOOT,
            '',
            'metal',
            9007199254740994
        )));

        return $resultat;
    }
}
