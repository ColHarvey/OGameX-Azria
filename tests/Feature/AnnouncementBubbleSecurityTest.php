<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Models\AnnouncementBubble;
use OGame\Models\AnnouncementBubbleDismissal;
use OGame\Models\AnnouncementBubbleVersion;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Services\AnnouncementBubbleService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AccountTestCase;

/**
 * Ce que la bulle refuse : les liens dangereux, le HTML libre, et les mains qui n ont rien a faire la.
 *
 * **Les liens sont eprouves par la vraie route**, pas par la regle seule : une regle correcte mal branchee ne
 * protegerait rien.
 */
class AnnouncementBubbleSecurityTest extends AccountTestCase
{
    private function service(): AnnouncementBubbleService
    {
        return resolve(AnnouncementBubbleService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        AnnouncementBubbleDismissal::query()->delete();
        AnnouncementBubbleVersion::query()->delete();
        AnnouncementBubble::query()->delete();
    }

    /**
     * **Une epreuve remet ce qu elle a leve.** Le role est retire au demontage : la base d un processus
     * est partagee, et un compte laisse administrateur ferait passer les essais suivants pour de
     * mauvaises raisons — ou rougir celui qui verifie justement qu un joueur ordinaire n atteint rien.
     */
    protected function tearDown(): void
    {
        $compte = User::query()->find($this->currentUserId);
        if ($compte !== null && $compte->hasRole('admin')) {
            $compte->removeRole('admin');
        }

        parent::tearDown();
    }

    /**
     * Le droit d administration passe par un **role**, pas par une colonne : `Admin::handle()` interroge
     * `hasRole('admin')`. Poser une colonne aurait fait passer l essai sans rien prouver du vrai chemin.
     */
    private function enAdministrateur(): User
    {
        $compte = User::query()->findOrFail($this->currentUserId);
        $compte->assignRole('admin');
        $this->assertTrue($compte->fresh()?->hasRole('admin') ?? false, 'Premisse : le compte est administrateur.');
        $this->actingAs($compte);

        return $compte;
    }

    /**
     * @return array<string, mixed>
     */
    private function formulaire(string $lien): array
    {
        return [
            'bulle' => [
                'title' => 'Titre',
                'body' => 'Corps',
                'link_url' => $lien,
                'link_label' => 'Voir',
            ],
        ];
    }

    /**
     * **Les liens dangereux, et pas seulement `javascript:`.**
     *
     * Keven, 20 septembre 2026 : « refuse aussi les antislashs et caracteres de controle : certains chemins
     * peuvent etre interpretes autrement par le navigateur. Tester les variantes malveillantes, pas seulement
     * javascript: et // ».
     *
     * Chaque forme est nommee, parce qu un tableau de chaines sans explication ne dit pas ce qui est protege.
     */
    public static function liensRefuses(): array
    {
        return [
            'schema javascript' => ['javascript:alert(1)'],
            'schema javascript en casse melee' => ['JaVaScRiPt:alert(1)'],
            'schema javascript avec une tabulation au milieu' => ["java\tscript:alert(1)"],
            'schema javascript avec un saut de ligne' => ["java\nscript:alert(1)"],
            'schema javascript precede d espaces' => ['  javascript:alert(1)'],
            'schema data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'schema vbscript' => ['vbscript:msgbox(1)'],
            'schema file' => ['file:///etc/passwd'],
            'schema blob' => ['blob:http://azria.test/abc'],
            'adresse sans schema' => ['//evil.test/piege'],
            'chemin qui devient une autorite par un antislash' => ['/\\evil.test'],
            'double antislash' => ['\\\\evil.test\\partage'],
            'antislash au milieu d un chemin local' => ['/overview\\..\\..\\etc'],
            'retour chariot dans une adresse' => ["http://azria.test/\rok"],
            'octet nul' => ["http://azria.test/\0ok"],
            'chemin relatif sans barre' => ['overview'],
            'schema inconnu' => ['azria-app://ouvrir'],
            'http sans hote' => ['http://'],
        ];
    }

    #[DataProvider('liensRefuses')]
    public function testADangerousLinkIsRefusedByTheRealRoute(string $lien): void
    {
        $this->enAdministrateur();

        $reponse = $this->post(route('admin.announcement.bubble.save'), $this->formulaire($lien));

        $reponse->assertSessionHasErrors(['bulle.link_url'], null, 'bulle');
        $this->assertNull(
            $this->service()->configuration()->draft_link_url,
            "Le lien « $lien » a ete enregistre malgre le refus."
        );
    }

    public static function liensAcceptes(): array
    {
        return [
            'chemin du jeu' => ['/overview'],
            'chemin avec parametres' => ['/galaxy?galaxy=1&system=42'],
            'chemin avec ancre' => ['/overview#files'],
            'adresse https' => ['https://ogamex.azriagaming.ca/reglement'],
            'adresse http' => ['http://azria.test/page'],
            // **Laravel rogne l entree avant la validation** (`TrimStrings`) : mesure faite, un espace
            // de fin n atteint jamais la regle et la valeur stockee est le chemin sur. Le refus des
            // marges reste dans la regle pour les appelants qui ne passent pas par HTTP.
            'chemin avec un espace de fin, rogne par le cadre' => ['/overview ', '/overview'],
        ];
    }

    #[DataProvider('liensAcceptes')]
    public function testASafeLinkIsAccepted(string $lien, string|null $stocke = null): void
    {
        $this->enAdministrateur();

        $this->post(route('admin.announcement.bubble.save'), $this->formulaire($lien))
            ->assertSessionHasNoErrors();

        $this->assertSame($stocke ?? $lien, $this->service()->configuration()->draft_link_url);
    }

    /**
     * **Aucun HTML libre.** Le titre et le corps ressortent echappes de la vue generale.
     */
    public function testHtmlInTheAnnouncementIsEscapedOnThePlayerPage(): void
    {
        $this->service()->saveDraft([
            'title' => '<script>alert(1)</script>',
            'body' => "<img src=x onerror=alert(2)>\nligne deux",
            'link_url' => null,
            'link_label' => null,
            'dismissible' => true,
        ]);
        $this->service()->publish(Date::now());
        $this->service()->setEnabled(true);

        $reponse = $this->get(route('overview.index'));
        $reponse->assertStatus(200);

        $html = $reponse->getContent();
        $this->assertIsString($html);

        // On cherche la FORME DE CODE, jamais le mot : le mot apparait aussi dans le texte echappe.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'Le titre a ete rendu en HTML.');
        $this->assertStringNotContainsString('<img src=x onerror=', $html, 'Le corps a ete rendu en HTML.');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'Le titre n est pas affiche du tout.');
    }

