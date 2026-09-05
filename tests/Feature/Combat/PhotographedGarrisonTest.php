<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\Models\CombatInstance;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * La bataille se joue contre l'effectif photographie, pas contre ce que le corps porte a la fermeture.
 *
 * ## Le defaut que cet essai ferme, et pourquoi il demandait une file mixte
 *
 * Une file de defenses achevee pendant le ralliement appartient au combat si elle a ete **engagee
 * avant l'ouverture** ; engagee apres, elle ne lui appartient pas, meme si elle s'acheve avant la
 * fermeture. Tant que la garnison etait relue vivante, la difference dependait de qui etait passe :
 * un joueur qui visitait sa page pendant le ralliement faisait entrer dans la bataille des defenses
 * decidees apres l'ouverture.
 *
 * Et l'appel du gestionnaire ne peut pas trancher a la place de la photographie :
 * `updateUnitQueue()` traite **toute la file echue** du corps. L'appeler pour l'achevement admissible
 * drainerait l'inadmissible avec lui. La photographie **compte** donc l'effet admissible sans que la
 * fermeture touche a la file ; le monde l'appliquera a la page suivante de son proprietaire.
 *
 * ## Ce que l'essai exige
 *
 * L'effectif de depart du defenseur, tel que le resultat gele le porte, contient les unites de la
 * file admissible et **pas** celles de l'inadmissible. Les deux files restent intactes en base :
 * la fermeture n'a applique ni l'une ni l'autre.
 */
final class PhotographedGarrisonTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int ADMISSIBLE = 7;

    private const int INADMISSIBLE = 11;

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

    public function testOnlyTheEligibleQueueEntersTheBattleAndNeitherIsApplied(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $depart = $this->garrisonOf($cible, 'light_laser');

        // Engagee **avant** l'ouverture, achevee dans la fenetre : elle appartient au combat.
        $admissible = $this->aUnitQueue($cible, 'light_laser', self::ADMISSIBLE, $ouverture - 100, $ouverture + 5);
        // Engagee **apres** l'ouverture : elle ne lui appartient pas, meme achevee avant la fermeture.
        $inadmissible = $this->aUnitQueue($cible, 'light_laser', self::INADMISSIBLE, $ouverture + 1, $ouverture + 6);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $this->assertSame(
            $depart + self::ADMISSIBLE,
            $this->defenderStartOf($combat, 'light_laser'),
            'The battle was fought against a garrison that either missed the eligible queue or counted the ineligible one.'
        );

        // **Ni l'une ni l'autre n'a ete appliquee** : la fermeture ne draine pas la file du corps.
        $this->assertSame(0, (int)DB::table('unit_queues')->where('id', $admissible)->value('processed'), 'The closure applied the eligible queue, and would have drained the other with it.');
        $this->assertSame(0, (int)DB::table('unit_queues')->where('id', $inadmissible)->value('processed'), 'The closure applied an ineligible queue.');
        $this->assertSame($depart, $this->garrisonOf($cible, 'light_laser'), 'The closure changed the body units it only had to photograph.');
    }

    /**
     * Le temoin inverse : sans photographie, la garnison vivante donnerait un autre effectif. Il
     * etablit que « juste » et « faux » ne coincident pas dans ce montage.
     */
    public function testTheLivingGarrisonWouldGiveADifferentBattle(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $depart = $this->garrisonOf($cible, 'light_laser');
        $this->aUnitQueue($cible, 'light_laser', self::ADMISSIBLE, $ouverture - 100, $ouverture + 5);

        // Le monde construit pendant le ralliement, sur une decision posterieure a l'ouverture.
        DB::table('planets')->where('id', $cible)->increment('light_laser', self::INADMISSIBLE);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        (new RallyClosureService())->close($combat->id, $fermeture);

        $this->assertSame($depart + self::ADMISSIBLE, $this->defenderStartOf($combat, 'light_laser'), 'Units the world added after the opening fought in this battle.');
        $this->assertNotSame($this->garrisonOf($cible, 'light_laser'), $this->defenderStartOf($combat, 'light_laser'), 'The living garrison and the photograph agree here: this test would pass without the photograph.');
    }

    private function garrisonOf(int $planetId, string $machineName): int
    {
        return (int)DB::table('planets')->where('id', $planetId)->value($machineName);
    }

    private function defenderStartOf(CombatInstance $combat, string $machineName): int
    {
        $combat->refresh();

        return BattleResultCodec::fromStorage($combat->battle_result)->defenderUnitsStart->getAmountByMachineName($machineName);
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
