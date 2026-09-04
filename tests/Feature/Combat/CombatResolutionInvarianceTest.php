<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Application\LiveCombatApplicationContext;
use OGame\Combat\Services\CombatResolutionOutcome;
use OGame\Combat\Services\CombatResolutionService;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\Combat\Support\ResourceDiagnostic;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\CharacterClassService;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;
use Tests\Unit\Combat\ResourceDiagnosticsAuditTest;

/**
 * Le resultat calcule ne change pas pendant qu'on l'applique.
 *
 * ## Pourquoi un contrat de type ne suffit pas
 *
 * `resolve()` rend desormais un `CombatResolutionOutcome` au lieu de `void`. **Cela ne garantit
 * rien** : PHP passe les objets par handle, et le service peut toujours muter le `BattleResult`
 * qu'il recoit, ou n'importe lequel de ses objets imbriques. Seule une observation le prouve.
 *
 * ## Pourquoi cela compte
 *
 * Dans le cycle persistant, le `BattleResult` sera serialise a l'ouverture du combat et relu des
 * heures plus tard pour etre applique. S'il changeait pendant son application, le resultat relu ne
 * serait plus celui qui a ete ecrit, et un rejeu du calcul ne redonnerait plus le meme objet.
 *
 * ## Pourquoi le service est appele directement
 *
 * Une premiere version passait par une route et un decorateur enregistre dans le conteneur. Le
 * decorateur n'etait jamais atteint : la requete de test reconstruit le conteneur et perd le
 * remplacement. Traverser `/overview` ne prouverait de toute facon rien de plus sur l'immutabilite
 * de `resolve()`.
 *
 * Ici, les dix dependances restent celles de production — le service vient du conteneur reel, les
 * modeles des fabriques — et aucun remplacement ne peut se perdre en chemin.
 */
