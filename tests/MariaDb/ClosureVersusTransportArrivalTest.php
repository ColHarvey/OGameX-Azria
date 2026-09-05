<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\EngagesAPersistentCombat;
use Tests\FleetDispatchTestCase;

/**
 * La fermeture d'un ralliement contre l'arrivee d'un transport sur le meme corps, dans deux
 * processus : dans les deux ordres, puis en meme temps.
 *
 * ## Ce que la matrice decide, et ce que la base doit tenir
 *
 * Un transport qui arrive sur un corps en ralliement livre normalement (`completeNormally`) ; la
 * fermeture calcule et fige la bataille — butin compris — sur le stock qu'elle lit. Deux issues sont
 * donc legitimes : la cargaison livree avant la fermeture entre dans le butin gele, livree apres
 * elle n'y entre pas. Ce qui n'est jamais legitime : une cargaison livree deux fois ou perdue, un
 * transport sans retour, un butin gele qui ne correspond a aucun des deux stocks, une fermeture
 * qui echoue. Les colonnes du potentiel, elles, ne s'ecrivent qu'au reglement : ce n'est pas la
 * qu'on lit ce que la fermeture a vu, c'est dans le resultat de bataille gele.
 *
 * Les deux ordres sont forces par une attente sur un fait de la base (le statut du combat, le
 * drapeau `processed` du transport) ; le troisieme scenario laisse les deux processus partir
 * ensemble et n'exige que la conservation et la coherence du potentiel avec l'un des deux stocks.
 */
#[Group('mariadb')]
final class ClosureVersusTransportArrivalTest extends FleetDispatchTestCase
{
    use EngagesAPersistentCombat {
        basicSetup as traitBasicSetup;
    }
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int STOCK_METAL = 100_000;

