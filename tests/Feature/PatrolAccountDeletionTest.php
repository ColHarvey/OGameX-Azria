<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolState;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Ce que la suppression d un compte fait — et ne fait pas — a une patrouille.
 *
 * ## La regle, et d ou elle vient
 *
 * Revue 124 de Codex, point 1 : **aucun sauvetage gratuit**. Le document V5 proposait de rendre a sa
 * base, gratuitement, une patrouille immobilisee des la demande de suppression. Si la suppression
 * est ensuite annulable ou differee, le joueur sauverait sa flotte sans carburant puis reprendrait
 * son compte — ce qui contredit « pas de retour gratuit », arrete a la revue 121 (R3).
 *
 * Ces temoins epinglent le comportement reel du code, qui est le bon : **rien** ne touche a une
 * patrouille tant que la suppression n est pas effective, et l effacement ne rend rien. Ils existent
 * pour que la proposition du document ne soit pas implantee par megarde plus tard.
 *
 * ## Ce qu ils n eprouvent pas
 *
 * Le depot n a **pas** d annulation de suppression : la suppression est un etat qui reprend de
 * lui-meme quand plus rien ne la retient (decision de Keven, revue 38). Le scenario « demande puis
 * annulation » de Codex n a donc pas de chemin ici ; ce qui est eprouve est son equivalent reel —
 * une suppression **differee**, pendant laquelle le compte est encore utilisable.
 */
class PatrolAccountDeletionTest extends AccountTestCase
{
    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', 0);
        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    /**
     * Une patrouille immobilisee, a sec, posee a son point.
     */
    private function unePatrouilleASec(): Patrol
    {
        return Patrol::query()->create([
            'user_id' => $this->currentUserId,
            'home_planet_id' => $this->planetService->getPlanetId(),
            'state' => PatrolState::Immobilised->value,
            'galaxy' => 1,
            'system' => 1,
            'x' => 620,
            'y' => 480,
            'fuel_reserve' => 0.0,
            'order_version' => 1,
        ]);
    }

    /**
     * Ce qui fait differer la suppression : une flotte en vol qui pourrait encore ouvrir un combat.
     *
     * Elle est ecrite directement plutot qu envoyee : ce temoin juge le sort de la patrouille, pas
     * le lancement d une flotte, et un envoi reconstruirait le conteneur au passage.
     */
    private function uneFlotteQuiRetientLaSuppression(): FleetMission
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', 1);

        return FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 1,
        ]);
    }

    /**
     * **Une suppression differee ne sauve rien.** Le compte est encore utilisable ; la patrouille
     * reste exactement ou elle etait, aussi seche qu avant, et aucun retour n est parti.
     */
    public function testADeferredDeletionLeavesADryPatrolExactlyWhereItWas(): void
    {
        $patrouille = $this->unePatrouilleASec();
        $this->uneFlotteQuiRetientLaSuppression();

        $retours = FleetMission::query()->whereNotNull('parent_id')->count();

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->delete();

        $this->assertTrue(
            DB::table('users')->where('id', $this->currentUserId)->exists(),
            'The account was deleted although a fleet still retained it: the scenario proves nothing.'
        );
        $this->assertNotNull(
            DB::table('users')->where('id', $this->currentUserId)->value('deletion_pending_since'),
            'The deletion was not deferred: this witness needs the state where the account is still usable.'
        );

        $patrouille->refresh();

        $this->assertSame(PatrolState::Immobilised, $patrouille->state, 'The deletion moved a patrol it must not touch.');
        $this->assertSame(620, (int)$patrouille->x);
        $this->assertSame(480, (int)$patrouille->y);
        $this->assertSame(0.0, (float)$patrouille->fuel_reserve, 'The deletion refuelled a dry patrol.');

        $this->assertSame(
            $retours,
            FleetMission::query()->whereNotNull('parent_id')->count(),
            'The deletion sent a patrol home for free.'
        );
    }

    /**
     * **La suppression effective emporte la patrouille, et ne rend rien.** Aucune ligne orpheline,
     * aucun vol qui continue sans proprietaire.
     */
    public function testAnEffectiveDeletionTakesThePatrolWithItAndCreditsNothing(): void
    {
        $patrouille = $this->unePatrouilleASec();
        $compte = $this->currentUserId;

        $retours = FleetMission::query()->whereNotNull('parent_id')->count();

        resolve(PlayerServiceFactory::class)->make($compte, true)->delete();

        $this->assertFalse(
            DB::table('users')->where('id', $compte)->exists(),
            'Nothing retained this account and it survived its own deletion.'
        );
        $this->assertNull(
            Patrol::query()->find($patrouille->id),
            'The patrol outlived its owner: an orphan the game would keep charging for.'
        );
        $this->assertSame(
            0,
            FleetMission::query()->where('user_id', $compte)->count(),
            'A mission of the deleted account is still flying.'
        );
        $this->assertSame(
            $retours,
            FleetMission::query()->whereNotNull('parent_id')->count(),
            'The deletion created a return: nothing must come home for free.'
        );
    }
}
