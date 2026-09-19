<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Models\CombatInstance;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use ReflectionMethod;
use Tests\FleetDispatchTestCase;

/**
 * Le missile n'est pas lineaire, et la photographie ne se deduit pas du monde : elle se **projette**.
 *
 * ## Le scenario que la revue 90 exige
 *
 * Des defenses admissibles et inadmissibles, des antimissiles admissibles et inadmissibles, une
 * priorite de cible, et la meme salve appliquee par le monde avant la fermeture ou par la fermeture
 * elle-meme. La photographie doit etre **identique** dans les deux ordres, et le monde ne recevoir
 * qu'un impact.
 *
 * ## Les nombres
 *
 * Ouverture : 200 lance-missiles, 50 lasers legers, aucun antimissile. Admissibles : +40 lance-missiles,
 * +1 antimissile. Inadmissibles : +25 lance-missiles, +2 antimissiles. Salve de 4 missiles, priorite
 * « laser leger ».
 *
 * - **Monde** : les files sont drainees, 3 antimissiles interceptent 3 missiles, 1 frappe (12 000) :
 *   les 50 lasers (10 000), puis 10 lance-missiles. Reste 255 lance-missiles, 0 laser.
 * - **Photographie** : 1 antimissile admissible intercepte 1 missile, 3 frappent (36 000) : les 50
 *   lasers, puis 130 lance-missiles sur 240. Reste **110** lance-missiles, 0 laser.
 *
 * Un delta mesure sur le monde donnerait 255 − 265 = −10 lance-missiles ; retranche de la
 * photographie, il laisserait 230. Un plafond a zero n'y changerait rien. Seule la projection donne 110.
 */
final class MissileProjectionOnThePhotographTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int ROCKET_LAUNCHERS = 200;

    private const int LIGHT_LASERS = 50;

    private const int PHOTO_ROCKET_LAUNCHERS_AFTER = 110;

    private const int WORLD_ROCKET_LAUNCHERS_AFTER = 255;

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

    public function testTheWorldAppliesTheSalvoBeforeTheClosure(): void
    {
        [$combat, $cible, $ouverture] = $this->aRallyUnderAMixedSalvo();

        $this->travelTo(Date::createFromTimestamp($ouverture + 8));
        $this->get('/overview')->assertStatus(200);
        $this->assertWorldStruckOnce($cible);

        $this->closeAt($combat, $ouverture);
        $this->assertPhotographProjected($combat);
        $this->assertWorldStruckOnce($cible);
    }

    public function testTheClosureAppliesTheSalvo(): void
    {
        [$combat, $cible, $ouverture] = $this->aRallyUnderAMixedSalvo();

        $this->closeAt($combat, $ouverture);
        $this->assertPhotographProjected($combat);
        $this->assertWorldStruckOnce($cible);
    }

    /**
     * Le temoin inverse : le delta du monde ne donne pas la photographie. Sans lui, une projection
     * fausse qui coinciderait avec le delta passerait.
     */
    public function testTheWorldDeltaWouldGiveADifferentPhotograph(): void
    {
        [$combat, $cible, $ouverture] = $this->aRallyUnderAMixedSalvo();
        $this->closeAt($combat, $ouverture);

        $deltaMonde = self::WORLD_ROCKET_LAUNCHERS_AFTER - (self::ROCKET_LAUNCHERS + 40 + 25);
        $this->assertNotSame(
            max(0, self::ROCKET_LAUNCHERS + 40 + $deltaMonde),
            $this->defenderStartOf($combat, 'rocket_launcher'),
            'The projection and the world delta agree here: this test would pass without the projection.'
        );
    }

    /**
     * **La projection lit le bonus de formes de vie des defenses gele a l ouverture** (journal §164) : le
     * Renforcement des boucliers d obsidienne compte contre les missiles comme en bataille. Le document d ouverture
     * porte ici 10 % sur les defenses (valeur que le photographe ecrit pour un Rock tal, posee a la main sur le
     * document : c est le LECTEUR que ce temoin eprouve) : chaque defense de 2 000 d integrite vaut 220 au lieu de 200.
     * Trois missiles (36 000) : les 50 lasers (11 000), puis floor(25 000 / 220) = 113 lance-missiles sur 240 — reste
     * 127, et non 110.
     */
    public function testTheProjectionReadsTheDefenceBonusFrozenAtTheOpening(): void
    {
        [$combat, , $ouverture] = $this->aRallyUnderAMixedSalvo();
        $etat = $combat->opening_state;
        $this->assertIsArray($etat);
        $this->assertSame([], $etat['defender']['lifeform_bonuses']['unit_stats'] ?? null, 'Premisse : la cible n a aucun bonus de formes de vie a l ouverture.');
        $etat['defender']['lifeform_bonuses']['unit_stats'] = [FrozenLifeformCombatBonuses::DEFENCE => 10.0];
        // Le document est scelle par son empreinte : le banc le rescelle comme le recorder le ferait (OpeningLifeformLossRateTest).
        $combat->opening_state = $etat;
        $combat->opening_state_fingerprint = (new ReflectionMethod(OpeningStateRecorder::class, 'fingerprintOf'))->invoke(null, $etat);
        $combat->save();

        $this->closeAt($combat, $ouverture);
        $this->assertSame(127, $this->defenderStartOf($combat, 'rocket_launcher'), 'La projection ignore le bonus des defenses gele a l ouverture.');
        $this->assertSame(0, $this->defenderStartOf($combat, 'light_laser'));
    }

    /**
     * @return array{0: CombatInstance, 1: int, 2: int}
     */
    private function aRallyUnderAMixedSalvo(): array
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::ROCKET_LAUNCHERS, ['light_laser' => self::LIGHT_LASERS]);

        $this->aUnitQueue($cible, 'rocket_launcher', 40, $ouverture - 100, $ouverture + 5);
        $this->aUnitQueue($cible, 'anti_ballistic_missile', 1, $ouverture - 100, $ouverture + 4);
        $this->aUnitQueue($cible, 'rocket_launcher', 25, $ouverture + 1, $ouverture + 6);
        $this->aUnitQueue($cible, 'anti_ballistic_missile', 2, $ouverture + 1, $ouverture + 7);
        $this->aPendingMissileTowards($cible, $ouverture - 50, $ouverture + 8, missiles: 4, priority: 3);

        return [$combat, $cible, $ouverture];
    }

    private function closeAt(CombatInstance $combat, int $ouverture): void
    {
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');
    }

    private function assertPhotographProjected(CombatInstance $combat): void
    {
        $this->assertSame(self::PHOTO_ROCKET_LAUNCHERS_AFTER, $this->defenderStartOf($combat, 'rocket_launcher'), 'The photograph is not the salvo projected on the admissible defences and interceptors.');
        $this->assertSame(0, $this->defenderStartOf($combat, 'light_laser'), 'The priority target survived in the photograph.');
    }

    private function assertWorldStruckOnce(int $cible): void
    {
        $this->assertSame(self::WORLD_ROCKET_LAUNCHERS_AFTER, $this->garrisonOf($cible, 'rocket_launcher'), 'The world did not receive exactly one impact of the real salvo.');
        $this->assertSame(0, $this->garrisonOf($cible, 'light_laser'), 'The priority target survived in the world.');
        $this->assertSame(0, $this->garrisonOf($cible, 'anti_ballistic_missile'), 'The interceptors the world used were not consumed.');
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
