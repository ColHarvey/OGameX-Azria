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
 * La bulle d annonce : ce que chaque geste touche, et surtout **ce qu il ne touche pas**.
 *
 * Le cœur de cette fonctionnalite n est pas l affichage, c est la separation des gestes. Enregistrer, apercevoir,
 * activer et publier font quatre choses differentes, et trois d entre elles ne doivent **rien** montrer aux
 * joueurs ni effacer une seule fermeture. Ces essais mesurent cette separation, pas la presence d un cadre bleu.
 */
class AnnouncementBubbleTest extends AccountTestCase
{
    private function service(): AnnouncementBubbleService
    {
        return resolve(AnnouncementBubbleService::class);
    }

    private function compte(): User
    {
        return User::query()->findOrFail($this->currentUserId);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // La base d un processus garde ce que les classes precedentes ont ecrit : on pose le monde qu on exige.
        AnnouncementBubbleDismissal::query()->delete();
        AnnouncementBubbleVersion::query()->delete();
        AnnouncementBubble::query()->delete();
    }

    /**
     * @param array<string, mixed> $remplace
     * @return array{title: string, body: string|null, link_url: string|null, link_label: string|null, dismissible: bool}
     */
    private function brouillon(array $remplace = []): array
    {
        return $remplace + [
            'title' => 'Maintenance',
            'body' => "Premiere ligne\nSeconde ligne",
            'link_url' => '/overview',
            'link_label' => 'Voir',
            'dismissible' => true,
        ];
    }

    /**
     * **Un brouillon n est pas une publication.** C est la regle qui porte tout le reste.
     */
    public function testSavingADraftShowsNothingToPlayers(): void
    {
        $this->service()->saveDraft($this->brouillon());
        $this->service()->setEnabled(true);

        $this->assertSame(0, AnnouncementBubbleVersion::query()->count(), 'Un enregistrement a cree une version.');
        $this->assertNull(
            $this->service()->visibleFor($this->compte()),
            'Un brouillon est visible des joueurs alors qu il n a jamais ete publie.'
        );
    }

    /**
     * **Desactivee, la bulle ne s affiche pas** — meme publiee.
     */
    public function testAPublishedBubbleStaysHiddenWhileDisabled(): void
    {
        $this->service()->saveDraft($this->brouillon());
        $this->service()->publish(Date::now());

        $this->assertNull($this->service()->visibleFor($this->compte()), 'Une bulle desactivee s affiche.');

        $this->service()->setEnabled(true);

        $this->assertNotNull($this->service()->visibleFor($this->compte()), 'Une bulle activee ne s affiche pas.');
    }

    /**
     * **Une fermeture vaut pour le compte et la version**, et elle survit a tout sauf a une nouvelle publication.
     *
     * Les quatre affirmations sont mesurees dans l ordre ou un administrateur les vivrait : fermer, enregistrer,
     * desactiver, reactiver, publier.
     */
    public function testADismissalSurvivesEverythingButANewPublication(): void
    {
        $this->service()->saveDraft($this->brouillon());
        $premiere = $this->service()->publish(Date::now());
        $this->service()->setEnabled(true);

        // **Chaque lecture porte son propre nom**, et ce n est pas une coquetterie : ecrites en ligne, les
        // quatre appels sont la MEME expression pour l analyse statique, qui reporte alors la premiere
        // assertion « est nul » sur la derniere et declare le `assertNotNull` final impossible. Elle a
        // raison de s en plaindre : un lecteur humain fait la meme lecture, et ne voit plus que l etat
        // change entre les appels.
        $this->assertTrue($this->service()->dismiss($this->compte(), $premiere->version));
        $apresFermeture = $this->service()->visibleFor($this->compte());
        $this->assertNull($apresFermeture, 'La fermeture n a pas masque la bulle.');

        // Un simple enregistrement ne la ressuscite pas.
        $this->service()->saveDraft($this->brouillon(['title' => 'Autre titre']));
        $apresEnregistrement = $this->service()->visibleFor($this->compte());
        $this->assertNull($apresEnregistrement, 'Un enregistrement a ressuscite la bulle.');

        // Ni une desactivation suivie d une reactivation.
        $this->service()->setEnabled(false);
        $this->service()->setEnabled(true);
        $apresBascule = $this->service()->visibleFor($this->compte());
        $this->assertNull($apresBascule, 'Une desactivation puis reactivation a efface la fermeture.');

        // Seule une nouvelle publication la fait revenir.
        $seconde = $this->service()->publish(Date::now());
        $visible = $this->service()->visibleFor($this->compte());

        $this->assertSame($premiere->version + 1, $seconde->version, 'La version n a pas avance.');
        $this->assertNotNull($visible, 'Une nouvelle publication n a pas fait revenir la bulle.');
        $this->assertSame($seconde->version, $visible->version, 'C est l ancienne version qui revient.');
        $this->assertSame('Autre titre', $visible->title, 'La publication n a pas repris le brouillon courant.');
    }

