<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\MoonDestruction\FrozenMoonDestructionPlan;
use OGame\Combat\MoonDestruction\MoonDestructionOutcome;
use OGame\Combat\MoonDestruction\MoonDestructionRolls;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\FleetDispatchTestCase;

/**
 * Une destruction de lune durable : le plan est gele a la cloture, la lune ne bouge qu'a l'echeance.
 *
 * ## Le defaut que cet essai ferme
 *
 * `FrozenMoonDestructionPlan` existait depuis le §27 sans qu'aucun chemin ne l'appelle : une mission
 * de destruction admise dans un combat durable se reglait comme une attaque ordinaire, et la lune
 * n'etait jamais tentee. Le raccordement : gel a la cloture avec les tirages, application a
 * l'echeance — etoiles perdues retirees du retour avant qu'il existe, lune supprimee apres la levee
 * de la barriere, flottes en vol vers elle redirigees vers la planete mere, messages aux deux camps.
 *
 * ## Les trois issues sont celles du chemin instantane
 *
 * Lune detruite : la flotte rentre, moins les etoiles qu'un tirage de perte a emportees. Echec
 * catastrophique : **aucun retour** — `handleCatastrophicFailure()` n'en cree pas, et le chemin
 * durable ne se montre pas plus clement. Echec simple : la flotte rentre entiere.
 *
 * ## Rien n'est visible avant l'echeance
 *
 * Entre la cloture et le reglement, la lune existe et le plan dort dans l'instance. Un joueur qui
 * lirait son tirage a l'avance saurait avant le defenseur ce qui va arriver.
 */
final class PersistentMoonDestructionTest extends FleetDispatchTestCase
{
    protected int $missionType = 9;

    protected string $missionName = 'Détruire';

    private const int DEATHSTARS = 4;

