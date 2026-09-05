<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\OpensARallyWithAWindow;
use Tests\FleetDispatchTestCase;

/**
 * Un missile qui frappe pendant que la fermeture se joue : une seule frappe, une photographie juste.
 *
 * ## Ce que le bac prouve, et que SQLite ne peut pas
 *
 * La fermeture applique les effets admissibles par la porte unique, sous la barriere qu'elle tient ;
 * un travailleur qui traite la meme arrivee au meme instant attend ce verrou. Sous SQLite,
 * `lockForUpdate()` ne compile a rien : les deux appels s'entrelaceraient sans que rien ne le montre,
 * et un essai vert ne prouverait rien.
 *
 * Ici, deux processus reels : l'un ferme le ralliement, l'autre livre le missile. Quel que soit celui
 * qui passe en premier :
 *
 * - **le monde ne recoit qu'une frappe** — le registre refuse un second delta sous la meme identite,
 *   et `processed` refuse un second gestionnaire ;
 * - **la photographie porte la destruction** — appliquee par la fermeture, elle vient de la projection ;
 *   appliquee par le monde avant elle, elle vient des faits inscrits au registre. Les deux chemins
 *   donnent le meme effectif de depart.
 */
#[Group('mariadb')]
final class MissileVersusClosureRaceTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int GARRISON = 200;

    /** Un missile : 12 000 de puissance, 200 d'armure par lance-missiles sans technologie. */
    private const int DESTROYED = 60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
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

    public function testAMissileAndTheClosureRaceAndTheBodyTakesExactlyOneStrike(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $missile = $this->aPendingMissileTowards($cible, $ouverture - 50, $ouverture + 8);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $identifiant = (int)$combat->id;
        $issues = $this->inParallel(2, static function (int $rang) use ($identifiant, $missile, $fermeture): string {
            if ($rang === 0) {
                return (new RallyClosureService())->close($identifiant, $fermeture)->closed ? 'fermee' : 'deja fermee';
            }

            resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

            return 'livre';
        });
        sort($issues);
        $this->assertSame(['fermee', 'livre'], $issues, 'One of the two workers failed instead of finding the other had passed.');

        // **Une seule frappe dans le monde.**
        $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The missile was never applied.');
        $this->assertSame(
            self::GARRISON - self::DESTROYED,
            (int)DB::table('planets')->where('id', $cible)->value('rocket_launcher'),
            'The body took the salvo twice, or not at all.'
        );

        // **Une photographie juste, quel que soit l'ordre.**
        $combat->refresh();
        $this->assertNotNull($combat->battle_result, 'The rally closed without freezing a battle.');
        $this->assertSame(
            self::GARRISON - self::DESTROYED,
            BattleResultCodec::fromStorage($combat->battle_result)->defenderUnitsStart->getAmountByMachineName('rocket_launcher'),
            'The battle was fought against a garrison that ignored the salvo, or counted it twice.'
        );

        // Le registre ne porte qu'une ligne pour cet effet : deux mesures contradictoires leveraient.
        $this->assertSame(
            1,
            DB::table('combat_effect_ledger')->where('combat_instance_id', $combat->id)->where('event_identity', CombatEventIdentity::forFleetArrival((int)$missile->id))->count(),
            'The effect ledger holds no line, or more than one, for a salvo applied exactly once.'
        );
    }
}
