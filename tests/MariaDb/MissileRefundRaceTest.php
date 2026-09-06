<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\MissileRefundClaims;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\OpensARallyWithAWindow;
use Tests\FleetDispatchTestCase;

/**
 * Les deux courses du remboursement des missiles : l'annulation, puis le reglement de la creance.
 *
 * ## Ce que le bac prouve, et que SQLite ne peut pas
 *
 * Les deux chemins tiennent par un verrou de ligne dans une transaction, et sous SQLite
 * `lockForUpdate()` ne compile a rien : un second appel sequentiel prouve l'idempotence, jamais la
 * course. Ici, deux processus reels se disputent la meme ligne.
 *
 * - **L'annulation** : `MissileArrivalGate::cancelWithoutImpact()` relit la mission `for update` et
 *   pose `processed` avant de crediter. Le second attend, relit `processed = 1`, ne rembourse pas.
 * - **Le reglement differe** : `MissileRefundClaims::settleOne()` verrouille la creance, credite le
 *   silo, puis acquitte — le tout dans une transaction. Le second attend, lit la creance acquittee,
 *   et ne credite rien. Avant la revue 103 l'acquittement precedait le credit hors transaction : une
 *   panne entre les deux fermait la creance sans rendre les missiles.
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

    /**
     * Deux rembourseurs reglent la meme creance : un credit, un acquittement.
     *
     * La commande de remboursement differe peut tourner deux fois — un passage planifie et une main
     * humaine, ou deux conteneurs. Chacun lit les creances dues puis les regle une par une. Ce que la
     * course doit etablir : le second attend le verrou de la creance, la relit acquittee, et
     * n'ajoute pas une seconde fois les missiles au silo.
     */
    public function testTwoSettlersShareTheSameClaimAndOnlyOneCreditsIt(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $origine = $this->planetService->getPlanetId();
        $missile = $this->aPendingMissileTowards($cible, $ouverture + 1, $ouverture + 8, missiles: 2);

        // Le lanceur n'a plus aucun corps a l'annulation : la restitution immediate ne trouve rien,
        // et ce qui est du devient une creance. La colonne etant NOT NULL, ses corps changent de main.
        $autre = (int)DB::table('planets')->where('id', $cible)->value('user_id');
        DB::table('fleet_missions')->where('id', $missile->id)->update(['planet_id_from' => null]);
        DB::table('planets')->where('user_id', $this->currentUserId)->update(['user_id' => $autre]);
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));

        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

        $creance = DB::table('combat_missile_refunds')->where('fleet_mission_id', $missile->id)->first();
        $this->assertNotNull($creance, 'No claim was written: the race would have nothing to settle.');

        // Le combat se termine et le lanceur retrouve son corps : la creance devient reglable.
        DB::table('celestial_body_combat_barriers')->where('target_body_id', $cible)->delete();
        DB::table('planets')->where('id', $origine)->update(['user_id' => $this->currentUserId]);
        DB::table('fleet_missions')->where('id', $missile->id)->update(['planet_id_from' => $origine]);
        $this->travelTo(Date::createFromTimestamp($ouverture + 600));

        $silo = (int)DB::table('planets')->where('id', $origine)->value('interplanetary_missile');

        $issues = $this->inParallel(2, static function (int $rang): string {
            $issue = resolve(MissileRefundClaims::class)->settlePending((int)Date::now()->timestamp);

            return 'rendues=' . $issue['credited'];
        });

        // **Un seul des deux a rendu.** L'ordre entre eux n'est pas impose : c'est le total qui est
        // la regle, et le dire ainsi evite d'epingler un vainqueur qui n'en est pas un.
        sort($issues);
        $this->assertSame(['rendues=0', 'rendues=1'], $issues, 'Both settlers credited the claim, or neither did.');

        $this->assertSame(
            $silo + 2,
            (int)DB::table('planets')->where('id', $origine)->value('interplanetary_missile'),
            'The owed missiles were credited twice, or never.'
        );

        $reglee = DB::table('combat_missile_refunds')->where('fleet_mission_id', $missile->id)->first();
        $this->assertNotNull($reglee, 'The claim disappeared instead of being settled.');
        $this->assertNotNull($reglee->credited_at, 'The claim stayed due although the missiles were returned.');
        $this->assertSame($origine, (int)$reglee->credited_body_id, 'The claim names a body that did not receive the missiles.');
    }
}
