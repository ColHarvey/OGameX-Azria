<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Presentation\CombatPresentationTimelineReader;
use OGame\Models\CombatInstance;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Le panneau des combats et le fil des pertes, tels que le navigateur les recoit.
 *
 * ## Ce que ces essais prouvent
 *
 * Que la vue generale montre un combat a ceux qui y sont partie, et a eux seuls ; que les pertes
 * apparaissent quand leur instant est passe, jamais avant ; que l'interface JSON ne rend que le
 * passe, sans numero de round, a l'heure du serveur et pour le joueur de la session — un
 * parametre `now` ou `player` glisse dans l'adresse n'y change rien ; qu'un tiers recoit 403, un
 * combat inconnu 404, un visiteur la page de connexion.
 */
class CombatPanelTest extends FleetDispatchTestCase
{
    use EngagesAPersistentCombat;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();

        CombatPresentationEvent::query()->delete();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
    }

    public function testTheAttackerSeesTheCombatOnTheOverview(): void
    {
        $combat = $this->anEngagedCombat();

        $reponse = $this->get('/overview');

        $reponse->assertStatus(200);
        $reponse->assertSee(__('t_ingame.combat.panel_title'));
        $reponse->assertSee(__('t_ingame.combat.role_attacker'));
        $reponse->assertSee('[' . $combat->galaxy . ':' . $combat->system . ':' . $combat->position . ']');
        $reponse->assertSee(__('t_ingame.combat.status_active'));
    }

    /**
     * Le proprietaire de la cible voit ses pertes de garnison — a la fin de leur periode, pas avant.
     */
    public function testTheTargetOwnerSeesItsGarrisonLossesOnlyOnceTheyAreVisible(): void
    {
        $combat = $this->anEngagedCombat();
        $proprietaire = $this->ownerOf($combat);
        $debut = (int)$combat->started_at;
        $premierePeriode = $this->secondsPerRoundOf($combat)[0];

        $this->actingAs($proprietaire);

        // **A la cloture, rien n'est encore visible.** Le montage ne le suppose pas : il le mesure.
        $this->travelTo(Date::createFromTimestamp($debut));
        $lecteur = new CombatPresentationTimelineReader();
        $this->assertSame([], $lecteur->visibleTo($combat, $proprietaire->id, $debut), 'Losses are already visible at the closure: the scenario would prove nothing.');

        $avant = $this->get('/overview');
        $avant->assertStatus(200);
        $avant->assertSee(__('t_ingame.combat.role_target'));
        $avant->assertSee(__('t_ingame.combat.no_losses_yet'));

        // **A la fin de la premiere periode, la garnison a perdu quelque chose.**
        $fin = $debut + $premierePeriode;
        $this->travelTo(Date::createFromTimestamp($fin));
        $visibles = $lecteur->visibleTo($combat, $proprietaire->id, $fin);
        $this->assertNotSame([], $visibles, 'The garrison lost nothing in the first period: the scenario would prove nothing.');

        $apres = $this->get('/overview');
        $apres->assertStatus(200);
        $apres->assertDontSee(__('t_ingame.combat.no_losses_yet'));
        $apres->assertSee(ObjectService::getUnitObjectByMachineName($visibles[0]->unit)->title);
    }

    /**
     * Le fil JSON ne rend que le passe, sans round, a l'heure du serveur, pour le joueur de la session.
     */
    public function testTheTimelineServesOnlyThePastAtTheServerClockForTheSessionPlayer(): void
    {
        $combat = $this->anEngagedCombat();
        $proprietaire = $this->ownerOf($combat);
        $debut = (int)$combat->started_at;
        $fin = $debut + $this->secondsPerRoundOf($combat)[0];
        $echeance = (int)$combat->ends_at;

        $this->actingAs($proprietaire);
        $this->travelTo(Date::createFromTimestamp($fin));

        // **Precondition : il reste du futur.** Sans elle, « rien du futur » ne prouverait rien.
        $lecteur = new CombatPresentationTimelineReader();
        $tout = $lecteur->visibleTo($combat, $proprietaire->id, $echeance);
        $maintenant = $lecteur->visibleTo($combat, $proprietaire->id, $fin);
        $this->assertGreaterThan(count($maintenant), count($tout), 'Everything is already visible at the end of the first period: the future could not be withheld.');
        $this->assertNotSame([], $maintenant);

        $reponse = $this->getJson('/ajax/combat/' . $combat->id . '/timeline');
        $reponse->assertStatus(200);
        $corps = $reponse->json();

        $this->assertSame($fin, $corps['server_now'], 'The server clock is not the one the response carries.');
        $this->assertSame('active', $corps['status']);
        $this->assertSame($echeance - $fin, $corps['seconds_remaining'], 'The remaining seconds are not counted from the server clock.');
        $this->assertCount(count($maintenant), $corps['events']);

        foreach ($corps['events'] as $evenement) {
            $this->assertLessThanOrEqual($fin, $evenement['at'], 'A loss from the future was sent to the browser.');
            $this->assertArrayNotHasKey('round', $evenement, 'A round number leaked into the feed.');
            $this->assertSame(['sequence', 'at', 'side', 'unit', 'unit_label', 'amount'], array_keys($evenement));
        }

        $this->assertSame((int)$corps['next_after'], (int)end($corps['events'])['sequence']);

        // **Reprendre apres le dernier rang ne rend rien de plus**, et garde le rang.
        $suite = $this->getJson('/ajax/combat/' . $combat->id . '/timeline?after=' . $corps['next_after'])->json();
        $this->assertSame([], $suite['events']);
        $this->assertSame($corps['next_after'], $suite['next_after']);

        // **Ni l'heure ni le joueur ne se choisissent depuis le navigateur.** Un futur lointain et
        // l'identifiant de l'attaquant glisses dans l'adresse ne changent rien a la reponse.
        $attaquant = (int)DB::table('fleet_missions')->where('id', $combat->mission_id)->value('user_id');
        $manipulee = $this->getJson('/ajax/combat/' . $combat->id . '/timeline?now=' . ($echeance + 3600) . '&player=' . $attaquant . '&playerId=' . $attaquant)->json();
        $this->assertSame($corps['server_now'], $manipulee['server_now'], 'A browser parameter moved the server clock.');
        $this->assertSame($corps['events'], $manipulee['events'], 'A browser parameter changed whose losses, or which instant, the feed shows.');
    }

    public function testAThirdPartyIsRefusedAndAnUnknownCombatIsNotFound(): void
    {
        $combat = $this->anEngagedCombat();

        // Un joueur qui n'est ni attaquant, ni proprietaire, ni renfort.
        $this->createAndLoginUser();

        $this->getJson('/ajax/combat/' . $combat->id . '/timeline')->assertStatus(403);
        $this->getJson('/ajax/combat/999999999/timeline')->assertStatus(404);

        $vue = $this->get('/overview');
        $vue->assertStatus(200);
        $vue->assertDontSee(__('t_ingame.combat.panel_title'));

        $fragment = $this->get('/ajax/combat/panel');
        $fragment->assertStatus(200);
        $fragment->assertDontSee(__('t_ingame.combat.panel_title'));
    }

    public function testTheFragmentRendersTheSamePanelAsTheOverview(): void
    {
        $combat = $this->anEngagedCombat();

        $fragment = $this->get('/ajax/combat/panel');

        $fragment->assertStatus(200);
        $fragment->assertSee(__('t_ingame.combat.panel_title'));
        $fragment->assertSee('data-combat-id="' . $combat->id . '"', false);
        $fragment->assertSee(route('combat.timeline', ['combatId' => $combat->id]), false);
    }

    public function testAVisitorIsSentToTheLoginPage(): void
    {
        $this->post('/logout');

        $this->get('/ajax/combat/panel')->assertRedirect('/login');
        $this->get('/ajax/combat/1/timeline')->assertRedirect('/login');
    }

    private function ownerOf(CombatInstance $combat): User
    {
        $proprietaire = User::query()->find((int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id'));
        $this->assertNotNull($proprietaire, 'The target has no owner.');

        return $proprietaire;
    }
}
