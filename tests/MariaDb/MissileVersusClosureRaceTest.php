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

        /*
         * **Aucune bataille heritee, et six classes soeurs du bac le faisaient deja.**
         *
         * Cet essai travaille sur la planete propre **partagee** par le processus, et la porte du
         * missile lit la barriere de ce corps par un simple `->first()`. Une barriere laissee par une
         * voisine ferait donc decider le missile contre un combat qui n est pas le sien. Le 9 septembre
         * 2026, l ajout de deux essais a change qui passe avant celui-ci, et il a rougi une fois.
         */
        DB::table('fleet_missions')->whereNotNull('combat_instance_id')->update(['combat_instance_id' => null]);

        foreach ([
            'patrol_combat_barriers',
            'combat_field_states',
            'combat_presentation_events',
            'combat_snapshot_inclusions',
            'combat_outbox',
            'combat_participants',
            'combat_effect_ledger',
            'combat_effect_receipts',
            'combat_loot_reservations',
            'celestial_body_combat_barriers',
            'combat_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
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

        /*
         * **La premisse, exigee et non esperee.** La porte du missile decide contre la barriere du
         * corps ; si elle ne designait pas ce combat-ci, le verdict porterait sur une autre bataille
         * et le rouge qui suivrait ne dirait pas pourquoi.
         */
        $this->assertSame(
            $identifiant,
            (int)DB::table('celestial_body_combat_barriers')->where('target_body_id', $cible)->value('combat_instance_id'),
            'The barrier of the target body does not name the combat being closed: the missile would be decided against another battle.'
        );

        $issues = $this->inParallel(2, static function (int $rang) use ($identifiant, $missile, $fermeture): string {
            if ($rang === 0) {
                return (new RallyClosureService())->close($identifiant, $fermeture)->reason;
            }

            resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

            return 'livre';
        });

        $this->assertContains('livre', $issues, 'The worker that delivers the missile did not run.');

        /*
         * **Trois denouements, et aucun n est un echec.**
         *
         * La fermeture passe, ou elle trouve le ralliement deja ferme, ou elle **se retire** parce que
         * l autre travailleur tenait l arrivee. Ce dernier cas etait, jusqu au 9 septembre 2026, une
         * exception qui accusait la barriere de ne pas avoir ete vue : la course a rougi une fois pour
         * cela. Un retrait n est pas un echec — il ne laisse rien derriere lui, et l avanceur repasse.
         */
        $fermetures = array_values(array_diff($issues, ['livre']));
        $this->assertCount(1, $fermetures);

        /*
         * **Le denouement se lit dans le journal de la CI.** Trois sont admis, et les compteurs d un
         * passage vert ne disent pas lequel a eu lieu : un entrelacement qui ne se produirait jamais
         * resterait invisible, et on croirait l avoir eprouve. Une ligne suffit a le savoir.
         */
        fwrite(STDERR, '[course missile/fermeture] denouement : ' . $fermetures[0] . PHP_EOL);
        $this->assertContains(
            $fermetures[0],
            ['fermee', 'deja fermee', 'arrivee tenue ailleurs'],
            'The closure ended in a way that is neither a success, nor a race already won, nor a clean withdrawal.'
        );

        /*
         * **Le retrait se rattrape, et c est cela qu il faut prouver.** Un passage suivant ferme le
         * ralliement : exactement une frappe, et une photographie juste — les memes exigences que
         * dans les deux autres denouements.
         */
        if ($fermetures[0] === 'arrivee tenue ailleurs') {
            $reprise = (new RallyClosureService())->close($identifiant, $fermeture + 120);

            $this->assertTrue($reprise->closed, 'The withdrawn closure never came back: ' . $reprise->reason);
        }

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