    /**
     * **Un joueur ordinaire n atteint aucune route d administration.**
     */
    public function testAPlayerCannotReachTheAdministrationRoutes(): void
    {
        $avant = AnnouncementBubbleVersion::query()->count();

        foreach ([
            'admin.announcement.bubble.save',
            'admin.announcement.bubble.publish',
            'admin.announcement.bubble.toggle',
            'admin.announcement.bubble.preview',
        ] as $route) {
            // Le garde **redirige** vers la vue generale plutot que de refuser par un 403 : on mesure
            // ce que le jeu fait, pas ce qu on aurait suppose. Ce qui compte est qu il n atteint pas
            // le controleur — l absence d ecriture le prouve plus surement que le code de retour.
            $this->post(route($route), $this->formulaire('/overview'))->assertRedirect('/overview');
        }

        $this->assertSame($avant, AnnouncementBubbleVersion::query()->count(), 'Un joueur a publie une version.');
    }

    /**
     * **Un visiteur ne ferme rien**, et la page d administration lui est fermee.
     */
    public function testAGuestReachesNothing(): void
    {
        $this->service()->saveDraft([
            'title' => 'Titre', 'body' => null, 'link_url' => null, 'link_label' => null, 'dismissible' => true,
        ]);
        $version = $this->service()->publish(Date::now());

        $avant = AnnouncementBubbleDismissal::query()->count();

        $this->post('/logout');
        $this->assertGuest();

        $this->post(route('announcement.dismiss'), ['version' => $version->version])
            ->assertRedirect(route('login'));
        $this->get(route('admin.announcement.index'))->assertRedirect(route('login'));

        $this->assertSame($avant, AnnouncementBubbleDismissal::query()->count(), 'Un visiteur a ferme une bulle.');
    }

    /**
     * **La fermeture ne peut pas viser un autre compte.**
     *
     * Le compte vient de la session, jamais du navigateur : aucun champ ne le porte, et en ajouter un n y
     * change rien.
     */
    public function testNoBrowserSuppliedIdentifierCanDismissForAnotherAccount(): void
    {
        $this->service()->saveDraft([
            'title' => 'Titre', 'body' => null, 'link_url' => null, 'link_label' => null, 'dismissible' => true,
        ]);
        $version = $this->service()->publish(Date::now());

        $autre = User::query()->where('id', '!=', $this->currentUserId)->firstOrFail();

        $this->post(route('announcement.dismiss'), [
            'version' => $version->version,
            'user_id' => $autre->id,
            'id' => $autre->id,
        ])->assertStatus(200);

        $this->assertSame(
            1,
            AnnouncementBubbleDismissal::query()->where('user_id', $this->currentUserId)->count(),
            'La fermeture n a pas ete posee sur le compte de la session.'
        );
        $this->assertSame(
            0,
            AnnouncementBubbleDismissal::query()->where('user_id', $autre->id)->count(),
            'Un identifiant venu du navigateur a ferme la bulle d un autre compte.'
        );
    }

    /**
     * **Une publication de bulle n envoie aucun message**, et l envoi d un message ne publie aucune bulle.
     *
     * Les deux circuits partagent une page ; ils ne partagent rien d autre.
     */
    public function testTheTwoCircuitsNeverTriggerEachOther(): void
    {
        $messagesAvant = Message::query()->count();
        $versionsAvant = AnnouncementBubbleVersion::query()->count();

        // Un administrateur publie une bulle.
        $this->enAdministrateur();
        $this->post(route('admin.announcement.bubble.publish'), $this->formulaire('/overview'))
            ->assertSessionHasNoErrors();

        $this->assertSame($messagesAvant, Message::query()->count(), 'Une publication de bulle a envoye des messages.');
        $this->assertSame($versionsAvant + 1, AnnouncementBubbleVersion::query()->count());

        // Puis il envoie un message.
        $this->post(route('admin.announcement.send'), ['subject' => 'Sujet', 'body' => 'Corps'])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan($messagesAvant, Message::query()->count(), 'Premisse : le message est bien parti.');
        $this->assertSame(
            $versionsAvant + 1,
            AnnouncementBubbleVersion::query()->count(),
            'Un envoi de message a publie une bulle.'
        );
    }
}
