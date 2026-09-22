<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use OGame\Empire\EmpireOrder;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use Tests\AccountTestCase;

/**
 * **Deux routes, deux contrats.**
 *
 * Ouvrir la page fait avancer le compte, comme toute page de jeu : c est `globalgame`, et c est voulu. **Actualiser
 * ne doit rien ecrire** — sinon une veille ferait paraitre le joueur en ligne et traiterait ses missions dans une
 * requete concurrente de ses propres clics. La difference ne se prouve pas sur la projection, qui n ecrit jamais :
 * elle se prouve **sur la route entiere**, middleware compris.
 */
class EmpireControllerTest extends AccountTestCase
{
    /**
     * L etat des tables que ces routes pourraient toucher.
     */
    private function etatDesTables(): string
    {
        $empreinte = '';
        foreach (['planets', 'users', 'lifeform_planets', 'lifeform_queues', 'building_queues', 'unit_queues', 'research_queues', 'fleet_missions'] as $table) {
            $lignes = DB::table($table)->orderBy('id')->get()->toArray();
            $empreinte .= $table . ':' . md5((string)json_encode($lignes)) . ';';
        }

        return $empreinte;
    }

    /**
     * **La page s ouvre pour tout joueur connecte, sans officier.**
     *
     * Le jeu officiel reserve cette vue au Commandant ; sur Azria elle est ouverte a tous. C est une adaptation
     * assumee, et cet essai la tient : si une garde d officier apparaissait, il tomberait.
     */
    public function testThePageIsServedToAnyLoggedInPlayerWithoutAnyOfficer(): void
    {
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertFalse($joueur->hasCommander(), 'Ce compte de banc n a pas de Commandant.');

        $reponse = $this->get('/empire');

        $reponse->assertStatus(200);
        $reponse->assertSee('empireComponent', false);
        $reponse->assertSee('window.empirePayload', false);
    }

    /**
     * **L actualisation ne modifie aucun etat du jeu**, et cela se verifie sur la route entiere : c est le middleware
     * qui ecrirait, pas la projection.
     */
    public function testTheRefreshRouteWritesNothingAtAll(): void
    {
        // Une premiere ouverture met le compte a jour : sans cela, la comparaison mesurerait ce retard, pas la route.
        $this->get('/empire')->assertStatus(200);

        $avant = $this->etatDesTables();
        $reponse = $this->get('/ajax/empire');

        $reponse->assertStatus(200);
        $reponse->assertJsonStructure(['taken_at', 'planets', 'groups', 'translations']);
        $this->assertSame($avant, $this->etatDesTables(), 'La route d actualisation a modifie une table du jeu.');
    }

    /**
     * **L actualisation vit hors de `globalgame`** — c est la seule facon de ne rien ecrire — mais elle garde
     * l authentification et le controle des bannis.
     */
    public function testTheRefreshRouteKeepsAuthAndBanChecksButNotGlobalgame(): void
    {
        $route = Route::getRoutes()->getByName('empire.refresh');
        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('banned', $middleware);
        $this->assertNotContains('globalgame', $middleware, 'Une lecture de fond qui traverse globalgame ecrit.');

        $page = Route::getRoutes()->getByName('empire.index');
        $this->assertNotNull($page);
        $this->assertContains('globalgame', $page->gatherMiddleware(), 'Ouvrir une page de jeu doit faire avancer le compte.');
    }

    /**
     * **L actualisation ne sert que les corps du joueur connecte.**
     */
    public function testTheRefreshRouteOnlyServesTheBodiesOfTheLoggedInPlayer(): void
    {
        $autre = User::factory()->create();
        $siens = DB::table('planets')->where('user_id', $this->currentUserId)->pluck('id')->all();
        $etrangers = DB::table('planets')->where('user_id', '!=', $this->currentUserId)->pluck('id')->all();

        $rendus = array_column($this->get('/ajax/empire')->assertStatus(200)->json('planets'), 'id');

        $this->assertNotEmpty($rendus);
        foreach ($rendus as $id) {
            $this->assertContains($id, $siens, 'Un corps qui n appartient pas au joueur figure dans la reponse.');
            $this->assertNotContains($id, $etrangers);
        }
        $this->assertNotNull($autre);
    }

