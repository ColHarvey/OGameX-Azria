<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatResolutionService;
use OGame\Combat\Services\CombatSettlementOutcome;
use OGame\Combat\Services\CombatSettlementService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\EngagesAPersistentCombat;
use Tests\FleetDispatchTestCase;

/**
 * Deux reglements du meme combat, au meme instant, dans deux processus : la bataille s'applique
 * une fois — un debit, un rapport, un retour.
 *
 * ## Ce que SQLite ne pouvait pas dire
 *
 * Le reglement prend la barriere puis l'instance sous `lockForUpdate()`, et relit l'etat sous ce
 * verrou : c'est ce qui le rend idempotent quand deux travailleurs se presentent ensemble — un
 * passage planifie et une page, ou deux ticks qui se chevauchent. Sous SQLite, `lockForUpdate()`
 * ne compile a rien, et un seul ecrivain existe : la course n'y est pas jouee, elle est evitee.
 * Ici, deux processus reels se disputent la ligne.
 *
 * ## Ce que l'essai exige
 *
 * Les deux issues sont exactement « regle » et « deja regle » ; le stock de la cible baisse du
 * butin applique, une fois ; un rapport de plus, pas deux ; un retour pour la flotte, pas deux.
 * Un butin nul rendrait le double debit invisible : il est exige strictement positif.
 */
#[Group('mariadb')]
final class SettlementRaceTest extends FleetDispatchTestCase
{
    use EngagesAPersistentCombat;
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    protected function tearDown(): void
    {
        // Le montage leve l'interrupteur des combats durables ; la base survit a l'essai, et une
        // classe voisine qui ne le pose pas heriterait de ce qu'on a laisse.
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    public function testTwoSettlementsAtTheSameInstantApplyTheBattleOnce(): void
    {
        $combat = $this->anEngagedCombat();
        $cible = (int)$combat->target_planet_id;
        $missionId = (int)$combat->mission_id;
        $stockAvant = $this->stockOf($cible);
        $rapportsAvant = BattleReport::query()->count();
        $this->assertSame(0, FleetMission::query()->where('parent_id', $missionId)->count(), 'The fleet already has a return: the scenario would prove nothing.');
        $instant = (int)$combat->ends_at;

        $issues = $this->inParallel(2, static function () use ($combat, $instant): string {
            $mission = resolve(SettlingAttackMission::class);
            $service = new CombatSettlementService(resolve(CombatResolutionService::class));
            $issue = $service->settle(
                $combat->id,
                $mission,
                static function (FleetMission $retourDe, Resources $ressources, UnitCollection $unites, int $tempsSupplementaire = 0, array|null $epaves = null, int|null $dureeImposee = null) use ($mission): void {
                    $mission->returnFor($retourDe, $ressources, $unites, $tempsSupplementaire, $epaves, $dureeImposee);
                },
                $instant,
            );

            return $issue->settled ? CombatSettlementOutcome::REASON_SETTLED : $issue->reason;
        });

        sort($issues);
        $this->assertSame([CombatSettlementOutcome::REASON_ALREADY_SETTLED, CombatSettlementOutcome::REASON_SETTLED], $issues, 'Both processes settled the battle, or neither did.');

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status);
        $butin = (int)$combat->applied_loot_metal + (int)$combat->applied_loot_crystal + (int)$combat->applied_loot_deuterium;
        $this->assertGreaterThan(0, $butin, 'No loot was applied: a double debit would be invisible.');
        $this->assertSame($stockAvant - $butin, $this->stockOf($cible), 'The target was debited a different amount than the loot applied once.');
        $this->assertSame($rapportsAvant + 1, BattleReport::query()->count(), 'The battle was reported twice, or not at all.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $missionId)->count(), 'The fleet returned twice, or not at all.');
    }

    private function stockOf(int $planetId): int
    {
        $ligne = DB::table('planets')->where('id', $planetId)->first(['metal', 'crystal', 'deuterium']);
        $this->assertNotNull($ligne);

        return (int)$ligne->metal + (int)$ligne->crystal + (int)$ligne->deuterium;
    }
}
