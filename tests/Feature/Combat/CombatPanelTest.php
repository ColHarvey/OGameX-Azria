<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Presentation\CombatPresentationTimelineReader;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Les batailles dans les bandeaux du jeu, telles que le navigateur les recoit.
 *
 * ## Ce que ces essais prouvent
 *
 * Que le bandeau ferme signale une bataille en cours, et que le deroulant « Evenements » en porte
 * la carte — a ceux qui y sont partie, et a eux seuls ; que les missions ordinaires y gardent leur
 * place ; que les pertes apparaissent quand leur instant est passe, jamais avant ; que le fil JSON
 * ne rend que le passe, sans numero de round, **sans aucune echeance de la bataille**, a l'heure du
 * serveur et pour le joueur de la session — un parametre glisse dans l'adresse n'y change rien ;
 * qu'un tiers recoit 403, un combat inconnu 404, un visiteur la page de connexion.
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

    /**
     * Le bandeau ferme signale la bataille par un libelle, et le deroulant en porte la carte.
     */
    public function testTheClosedBannerSignalsTheBattleAndTheDropdownCarriesItsCard(): void
    {
        $combat = $this->anEngagedCombat();

        $bandeau = $this->get('/ajax/fleet/eventbox/fetch');
        $bandeau->assertStatus(200);
        $bandeau->assertJsonFragment(['combats' => 1]);
        $bandeau->assertJsonFragment(['combatText' => trans_choice('t_ingame.combat.eventbox_combats', 1, ['count' => 1])]);

        // **L'echeance ne transite pas non plus par le bandeau.**
        $this->assertNotContains((int)$combat->ends_at, array_values($bandeau->json()), 'The battle deadline reached the closed banner.');

        $deroulant = $this->get('/ajax/fleet/eventlist/fetch');
        $deroulant->assertStatus(200);
        $deroulant->assertSee(__('t_ingame.combat.role_attacker'));
        $deroulant->assertSee(__('t_ingame.combat.status_active'));
        $deroulant->assertSee('[' . $combat->galaxy . ':' . $combat->system . ':' . $combat->position . ']');
        $deroulant->assertSee('id="combatRow-' . $combat->id . '"', false);

        // **Les missions ordinaires gardent leur place** : la carte s'ajoute, elle ne remplace rien.
        $contenu = (string)$deroulant->getContent();
        $this->assertStringContainsString('id="eventRow-' . $combat->mission_id . '"', $contenu, 'The attacking fleet lost its own line in the dropdown.');
        $this->assertStringNotContainsString((string)$combat->ends_at, $contenu, 'The battle deadline is written into the dropdown.');
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

        $avant = $this->get('/ajax/fleet/eventlist/fetch');
        $avant->assertStatus(200);
        $avant->assertSee(__('t_ingame.combat.role_target'));
        $avant->assertSee(__('t_ingame.combat.no_losses_yet'));

        // **A la fin de la premiere periode, la garnison a perdu quelque chose.**
        $fin = $debut + $premierePeriode;
        $this->travelTo(Date::createFromTimestamp($fin));
        $visibles = $lecteur->visibleTo($combat, $proprietaire->id, $fin);
        $this->assertNotSame([], $visibles, 'The garrison lost nothing in the first period: the scenario would prove nothing.');

        $apres = $this->get('/ajax/fleet/eventlist/fetch');
        $apres->assertStatus(200);
        $apres->assertDontSee(__('t_ingame.combat.no_losses_yet'));
        $apres->assertSee(ObjectService::getUnitObjectByMachineName($visibles[0]->unit)->title);
    }

    /**
     * Le fil JSON ne rend que le passe, sans round ni echeance, a l'heure du serveur, pour le
     * joueur de la session.
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
        $this->assertCount(count($maintenant), $corps['events']);

        // **L'echeance de la bataille ne transite sous aucune forme.** Ni l'instant, ni des secondes
        // restantes que `server_now` suffirait a retourner en instant.
        $this->assertArrayNotHasKey('seconds_remaining', $corps, 'The battle deadline came back as remaining seconds.');
        $this->assertArrayNotHasKey('ends_at', $corps);

        // La recherche porte sur les **valeurs** du document, pas sur son texte : un nombre de
        // pertes se retrouve par hasard dans une chaine, et une assertion par sous-chaine
        // accuserait alors le code a tort.
        $valeurs = [];
        $aplatir = static function (mixed $noeud) use (&$aplatir, &$valeurs): void {
            if (is_array($noeud)) {
                foreach ($noeud as $fils) {
                    $aplatir($fils);
                }

                return;
            }

            $valeurs[] = $noeud;
        };
        $aplatir($corps);

        $this->assertNotContains($echeance, $valeurs, 'The battle deadline is a value of the feed.');

        // L'ecart, lui, ne se cherche pas ainsi : quand il vaut un ou deux, il coincide avec un
        // identifiant ou un nombre de pertes, et l'assertion accuserait le code a tort. Ce qui le
        // rend impossible a reconstituer, c'est l'absence de toute clef qui le porterait — les clefs
        // du document sont epinglees plus haut et plus bas.

        foreach ($corps['events'] as $evenement) {
            $this->assertLessThanOrEqual($fin, $evenement['at'], 'A loss from the future was sent to the browser.');
            $this->assertArrayNotHasKey('round', $evenement, 'A round number leaked into the feed.');
            $this->assertSame(['key', 'sequence', 'at', 'side', 'unit', 'unit_label', 'amount'], array_keys($evenement));
        }

        $this->assertSame((int)$corps['next_after'], (int)end($corps['events'])['sequence']);

        // **Reprendre apres le dernier rang ne rend rien de plus**, et garde le rang.
        $suite = $this->getJson('/ajax/combat/' . $combat->id . '/timeline?after=' . $corps['next_after'])->json();
        $this->assertSame([], $suite['events']);
        $this->assertSame($corps['next_after'], $suite['next_after']);

        // **Ni l'heure ni le joueur ne se choisissent depuis le navigateur.**
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

        $bandeau = $this->get('/ajax/fleet/eventbox/fetch');
        $bandeau->assertStatus(200);
        $bandeau->assertJsonFragment(['combats' => 0]);

        $deroulant = $this->get('/ajax/fleet/eventlist/fetch');
        $deroulant->assertStatus(200);
        $deroulant->assertDontSee('combatRow-' . $combat->id, false);

        $fragment = $this->get('/ajax/combat/panel');
        $fragment->assertStatus(200);
        $fragment->assertDontSee('combatRow-' . $combat->id, false);
    }

    /**
     * Le fragment rend exactement les cartes du deroulant, et rien d'autre : c'est lui que le
     * navigateur redemande pour ne pas remplacer les lignes de mouvement.
     */
    public function testTheFragmentRendersTheCardsAloneWithoutTheOrdinaryMissions(): void
    {
        $combat = $this->anEngagedCombat();

        $fragment = $this->get('/ajax/combat/panel');

        $fragment->assertStatus(200);
        $fragment->assertSee('id="combatRow-' . $combat->id . '"', false);
        $fragment->assertSee(__('t_ingame.combat.role_attacker'));

        $contenu = (string)$fragment->getContent();
        $this->assertStringNotContainsString('id="eventRow-', $contenu, 'The fragment carries the ordinary missions: replacing it would move them.');
        $this->assertStringNotContainsString('eventListWrap', $contenu, 'The fragment carries the whole dropdown.');
        $this->assertStringNotContainsString((string)$combat->ends_at, $contenu, 'The battle deadline is written into the fragment.');
    }

    /**
     * Une seconde bataille se voit aussi, et le bandeau les compte toutes les deux.
     */
    public function testASecondBattleIsCountedAndCarriesItsOwnCard(): void
    {
        $premier = $this->anEngagedCombat();

        // Une seconde attaque, vers une autre planete propre : deux batailles a la fois.
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 30);
        $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));

        $seconde = DB::table('fleet_missions')->where('user_id', $this->currentUserId)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($seconde);
        $this->travelTo(Date::createFromTimestamp((int)$seconde->time_arrival));
        $this->get('/overview')->assertStatus(200);

        $combats = CombatInstance::query()->whereIn('mission_id', [$premier->mission_id, $seconde->id])->pluck('id')->all();
        $this->assertCount(2, $combats, 'The second arrival did not open a second combat: the scenario would prove nothing.');

        $bandeau = $this->get('/ajax/fleet/eventbox/fetch');
        $bandeau->assertJsonFragment(['combats' => 2]);
        $bandeau->assertJsonFragment(['combatText' => trans_choice('t_ingame.combat.eventbox_combats', 2, ['count' => 2])]);

        $deroulant = (string)$this->get('/ajax/fleet/eventlist/fetch')->getContent();

        foreach ($combats as $identifiant) {
            $this->assertStringContainsString('id="combatRow-' . $identifiant . '"', $deroulant, 'A battle has no card of its own.');
        }
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
        $this->assertInstanceOf(User::class, $proprietaire);

        return $proprietaire;
    }
}
