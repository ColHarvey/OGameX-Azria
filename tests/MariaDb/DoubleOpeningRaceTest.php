<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux attaquantes arrivent sur le meme corps a la meme seconde : un seul combat gouverne.
 *
 * ## Ce que la base garantit, et ce que le code doit en faire
 *
 * `celestial_body_combat_barriers.target_body_id` est unique : deux barrieres sur un meme corps ne
 * peuvent pas exister. Mais l'unicite ne suffit pas — encore faut-il que le perdant **rejoigne** le
 * combat du gagnant au lieu de tomber, sinon une arrivee legitime echouerait parce qu'une autre est
 * arrivee dans la meme seconde.
 *
 * `CombatOpeningService::openOrJoin()` lit, tente, et sur violation d'unicite relit : si une barriere
 * existe alors, elle appartient a l'autre, et c'est ce combat-la qui est rendu. Sous SQLite, cette
 * course ne se joue pas — un seul ecrivain a la fois. Ici, deux processus l'ouvrent ensemble.
 *
 * ## Ce que l'essai exige
 *
 * Les deux processus rendent **le meme** identifiant de combat ; il n'existe qu'une instance sur ce
 * corps, qu'une barriere, et l'etat protege de l'ouverture a bien ete capture — un combat qui
 * gouverne sans photographie ne pourrait pas se fermer.
 */
#[Group('mariadb')]
final class DoubleOpeningRaceTest extends TestCase
{
    use RunsInParallelProcesses;

    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    public function testTwoArrivalsOnTheSameBodyAtTheSameSecondOpenExactlyOneCombat(): void
    {
        $corps = $this->aBodyOf(User::factory()->create());
        $premiere = $this->anAttackTowards($corps, 1_700_000_600);
        $seconde = $this->anAttackTowards($corps, 1_700_000_600);
        $this->assertSame(0, CelestialBodyCombatBarrier::query()->where('target_body_id', $corps->id)->count(), 'The body already holds a barrier: the scenario would prove nothing.');

        $issues = $this->inParallel(2, static function (int $rang) use ($premiere, $seconde, $corps): string {
            $mission = FleetMission::query()->findOrFail($rang === 0 ? $premiere->id : $seconde->id);

            return (string)(new CombatOpeningService())->openOrJoin($mission, $corps->id, (int)$mission->time_arrival)->id;
        });

        $this->assertSame($issues[0], $issues[1], 'The two arrivals opened two different combats on the same body.');
        $this->assertSame(1, CombatInstance::query()->where('target_planet_id', $corps->id)->count(), 'More than one combat governs this body.');
        $this->assertSame(1, CelestialBodyCombatBarrier::query()->where('target_body_id', $corps->id)->count(), 'More than one barrier holds this body.');

        $combat = CombatInstance::query()->findOrFail((int)$issues[0]);
        $this->assertSame(CombatState::Rallying, $combat->status);
        $this->assertNotNull($combat->opening_state, 'The governing combat has no opening state: it could never close.');
        $this->assertSame((int)$combat->opening_captured_at, (int)$combat->started_at, 'The opening state was not captured at the opening.');
    }

    private function anAttackTowards(Planet $corps, int $arrival): FleetMission
    {
        $joueur = User::factory()->create();
        $origine = $this->aBodyOf($joueur);

        return FleetMission::forceCreate([
            'user_id' => $joueur->id,
            'planet_id_from' => $origine->id,
            'type_from' => 1,
            'galaxy_from' => $origine->galaxy,
            'system_from' => $origine->system,
            'position_from' => $origine->planet,
            'planet_id_to' => $corps->id,
            'type_to' => 1,
            'galaxy_to' => $corps->galaxy,
            'system_to' => $corps->system,
            'position_to' => $corps->planet,
            'mission_type' => 1,
            'time_departure' => $arrival - 600,
            'time_arrival' => $arrival,
            'light_fighter' => 20,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    private function aBodyOf(User $owner): Planet
    {
        $this->bodies++;
        $coordonnees = $this->getSafeEmptyCoordinate(new Coordinate(7, random_int(20, 480), 1));

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'planet' => $coordonnees->position,
        ]);
    }
}