    protected function basicSetup(): void
    {
        $this->planetAddUnit('deathstar', self::DEATHSTARS);
        $this->playerSetResearchLevel('computer_technology', object_level: 5);
        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 1);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);
        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    public function testASuccessfulAttemptDestroysTheMoonOnlyAtTheSettlementAndRedirectsTheFleetsInFlight(): void
    {
        [$combat, $lune, $mission] = $this->aDurableMoonDestructionReadyToSettle([1, 100]);

        // **Rien avant l'echeance** : la lune existe, le plan dort, et il dit deja son issue.
        $this->assertNotNull(Planet::query()->find($lune->getPlanetId()), 'The moon vanished at the closure: the plan was applied before the settlement.');
        $plan = $this->frozenPlanOf($combat);
        $this->assertTrue($plan->destroysTheMoon(), 'The frozen plan does not destroy the moon although the roll was 1.');
        $this->assertSame(MoonDestructionOutcome::MoonDestroyed, $plan->attempts[0]->outcome);

        // Une flotte etrangere encore en vol vers la lune : elle sera redirigee vers la planete mere.
        $enVol = $this->aForeignTransportTowards($lune);

        $this->settle($combat);

        $this->assertNull(Planet::query()->find($lune->getPlanetId()), 'The moon survived a successful attempt.');
        $enVol->refresh();
        $this->assertSame(PlanetType::Planet->value, (int)$enVol->type_to, 'A fleet in flight towards the destroyed moon still targets a moon.');
        $retour = FleetMission::query()->where('parent_id', $mission->id)->first();
        $this->assertNotNull($retour, 'The destroyer has no return.');
        $this->assertSame(self::DEATHSTARS, (int)$retour->deathstar, 'Death stars were lost although the loss roll was 100.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'moon_destruction_success')->count(), 'The destroyer was not told of the success.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $lune->getPlayer()?->getId())->where('key', 'moon_destroyed')->count(), 'The owner was not told the moon is gone.');
    }

    public function testACatastrophicAttemptLeavesTheMoonAndTakesEveryDeathStarOutOfTheReturn(): void
    {
        [$combat, $lune, $mission] = $this->aDurableMoonDestructionReadyToSettle([100, 1]);
        $this->assertSame(MoonDestructionOutcome::AttemptFailed, $this->frozenPlanOf($combat)->attempts[0]->outcome);

        $this->settle($combat);

        $this->assertNotNull(Planet::query()->find($lune->getPlanetId()), 'The moon vanished after a failed attempt.');

        // **Aucun retour** : c'est ce que le chemin instantane fait d'un echec catastrophique — il
        // n'appelle pas `startReturn()`, et la flotte reste sur place. Le chemin durable applique la
        // meme regle, il n'en invente pas une autre.
        $this->assertNull(
            FleetMission::query()->where('parent_id', $mission->id)->first(),
            'A catastrophic failure sent a fleet home: the durable path is kinder than the instant one.'
        );
        $this->assertSame(1, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'moon_destruction_catastrophic')->count());
        $this->assertSame(1, DB::table('messages')->where('user_id', $lune->getPlayer()?->getId())->where('key', 'moon_destruction_repelled')->count());
    }

    public function testAFailedAttemptChangesNothingButTellsBothSides(): void
    {
        [$combat, $lune, $mission] = $this->aDurableMoonDestructionReadyToSettle([100, 100]);

        $this->settle($combat);

        $this->assertNotNull(Planet::query()->find($lune->getPlanetId()));
        $retour = FleetMission::query()->where('parent_id', $mission->id)->first();
        $this->assertNotNull($retour);
        $this->assertSame(self::DEATHSTARS, (int)$retour->deathstar, 'Death stars were lost on a plain failure.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'moon_destruction_failure')->count());
    }

    /**
     * Le plan gele de ce combat, relu par sa porte de confiance.
     */
    private function frozenPlanOf(CombatInstance $combat): FrozenMoonDestructionPlan
    {
        $document = $combat->moon_destruction_plan;
        $this->assertIsArray($document, 'The closure froze no moon destruction plan.');

        return FrozenMoonDestructionPlan::fromFrozenFacts($document);
    }

    /**
     * @param array<int, int> $rolls
     */
    private function rollsWillBe(array $rolls): void
    {
        app()->instance(MoonDestructionRolls::class, new class ($rolls) extends MoonDestructionRolls {
            /** @param array<int, int> $rolls */
            public function __construct(private array $rolls)
            {
            }

            public function roll(): int
            {
                $tirage = array_shift($this->rolls);
                if ($tirage === null) {
                    throw new RuntimeException('The bench ran out of rolls.');
                }

                return $tirage;
            }
        });
    }

    /**
     * Une mission de destruction admise seule : le combat s'ouvre et se ferme a l'arrivee, la bataille
     * est calculee, le plan gele, et l'echeance attend.
     *
     * @param array<int, int> $rolls Les tirages que la cloture consommera : destruction, puis perte.
     * @return array{0: CombatInstance, 1: PlanetService, 2: FleetMission}
     */
    private function aDurableMoonDestructionReadyToSettle(array $rolls): array
    {
        $this->basicSetup();
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('deathstar'), self::DEATHSTARS);
        $lune = $this->sendMissionToOtherPlayerMoon($unites, new Resources(0, 0, 0, 0));
        $lune->removeUnits($lune->getShipUnits(), false);
        $lune->removeUnits($lune->getDefenseUnits(), false);
        $lune->save();
        $lune->reloadPlanet();
        // **Un diametre ou l echec est atteignable.** A 1, la chance de destruction vaut 100 % et les
        // trois scenarios reussissent : le juste et le faux coincideraient. A 8100 avec quatre etoiles :
        // destruction 20 % (seuil 20), perte des etoiles 45 % (seuil 45) — un tirage separe chaque issue.
        Planet::query()->where('id', $lune->getPlanetId())->update(['diameter' => 8100]);
        DB::table('users')->where('id', $lune->getPlayer()?->getId())->update(['tactical_retreat_ratio' => 0]);

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 9)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($mission, 'The moon destruction mission was not dispatched.');

        // **Le stub se pose ici, pas avant.** Le montage ci-dessus reconstruit le conteneur : une
        // instance enregistree plus tot serait orpheline, et la cloture tirerait au hasard — le juste
        // et le faux coincideraient un passage sur deux.
        $this->rollsWillBe($rolls);

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 1));
        $this->get('/overview')->assertStatus(200);

        $combat = CombatInstance::query()->where('mission_id', $mission->id)->first();
        $this->assertNotNull($combat, 'The arrival did not open a durable combat.');
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not close at once.');
        $this->assertNotNull($combat->moon_destruction_plan, 'The closure froze no moon destruction plan.');

        return [$combat, $lune, $mission];
    }

    private function aForeignTransportTowards(PlanetService $lune): FleetMission
    {
        $coordonnees = $lune->getPlanetCoordinates();
        $depart = $this->planetService->getPlanetCoordinates();

        return FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'type_from' => 1,
            'galaxy_from' => $depart->galaxy,
            'system_from' => $depart->system,
            'position_from' => $depart->position,
            'planet_id_to' => $lune->getPlanetId(),
            'type_to' => PlanetType::Moon->value,
            'galaxy_to' => $coordonnees->galaxy,
            'system_to' => $coordonnees->system,
            'position_to' => $coordonnees->position,
            'mission_type' => 3,
            'time_departure' => (int)Date::now()->timestamp,
            'time_arrival' => (int)Date::now()->timestamp + 100_000,
            'small_cargo' => 1,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    private function settle(CombatInstance $combat): void
    {
        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);
    }
}
