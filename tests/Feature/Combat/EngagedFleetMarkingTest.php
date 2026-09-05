<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Une flotte engagee dans un combat durable se dit engagee, et son rappel n'est plus offert.
 *
 * ## Pourquoi
 *
 * Une flotte engagee ne se rappelle plus jusqu'au combat final : le serveur le refuse
 * (`EngagedFleetCheck`). Une interface qui offrirait encore le bouton, et un compte a rebours a
 * zero qui dirait « pret » pendant toute la bataille, feraient conclure a une panne. La page de
 * mouvement et la boite d'evenements disent l'etat a la place — et le proprietaire de la cible le
 * voit aussi sur l'attaque entrante.
 */
class EngagedFleetMarkingTest extends FleetDispatchTestCase
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

    /**
     * Sur la page de mouvement : la flotte engagee est dite engagee et sans rappel ; une autre
     * flotte du meme joueur garde son rappel — le temoin qui discrimine.
     */
    public function testTheMovementPageMarksTheEngagedFleetAndKeepsTheRecallOfTheOthers(): void
    {
        $combat = $this->anEngagedCombat();

        // Une expedition ordinaire, en vol, du meme joueur : elle, reste rappelable. Elle ne vise
        // aucun corps, donc aucun verrou de combat ne peut la refuser au depart.
        $this->playerSetResearchLevel('astrophysics', 1);
        $expedition = new UnitCollection();
        $expedition->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 1);
        $this->missionType = 15;
        $this->sendMissionToPosition16($expedition, new Resources(0, 0, 0, 0));
        $this->missionType = 1;

        $page = $this->get('/fleet/movement');
        $page->assertStatus(200);
        $contenu = (string)$page->getContent();

        $expeditionId = (int)DB::table('fleet_missions')->where('user_id', $this->currentUserId)->where('mission_type', 15)->where('processed', 0)->value('id');
        $this->assertGreaterThan(0, $expeditionId, 'The expedition was not dispatched: the scenario would prove nothing.');

        $page->assertSee(__('t_ingame.fleet.engaged_in_combat'));
        $this->assertSame(1, substr_count($contenu, 'data-combat-id="' . $combat->id . '"'), 'The engaged fleet is not marked exactly once.');

        // **Le temoin qui discrimine** : l'expedition garde son minuteur, la flotte engagee ne l'a
        // plus. (Le bouton de rappel de la page de mouvement depend de l'horloge reelle, pas de
        // celle du banc : il ne peut pas servir de temoin ici.)
        $this->assertStringContainsString('id="timer_' . $expeditionId . '"', $contenu, 'The expedition lost its countdown: the marking is not selective.');
        $this->assertStringNotContainsString('id="timer_' . $combat->mission_id . '"', $contenu, 'The engaged fleet still shows a countdown.');
        $this->assertStringNotContainsString('recallFleet" data-fleet-id="' . $combat->mission_id . '"', $contenu, 'The engaged fleet is still offered a recall.');
    }

    /**
     * Dans la boite d'evenements : meme marquage pour l'attaquant, et le proprietaire de la cible
     * voit l'attaque entrante engagee.
     */
    public function testTheEventListMarksTheEngagedFleetForBothSides(): void
    {
        $combat = $this->anEngagedCombat();

        $attaquant = $this->get('/ajax/fleet/eventlist/fetch');
        $attaquant->assertStatus(200);
        $attaquant->assertSee(__('t_ingame.fleet.engaged_in_combat'));
        $attaquant->assertSee('data-combat-id="' . $combat->id . '"', false);
        $this->assertStringNotContainsString('id="counter-eventlist-' . $combat->mission_id . '"', (string)$attaquant->getContent(), 'The engaged fleet still carries the countdown the script would overwrite.');
        // Le bouton, pas le mot : le script de la liste nomme la classe `recallFleet` quoi qu'il arrive.
        $this->assertStringNotContainsString('class="icon_link tooltipHTML recallFleet"', (string)$attaquant->getContent(), 'The engaged fleet is still offered a recall.');

        $proprietaire = User::query()->find((int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id'));
        $this->assertInstanceOf(User::class, $proprietaire);
        $this->actingAs($proprietaire);

        $cible = $this->get('/ajax/fleet/eventlist/fetch');
        $cible->assertStatus(200);
        $cible->assertSee(__('t_ingame.fleet.engaged_in_combat'));
        $cible->assertSee('data-combat-id="' . $combat->id . '"', false);
    }
}
