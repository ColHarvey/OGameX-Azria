<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\OpensARallyWithAWindow;
use Tests\FleetDispatchTestCase;

/**
 * Deux travailleurs annulent le meme missile parti apres l'ouverture : un seul remboursement.
 *
 * ## Ce que le bac prouve, et que SQLite ne peut pas
 *
 * `MissileArrivalGate::cancelWithoutImpact()` relit la mission `for update` dans une transaction et
 * pose `processed` avant de crediter le silo. Sous SQLite, `lockForUpdate()` ne compile a rien : un
 * second appel sequentiel prouve l'idempotence, pas la course. Ici, deux processus reels appellent
 * `updateMission()` sur la meme mission au meme instant ; le second attend le verrou de ligne, relit
 * `processed = 1`, et ne rembourse pas.
 */
#[Group('mariadb')]
final class MissileRefundRaceTest extends FleetDispatchTestCase
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

    public function testTwoWorkersCancelTheSameMissileAndOnlyOneRefundsIt(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $missile = $this->aPendingMissileTowards($cible, $ouverture + 1, $ouverture + 8, missiles: 3);
        $silo = (int)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('interplanetary_missile');
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));

        $issues = $this->inParallel(2, static function (int $rang) use ($missile): string {
            resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

            return 'passe';
        });
        $this->assertSame(['passe', 'passe'], $issues, 'A worker failed instead of finding the missile already cancelled.');

        $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The anomalous missile was not cancelled.');
        $this->assertSame(
            $silo + 3,
            (int)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('interplanetary_missile'),
            'The missiles were refunded twice, or never: two workers cancelled the same mission.'
        );
        $this->assertSame(self::GARRISON, (int)DB::table('planets')->where('id', $cible)->value('rocket_launcher'), 'A cancelled missile struck the body.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count(), 'The launcher was told twice, or never.');
    }
}