    private const int CARGAISON = 10_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->traitBasicSetup();
        // La seconde vague, qui ouvre la fenetre de ralliement, a besoin de ses propres chasseurs.
        $this->planetAddUnit('light_fighter', 300);
    }

    public function testACargoDeliveredAfterTheClosureStaysOutOfTheFrozenLoot(): void
    {
        [$combat, $transport, $cible, $fermeture] = $this->anOpenRallyAndAnArrivingTransport();

        $issues = $this->inParallel(2, function (int $rang) use ($combat, $transport, $fermeture): string {
            if ($rang === 0) {
                return self::closeTheRally($combat, $fermeture);
            }
            $this->waitUntil(
                static fn (): bool => DB::table('combat_instances')->where('id', $combat->id)->value('status') === CombatState::Active->value,
                'The rally never closed: the transport could not arrive after it.'
            );

            return self::deliverTheTransport($transport);
        });

        $this->assertBothHappened($issues);
        $this->assertConservation($combat, $transport, $cible);
        $this->assertSame(self::frozenShareOf($combat, self::STOCK_METAL), $this->frozenMetalOf($combat), 'A cargo delivered after the closure entered the loot the closure froze.');
    }

    public function testACargoDeliveredBeforeTheClosureEntersTheFrozenLoot(): void
    {
        [$combat, $transport, $cible, $fermeture] = $this->anOpenRallyAndAnArrivingTransport();

        $issues = $this->inParallel(2, function (int $rang) use ($combat, $transport, $fermeture): string {
            if ($rang === 1) {
                return self::deliverTheTransport($transport);
            }
            $this->waitUntil(
                static fn (): bool => (int)DB::table('fleet_missions')->where('id', $transport->id)->value('processed') === 1,
                'The transport never arrived: the rally could not close after it.'
            );

            return self::closeTheRally($combat, $fermeture);
        });

        $this->assertBothHappened($issues);
        $this->assertConservation($combat, $transport, $cible);
        $this->assertSame(self::frozenShareOf($combat, self::STOCK_METAL + self::CARGAISON), $this->frozenMetalOf($combat), 'A cargo delivered before the closure was left out of the frozen loot.');
    }

    public function testASimultaneousClosureAndArrivalKeepEveryUnitAndFreezeOneOfTheTwoStocks(): void
    {
        [$combat, $transport, $cible, $fermeture] = $this->anOpenRallyAndAnArrivingTransport();

        $issues = $this->inParallel(2, static fn (int $rang): string => $rang === 0
            ? self::closeTheRally($combat, $fermeture)
            : self::deliverTheTransport($transport));

        $this->assertBothHappened($issues);
        $this->assertConservation($combat, $transport, $cible);
        $this->assertContains(
            $this->frozenMetalOf($combat),
            [self::frozenShareOf($combat, self::STOCK_METAL), self::frozenShareOf($combat, self::STOCK_METAL + self::CARGAISON)],
            'The frozen loot matches neither the stock before the cargo nor the stock after it.'
        );
    }

    private static function closeTheRally(CombatInstance $combat, int $fermeture): string
    {
        return (new RallyClosureService())->close($combat->id, $fermeture)->closed ? 'fermee' : 'non fermee';
    }

    private static function deliverTheTransport(FleetMission $transport): string
    {
        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($transport->id));

        return 'livre';
    }

    /**
     * @param array<int, string> $issues
     */
    private function assertBothHappened(array $issues): void
    {
        $this->assertSame(['fermee', 'livre'], [$issues[0], $issues[1]], 'The closure or the delivery did not happen.');
    }

    private function assertConservation(CombatInstance $combat, FleetMission $transport, int $cible): void
    {
        $transport->refresh();
        $this->assertSame(1, (int)$transport->processed, 'The transport was not processed exactly once.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $transport->id)->count(), 'The transport did not return exactly once.');
        $this->assertSame(self::STOCK_METAL + self::CARGAISON, (int)DB::table('planets')->where('id', $cible)->value('metal'), 'The cargo was lost or delivered twice.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not end up closed.');
        $this->assertNotNull($combat->battle_result, 'The closure froze no battle result.');
    }

    private function frozenMetalOf(CombatInstance $combat): int
    {
        $combat->refresh();

        return (int)BattleResultCodec::fromStorage($combat->battle_result)->loot->metal->get();
    }

    /**
     * La part du stock que la fermeture fige, au taux que le resultat gele porte — lu, jamais suppose.
     */
    private static function frozenShareOf(CombatInstance $combat, int $stock): int
    {
        $combat->refresh();

        return intdiv($stock * BattleResultCodec::fromStorage($combat->battle_result)->lootRateInBasisPoints, 10_000);
    }

    /**
     * Un ralliement ouvert par une premiere attaque, tenu ouvert par une seconde vague a +18 s, et
     * un transport du meme joueur qui arrive dans la fenetre. Les stocks sont poses et la production
     * gelee, pour que la seule variation possible du metal soit la cargaison.
     *
     * @return array{0: CombatInstance, 1: FleetMission, 2: int, 3: int}
     */
    private function anOpenRallyAndAnArrivingTransport(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }
        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        $ouvreuse = $this->lastMissionDispatched();
        $ouverture = (int)$ouvreuse->time_arrival;

        $coordonnees = $cible->getPlanetCoordinates();
        $vague = new UnitCollection();
        $vague->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);
        $this->dispatchFleet(new Coordinate($coordonnees->galaxy, $coordonnees->system, $coordonnees->position), $vague, new Resources(0, 0, 0, 0), PlanetType::Planet);
        $seconde = $this->lastMissionDispatched();
        $this->assertNotSame($ouvreuse->id, $seconde->id, 'The second wave was not dispatched.');
        DB::table('fleet_missions')->where('id', $seconde->id)->update(['time_arrival' => $ouverture + 18]);

        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => self::STOCK_METAL,
            'crystal' => 50_000,
            'deuterium' => 10_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        $origine = $this->planetService;
        $transport = FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $origine->getPlanetId(),
            'type_from' => 1,
            'galaxy_from' => $origine->getPlanetCoordinates()->galaxy,
            'system_from' => $origine->getPlanetCoordinates()->system,
            'position_from' => $origine->getPlanetCoordinates()->position,
            'planet_id_to' => $cible->getPlanetId(),
            'type_to' => 1,
            'galaxy_to' => $coordonnees->galaxy,
            'system_to' => $coordonnees->system,
            'position_to' => $coordonnees->position,
            'mission_type' => 3,
            'time_departure' => $ouverture - 100,
            'time_arrival' => $ouverture + 10,
            'small_cargo' => 5,
            'metal' => self::CARGAISON,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp($ouverture));
        $this->get('/overview')->assertStatus(200);

        $combat = CombatInstance::query()->where('mission_id', $ouvreuse->id)->first();
        $this->assertNotNull($combat, 'The arrival did not open a combat.');
        $this->assertSame(CombatState::Rallying, $combat->status, 'The rally closed at once: the second wave did not hold the window open.');

        // Le temps de la fermeture : la fenetre est passee, et le transport est arrive.
        $fermeture = $ouverture + 19;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        return [$combat, $transport, $cible->getPlanetId(), $fermeture];
    }

    private function lastMissionDispatched(): FleetMission
    {
        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($mission, 'No fleet mission was dispatched.');

        return $mission;
    }
}
