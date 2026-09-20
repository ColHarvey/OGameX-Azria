<?php

namespace Tests\Feature;

use OGame\Models\Ban;
use OGame\Models\DailyReward;
use OGame\Models\User;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **Les trois routes de la recompense vivent hors de `globalgame` — elles n en sont pas moins gardees.**
 *
 * Elles y sont a dessein : `globalgame` fait avancer l activite du compte et traite les missions, et une fenetre
 * qui se resynchronise a minuit ferait tourner tout cela dans une requete de fond (le piege du bandeau des
 * ressources, journal §170). Mais « hors de `globalgame` » ne doit pas vouloir dire « hors de tout ».
 *
 * Ce fichier verifie **par les vraies routes**, jamais par le service :
 *
 * - un visiteur non authentifie n atteint rien ;
 * - un compte banni n atteint rien ;
 * - le POST est refuse sans jeton anti-contrefacon valide ;
 * - **aucun identifiant fourni par le navigateur** ne permet de reclamer pour un autre compte.
 */
final class DailyRewardRouteProtectionTest extends AccountTestCase
{
    use PinsSettings;

    protected function tearDown(): void
    {
        DailyReward::query()->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * **Un visiteur n atteint aucune des trois routes.**
     */
    public function testAGuestReachesNoneOfTheThreeRoutes(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1]);
        $this->post('/logout');
        $this->assertGuest();

        $this->get(route('daily_reward.state'))->assertRedirect(route('login'));
        $this->get(route('daily_reward.overlay'))->assertRedirect(route('login'));
        $this->post(route('daily_reward.claim'), ['_token' => csrf_token()])->assertRedirect(route('login'));

        $this->assertSame(0, DailyReward::query()->count(), 'Un visiteur a reclame une recompense.');
    }

    /**
     * **Un compte banni n atteint rien non plus.** Le groupe porte `banned`, et cet essai le prouve plutot que de
     * le lire dans la liste des intergiciels.
     */
    public function testABannedAccountIsTurnedAway(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1]);

        // Le bannissement vit dans sa propre table depuis la migration du 29 mars : une colonne sur `users`
        // n existe plus, et l ecrire aurait fait rougir l essai sans rien dire du bannissement.
        $ban = Ban::create([
            'user_id' => $this->currentUserId,
            'reason' => 'Banni par le banc de la recompense quotidienne',
            'banned_until' => null,
            'canceled' => false,
        ]);

        // Premisse : le compte est bien vu comme banni, sinon l essai mesurerait autre chose.
        $this->assertTrue(User::query()->findOrFail($this->currentUserId)->isBanned(), 'Le banc n a pas reussi a bannir le compte.');

        $reponse = $this->post(route('daily_reward.claim'), ['_token' => csrf_token()]);
        $reponse->assertRedirect(route('login'));

        $this->assertSame(0, DailyReward::query()->count(), 'Un compte banni a reclame une recompense.');

        $ban->delete();
    }

    /**
     * **Le jeton anti-contrefacon ne se prouve PAS ici, et il faut le dire.**
     *
     * `PreventRequestForgery::handle()` commence par `$this->runningUnitTests()` : sous PHPUnit la verification
     * est court-circuitee, quoi qu on envoie. Un essai qui enverrait un mauvais jeton et attendrait 419 ne
     * mesurerait que cette derogation — il passerait meme si la route etait exclue de la protection.
     *
     * Ce qui se verifie ici est donc l autre moitie : la route **est** dans le groupe protege, et **rien** ne
     * l en exclut. La preuve du refus se fait au navigateur (`recompense-securite.mjs`), la seule ou le
     * middleware s execute vraiment.
     */
    public function testTheClaimRouteSitsInsideTheProtectedGroupAndIsNotExcluded(): void
    {
        // 1. Elle est declaree dans `routes/web.php`, donc dans le groupe `web` qui porte la protection.
        $web = (string)file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString("Route::post('/ajax/daily-reward/claim'", $web, 'La route de reclamation n est pas declaree dans le groupe web.');

        // 2. Aucune exclusion ne la vise. Le depot n en declare aucune ; si cela changeait, ce temoin tombe.
        $config = (string)file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringNotContainsString('validateCsrfTokens', $config, 'Des exclusions anti-contrefacon existent desormais : verifier que la recompense n en fait pas partie.');

        // 3. Et c est bien un POST : une lecture ne serait jamais verifiee (`isReading`).
        $route = app('router')->getRoutes()->getByName('daily_reward.claim');
        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods(), 'La reclamation doit etre un POST, sinon la protection ne s applique pas.');
    }

    /**
     * **Aucun identifiant du navigateur ne designe le compte credite.** On envoie tout ce qu un attaquant
     * essaierait : le compte credite reste celui de la session.
     */
    public function testNoBrowserSuppliedIdentifierCanClaimForAnotherAccount(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);

        $autre = $this->getSecondPlayerId();
        $this->assertNotSame($this->currentUserId, $autre, 'Premisse : il faut un second compte.');
        $soldeDeLAutre = (int)User::query()->findOrFail($autre)->dark_matter;

        $reponse = $this->post(route('daily_reward.claim'), [
            '_token' => csrf_token(),
            // Tout ce qu on pourrait tenter de faire passer.
            'user_id' => $autre,
            'userId' => $autre,
            'id' => $autre,
            'player_id' => $autre,
            'account' => $autre,
            'amount' => 999999,
        ]);
        $reponse->assertStatus(200);

        $lignes = DailyReward::query()->get();
        $this->assertCount(1, $lignes, 'Une seule inscription doit exister.');
        $ligne = $lignes->first();
        $this->assertInstanceOf(DailyReward::class, $ligne);
        $this->assertSame($this->currentUserId, $ligne->user_id, 'La reclamation a ete portee sur un autre compte.');

        // **Le montant vient du reglage, jamais de la requete.**
        $this->assertSame(1000, $ligne->amount, 'Le montant a ete impose par le navigateur.');

        $this->assertSame(
            $soldeDeLAutre,
            (int)User::query()->findOrFail($autre)->dark_matter,
            'Le solde d un autre compte a bouge.'
        );
    }

    /**
     * **La lecture d etat ne parle que du compte de la session non plus.**
     */
    public function testTheStateRouteIgnoresAnyAccountGivenByTheBrowser(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);

        $autre = $this->getSecondPlayerId();
        $moi = User::query()->findOrFail($this->currentUserId);

        $reponse = $this->get(route('daily_reward.state') . '?user_id=' . $autre . '&id=' . $autre);
        $reponse->assertStatus(200);
        $reponse->assertJsonPath('dark_matter', (int)$moi->dark_matter);
    }
}
