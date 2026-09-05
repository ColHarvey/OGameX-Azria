<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\HeldTargetCheck;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\OpensARallyWithAWindow;
use Tests\FleetDispatchTestCase;

/**
 * Un missile lance a l'instant meme ou un combat s'ouvre sur sa cible : jamais de frappe.
 *
 * ## La course, et ou la garantie se tient vraiment
 *
 * `HeldTargetCheck` lit la barriere **sans verrou** : entre son verdict et l'insertion de la mission,
 * une ouverture peut passer. Ce n'est pas une faiblesse a corriger la — un verrou au lancement
 * serialiserait toute la Galaxie sur un corps — mais un cas que la porte d'arrivee doit rattraper.
 *
 * L'invariant du systeme n'est donc pas « le controle est atomique », c'est : **un missile ne frappe
 * jamais un corps tenu par un ralliement s'il est parti apres l'ouverture**. Deux issues acceptables,
 * et deux seulement :
 *
 * - le lancement est refuse (le controle a vu la barriere) ;
 * - le lancement passe, et l'arrivee l'annule sans impact en rendant les missiles.
 *
 * Ce que le bac exclut : une troisieme issue ou les defenses tombent.
 */
#[Group('mariadb')]
final class MissileLaunchVersusOpeningRaceTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int GARRISON = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
        $this->planetAddUnit('interplanetary_missile', 5);
        $this->playerSetResearchLevel('impulse_drive', object_level: 10);
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

    public function testALaunchThatRacesTheOpeningNeverStrikesTheBody(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        // Le ralliement court : la barriere existe deja, et c'est le cas ou la course se joue — le
        // lanceur peut la voir ou non selon l'instant ou il lit.
        $coordonnees = DB::table('planets')->where('id', $cible)->first(['galaxy', 'system', 'planet']);
        $this->assertNotNull($coordonnees);
        $silo = (int)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('interplanetary_missile');
        $origine = $this->planetService->getPlanetId();
        $lanceur = $this->currentUserId;
        $depart = $this->planetService->getPlanetCoordinates();
        $arrivee = $ouverture + 8;

        $issues = $this->inParallel(2, static function (int $rang) use ($cible, $coordonnees, $origine, $lanceur, $depart, $arrivee): string {
            if ($rang === 0) {
                // Le lanceur : il consulte, puis tire s'il croit pouvoir.
                if (resolve(HeldTargetCheck::class)->isHeld($cible)) {
                    return 'refuse';
                }

                FleetMission::forceCreate([
                    'user_id' => $lanceur,
                    'planet_id_from' => $origine,
                    'type_from' => PlanetType::Planet->value,
                    'galaxy_from' => $depart->galaxy,
                    'system_from' => $depart->system,
                    'position_from' => $depart->position,
                    'planet_id_to' => $cible,
                    'type_to' => PlanetType::Planet->value,
                    'galaxy_to' => (int)$coordonnees->galaxy,
                    'system_to' => (int)$coordonnees->system,
                    'position_to' => (int)$coordonnees->planet,
                    'mission_type' => 10,
                    'time_departure' => (int)Date::now()->timestamp,
                    'time_arrival' => $arrivee,
                    'interplanetary_missile' => 2,
                    'target_priority' => 0,
                    'metal' => 0,
                    'crystal' => 0,
                    'deuterium' => 0,
                ]);
                DB::table('planets')->where('id', $origine)->decrement('interplanetary_missile', 2);

                return 'tire';
            }

            // Le monde : il consulte la meme barriere, comme la page de la Galaxie le ferait.
            return resolve(HeldTargetCheck::class)->isHeld($cible) ? 'tenue' : 'libre';
        });

        $this->assertContains($issues[0], ['refuse', 'tire'], 'The launcher reported something else than a refusal or a launch.');

        // Le monde avance jusqu'a l'arrivee : s'il y a un missile, il doit etre annule.
        $this->travelTo(Date::createFromTimestamp($arrivee));
        $missile = FleetMission::query()->where('user_id', $lanceur)->where('mission_type', 10)->orderByDesc('id')->first();

        if ($issues[0] === 'refuse') {
            $this->assertNull($missile, 'The launch was refused and a missile exists anyway.');
            $this->assertSame($silo, (int)DB::table('planets')->where('id', $origine)->value('interplanetary_missile'), 'A refused launch consumed missiles.');
        } else {
            $this->assertNotNull($missile, 'The launcher reported a launch and no missile exists.');
            resolve(FleetMissionService::class)->updateMission($missile);

            $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The anomalous missile was left pending.');
            $this->assertSame($silo, (int)DB::table('planets')->where('id', $origine)->value('interplanetary_missile'), 'The cancelled missiles were not returned to their silo.');
        }

        // **La seule chose qui compte, dans les deux cas** : le corps n'a rien perdu.
        $this->assertSame(
            self::GARRISON,
            (int)DB::table('planets')->where('id', $cible)->value('rocket_launcher'),
            'A missile launched against a body held by a rally destroyed defences.'
        );

        // Et la photographie ne connait pas ce missile : il est parti apres l'ouverture.
        $this->assertSame(0, DB::table('combat_effect_ledger')->where('combat_instance_id', $combat->id)->count(), 'The anomalous missile left a delta in the effect ledger.');
    }

    /**
     * Le temoin inverse : sans barriere, le meme lancement passe et frappe. Sans lui, un essai qui
     * refuserait tout lancement passerait aussi.
     */
    public function testWithoutARallyTheSameLaunchStrikes(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        // Le combat s'en va : plus de barriere, la cible est libre.
        DB::table('celestial_body_combat_barriers')->where('target_body_id', $cible)->delete();
        CombatInstance::query()->whereKey($combat->id)->delete();
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        $this->assertFalse(resolve(HeldTargetCheck::class)->isHeld($cible), 'The body is still held although its combat is gone.');

        $missile = $this->aPendingMissileTowards($cible, $ouverture + 1, $ouverture + 8);
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));
        resolve(FleetMissionService::class)->updateMission($missile);

        $this->assertLessThan(
            self::GARRISON,
            (int)DB::table('planets')->where('id', $cible)->value('rocket_launcher'),
            'A missile against a free body destroyed nothing: the previous test would pass whatever the gate does.'
        );
    }
}
