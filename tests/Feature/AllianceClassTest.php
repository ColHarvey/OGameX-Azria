<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Alliance;
use OGame\Models\AllianceRank;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use Tests\AccountTestCase;

/**
 * La classe d une alliance : qui peut la choisir, ce qu elle coute, ce qu elle devient.
 *
 * ## Ce que la page promettait sans rien tenir
 *
 * `resources/views/ingame/alliance/classes.blade.php` affichait trois classes a 400 000 de matiere
 * noire et disait, dans son propre code : « Alliance class selection will be implemented in the
 * future — for now, this is a placeholder view ». Aucune colonne, aucun achat, aucun bonus. Ce banc
 * eprouve la **fondation** : le choix, son prix, son droit, et la lecture du bonus par un membre.
 * Les douze bonus eux-memes ne sont pas encore appliques — ils viendront un par un, avec leurs
 * propres temoins.
 *
 * ## Le droit existait deja
 *
 * `AllianceRank::PERMISSION_MANAGE_CLASSES` est declare depuis la creation des rangs et n etait lu
 * nulle part. C est lui qui gouverne, plus le fondateur, comme partout ailleurs dans ce module.
 */
class AllianceClassTest extends AccountTestCase
{
    private function unTag(): string
    {
        return 'CL' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5);
    }

    private function unNom(): string
    {
        return 'Alliance de classe ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }

    /**
     * Une alliance dont le joueur courant est le fondateur.
     */
    private function uneAllianceFondee(): Alliance
    {
        $alliance = resolve(AllianceService::class)->createAlliance($this->currentUserId, $this->unTag(), $this->unNom());

        $this->assertNotNull($alliance);

        return $alliance;
    }

    private function leJoueur(): User
    {
        $joueur = User::query()->findOrFail($this->currentUserId);
        $joueur->refresh();

        return $joueur;
    }

    private function donnerDeLaMatiereNoire(int $montant): void
    {
        DB::table('users')->where('id', $this->currentUserId)->update(['dark_matter' => $montant]);
    }

    /**
     * Le fondateur choisit, paie, et l alliance porte sa classe.
     */
    public function testTheFounderChoosesPaysAndTheAllianceCarriesTheClass(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER + 1000);

        $classes = resolve(AllianceClassService::class);
        $this->assertNull($classes->classOfAlliance($alliance), 'La premisse tombe : l alliance a deja une classe.');

        $classes->choose($this->leJoueur(), $alliance, AllianceClass::TRADERS);

        $alliance->refresh();
        $this->assertSame(AllianceClass::TRADERS, $classes->classOfAlliance($alliance));
        $this->assertSame(AllianceClass::TRADERS->name, (string)DB::table('alliances')->where('id', (int)$alliance->id)->value('alliance_class'));

        // Paye, et la depense est tracee sous son propre genre.
        $this->assertSame(1000, (int)DB::table('users')->where('id', $this->currentUserId)->value('dark_matter'), 'Le prix n a pas ete debite, ou l a ete deux fois.');
        $this->assertTrue(
            DB::table('dark_matter_transactions')
                ->where('user_id', $this->currentUserId)
                ->where('type', DarkMatterTransactionType::ALLIANCE_CLASS->value)
                ->where('amount', -AllianceClass::PRICE_IN_DARK_MATTER)
                ->exists(),
            'La depense n est pas tracee sous le genre « classe d alliance ».'
        );

        // L instant est ecrit : il sert au joueur, a l exploitation et a toute regle de delai.
        $this->assertGreaterThan(0, (int)DB::table('alliances')->where('id', (int)$alliance->id)->value('alliance_class_selected_at'));
    }

    /**
     * **Un membre de l alliance beneficie de la classe ; un etranger, non.**
     *
     * C est la lecture dont les douze bonus dependront : elle part de `users.alliance_id`, donc
     * quitter l alliance fait perdre le bonus des la requete suivante.
     */
    public function testAMemberBenefitsAndAnOutsiderDoesNot(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER);

        $classes = resolve(AllianceClassService::class);
        $classes->choose($this->leJoueur(), $alliance, AllianceClass::WARRIORS);

        $this->assertSame(AllianceClass::WARRIORS, $classes->classOf($this->leJoueur()));
        $this->assertTrue($classes->isWarriors($this->leJoueur()));
        $this->assertFalse($classes->isTraders($this->leJoueur()));

        // Un joueur sans alliance ne beneficie de rien.
        $etranger = User::factory()->create();
        $this->assertNull($classes->classOf($etranger));
        $this->assertFalse($classes->isWarriors($etranger));
    }

    /**
     * **Un joueur sans alliance ne coute aucune requete**, et un membre n en coute qu une.
     *
     * Ce service est interroge sur les chemins les plus chauds du jeu — la production d une planete
     * le consulterait une fois par batiment. Le garde « pas d alliance » et le cache par requete ne
     * changent rien a ce que le joueur voit : ils ne se prouvent donc pas par une valeur, mais par
     * le nombre de requetes. Sans ce temoin, les retirer serait invisible.
     */
    public function testTheServiceCostsNoQueryWithoutAnAllianceAndOneWithOne(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER);
        resolve(AllianceClassService::class)->choose($this->leJoueur(), $alliance, AllianceClass::TRADERS);

        $membre = $this->leJoueur();
        $etranger = User::factory()->create();

        // Sans alliance : rien ne part vers la base, meme en demandant dix fois.
        $classes = resolve(AllianceClassService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($classes->classOf($etranger));
        }

        $this->assertCount(0, DB::getQueryLog(), 'Un joueur sans alliance interroge la base : le garde est tombe.');

        // Avec une alliance : une seule requete, quel que soit le nombre de demandes.
        DB::flushQueryLog();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(AllianceClass::TRADERS, $classes->classOf($membre));
        }

        $this->assertCount(1, DB::getQueryLog(), 'La classe est relue a chaque demande : le cache par requete est tombe.');
        DB::disableQueryLog();
    }

    /**
     * Quitter l alliance fait perdre le bonus — la lecture part du compte, pas d une copie.
     */
    public function testLeavingTheAllianceLosesTheBonus(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER);

        $classes = resolve(AllianceClassService::class);
        $classes->choose($this->leJoueur(), $alliance, AllianceClass::RESEARCHERS);
        $this->assertSame(AllianceClass::RESEARCHERS, $classes->classOf($this->leJoueur()));

        DB::table('users')->where('id', $this->currentUserId)->update(['alliance_id' => null]);

        // Un service neuf : le cache par requete ne doit pas tenir lieu de verite entre requetes.
        $this->assertNull(resolve(AllianceClassService::class)->classOf($this->leJoueur()));
    }

    /**
     * Sans la matiere noire, rien n est choisi et rien n est debite.
     */
    public function testWithoutTheDarkMatterNothingIsChosenAndNothingIsCharged(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER - 1);

        $classes = resolve(AllianceClassService::class);

        try {
            $classes->choose($this->leJoueur(), $alliance, AllianceClass::TRADERS);
            $this->fail('Une alliance a pris une classe sans pouvoir la payer.');
        } catch (Exception $e) {
            // Le banc tourne en anglais : comparer a la phrase traduite, jamais a un mot d une langue.
            $this->assertSame(__('t_ingame.alliance.class_not_enough_dark_matter', ['price' => number_format(AllianceClass::PRICE_IN_DARK_MATTER, 0, ',', '.')]), $e->getMessage());
            $this->assertStringNotContainsString(':price', $e->getMessage(), 'Le joueur lit un espace reserve au lieu du prix.');
        }

        $alliance->refresh();
        $this->assertNull($classes->classOfAlliance($alliance));
        $this->assertSame(AllianceClass::PRICE_IN_DARK_MATTER - 1, (int)DB::table('users')->where('id', $this->currentUserId)->value('dark_matter'), 'Un refus a quand meme debite le joueur.');
    }

    /**
     * Un membre sans le droit ne choisit pas, meme riche.
     */
    public function testAMemberWithoutThePermissionCannotChoose(): void
    {
        $alliance = $this->uneAllianceFondee();

        // Un second joueur, membre de l alliance, sans aucun droit particulier.
        $membre = User::factory()->create(['alliance_id' => (int)$alliance->id, 'dark_matter' => AllianceClass::PRICE_IN_DARK_MATTER * 2]);
        $rang = AllianceRank::query()->create([
            'alliance_id' => (int)$alliance->id,
            'rank_name' => 'Bleu',
            'permissions' => [AllianceRank::PERMISSION_SEE_MEMBERS],
            'sort_order' => 9,
        ]);
        DB::table('alliance_members')->insert([
            'alliance_id' => (int)$alliance->id,
            'user_id' => (int)$membre->id,
            'rank_id' => (int)$rang->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // **Le solde est lu, pas suppose** : la creation d un compte credite un bonus initial.
        $avant = (int)DB::table('users')->where('id', (int)$membre->id)->value('dark_matter');
        $this->assertGreaterThanOrEqual(AllianceClass::PRICE_IN_DARK_MATTER, $avant, 'La premisse tombe : le membre ne pourrait pas payer de toute facon.');

        $classes = resolve(AllianceClassService::class);
        $this->assertFalse($classes->mayChooseFor($membre, $alliance), 'Un membre sans le droit « gerer les classes » peut choisir.');

        try {
            $classes->choose($membre, $alliance, AllianceClass::WARRIORS);
            $this->fail('Un membre sans droit a choisi la classe de son alliance.');
        } catch (Exception $e) {
            $this->assertSame(__('t_ingame.alliance.class_not_allowed'), $e->getMessage());
        }

        $alliance->refresh();
        $this->assertNull($classes->classOfAlliance($alliance));
        $this->assertSame($avant, (int)DB::table('users')->where('id', (int)$membre->id)->value('dark_matter'), 'Un refus a quand meme debite le membre.');
    }

    /**
     * **Le droit `manage_classes` suffit** : ce n est pas reserve au fondateur.
     */
    public function testAMemberWithTheManageClassesPermissionMayChoose(): void
    {
        $alliance = $this->uneAllianceFondee();

        $membre = User::factory()->create(['alliance_id' => (int)$alliance->id, 'dark_matter' => AllianceClass::PRICE_IN_DARK_MATTER]);
        $rang = AllianceRank::query()->create([
            'alliance_id' => (int)$alliance->id,
            'rank_name' => 'Intendant',
            'permissions' => [AllianceRank::PERMISSION_MANAGE_CLASSES],
            'sort_order' => 5,
        ]);
        DB::table('alliance_members')->insert([
            'alliance_id' => (int)$alliance->id,
            'user_id' => (int)$membre->id,
            'rank_id' => (int)$rang->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $avant = (int)DB::table('users')->where('id', (int)$membre->id)->value('dark_matter');

        $classes = resolve(AllianceClassService::class);
        $this->assertTrue($classes->mayChooseFor($membre, $alliance));

        $classes->choose($membre, $alliance, AllianceClass::WARRIORS);

        $alliance->refresh();
        $this->assertSame(AllianceClass::WARRIORS, $classes->classOfAlliance($alliance));
        $this->assertSame($avant - AllianceClass::PRICE_IN_DARK_MATTER, (int)DB::table('users')->where('id', (int)$membre->id)->value('dark_matter'), 'C est celui qui choisit qui paie.');
    }

    /**
     * Reprendre la classe qu on a deja est refuse — et ne coute rien.
     */
    public function testChoosingTheSameClassAgainIsRefusedAndFree(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER * 2);

        $classes = resolve(AllianceClassService::class);
        $classes->choose($this->leJoueur(), $alliance, AllianceClass::TRADERS);

        $restant = (int)DB::table('users')->where('id', $this->currentUserId)->value('dark_matter');

        try {
            $classes->choose($this->leJoueur(), $alliance, AllianceClass::TRADERS);
            $this->fail('Une alliance a rachete la classe qu elle avait deja.');
        } catch (Exception $e) {
            $this->assertSame(__('t_ingame.alliance.class_already_selected'), $e->getMessage());
        }

        $this->assertSame($restant, (int)DB::table('users')->where('id', $this->currentUserId)->value('dark_matter'), 'Racheter la meme classe a debite le joueur.');
    }

    /**
     * Changer de classe se paie, et remplace la precedente.
     */
    public function testChangingClassCostsAgainAndReplacesTheFormerOne(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER * 2);

        $classes = resolve(AllianceClassService::class);
        $classes->choose($this->leJoueur(), $alliance, AllianceClass::TRADERS);
        $classes->choose($this->leJoueur(), $alliance, AllianceClass::RESEARCHERS);

        $alliance->refresh();
        $this->assertSame(AllianceClass::RESEARCHERS, $classes->classOfAlliance($alliance));
        $this->assertSame(0, (int)DB::table('users')->where('id', $this->currentUserId)->value('dark_matter'), 'Le changement de classe n a pas ete paye.');
    }

    /**
     * Une colonne qui ne dit rien d utilisable ne donne aucun bonus, et ne leve pas.
     */
    public function testAnUnknownStoredClassGrantsNothing(): void
    {
        $alliance = $this->uneAllianceFondee();
        DB::table('alliances')->where('id', (int)$alliance->id)->update(['alliance_class' => 'VAGABONDS']);
        $alliance->refresh();

        $classes = resolve(AllianceClassService::class);
        $this->assertNull($classes->classOfAlliance($alliance));
        $this->assertNull($classes->classOf($this->leJoueur()));
    }

    /**
     * La page dit la classe active, et n offre le geste qu a qui peut l accomplir.
     */
    public function testThePageShowsTheActiveClassAndOnlyOffersWhatIsPossible(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(0);

        // Sans matiere noire : aucun bouton actif, et la page le dit.
        $page = (string)$this->getJson(route('alliance.ajax.classes'))->assertStatus(200)->json('content.alliance/alliance_classes');

        $this->assertStringContainsString(__('t_ingame.alliance.class_none_selected'), $page, 'La page ne dit pas qu aucune classe n est choisie.');
        // **La forme du lien, pas le mot** : « allianceclass-choose » vit aussi dans le script de la page.
        $this->assertStringNotContainsString('class="build-it js_hideTipOnMobile allianceclass-choose"', $page, 'La page offre le geste a un joueur qui ne peut pas payer.');

        // Avec la monnaie : les trois gestes sont offerts.
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER);
        $page = (string)$this->getJson(route('alliance.ajax.classes'))->assertStatus(200)->json('content.alliance/alliance_classes');

        $this->assertSame(3, substr_count($page, 'class="build-it js_hideTipOnMobile allianceclass-choose"'), 'Les trois classes ne sont pas offertes a qui peut les payer.');

        // Une fois choisie, la classe active est nommee et n est plus offerte.
        resolve(AllianceClassService::class)->choose($this->leJoueur(), $alliance, AllianceClass::WARRIORS);
        $page = (string)$this->getJson(route('alliance.ajax.classes'))->assertStatus(200)->json('content.alliance/alliance_classes');

        $this->assertStringContainsString(AllianceClass::WARRIORS->getName(), $page);
        $this->assertStringNotContainsString(__('t_ingame.alliance.class_none_selected'), $page, 'La page dit encore qu aucune classe n est choisie.');
    }

    /**
     * La page ne promet plus ce qu elle ne tient pas.
     */
    public function testThePageNoLongerSaysTheFeatureIsUnimplemented(): void
    {
        $this->uneAllianceFondee();

        $page = (string)$this->getJson(route('alliance.ajax.classes'))->assertStatus(200)->json('content.alliance/alliance_classes');

        $this->assertStringNotContainsString(__('t_ingame.alliance.class_not_implemented'), $page, 'La page dit encore au joueur que la fonction n existe pas.');
        $this->assertStringNotContainsString('TODO', $page);
    }

    /**
     * L action HTTP refuse un identifiant de classe inconnu, sans rien debiter.
     */
    public function testTheHttpActionRefusesAnUnknownClass(): void
    {
        $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER);

        $this->postJson(route('alliance.classes.choose'), ['alliance_class_id' => 99])->assertStatus(400);

        $this->assertSame(AllianceClass::PRICE_IN_DARK_MATTER, (int)DB::table('users')->where('id', $this->currentUserId)->value('dark_matter'));
    }

    /**
     * L action HTTP choisit pour de vrai, et repond au joueur dans sa langue.
     */
    public function testTheHttpActionChoosesAndAnswersThePlayer(): void
    {
        $alliance = $this->uneAllianceFondee();
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER);

        $reponse = $this->postJson(route('alliance.classes.choose'), ['alliance_class_id' => AllianceClass::TRADERS->value])
            ->assertStatus(200)
            ->json();

        $this->assertSame('success', $reponse['status']);
        $this->assertStringContainsString(AllianceClass::TRADERS->getName(), (string)$reponse['message']);
        $this->assertStringNotContainsString('t_ingame.', (string)$reponse['message'], 'Le joueur lit une clef de traduction au lieu d une phrase.');

        $alliance->refresh();
        $this->assertSame(AllianceClass::TRADERS, resolve(AllianceClassService::class)->classOfAlliance($alliance));
    }

    /**
     * Un joueur sans alliance ne peut rien choisir.
     */
    public function testAPlayerWithoutAnAllianceCannotChoose(): void
    {
        $this->donnerDeLaMatiereNoire(AllianceClass::PRICE_IN_DARK_MATTER);

        $this->postJson(route('alliance.classes.choose'), ['alliance_class_id' => AllianceClass::WARRIORS->value])->assertStatus(400);
    }
}
