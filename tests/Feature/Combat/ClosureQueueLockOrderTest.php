<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\Feature\ObservesLockingStatements;
use Tests\FleetDispatchTestCase;

/**
 * **La fermeture d'un ralliement tient le corps avant ses files — dès l'entrée de sa transaction.**
 *
 * La fermeture vide la file d'unités du corps par la progression normale. Elle verrouillait les lignes de file
 * (`CausalEventReader`) avant le corps, puis écrivait le corps en livrant : l'ordre inverse d'une page, qui prend le
 * corps puis ses lignes. L'ordre est désormais : recherches échues, corps, lignes d'unités et de bâtiments.
 *
 * Prendre le corps « avant la file dans une méthode » ne suffirait pas si une instruction antérieure de la même
 * transaction touchait déjà une ligne de file : cet essai observe **toute** la transaction de fermeture.
 *
 * Témoin de forme (`ObservesLockingStatements`) ; l'effet sous une vraie concurrence appartient au bac MariaDB.
 */
final class ClosureQueueLockOrderTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use ObservesLockingStatements;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

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

    public function testTheClosureHoldsTheBodyBeforeTouchingAnyOfItsQueues(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();

        // Douze unités, une toutes les cinq secondes, de −10 à +50 : la fermeture (+19) en a cinq à livrer.
        $this->aUnitQueue($cible, 'rocket_launcher', 12, $ouverture - 10, $ouverture + 50);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);

        $avant = (int)DB::table('planets')->where('id', $cible)->value('rocket_launcher');
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $instructions = $this->statementsOf(fn () => $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.'));

        $this->assertSame($avant + 5, (int)DB::table('planets')->where('id', $cible)->value('rocket_launcher'), 'Prémisse : la fermeture a vidé la file du corps.');

        $corps = null;
        $premiereFile = null;
        $recherches = null;

        foreach ($instructions as $rang => $instruction) {
            if ($corps === null && $instruction['lock'] && $instruction['table'] === 'planets' && in_array($cible, $instruction['bindings'], false)) {
                $corps = $rang;
            }

            if ($premiereFile === null && in_array($instruction['table'], ['unit_queues', 'building_queues'], true)) {
                $premiereFile = $rang;
            }

            if ($recherches === null && $instruction['lock'] && $instruction['table'] === 'research_queues') {
                $recherches = $rang;
            }
        }

        $this->assertNotNull($corps, 'La fermeture n’a jamais tenu le corps visé.');
        $this->assertNotNull($premiereFile, 'Prémisse : la fermeture a touché la file du corps.');
        $this->assertLessThan($premiereFile, $corps, 'Une instruction de la fermeture a touché une file avant que le corps soit tenu.');
        $this->assertNotNull($recherches, 'La fermeture n’a pas tenu les recherches échues.');
        $this->assertLessThan($corps, $recherches, 'La fermeture tient le corps avant les recherches : la progression d’un compte prend ses recherches puis débite le corps.');
    }

    private function aUnitQueue(int $planetId, string $machineName, int $amount, int $start, int $end): int
    {
        return (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $planetId,
            'object_id' => ObjectService::getUnitObjectByMachineName($machineName)->id,
            'object_amount' => $amount,
            'time_duration' => max(1, $end - $start),
            'time_start' => $start,
            'time_end' => $end,
            'time_progress' => 0,
            'object_amount_progress' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
        ]);
    }
}
