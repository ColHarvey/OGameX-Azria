<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Lang;
use OGame\Models\DailyReward;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\User;
use OGame\Services\DailyRewardService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **La recompense quotidienne de connexion** — fonctionnalite propre a Azria, demandee par Keven le
 * 20 septembre 2026. Elle n imite aucune regle d OGame et ne se presente pas comme telle.
 *
 * Les regles que ces temoins tiennent :
 *
 * - mille de matiere noire, **par compte et par journee**, jamais par planete ;
 * - **renouvellement a minuit**, heure du serveur — pas vingt-quatre heures apres la derniere reclamation ;
 * - **aucun cumul, aucun rattrapage** : une journee sautee est perdue ;
 * - **se connecter ne credite rien** : il faut le geste ;
 * - **desarmee par defaut**, et elle le reste tant que Keven n a pas donne son accord.
 */
final class DailyRewardTest extends AccountTestCase
{
    use PinsSettings;

    private function service(): DailyRewardService
    {
        return resolve(DailyRewardService::class);
    }

    private function compte(): User
    {
        return User::query()->findOrFail($this->currentUserId);
    }

    private function matiereNoire(): int
    {
        return (int)$this->compte()->dark_matter;
    }

    protected function tearDown(): void
    {
        DailyReward::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * **Desarmee par defaut.** Le reglage n existe pas en base au depart : la valeur initiale doit fermer la
     * fonctionnalite, pas l ouvrir.
     */
    public function testTheFeatureIsClosedUntilItIsExplicitlyOpened(): void
    {
        // **L absence se pose, elle ne se suppose pas.** Cet essai mesure ce que rend le code quand la table ne
        // porte rien ; sur la base partagee d un processus, une classe voisine a pu ecrire la ligne.
        $this->removeSettings(['daily_reward_enabled', 'daily_reward_amount']);

        $etat = $this->service()->stateFor($this->compte(), Date::now());
        $this->assertFalse($etat['enabled'], 'La recompense quotidienne est ouverte par defaut.');

        $avant = $this->matiereNoire();
        $this->assertSame(DailyRewardService::CLOSED, $this->service()->claim($this->compte(), Date::now()));
        $this->assertSame($avant, $this->matiereNoire(), 'Un compte a ete credite alors que la fonctionnalite est fermee.');
        $this->assertSame(0, DailyReward::query()->where('user_id', $this->currentUserId)->count());
    }

    /**
     * La premiere reclamation credite le montant regle, une fois, par le circuit de la matiere noire **gratuite**.
     */
    public function testTheFirstClaimCreditsExactlyTheConfiguredAmount(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $avant = $this->matiereNoire();

        $this->assertSame(DailyRewardService::CLAIMED, $this->service()->claim($this->compte(), Date::now()));

        $this->assertSame($avant + 1000, $this->matiereNoire(), 'Le credit ne vaut pas le montant regle.');

        // Et la transaction porte le type dedie — ni regeneration, ni recompense d evenement.
        $transaction = DarkMatterTransaction::query()
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($transaction);
        $this->assertSame('daily_reward', $transaction->type, 'Le credit emprunte le type d un autre systeme.');
        $this->assertSame(1000, (int)$transaction->amount);
    }

    /**
     * **Se connecter ne credite rien.** Lire l etat, charger une page, changer de planete : aucun credit.
     */
    public function testMerelyLookingAtTheStateNeverCredits(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1]);
        $avant = $this->matiereNoire();

        $this->service()->stateFor($this->compte(), Date::now());
        $this->get(route('overview.index'))->assertStatus(200);
        $this->get(route('daily_reward.state'))->assertStatus(200);

