<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Models\DailyReward;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\User;
use OGame\Services\DailyRewardService;
use OGame\Services\DarkMatterTransactionService;
use RuntimeException;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **Ce que la contrainte unique NE prouve pas** : que les trois ecritures tiennent ensemble.
 *
 * La contrainte (compte, journee) empeche deux reclamations pour le meme jour. Elle ne dit rien de ce qui se
 * passe si une ecriture tombe **entre** les trois :
 *
 *   1. l inscription dans `daily_rewards` ;
 *   2. le credit du solde sur `users.dark_matter` ;
 *   3. la transaction comptable dans `dark_matter_transactions`.
 *
 * Une defaillance au milieu pourrait consommer la journee sans crediter, ou crediter sans trace comptable. Ces
 * temoins provoquent l echec a chaque etape et exigent que **rien** ne subsiste — et que la recompense reste
 * reclamable ensuite (exigence de Keven, 20 septembre 2026).
 */
final class DailyRewardIntegrityTest extends AccountTestCase
{
    use PinsSettings;

    private const int MONTANT = 1000;

    protected function tearDown(): void
    {
        DailyReward::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    private function compte(): User
    {
        return User::query()->findOrFail($this->currentUserId);
    }

    /**
     * Une comptabilite qui tombe : le service de transaction leve au moment d ecrire la ligne.
     *
     * **La liaison est posee AVANT toute resolution** : le conteneur garde ses instances, et un service resolu
     * plus tot ne serait pas celui que le jeu emploie.
     */
    private function faireEchouerLaComptabilite(): void
    {
        $this->app->bind(DarkMatterTransactionService::class, function (): DarkMatterTransactionService {
            return new class () extends DarkMatterTransactionService {
                public function recordTransaction(User $user, int $amount, string $type, string $description, int $balanceAfter): DarkMatterTransaction
                {
                    throw new RuntimeException('La comptabilite tombe : panne provoquee par le banc.');
                }
            };
        });
    }

    /**
     * **Premisse du fichier** : sans panne, les trois ecritures existent bien. Sinon les temoins suivants
     * pourraient constater « rien n a ete ecrit » sur un chemin qui n ecrit jamais rien.
     */
    public function testWithoutAnyFailureTheThreeWritesAllExist(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => self::MONTANT]);
        $avant = (int)$this->compte()->dark_matter;

        $this->assertSame(DailyRewardService::CLAIMED, resolve(DailyRewardService::class)->claim($this->compte(), Date::now()));

        $this->assertSame(1, DailyReward::query()->where('user_id', $this->currentUserId)->count(), '1. inscription');
        $this->assertSame($avant + self::MONTANT, (int)$this->compte()->dark_matter, '2. credit');
        $this->assertSame(
            1,
            DarkMatterTransaction::query()->where('user_id', $this->currentUserId)->where('type', 'daily_reward')->count(),
            '3. transaction comptable'
        );
    }

    /**
     * **La comptabilite tombe : rien ne subsiste.** Ni inscription, ni credit, ni transaction — et la journee
     * n est pas consommee.
     */
    public function testAFailureInTheAccountingLeavesNothingBehindAndConsumesNothing(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => self::MONTANT]);
        $avant = (int)$this->compte()->dark_matter;
        $transactionsAvant = DarkMatterTransaction::query()->where('user_id', $this->currentUserId)->count();

        $this->faireEchouerLaComptabilite();

        $leve = null;
        try {
            resolve(DailyRewardService::class)->claim($this->compte(), Date::now());
        } catch (RuntimeException $erreur) {
            $leve = $erreur;
        }
        $this->assertNotNull($leve, 'Premisse : la panne doit bien remonter, sinon rien n est eprouve.');

        // **Aucune des trois ecritures ne subsiste.**
        $this->assertSame(0, DailyReward::query()->where('user_id', $this->currentUserId)->count(), 'Une inscription a survecu a la panne.');
        $this->assertSame($avant, (int)$this->compte()->dark_matter, 'Le solde a ete credite malgre la panne.');
        $this->assertSame(
            $transactionsAvant,
            DarkMatterTransaction::query()->where('user_id', $this->currentUserId)->count(),
            'Une transaction comptable a survecu a la panne.'
        );

        // **Et la journee n est pas consommee** : une fois la panne passee, la recompense se reclame.
        $this->app->forgetInstance(DarkMatterTransactionService::class);
        $this->app->bind(DarkMatterTransactionService::class, fn (): DarkMatterTransactionService => new DarkMatterTransactionService());

        $this->assertSame(
            DailyRewardService::CLAIMED,
            resolve(DailyRewardService::class)->claim($this->compte(), Date::now()),
            'La panne a consomme la journee : le joueur a perdu sa recompense sans rien recevoir.'
        );
        $this->assertSame($avant + self::MONTANT, (int)$this->compte()->dark_matter);
    }

    /**
     * **Par la vraie route**, une panne ne laisse rien non plus, et ne credite pas a moitie.
     */
    public function testAFailureThroughTheRealRouteCreditsNothingAtAll(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => self::MONTANT]);
        $avant = (int)$this->compte()->dark_matter;

        $this->faireEchouerLaComptabilite();
        $this->withoutExceptionHandling();

        $leve = false;
        try {
            $this->post(route('daily_reward.claim'), ['_token' => csrf_token()]);
        } catch (RuntimeException) {
            $leve = true;
        }
        $this->assertTrue($leve, 'Premisse : la panne doit remonter par la route.');

        $this->assertSame(0, DailyReward::query()->where('user_id', $this->currentUserId)->count());
        $this->assertSame($avant, (int)$this->compte()->dark_matter);
    }

    /**
     * **Le solde et sa transaction tombent ensemble.** Une transaction comptable sans credit, ou l inverse, est
     * une incoherence : la somme des transactions de recompense doit toujours valoir ce qui a ete credite.
     */
    public function testTheBalanceAndItsAccountingAlwaysAgree(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => self::MONTANT]);
        $avant = (int)$this->compte()->dark_matter;
        $service = resolve(DailyRewardService::class);

        // Trois journees, dont une panne au milieu.
        $service->claim($this->compte(), Date::parse('2026-09-21 10:00:00', config('app.timezone')));

        $this->faireEchouerLaComptabilite();
        try {
            $service = resolve(DailyRewardService::class);
            $service->claim($this->compte(), Date::parse('2026-09-22 10:00:00', config('app.timezone')));
        } catch (RuntimeException) {
            // attendu
        }

        $this->app->forgetInstance(DarkMatterTransactionService::class);
        $this->app->bind(DarkMatterTransactionService::class, fn (): DarkMatterTransactionService => new DarkMatterTransactionService());
        resolve(DailyRewardService::class)->claim($this->compte(), Date::parse('2026-09-23 10:00:00', config('app.timezone')));

        $inscriptions = DailyReward::query()->where('user_id', $this->currentUserId)->count();
        $sommeComptable = (int)DarkMatterTransaction::query()
            ->where('user_id', $this->currentUserId)
            ->where('type', 'daily_reward')
            ->sum('amount');

        $this->assertSame(2, $inscriptions, 'La journee en panne ne doit pas avoir laisse d inscription.');
        $this->assertSame(2 * self::MONTANT, $sommeComptable, 'La comptabilite ne dit pas ce qui a ete credite.');
        $this->assertSame(
            $avant + $sommeComptable,
            (int)$this->compte()->dark_matter,
            'Le solde et la comptabilite divergent.'
        );
    }
}
