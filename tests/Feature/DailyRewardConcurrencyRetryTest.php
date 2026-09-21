<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Models\DailyReward;
use OGame\Models\User;
use OGame\Services\DailyRewardService;
use PDOException;
use Tests\AccountTestCase;
use Tests\Support\InterblocageProvoque;
use Tests\Support\PinsSettings;

/**
 * **La reprise apres interblocage, prouvee sans concurrence.**
 *
 * Keven, 20 septembre 2026 : « une course MariaDB verte demontre le comportement concurrent sur ce passage,
 * mais ne prouve pas forcement que la reprise a ete exercee : aucun interblocage ne se sera peut-etre
 * produit ». C est exact, et c est pourquoi la course ne suffit pas.
 *
 * Ici l interblocage n est pas espere, il est **provoque** : `Connection::beforeExecuting()` est une couture
 * du cadre qui laisse lever avant l envoi d une requete. On y pose une `QueryException` de la forme exacte
 * que MariaDB rend — `SQLSTATE[40001] ... 1213 Deadlock found when trying to get lock` —, reconnue par la
 * meme regle que celle qui decide des reprises (`DetectsConcurrencyErrors`). Le faux porte donc la regle du
 * vrai : s il ne la portait pas, il ne mesurerait que l entree du canal.
 *
 * Trois choses sont etablies ici, qu aucune course ne peut garantir a elle seule :
 *
 *   - une tentative **annulee puis reussie** laisse un compte credite une seule fois ;
 *   - des tentatives **toutes epuisees** ne laissent **rien** — ni ligne, ni credit — et rendent `BUSY` ;
 *   - apres un `BUSY`, **redemander aboutit**, la journee etant restee a prendre.
 */
class DailyRewardConcurrencyRetryTest extends AccountTestCase
{
    use PinsSettings;

    protected function setUp(): void
    {
        parent::setUp();

        DailyReward::query()->delete();
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
    }

    /**
     * **Une epreuve remet ce qu elle a leve.**
     *
     * Cette classe posait le reglage sans jamais le rendre : sur la base unique du passage sequentiel, elle
     * laissait `daily_reward_enabled` a 1 et ses lignes en place pour toutes les classes suivantes du
     * processus. Deux essais voisins en sont tombes — celui qui verifie que la fonctionnalite est **fermee par
     * defaut**, et celui qui compte les lignes apres le passage d un visiteur. Seize processus paralleles ne
     * l avaient jamais montre.
     */
    protected function tearDown(): void
    {
        DailyReward::query()->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * **Une tentative annulee, puis une reussie : un seul credit.**
     */
    public function testAClaimCancelledByADeadlockIsRetriedAndCreditsExactlyOnce(): void
    {
        $compte = $this->compte();
        $avant = $this->soldeDe($compte);

        $insertions = $this->provoquerUnInterblocage(surLesPremieres: 1);

        $issue = app(DailyRewardService::class)->claim($compte, now());

        $this->assertSame(
            DailyRewardService::CLAIMED,
            $issue,
            'Une tentative annulee par un interblocage doit etre reprise, pas rendue au joueur.'
        );
        $this->assertSame(
            2,
            $insertions->vues,
            'Premisse de la mesure : l insertion doit avoir ete tentee deux fois — une annulee, une reussie. '
            . 'Sans cela, ce temoin ne prouverait pas la reprise mais seulement un chemin sans conflit.'
        );

        $this->assertSame(1, DailyReward::query()->where('user_id', $compte->id)->count(), 'Une seule ligne.');
        $this->assertSame(
            $avant + 1000,
            $this->soldeDe($compte),
            'Le solde doit avoir augmente d exactement une recompense : ni zero, ni deux.'
        );
    }

    /**
     * **Toutes les tentatives epuisees : rien n est ecrit, et le joueur lit « reessaie ».**
     */
    public function testAClaimThatKeepsDeadlockingWritesNothingAndReportsBusy(): void
    {
        $compte = $this->compte();
        $avant = $this->soldeDe($compte);

        $insertions = $this->provoquerUnInterblocage(surLesPremieres: PHP_INT_MAX);

        $issue = app(DailyRewardService::class)->claim($compte, now());

        $this->assertSame(
            DailyRewardService::BUSY,
            $issue,
            'Des tentatives epuisees doivent rendre BUSY, jamais remonter une erreur de base au joueur.'
        );
        $this->assertSame(
            DailyRewardService::ATTEMPTS,
            $insertions->vues,
            'Premisse : les cinq tentatives accordees ont bien toutes ete employees.'
        );

        $this->assertSame(0, DailyReward::query()->where('user_id', $compte->id)->count(), 'Aucune ligne posee.');
        $this->assertSame($avant, $this->soldeDe($compte), 'Aucun credit : la journee reste entiere a prendre.');
    }

    /**
     * **Apres un `BUSY`, redemander aboutit** — c est ce qui rend l issue sure a proposer au joueur.
     */
    public function testAfterABusyOutcomeTheSameDayCanStillBeClaimed(): void
    {
        $compte = $this->compte();
        $avant = $this->soldeDe($compte);

        $insertions = $this->provoquerUnInterblocage(surLesPremieres: PHP_INT_MAX);
        $premier = app(DailyRewardService::class)->claim($compte, now());

        // Le conflit se dissipe : la couture laisse passer.
        $insertions->actif = false;
        $second = app(DailyRewardService::class)->claim($compte, now());

        $this->assertSame(DailyRewardService::BUSY, $premier, 'Premisse : la premiere demande a bien rendu BUSY.');
        $this->assertSame(DailyRewardService::CLAIMED, $second, 'La seconde demande doit aboutir.');
        $this->assertSame(1, DailyReward::query()->where('user_id', $compte->id)->count());
        $this->assertSame(
            $avant + 1000,
            $this->soldeDe($compte),
            'Un seul credit au total, malgre les deux demandes.'
        );
    }

    /**
     * Pose une couture qui leve un interblocage sur les premieres insertions dans `daily_rewards`, et compte
     * combien de fois l insertion a ete tentee.
     *
     * L objet rendu porte le compte : **un entier capture par reference serait tenu pour constant** par
     * l analyse statique, et le temoin lirait toujours zero.
     */
    private function provoquerUnInterblocage(int $surLesPremieres): InterblocageProvoque
    {
        $etat = new InterblocageProvoque($surLesPremieres);

        DB::connection()->beforeExecuting(function (string $requete) use ($etat): void {
            // On ne vise que l insertion de la recompense : le credit, lui, doit rester intact pour que
            // l annulation de la transaction soit la seule raison qu il n ait pas eu lieu.
            if (!str_contains($requete, 'insert into') || !str_contains($requete, 'daily_rewards')) {
                return;
            }

            if (!$etat->doitLever()) {
                return;
            }

            // **La forme exacte que MariaDB rend.** C est ce message que `DetectsConcurrencyErrors` reconnait ;
            // un faux qui ne le porterait pas prouverait seulement qu une exception remonte.
            throw new QueryException(
                'mysql',
                $requete,
                [],
                new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
            );
        });

        return $etat;
    }

    private function compte(): User
    {
        return User::query()->findOrFail($this->currentUserId);
    }

    private function soldeDe(User $compte): int
    {
        return (int)User::query()->whereKey($compte->id)->value('dark_matter');
    }
}