    /**
     * **La fermeture est par compte**, donc elle ne masque rien chez un autre joueur.
     */
    public function testADismissalBelongsToOneAccountOnly(): void
    {
        $this->service()->saveDraft($this->brouillon());
        $version = $this->service()->publish(Date::now());
        $this->service()->setEnabled(true);

        $autre = User::query()->where('id', '!=', $this->currentUserId)->firstOrFail();

        $this->service()->dismiss($this->compte(), $version->version);

        $this->assertNull($this->service()->visibleFor($this->compte()), 'Premisse : le compte l a bien fermee.');
        $this->assertNotNull($this->service()->visibleFor($autre), 'La fermeture d un compte en a masque un autre.');
    }

    /**
     * **Une version non masquable refuse la fermeture, cote serveur.**
     *
     * L absence de croix dans la page n est pas une protection : une requete forgee viserait la meme route.
     */
    public function testAVersionThatForbidsHidingRefusesTheDismissal(): void
    {
        $this->service()->saveDraft($this->brouillon(['dismissible' => false]));
        $version = $this->service()->publish(Date::now());
        $this->service()->setEnabled(true);

        $this->assertFalse(
            $this->service()->dismiss($this->compte(), $version->version),
            'Une version non masquable a accepte une fermeture.'
        );
        $this->assertNotNull($this->service()->visibleFor($this->compte()), 'La bulle a ete masquee malgre le refus.');
        $this->assertSame(0, AnnouncementBubbleDismissal::query()->count(), 'Une ligne de fermeture a ete posee.');
    }

    /**
     * **Le drapeau se lit sur la version visee, pas sur le brouillon courant.**
     *
     * C est ce qui exige de garder l historique : une version masquable reste masquable meme si le brouillon a
     * change d avis depuis.
     */
    public function testTheDismissibleFlagIsReadOnTheTargetedVersionNotOnTheDraft(): void
    {
        $this->service()->saveDraft($this->brouillon(['dismissible' => true]));
        $ancienne = $this->service()->publish(Date::now());

        // Le brouillon change d avis, et une nouvelle version l applique.
        $this->service()->saveDraft($this->brouillon(['dismissible' => false]));
        $this->service()->publish(Date::now());

        $this->assertTrue(
            $this->service()->dismiss($this->compte(), $ancienne->version),
            'Une version masquable a ete refusee parce que le brouillon courant ne l est plus.'
        );
    }

    /**
     * **Fermer l ancienne version ne masque pas la nouvelle.**
     *
     * C est la course que Keven a nommee : une fermeture partie pendant qu une publication arrivait effacerait
     * une annonce que le joueur n a jamais lue.
     */
    public function testDismissingAnOlderVersionNeverHidesTheNewOne(): void
    {
        $this->service()->saveDraft($this->brouillon());
        $ancienne = $this->service()->publish(Date::now());
        $this->service()->setEnabled(true);

        // La nouvelle publication arrive pendant que le joueur regarde encore l ancienne.
        $nouvelle = $this->service()->publish(Date::now());

        // Son clic porte la version qu il avait sous les yeux.
        $this->assertTrue($this->service()->dismiss($this->compte(), $ancienne->version));

        $visible = $this->service()->visibleFor($this->compte());
        $this->assertNotNull($visible, 'La fermeture de l ancienne version a masque la nouvelle.');
        $this->assertSame($nouvelle->version, $visible->version);
    }

    /**
     * **Deux fermetures ne posent qu une ligne**, et la seconde n est pas une erreur.
     */
    public function testDismissingTwiceIsNotAnError(): void
    {
        $this->service()->saveDraft($this->brouillon());
        $version = $this->service()->publish(Date::now());

        $this->assertTrue($this->service()->dismiss($this->compte(), $version->version));
        $this->assertTrue($this->service()->dismiss($this->compte(), $version->version));

        $this->assertSame(1, AnnouncementBubbleDismissal::query()->count(), 'Une seconde ligne a ete posee.');
    }

    /**
     * **Une version inconnue est refusee.**
     */
    public function testAnUnknownVersionIsRefused(): void
    {
        $this->assertFalse($this->service()->dismiss($this->compte(), 9999));
        $this->assertSame(0, AnnouncementBubbleDismissal::query()->count());
    }

    /**
     * **La configuration est un singleton, et c est la base qui le tient.**
     */
    public function testTheConfigurationIsASingleton(): void
    {
        $premiere = $this->service()->configuration();
        $seconde = $this->service()->configuration();

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, AnnouncementBubble::query()->count(), 'Une seconde configuration a ete creee.');
    }

    /**
     * **Les versions se suivent sans trou ni collision.**
     */
    public function testVersionsAdvanceOneByOne(): void
    {
        $this->service()->saveDraft($this->brouillon());

        $numeros = [];
        for ($i = 0; $i < 4; $i++) {
            $numeros[] = $this->service()->publish(Date::now())->version;
        }

        $this->assertSame([1, 2, 3, 4], $numeros, 'Les versions ne se suivent pas.');
    }
}