    /**
     * **L ordre n accepte que ses propres corps**, et oublie ce qu il ne reconnait pas — sans rien reveler.
     */
    public function testTheOrderOnlyAcceptsTheBodiesOfThePlayer(): void
    {
        $sien = (int)DB::table('planets')->where('user_id', $this->currentUserId)->value('id');
        $etranger = (int)DB::table('planets')->where('user_id', '!=', $this->currentUserId)->value('id');

        $this->assertGreaterThan(0, $sien);

        $reponse = $this->post('/ajax/empire/order', [
            'type' => 'impSortOrder',
            'planets' => ['planet' . $etranger, 'planet' . $sien, 'planet0'],
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(200)->assertJsonPath('success', true);

        $compte = User::find($this->currentUserId);
        $this->assertNotNull($compte);
        $enregistre = EmpireOrder::of($compte, false);
        $this->assertContains($sien, $enregistre);
        $this->assertContains(0, $enregistre, 'La colonne des totaux garde sa place dans l ordre.');

        if ($etranger > 0) {
            $this->assertNotContains($etranger, $enregistre, 'Un corps etranger est entre dans la preference du joueur.');
        }
    }

    /**
     * **L ordre enregistre gouverne les colonnes**, et « reinitialiser » le rend a l ordre du compte.
     */
    public function testTheStoredOrderDrivesTheColumnsAndCanBeForgotten(): void
    {
        $ids = DB::table('planets')->where('user_id', $this->currentUserId)->where('planet_type', 1)->orderBy('id')->pluck('id')->all();

        // Le banc pose deux planetes ; on l exige plutot que de se passer, car la CI refuse tout essai ignore — et un
        // essai qui se passe ne prouve rien le jour ou le montage change.
        $this->assertGreaterThanOrEqual(2, count($ids), 'Il faut deux planetes pour eprouver un ordre.');

        $inverse = array_reverse($ids);
        $this->post('/ajax/empire/order', [
            'type' => 'impSortOrder',
            'planets' => array_map(static fn (int $id): string => 'planet' . $id, $inverse),
            '_token' => csrf_token(),
        ])->assertStatus(200);

        $rendus = array_column($this->get('/ajax/empire')->json('planets'), 'id');
        $this->assertSame($inverse, array_slice($rendus, 0, count($inverse)), 'Les colonnes ne suivent pas l ordre enregistre.');

        $this->post('/ajax/empire/order', ['type' => 'reset', '_token' => csrf_token()])->assertStatus(200);

        $rendusApres = array_column($this->get('/ajax/empire')->json('planets'), 'id');
        $this->assertSame($ids, array_slice($rendusApres, 0, count($ids)), 'Reinitialiser n a pas rendu l ordre du compte.');
    }

    /**
     * **Un compte sans lune ne voit pas une grille vide.** La demande de l onglet des lunes retombe sur les planetes,
     * et la page dit pourquoi.
     */
    public function testAnAccountWithoutMoonsFallsBackToPlanetsInsteadOfAnEmptyGrid(): void
    {
        DB::table('planets')->where('user_id', $this->currentUserId)->where('planet_type', '!=', 1)->delete();

        $charge = $this->get('/ajax/empire?planetType=1')->assertStatus(200)->json();

        $this->assertFalse($charge['moons'], 'Sans lune, l onglet des lunes ne doit pas etre servi.');
        $this->assertSame(0, $charge['moon_count']);
        $this->assertNotEmpty($charge['planets'], 'La reponse doit montrer les planetes, pas rien.');
    }
}