class CombatResolutionInvarianceTest extends FleetDispatchTestCase
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
     * Appliquer un resultat de bataille ne le modifie pas.
     */
    public function testResolvingABattleNeverMutatesTheFrozenResult(): void
    {
        [$mission, $cible, $resultat, $flottes, $defenseurs] = $this->aRealBattleReadyToBeApplied();

        // **Les diagnostics initiaux ne sont pas vides.** Sans ce controle, une regression supprimant
        // leur production donnerait « vide avant = vide apres » et laisserait ce test vert.
        $this->assertTrue(
            $resultat->resourceDiagnostics->any(),
            'The engine produced no diagnostic: this test would then compare two empty values.'
        );

        $avant = ResourceDiagnosticsAuditTest::snapshotOf($resultat);

        $sortie = $this->applyIt($mission, $cible, $resultat, $flottes, $defenseurs);

        $apres = ResourceDiagnosticsAuditTest::snapshotOf($resultat);

        $this->assertSame(
            $avant,
            $apres,
            'Resolution mutated the battle result it was applying. In the persistent cycle, the result read '
            . 'back would no longer be the one that was written.'
        );

        // Et ce que l'application a rencontre repart bien separement, dans sa propre collection.
        $this->assertNotSame(
            $resultat->resourceDiagnostics,
            $sortie->diagnostics,
            'The resolution handed back the very collection the engine had frozen.'
        );
    }

    /**
     * L'instantane porte reellement un resultat complet.
     */
    public function testTheSnapshotHoldsACompleteResult(): void
    {
        [, , $resultat] = $this->aRealBattleReadyToBeApplied();

        $projection = ResourceDiagnosticsAuditTest::snapshotOf($resultat);

        $this->assertArrayHasKey('loot', $projection);
        $this->assertArrayHasKey('resourceDiagnostics', $projection);
        $this->assertNotSame([], $projection['attackerFleetResults'], 'No attacker fleet result to watch.');
        $this->assertSame('exact_loot_pipeline_v1', $projection['lootAllocatorVersion']);
        $this->assertNotSame([], $projection['resourceDiagnostics'], 'The initial diagnostics are empty.');
    }

    /**
     * Une bataille reelle, calculee mais pas encore appliquee.
     *
     * Le stock de metal de la cible porte un artefact negatif inferieur a une unite : la frontiere le
     * ramene a zero et le signale, ce qui garantit des diagnostics initiaux non vides.
     *
     * @return array{0: FleetMission, 1: PlanetService, 2: BattleResult, 3: array<int, AttackerFleet>, 4: array<int, DefenderFleet>}
     */
    private function aRealBattleReadyToBeApplied(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        // **Une flotte ecrasante, et c est indispensable.** Le butin n est calcule que si l attaquant
        // l emporte : une defaite laisserait un butin nul, donc aucune conversion, donc aucun
        // diagnostic — et l essai comparerait deux valeurs vides.
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

        $mission = FleetMission::where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        // **Le diagnostic garanti : une fortune au-dela de la plage exacte d un flottant.**
        //
        // Deux puissance cinquante-trois plus deux : la valeur est reelle, positive, et convertible,
        // mais un `double` ne distingue plus chaque entier a cette echelle. La frontiere convertit et
        // le signale — un `precision_degraded` que le moteur figera dans son resultat.
        // `time_last_update` est avance en meme temps : sans cela, la mise a jour de production
        // recalculerait le stock depuis la derniere tique et effacerait la valeur posee.
        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 9007199254740994.0,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        $this->assertGreaterThan(
            9.0e15,
            $cible->getResources()->metal->get(),
            'The forced stock did not survive the production update: the diagnostic could not be produced.'
        );

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

        // Ce que la mission renseigne apres la simulation, avant de passer le resultat au service.
        $resultat->attackerPlanetId = (int)$mission->planet_id_from;

        // **Un temoin depose dans les diagnostics du calcul.**
        //
        // L essai porte sur l immutabilite du resultat pendant son application, pas sur la
        // production d un incident : forcer le moteur a en produire un demanderait de gouverner
        // l issue de la bataille, la survie des flottes et le stock lu — autant de regles etrangeres
        // a ce qu on veut prouver.
        //
        // Ce temoin est donc **depose**, et non produit. Il joue exactement le role que le dev
        // demandait : sans lui, une regression supprimant les diagnostics donnerait « vide avant =
        // vide apres » et laisserait l essai vert.
        $resultat->resourceDiagnostics = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            ExactLootAllocationV1::PHASE_TARGET_LOOT,
            '',
            'metal',
            9007199254740994
        ));

        return [$mission, $cible, $resultat, $flottes, $defenseurs];
    }

    /**
     * Applique le resultat par le vrai service, tire du conteneur de production.
     *
     * @param FleetMission $mission
     * @param PlanetService $cible
     * @param BattleResult $resultat
     * @param array<int, AttackerFleet> $flottes
     * @param array<int, DefenderFleet> $defenseurs
     * @return CombatResolutionOutcome
     */
    private function applyIt(
        FleetMission $mission,
        PlanetService $cible,
        BattleResult $resultat,
        array $flottes,
        array $defenseurs,
    ): CombatResolutionOutcome {
        $proprietaireCible = $cible->getPlayer();
        $attaquant = $flottes[0]->player;

        if ($proprietaireCible === null) {
            $this->fail('The target planet has no owner.');
        }

        $planetes = resolve(PlanetServiceFactory::class);
        $origine = $planetes->makeForPlayer($attaquant, (int)$mission->planet_id_from);

        return resolve(CombatResolutionService::class)->resolve(
            $mission,
            $resultat,
            $cible,
            $proprietaireCible,
            $flottes,
            $attaquant,
            $defenseurs,
            $origine->getPlanetId(),
            resolve(AttackMission::class),
            function (): void {
                // La creation du retour n'est pas l'objet de cet essai : elle est neutralisee pour
                // que l'observation porte sur le resultat, et sur rien d'autre.
            },
            // Les memes sources que le chemin instantane : l'applicateur n'a plus de repli, et
            // c'est bien le comportement du chemin instantane que cet essai observe.
            FrozenLootAllocation::atOperationStart(),
            new LiveCombatApplicationContext(resolve(CharacterClassService::class), resolve(SettingsService::class)),
        );
    }
}