        $this->assertSame($avant, $this->matiereNoire(), 'Une simple lecture a credite le compte.');
        $this->assertSame(0, DailyReward::query()->where('user_id', $this->currentUserId)->count());
    }

    /**
     * **Deux clics, un seul credit.** La seconde demande rend « deja reclamee » et ne touche a rien.
     */
    public function testAsecondClaimOnTheSameDayCreditsNothing(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $avant = $this->matiereNoire();

        $this->assertSame(DailyRewardService::CLAIMED, $this->service()->claim($this->compte(), Date::now()));
        $this->assertSame(DailyRewardService::ALREADY, $this->service()->claim($this->compte(), Date::now()));
        $this->assertSame(DailyRewardService::ALREADY, $this->service()->claim($this->compte(), Date::now()));

        $this->assertSame($avant + 1000, $this->matiereNoire(), 'Le compte a ete credite plus d une fois.');
        $this->assertSame(1, DailyReward::query()->where('user_id', $this->currentUserId)->count());
    }

    /**
     * **Le renouvellement est minuit, pas vingt-quatre heures.** L exemple de Keven, joue tel quel : lundi
     * 23 h 55 on reclame ; mardi a 00 h 00 la suivante est disponible — cinq minutes plus tard, pas vingt-quatre
     * heures.
     */
    public function testTheRenewalIsMidnightAndNotTwentyFourHoursLater(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $avant = $this->matiereNoire();

        $lundiSoir = Date::parse('2026-09-21 23:55:00', config('app.timezone'));
        $this->assertSame(DailyRewardService::CLAIMED, $this->service()->claim($this->compte(), $lundiSoir));
        $this->assertSame($avant + 1000, $this->matiereNoire());

        // Une minute avant minuit : toujours la meme journee, rien de plus.
        $avantMinuit = Date::parse('2026-09-21 23:59:59', config('app.timezone'));
        $this->assertTrue($this->service()->alreadyClaimed($this->compte(), $avantMinuit));
        $this->assertSame(DailyRewardService::ALREADY, $this->service()->claim($this->compte(), $avantMinuit));
        $this->assertSame($avant + 1000, $this->matiereNoire());

        // Minuit pile : journee neuve.
        $minuit = Date::parse('2026-09-22 00:00:00', config('app.timezone'));
        $this->assertFalse($this->service()->alreadyClaimed($this->compte(), $minuit), 'La journee n a pas tourne a minuit.');
        $this->assertSame(DailyRewardService::CLAIMED, $this->service()->claim($this->compte(), $minuit));
        $this->assertSame($avant + 2000, $this->matiereNoire(), 'La recompense du mardi n a pas ete creditee.');

        // **Premisse de l exemple** : entre les deux reclamations il s est ecoule cinq minutes, pas un jour.
        $this->assertSame(300, $minuit->getTimestamp() - $lundiSoir->getTimestamp());
    }

    /**
     * **Aucun cumul, aucun rattrapage.** Trois journees sautees ne donnent pas quatre recompenses.
     */
    public function testMissedDaysAreLostAndNeverStack(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $avant = $this->matiereNoire();

        $this->service()->claim($this->compte(), Date::parse('2026-09-21 10:00:00', config('app.timezone')));
        // Puis plus rien pendant trois jours.
        $retour = Date::parse('2026-09-25 10:00:00', config('app.timezone'));
        $this->assertSame(DailyRewardService::CLAIMED, $this->service()->claim($this->compte(), $retour));

        $this->assertSame($avant + 2000, $this->matiereNoire(), 'Les journees sautees ont ete rattrapees.');
        $this->assertSame(2, DailyReward::query()->where('user_id', $this->currentUserId)->count());
    }

    /**
     * **Une par compte, jamais par planete.** Changer de planete ne rouvre pas la recompense.
     */
    public function testTheRewardIsPerAccountAndNotPerPlanet(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $avant = $this->matiereNoire();
        $maintenant = Date::now();

        $this->assertSame(DailyRewardService::CLAIMED, $this->service()->claim($this->compte(), $maintenant));

        // Premisse : le compte porte bien plus d une planete, sinon ce temoin ne distingue rien.
        $planetes = $this->planetService->getPlayer()?->planets->all() ?? [];
        $this->assertGreaterThan(1, count($planetes), 'Le banc doit porter au moins deux planetes.');
        $this->planetService->getPlayer()?->setCurrentPlanetId($planetes[1]->getPlanetId());

        $this->assertSame(DailyRewardService::ALREADY, $this->service()->claim($this->compte(), $maintenant));
        $this->assertSame($avant + 1000, $this->matiereNoire(), 'Une seconde planete a ouvert une seconde recompense.');
    }

    /**
     * L etat rendu au navigateur : des **secondes restantes**, jamais un instant absolu, et le fuseau nomme.
     */
    public function testTheStatePublishesSecondsAndNeverAnAbsoluteInstant(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1]);

        $midi = Date::parse('2026-09-21 12:00:00', config('app.timezone'));
        $etat = $this->service()->stateFor($this->compte(), $midi);

        $this->assertSame(12 * 3600, $etat['seconds_remaining'], 'Le temps jusqu a minuit n est pas celui du serveur.');
        $this->assertSame($midi->getTimestamp(), $etat['server_now']);
        $this->assertSame(config('app.timezone'), $etat['timezone']);
        $this->assertFalse($etat['claimed']);
    }

    /**
     * La route de reclamation credite, et rend de quoi reecrire le compteur **sans recharger la page**.
     */
    public function testTheRouteCreditsOnceAndReturnsWhatThePageMustRewrite(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $avant = $this->matiereNoire();

        $premiere = $this->post(route('daily_reward.claim'), ['_token' => csrf_token()]);
        $premiere->assertStatus(200);
        $premiere->assertJsonPath('success', true);
        $premiere->assertJsonPath('claimed_now', true);
        $premiere->assertJsonPath('claimed', true);
        $premiere->assertJsonPath('dark_matter', $avant + 1000);
        $this->assertIsString($premiere->json('dark_matter_formatted'));

        // **Une reponse perdue, puis une nouvelle tentative** : elle retrouve la reclamation sans recrediter.
        $seconde = $this->post(route('daily_reward.claim'), ['_token' => csrf_token()]);
        $seconde->assertStatus(200);
        $seconde->assertJsonPath('success', true);
        $seconde->assertJsonPath('claimed_now', false);
        $seconde->assertJsonPath('dark_matter', $avant + 1000);

        $this->assertSame($avant + 1000, $this->matiereNoire());
        $this->assertSame(1, DailyReward::query()->where('user_id', $this->currentUserId)->count());
    }

    /**
     * **La route credite LE compte de la session.** Defaut trouve au navigateur, pas par les essais : les
     * routes vivaient hors de `globalgame`, ou `PlayerService` n est pas charge — `getUser()->id` valait zero
     * et l insertion tombait sur la cle etrangere. Les essais passaient leur propre modele au service et ne
     * traversaient donc jamais ce chemin.
     */
    public function testTheRouteCreditsTheAccountOfTheSessionAndNotAccountZero(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);

        $this->post(route('daily_reward.claim'), ['_token' => csrf_token()])->assertStatus(200);

        $lignes = DailyReward::query()->get();
        $this->assertCount(1, $lignes);
        $ligne = $lignes->first();
        $this->assertInstanceOf(DailyReward::class, $ligne);
        $this->assertSame($this->currentUserId, $ligne->user_id, 'La reclamation a ete ecrite sur un autre compte.');
        $this->assertNotSame(0, $ligne->user_id, 'Le compte zero n existe pas : le service joueur n etait pas charge.');
    }

    /**
     * Fermee, la route refuse et ne credite rien.
     */
    public function testTheRouteRefusesWhenTheFeatureIsClosed(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 0]);
        $avant = $this->matiereNoire();

        $reponse = $this->post(route('daily_reward.claim'), ['_token' => csrf_token()]);
        $reponse->assertStatus(409);
        $reponse->assertJsonPath('success', false);

        $this->assertSame($avant, $this->matiereNoire());
    }

    /**
     * **Le bouton n apparait que si la fonctionnalite est ouverte**, et il dit son etat autrement que par la
     * couleur.
     */
    public function testTheHeaderButtonAppearsOnlyWhenOpenAndNamesItsState(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 0]);
        $fermee = (string)$this->get(route('overview.index'))->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('id="dailyRewardButton"', $fermee, 'Le bouton s affiche alors que la fonctionnalite est fermee.');

        $this->pinSettings(['daily_reward_enabled' => 1]);
        $ouverte = (string)$this->get(route('overview.index'))->assertStatus(200)->getContent();
        $this->assertStringContainsString('id="dailyRewardButton"', $ouverte);
        $this->assertStringContainsString(e(__('t_ingame.daily_reward.button_available')), $ouverte, 'Le bouton ne dit pas son etat.');
        // L image est decorative : le bouton porte deja le nom.
        $this->assertStringContainsString('alt=""', $ouverte);
        // Et il ne remplace pas le lien d achat existant.
        $this->assertStringContainsString('#TODO_page=payment', $ouverte, 'Le lien d achat de matiere noire a disparu.');

        // Une fois reclamee, le meme bouton reste la et change de nom.
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $this->service()->claim($this->compte(), Date::now());
        $apres = (string)$this->get(route('overview.index'))->assertStatus(200)->getContent();
        $this->assertStringContainsString('az-daily-gift--claimed', $apres);
        $this->assertStringContainsString(e(__('t_ingame.daily_reward.button_claimed')), $apres);
    }

    /**
     * La fenetre dit le renouvellement en clair et porte la regle du non-cumul.
     */
    public function testTheWindowStatesTheRenewalAndTheNoCarryOverRule(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);

        $fenetre = (string)$this->get(route('daily_reward.overlay'))->assertStatus(200)->getContent();

        $this->assertStringContainsString(e(__('t_ingame.daily_reward.renewal')), $fenetre);
        $this->assertStringContainsString(e(__('t_ingame.daily_reward.no_carry_over')), $fenetre);
        $this->assertStringContainsString(e(__('t_ingame.daily_reward.claim')), $fenetre);
        // L illustration existante de matiere noire, pas une image nouvelle.
        $this->assertStringContainsString('officers100 darkMatter', $fenetre);
        // Et des secondes, jamais un instant absolu.
        $this->assertStringContainsString('data-seconds=', $fenetre);
        $this->assertStringNotContainsString('data-renews-at', $fenetre);
    }

    /**
     * **Ni la regeneration periodique ni les recompenses d evenement ne sont touchees.** Trois mecaniques, trois
     * jeux de reglages, trois types de transaction.
     */
    public function testTheExistingDarkMatterSystemsAreLeftAlone(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);

        $compte = $this->compte();
        $regenAvant = $compte->dark_matter_last_regen;

        $this->service()->claim($compte, Date::now());

        $this->assertSame(
            $regenAvant?->toDateTimeString(),
            $this->compte()->dark_matter_last_regen?->toDateTimeString(),
            'La recompense quotidienne a touche a la regeneration periodique.'
        );

        // Aucune transaction de regeneration ni d evenement n a ete ecrite par ce chemin.
        $types = DarkMatterTransaction::query()
            ->where('user_id', $this->currentUserId)
            ->pluck('type')
            ->unique()
            ->values()
            ->all();
        $this->assertNotContains('regeneration', $types);
        $this->assertNotContains('event_reward', $types);
        $this->assertContains('daily_reward', $types);
    }

    /**
     * **L echeance se calcule, elle ne se force pas.**
     *
     * Le banc navigateur simule minuit en effacant la ligne et en mettant le compte a rebours a un : il
     * prouve la **resynchronisation**, pas le calcul. Celui-ci se prouve ici, avec l horloge de test et rien
     * d autre — on avance l heure du serveur seconde par seconde autour de minuit et on lit ce que le service
     * en deduit.
     */
    public function testTheDeadlineIsComputedFromTheServerClockAlone(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1]);
        $fuseau = config('app.timezone');
        $compte = $this->compte();

        // Quatre instants autour de minuit, et ce que le serveur doit en dire.
        $attendus = [
            '2026-09-21 00:00:00' => 86400,   // minuit pile : la journee entiere reste
            '2026-09-21 12:00:00' => 43200,   // midi : la moitie
            '2026-09-21 23:59:00' => 60,      // une minute avant
            '2026-09-21 23:59:59' => 1,       // une seconde avant
        ];

        foreach ($attendus as $instant => $restant) {
            $maintenant = Date::parse($instant, $fuseau);
            $etat = $this->service()->stateFor($compte, $maintenant);

            $this->assertSame($restant, $etat['seconds_remaining'], "A $instant il doit rester $restant secondes.");
            // **La journee, elle, ne change pas avant minuit.**
            $this->assertSame('2026-09-21', $this->service()->serverDay($maintenant), "A $instant on est encore le 21.");
            $this->assertSame(
                '2026-09-22 00:00:00',
                $this->service()->nextRenewal($maintenant)->format('Y-m-d H:i:s'),
                "A $instant le renouvellement vise minuit, pas vingt-quatre heures plus tard."
            );
        }

        // **Une seconde plus tard, tout a bascule** — et la marche est d exactement une seconde.
        $juste = Date::parse('2026-09-22 00:00:00', $fuseau);
        $this->assertSame('2026-09-22', $this->service()->serverDay($juste), 'La journee n a pas tourne a minuit.');
        $this->assertSame(86400, $this->service()->stateFor($compte, $juste)['seconds_remaining']);
        $this->assertSame(
            '2026-09-23 00:00:00',
            $this->service()->nextRenewal($juste)->format('Y-m-d H:i:s'),
            'Le renouvellement suivant n est pas le minuit d apres.'
        );
        $this->assertSame(
            1,
            $juste->getTimestamp() - Date::parse('2026-09-21 23:59:59', $fuseau)->getTimestamp(),
            'Premisse : ces deux instants sont bien separes d une seconde.'
        );
    }

    /**
     * **L echeance ne depend d aucune reclamation.** Reclamer a 23 h 55 ne repousse pas le renouvellement de
     * vingt-quatre heures : il reste minuit.
     */
    public function testClaimingDoesNotPushTheDeadlineAway(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);
        $fuseau = config('app.timezone');

        $tard = Date::parse('2026-09-21 23:55:00', $fuseau);
        $avantReclamation = $this->service()->stateFor($this->compte(), $tard)['seconds_remaining'];

        $this->service()->claim($this->compte(), $tard);

        $apresReclamation = $this->service()->stateFor($this->compte(), $tard)['seconds_remaining'];

        $this->assertSame(300, $avantReclamation, 'Cinq minutes avant minuit, il reste trois cents secondes.');
        $this->assertSame(
            $avantReclamation,
            $apresReclamation,
            'La reclamation a deplace l echeance : elle doit rester minuit, quoi qu il arrive.'
        );
    }

    /**
     * **Le mode vacances n empeche pas la recompense** — decision de Keven, 20 septembre 2026.
     *
     * « Le joueur se connecte et reclame sa recompense, sans lancer de production ni d action militaire. »
     * C est un choix de jeu, pas une consequence du code : rien ne bloquait, et rien ne bloquera. Ce temoin
     * existe pour que la decision survive a la prochaine relecture — si quelqu un ajoute un refus en vacances,
     * il tombe et il faudra en reparler a Keven.
     *
     * **Les autres regles ne changent pas** : une fois par journee du serveur, aucun cumul, aucun rattrapage.
     */
    public function testVacationModeDoesNotPreventTheDailyReward(): void
    {
        $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => 1000]);

        $compte = $this->compte();
        $compte->vacation_mode = true;
        $compte->vacation_mode_activated_at = Date::now()->subDays(3);
        $compte->save();

        // Premisse : le compte est bien en vacances, sinon l essai ne mesure rien.
        $joueur = resolve(\OGame\Factories\PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertTrue($joueur->isInVacationMode(), 'Le banc n a pas reussi a mettre le compte en vacances.');

        $avant = $this->matiereNoire();

        // **Par la vraie route**, pas seulement par le service : c est la ou un refus serait pose.
        $reponse = $this->post(route('daily_reward.claim'), ['_token' => csrf_token()]);
        $reponse->assertStatus(200);
        $reponse->assertJsonPath('claimed_now', true);
        $this->assertSame($avant + 1000, $this->matiereNoire(), 'Un compte en vacances n a pas ete credite.');

        // Et les regles tiennent quand meme : une seule fois pour la journee.
        $this->post(route('daily_reward.claim'), ['_token' => csrf_token()])->assertJsonPath('claimed_now', false);
        $this->assertSame($avant + 1000, $this->matiereNoire(), 'Les vacances ont ouvert un second credit.');

        // Aucun rattrapage non plus : trois journees d absence ne valent qu une recompense au retour.
        $retour = Date::now()->addDays(4);
        $this->assertSame(DailyRewardService::CLAIMED, $this->service()->claim($this->compte(), $retour));
        $this->assertSame($avant + 2000, $this->matiereNoire(), 'Les journees d absence ont ete rattrapees.');

        $compte->vacation_mode = false;
        $compte->vacation_mode_activated_at = null;
        $compte->save();
    }

    /**
     * Les douze libelles existent dans les cinq langues : une clef absente rendrait l anglais en silence.
     */
    public function testEveryLabelExistsInEveryLanguage(): void
    {
        $clefs = [
            'title', 'button_available', 'button_claimed', 'claim', 'already_short', 'claimed',
            'already', 'closed', 'renewal', 'expires_in', 'next_in', 'no_carry_over',
        ];

        foreach (['fr', 'en', 'it', 'nl', 'zh-TW'] as $langue) {
            foreach ($clefs as $clef) {
                $this->assertTrue(
                    Lang::has('t_ingame.daily_reward.' . $clef, $langue, false),
                    "La clef $clef manque en $langue : le joueur lirait l anglais sans que rien ne le signale."
                );
            }
        }
    }

    /**
     * Un montant absurde en base ne credite pas n importe quoi.
     */
    public function testAnAbsurdConfiguredAmountFallsBackInsteadOfCreditingNonsense(): void
    {
        foreach (['0', '-500', 'beaucoup', ''] as $absurde) {
            $this->pinSettings(['daily_reward_enabled' => 1, 'daily_reward_amount' => $absurde]);
            $this->assertSame(
                1000,
                $this->service()->stateFor($this->compte(), Date::now())['amount'],
                "Le montant « $absurde » n a pas ete ecarte."
            );
        }
    }
}
