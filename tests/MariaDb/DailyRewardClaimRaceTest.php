<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use OGame\Models\DailyReward;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\User;
use OGame\Services\DailyRewardService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **Deux mille pour la meme journee : impossible, et prouve sur la vraie base.**
 *
 * Deux clics, deux onglets ou deux demandes simultanees. Sous SQLite l epreuve ne dirait rien : rien n y est
 * verrouille ligne par ligne, et deux processus ne s y attendent pas. C est le moteur qui doit refuser la seconde
 * insertion, et c est ici qu on le lui demande.
 *
 * Ce que l epreuve etablit, et qui ne se deduit pas d une lecture du code :
 *
 * - **une seule ligne** dans `daily_rewards` pour le couple (compte, journee), quel que soit l ordre des
 *   processus ;
 * - **un seul credit** : le solde bouge d un montant, pas de deux ;
 * - **une seule transaction** de type `daily_reward` ;
 * - et le processus perdant rend `already`, pas une erreur : c est exactement le cas d une reponse perdue suivie
 *   d une nouvelle tentative, qui doit retrouver la reclamation sans recrediter.
 */
final class DailyRewardClaimRaceTest extends AccountTestCase
{
    use PinsSettings;
    use RunsInParallelProcesses;

    private const int MONTANT = 1000;

    protected function tearDown(): void
    {
        DailyReward::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testTwoSimultaneousClaimsCreditTheRewardExactlyOnce(): void
    {
        $this->requiresMariaDb();
        $this->requiresProcesses();

        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => self::MONTANT]);

        $compteId = $this->currentUserId;
        $avant = (int)User::query()->findOrFail($compteId)->dark_matter;

        // Premisse : rien n a encore ete reclame aujourd hui, sinon les deux processus rendraient « already »
        // et l epreuve passerait sans rien mesurer.
        $this->assertSame(0, DailyReward::query()->where('user_id', $compteId)->count());

        $issues = $this->inParallel(4, function (int $rang) use ($compteId): string {
            $compte = User::query()->findOrFail($compteId);

            return resolve(DailyRewardService::class)->claim($compte, Date::now());
        });

        // **Exactement un gagnant.** Les autres disent « deja reclamee », jamais une erreur.
        $gagnants = array_keys($issues, DailyRewardService::CLAIMED, true);
        $perdants = array_keys($issues, DailyRewardService::ALREADY, true);

        $this->assertCount(
            1,
            $gagnants,
            "Il faut exactement un credit. Issues : " . json_encode($issues)
        );
        $this->assertCount(
            3,
            $perdants,
            "Les trois autres doivent rendre « deja reclamee ». Issues : " . json_encode($issues)
        );

        // **Le solde, et lui seul, tranche.** Une seule ligne ne prouverait pas qu un seul credit est parti.
        $this->assertSame(
            $avant + self::MONTANT,
            (int)User::query()->findOrFail($compteId)->dark_matter,
            'Le compte a ete credite plus d une fois pour la meme journee.'
        );

        $this->assertSame(
            1,
            DailyReward::query()->where('user_id', $compteId)->count(),
            'Plus d une reclamation a ete enregistree pour la meme journee.'
        );

        $this->assertSame(
            1,
            DarkMatterTransaction::query()
                ->where('user_id', $compteId)
                ->where('type', 'daily_reward')
                ->count(),
            'Plus d une transaction de recompense quotidienne a ete ecrite.'
        );
    }

    /**
     * **Une nouvelle tentative apres une reponse perdue ne recredite rien**, y compris quand elle arrive pendant
     * que d autres demandes tournent.
     */
    public function testARetryAfterTheRewardWasAlreadyGrantedCreditsNothing(): void
    {
        $this->requiresMariaDb();
        $this->requiresProcesses();

        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => self::MONTANT]);

        $compteId = $this->currentUserId;
        $compte = User::query()->findOrFail($compteId);
        $avant = (int)$compte->dark_matter;

        // La recompense est deja prise : c est l etat d une reponse perdue apres un credit reussi.
        $this->assertSame(DailyRewardService::CLAIMED, resolve(DailyRewardService::class)->claim($compte, Date::now()));
        $apresLePremier = (int)User::query()->findOrFail($compteId)->dark_matter;
        $this->assertSame($avant + self::MONTANT, $apresLePremier);

        $issues = $this->inParallel(3, function (int $rang) use ($compteId): string {
            return resolve(DailyRewardService::class)->claim(User::query()->findOrFail($compteId), Date::now());
        });

        foreach ($issues as $rang => $issue) {
            $this->assertSame(DailyRewardService::ALREADY, $issue, "Le processus $rang a credite une seconde fois.");
        }

        $this->assertSame(
            $apresLePremier,
            (int)User::query()->findOrFail($compteId)->dark_matter,
            'Une nouvelle tentative a recredite le compte.'
        );
    }
}
