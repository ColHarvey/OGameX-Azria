<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Models\AnnouncementBubble;
use OGame\Models\AnnouncementBubbleDismissal;
use OGame\Models\AnnouncementBubbleVersion;
use OGame\Models\User;
use OGame\Services\AnnouncementBubbleService;
use Tests\AccountTestCase;

/**
 * **Les deux formulaires de la page des annonces ne se melangent pas.**
 *
 * Keven, 20 septembre 2026 : « Les sacs d erreurs nommes isolent les erreurs, pas automatiquement les anciennes
 * saisies. Donne aux champs de la bulle des noms distincts, par exemple bulle[title], bulle[body], etc., et
 * conserve le bon onglet apres validation refusee. »
 *
 * Il a raison, et les deux mecanismes sont **necessaires** :
 *
 * - le **sac nomme** isole les erreurs, pour que le formulaire des messages n affiche pas celles de la bulle ;
 * - les **noms prefixes** isolent `old()`, qui est un seul depot partage par toute la requete. Le champ `body`
 *   existe des deux cotes : sans prefixe, une saisie refusee d un formulaire repeuplerait l autre.
 *
 * Ces essais mesurent donc les deux, et l onglet rouvert.
 */
class AnnouncementFormsAreIndependentTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AnnouncementBubbleDismissal::query()->delete();
        AnnouncementBubbleVersion::query()->delete();
        AnnouncementBubble::query()->delete();

        $compte = User::query()->findOrFail($this->currentUserId);
        $compte->assignRole('admin');
        $this->assertTrue($compte->fresh()?->hasRole('admin') ?? false, 'Premisse : le compte est administrateur.');
        $this->actingAs($compte);
    }

    protected function tearDown(): void
    {
        $compte = User::query()->find($this->currentUserId);
        if ($compte !== null && $compte->hasRole('admin')) {
            $compte->removeRole('admin');
        }

        parent::tearDown();
    }

    /**
     * **Un refus de la bulle ne repeuple pas le formulaire des messages.**
     *
     * C est le cas exact que les noms prefixes ferment : les deux formulaires ont un champ `body`.
     */
    public function testARefusedBubbleDoesNotRefillTheMessageForm(): void
    {
        $reponse = $this->from(route('admin.announcement.index'))->post(route('admin.announcement.bubble.save'), [
            'bulle' => [
                'title' => '',                       // refuse : le titre est obligatoire
                'body' => 'CORPS DE LA BULLE',
                'link_url' => '/overview',
                'link_label' => 'Voir',
            ],
        ]);

        $reponse->assertSessionHasErrors(['bulle.title'], null, 'bulle');

        $page = $this->get(route('admin.announcement.index'));
        $page->assertStatus(200);
        $html = $page->getContent();
        $this->assertIsString($html);

        // Le champ du formulaire des messages doit etre vide : on cherche sa FORME, pas le mot.
        $this->assertStringContainsString(
            '<textarea id="announcementBody" name="body" class="alliancetexts" required></textarea>',
            $html,
            'Le champ du formulaire des messages a ete repeuple par une saisie de la bulle.'
        );

        // Et celui de la bulle doit avoir garde la saisie.
        $this->assertStringContainsString('CORPS DE LA BULLE', $html, 'La saisie de la bulle a ete perdue.');
    }

    /**
     * **Un refus du formulaire des messages ne repeuple pas la bulle**, et ne montre pas ses erreurs.
     */
    public function testARefusedMessageDoesNotRefillTheBubbleForm(): void
    {
        $this->from(route('admin.announcement.index'))->post(route('admin.announcement.send'), [
            'subject' => '',                          // refuse : le sujet est obligatoire
            'body' => 'CORPS DU MESSAGE',
        ])->assertSessionHasErrors(['subject']);

        $page = $this->get(route('admin.announcement.index'));
        $html = $page->getContent();
        $this->assertIsString($html);

        // Le champ de la bulle porte la valeur du brouillon — vide ici —, jamais celle du message.
        $this->assertStringNotContainsString(
            'name="bulle[body]" class="alliancetexts">CORPS DU MESSAGE',
            $html,
            'Le champ de la bulle a ete repeuple par une saisie du formulaire des messages.'
        );
        $this->assertStringContainsString('CORPS DU MESSAGE', $html, 'Premisse : la saisie du message est bien conservee.');
    }

    /**
     * **L onglet de la bulle se rouvre apres un refus de la bulle.**
     */
    public function testARefusedBubbleReopensTheBubbleTab(): void
    {
        $this->from(route('admin.announcement.index'))->post(route('admin.announcement.bubble.save'), [
            'bulle' => ['title' => '', 'body' => 'x', 'link_url' => null, 'link_label' => null],
        ]);

        $html = $this->get(route('admin.announcement.index'))->getContent();
        $this->assertIsString($html);

        $this->assertStringContainsString("tabs({ active: 1 })", $html, 'L onglet de la bulle ne se rouvre pas.');
        $this->assertStringContainsString('id="tab-message"', $html, 'Premisse : le panneau des messages existe.');
        $this->assertStringContainsString('aria-hidden="true"', $html, 'Aucun panneau n est annonce masque.');
    }

    /**
     * **L onglet des messages reste celui d un refus des messages.**
     */
    public function testARefusedMessageKeepsTheMessageTab(): void
    {
        $this->from(route('admin.announcement.index'))->post(route('admin.announcement.send'), [
            'subject' => '', 'body' => 'x',
        ]);

        $html = $this->get(route('admin.announcement.index'))->getContent();
        $this->assertIsString($html);

        $this->assertStringContainsString("tabs({ active: 0 })", $html, 'Un refus des messages a ouvert l onglet de la bulle.');
    }

    /**
     * **Les erreurs de la bulle ne s affichent pas dans l onglet des messages**, et reciproquement.
     *
     * Le sac nomme est ce qui le garantit ; sans lui, `$errors->all()` melangerait les deux.
     */
    public function testEachTabShowsOnlyItsOwnErrors(): void
    {
        $this->from(route('admin.announcement.index'))->post(route('admin.announcement.bubble.save'), [
            'bulle' => ['title' => '', 'body' => 'x', 'link_url' => 'javascript:alert(1)', 'link_label' => null],
        ]);

        $html = $this->get(route('admin.announcement.index'))->getContent();
        $this->assertIsString($html);

        // Le message de refus du lien est celui de la bulle : il ne doit paraitre qu une fois.
        $refus = __('t_ingame.announcement.link_refused');
        $this->assertSame(
            1,
            substr_count($html, $refus),
            'Le refus de la bulle apparait ailleurs que dans son propre onglet.'
        );
    }

    /**
     * **Les deux formulaires ne partagent aucun nom de champ**, et c est mesure sur la page rendue.
     *
     * Une mutation a montre que cela manquait : renommer `bulle[body]` en `body` dans le gabarit ne faisait
     * rougir personne, parce que les essais postaient les noms prefixes quoi que la page affiche. Ils
     * mesuraient donc le controleur, jamais le formulaire.
     *
     * Ici on lit la page, on separe les deux panneaux, et on exige que leurs ensembles de noms soient
     * **disjoints** : c est la propriete elle-meme, pas une de ses consequences.
     */
    public function testTheTwoFormsShareNoFieldName(): void
    {
        $html = $this->get(route('admin.announcement.index'))->getContent();
        $this->assertIsString($html);

        $messages = $this->nomsDuPanneau($html, 'tab-message');
        $bulle = $this->nomsDuPanneau($html, 'tab-bulle');

        $this->assertNotSame([], $messages, 'Premisse : le formulaire des messages porte des champs.');
        $this->assertNotSame([], $bulle, 'Premisse : le formulaire de la bulle porte des champs.');

        $this->assertSame(
            [],
            array_values(array_intersect($messages, $bulle)),
            "Les deux formulaires partagent des noms de champs : old() les melangerait.\n"
            . '  messages : ' . implode(', ', $messages) . "\n"
            . '  bulle    : ' . implode(', ', $bulle)
        );

        // Et chaque champ de la bulle porte bien le prefixe, plutot qu un nom simplement different.
        foreach ($bulle as $nom) {
            if ($nom === 'enabled') {
                // L interrupteur vit dans son propre formulaire : il ne porte aucune saisie a conserver.
                continue;
            }
            $this->assertStringStartsWith('bulle[', $nom, "Le champ « $nom » de la bulle n est pas prefixe.");
        }
    }

    /**
     * Les noms de champs d un panneau, le jeton anti-contrefacon mis a part — il est commun aux deux par
     * construction et n appartient donc a aucun.
     *
     * @return list<string>
     */
    private function nomsDuPanneau(string $html, string $panneau): array
    {
        $debut = strpos($html, 'id="' . $panneau . '"');
        $this->assertNotFalse($debut, "Le panneau $panneau est introuvable.");

        $suivant = strpos($html, 'id="tab-', $debut + 10);
        $bloc = substr($html, $debut, $suivant === false ? null : $suivant - $debut);

        preg_match_all('/name="([^"]+)"/', $bloc, $m);

        return array_values(array_diff(array_unique($m[1]), ['_token']));
    }

    /**
     * **Enregistrer par la vraie route ne publie rien.**
     *
     * L essai du service ne suffisait pas : un controleur qui publierait au passage lui echappait. Une
     * mutation l a montre en ajoutant une publication dans `save()` sans faire rougir personne.
     */
    public function testSavingThroughTheRealRoutePublishesNothing(): void
    {
        $avant = AnnouncementBubbleVersion::query()->count();

        $this->post(route('admin.announcement.bubble.save'), [
            'bulle' => ['title' => 'Titre', 'body' => 'Corps', 'link_url' => null, 'link_label' => null],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            $avant,
            AnnouncementBubbleVersion::query()->count(),
            'Un enregistrement a publie une version.'
        );
    }

    /**
     * **L apercu n ecrit rien du tout** — ni brouillon, ni version — **et montre bien ce qui a ete saisi**.
     *
     * La premiere version ne prouvait que l absence d ecriture. Un apercu qui aurait affiche la derniere
     * publication au lieu de la saisie l aurait donc passee : il n ecrit rien non plus. Or c est precisement
     * ce qu un apercu doit faire — montrer le brouillon en cours.
     */
    public function testThePreviewShowsTheSubmittedDraftAndWritesNothing(): void
    {
        $service = resolve(AnnouncementBubbleService::class);

        // Un monde de depart DIFFERENT de la saisie : sans cela, « montre la saisie » et « montre la
        // publication » coincideraient, et l essai ne distinguerait pas les deux.
        $service->saveDraft([
            'title' => 'ANCIEN BROUILLON', 'body' => 'ancien corps',
            'link_url' => null, 'link_label' => null, 'dismissible' => true,
        ]);
        $service->publish(Date::now());

        $versionsAvant = AnnouncementBubbleVersion::query()->count();
        $brouillonAvant = $service->configuration()->fresh()?->draft_title;

        $reponse = $this->post(route('admin.announcement.bubble.preview'), [
            'bulle' => [
                'title' => 'SAISIE EN COURS',
                'body' => "premiere ligne\nseconde ligne",
                'link_url' => '/overview',
                'link_label' => 'Voir',
            ],
        ]);
        $reponse->assertStatus(200);

        $html = $reponse->getContent();
        $this->assertIsString($html);

        // Ce qu il montre : la saisie, et pas la publication.
        $this->assertStringContainsString('SAISIE EN COURS', $html, 'L apercu ne montre pas la saisie.');
        $this->assertStringNotContainsString('ANCIEN BROUILLON', $html, 'L apercu montre la publication au lieu de la saisie.');
        $this->assertStringContainsString('seconde ligne', $html, 'Le corps saisi n est pas rendu.');

        // **Et il charge bien la feuille du jeu** : rendu sans elle, il perdrait les retours a la ligne et
        // tout l habillage — c est le defaut qu il a fallu corriger le 21 septembre 2026.
        //
        // On cherche **le fichier que le manifeste designe**, pas le chemin source : `@vite` rend l URL
        // construite, et une empreinte ecrite en dur ici serait fausse au premier rebuild.
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $feuille = $manifeste['resources/css/ingame.css']['file'] ?? null;
        $this->assertIsString($feuille, 'Premisse : le manifeste designe bien une feuille pour le jeu.');
        $this->assertStringContainsString($feuille, $html, 'L apercu ne charge pas la feuille du jeu.');
        $this->assertStringContainsString('id="pageContent"', $html, 'L apercu ne reproduit pas les conteneurs de la vue generale.');

        // Ce qu il n ecrit pas.
        $this->assertSame($versionsAvant, AnnouncementBubbleVersion::query()->count(), 'L apercu a publie une version.');
        $this->assertSame(
            $brouillonAvant,
            $service->configuration()->fresh()?->draft_title,
            'L apercu a ecrit le brouillon.'
        );
    }
}
