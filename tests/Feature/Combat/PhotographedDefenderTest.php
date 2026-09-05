<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\CombatInstance;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Les technologies de combat du defenseur sont celles de la photographie, pas celles du joueur vivant.
 *
 * ## La regle, et le defaut qu'elle ferme
 *
 * Une recherche appartient au combat si elle a ete **engagee avant l'ouverture** et **achevee avant la
 * fermeture**. Engagee apres l'ouverture, elle ne lui appartient pas : un defenseur ne renforce pas une
 * defense deja engagee en lancant une recherche pendant le ralliement. Tant que le moteur lisait le
 * joueur vivant, il suffisait qu'une page soit visitee pour que le niveau monte dans la bataille.
 *
 * Comme pour les files d'unites, l'effet admissible est **compte** sans etre applique : le gestionnaire
 * de la file de recherche traite tout ce qui est echu, et l'appeler drainerait l'inadmissible.
 */
final class PhotographedDefenderTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int NIVEAU_OUVERTURE = 3;

    private const int NIVEAU_ADMISSIBLE = 4;

    private const int NIVEAU_INADMISSIBLE = 9;

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

    public function testAnEligibleResearchRaisesTheFrozenLevelAndAnIneligibleOneDoesNot(): void
    {
        [$combat, $cible, $ouverture, $proprietaire] = $this->anOpenRallyAgainstAResearcher();

        // Engagee avant l'ouverture, achevee dans la fenetre : elle appartient au combat.
        $admissible = $this->aResearchQueue($cible, 'weapon_technology', self::NIVEAU_ADMISSIBLE, $ouverture - 100, $ouverture + 5);
        // Engagee apres l'ouverture : elle ne lui appartient pas, meme achevee avant la fermeture.
        $inadmissible = $this->aResearchQueue($cible, 'shielding_technology', self::NIVEAU_INADMISSIBLE, $ouverture + 1, $ouverture + 6);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $bonus = $this->classBonusOf($proprietaire);
        $this->assertSame(self::NIVEAU_ADMISSIBLE + $bonus, $this->frozenLevel($combat, 'weapon'), 'The eligible research did not raise the level the battle used.');
        $this->assertSame(self::NIVEAU_OUVERTURE + $bonus, $this->frozenLevel($combat, 'shield'), 'A research engaged after the opening strengthened a defence already engaged.');

        // Ni l'une ni l'autre n'a ete appliquee : la fermeture ne draine pas la file de recherche.
        $this->assertSame(0, (int)DB::table('research_queues')->where('id', $admissible)->value('processed'));
        $this->assertSame(0, (int)DB::table('research_queues')->where('id', $inadmissible)->value('processed'));
    }

    /**
     * Le temoin inverse : le joueur vivant donnerait un autre niveau. Sans lui, l'essai passerait
     * meme si le moteur lisait encore le joueur.
     */
    public function testTheLivingPlayerWouldGiveADifferentLevel(): void
    {
        [$combat, $cible, $ouverture, $proprietaire] = $this->anOpenRallyAgainstAResearcher();

        // Le monde termine la recherche pendant le ralliement, sur une decision posterieure.
        resolve(PlayerServiceFactory::class)->make($proprietaire, true)->setResearchLevel('weapon_technology', self::NIVEAU_INADMISSIBLE);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        (new RallyClosureService())->close($combat->id, $fermeture);

        $bonus = $this->classBonusOf($proprietaire);
        $this->assertSame(self::NIVEAU_OUVERTURE + $bonus, $this->frozenLevel($combat, 'weapon'), 'The battle used the level the world reached during the rally.');
        $this->assertNotSame(self::NIVEAU_INADMISSIBLE + $bonus, $this->frozenLevel($combat, 'weapon'), 'The living player and the photograph agree here: this test would pass without the photograph.');
    }

    /**
     * @return array{0: CombatInstance, 1: int, 2: int, 3: int}
     */
    private function anOpenRallyAgainstAResearcher(): array
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $proprietaire = (int)DB::table('planets')->where('id', $cible)->value('user_id');
        $joueur = resolve(PlayerServiceFactory::class)->make($proprietaire, true);
        foreach (['weapon_technology', 'shielding_technology'] as $technologie) {
            $joueur->setResearchLevel($technologie, self::NIVEAU_OUVERTURE);
        }

        // L'etat d'ouverture doit refleter ces niveaux : il est capture a l'ouverture, donc on le
        // recapture ici, comme si le combat venait de s'ouvrir sur ce joueur.
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);

        return [$combat, $cible, $ouverture, $proprietaire];
    }

    private function classBonusOf(int $playerId): int
    {
        return resolve(CharacterClassService::class)
            ->getAdditionalCombatResearchLevels(resolve(PlayerServiceFactory::class)->make($playerId, true)->getUser());
    }

    private function frozenLevel(CombatInstance $combat, string $which): int
    {
        $combat->refresh();
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        return $which === 'weapon' ? $resultat->defenderWeaponLevel : $resultat->defenderShieldLevel;
    }

    private function aResearchQueue(int $planetId, string $machineName, int $level, int $start, int $end): int
    {
        return (int)DB::table('research_queues')->insertGetId([
            'planet_id' => $planetId,
            'object_id' => ObjectService::getResearchObjectByMachineName($machineName)->id,
            'object_level_target' => $level,
            'time_duration' => max(1, $end - $start),
            'time_start' => $start,
            'time_end' => $end,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'building' => 0,
            'processed' => 0,
        ]);
    }
}
