<?php

namespace OGame\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use OGame\Models\User;
use OGame\Rules\SafeAnnouncementLink;
use OGame\Services\AnnouncementBubbleService;

/**
 * La bulle d annonce : quatre gestes d administration, et la fermeture cote joueur.
 *
 * ## Les deux formulaires de la page ne se melangent pas
 *
 * Le formulaire de messages garde son controleur, sa route et son nom. Celui de la bulle porte des champs
 * **prefixes** (`bulle[title]`, `bulle[body]`, …) et un **sac d erreurs nomme** (`bulle`).
 *
 * Les deux sont necessaires, et pour des raisons differentes : le sac isole les **erreurs**, mais `old()` est
 * un seul depot partage — sans prefixe, une saisie refusee d un formulaire repeuplerait les champs de l autre
 * (precaution de Keven, 20 septembre 2026). Le champ `body` existe des deux cotes : la collision serait
 * immediate.
 *
 * L onglet a rouvrir apres un refus se deduit du sac qui porte l erreur.
 */
class AnnouncementBubbleController extends OGameController
{
    public function __construct(private AnnouncementBubbleService $bubbles)
    {
    }

    /**
     * Enregistrer le brouillon. **Aucun message n est envoye, aucune bulle n est publiee.**
     */
    public function save(): RedirectResponse
    {
        $champs = $this->valider();

        $this->bubbles->saveDraft($champs);

        return redirect()->route('admin.announcement.index', ['onglet' => 'bulle'])
            ->with('bulle_status', __('t_ingame.announcement.draft_saved'));
    }

    /**
     * Publier le brouillon : **le seul geste qui consomme une version** et qui fait reapparaitre la bulle chez
     * les joueurs qui l avaient fermee.
     */
    public function publish(): RedirectResponse
    {
        $champs = $this->valider();
        $this->bubbles->saveDraft($champs);

        $version = $this->bubbles->publish(Date::now());

        return redirect()->route('admin.announcement.index', ['onglet' => 'bulle'])
            ->with('bulle_status', __('t_ingame.announcement.published', ['version' => $version->version]));
    }

    /**
     * Retirer ou remettre la bulle. **La version ne bouge pas**, donc aucune fermeture n est effacee.
     */
    public function toggle(): RedirectResponse
    {
        $actif = request()->boolean('enabled');
        $this->bubbles->setEnabled($actif);

        return redirect()->route('admin.announcement.index', ['onglet' => 'bulle'])
            ->with('bulle_status', $actif
                ? __('t_ingame.announcement.enabled')
                : __('t_ingame.announcement.disabled'));
    }

    /**
     * L apercu : **le rendu du joueur, pas seulement son balisage**, avec les valeurs saisies, et **rien
     * n est ecrit**.
     *
     * Une premiere version rendait le partiel **seul**. Un partiel n a ni gabarit ni feuille : la page
     * sortait en Times New Roman, sans cadre ni badge — et **les retours a la ligne disparaissaient**,
     * `white-space: pre-wrap` vivant dans la feuille absente. L apercu montrait donc au redacteur un texte
     * que le joueur n aurait jamais vu. Mesure au navigateur, 21 septembre 2026 : une seule feuille
     * chargee, celle de la barre de debogage.
     *
     * La page d apercu reproduit la chaine de conteneurs de la vue generale et charge la feuille par le
     * meme `@vite` que le jeu.
     *
     * Il passe par la meme validation : un lien que le jeu refuserait ne doit pas davantage s afficher ici.
     */
    public function preview(): View
    {
        $champs = $this->valider();

        return view('ingame.announcement.preview', [
            'announcement' => (object)[
                'version' => 0,
                'title' => $champs['title'],
                'body' => $champs['body'],
                'link_url' => $champs['link_url'],
                'link_label' => $champs['link_label'],
                'dismissible' => $champs['dismissible'],
            ],
        ]);
    }

    /**
     * Fermer la bulle pour le compte de la session.
     *
     * **La version vient du navigateur, et le serveur la verifie.** Sans cela, une fermeture partie pendant
     * qu une nouvelle publication arrivait masquerait la nouvelle. Le drapeau « masquable » est lu **sur cette
     * version-la**, pas sur le brouillon courant.
     */
    public function dismiss(): JsonResponse
    {
        $donnees = request()->validate([
            'version' => 'required|integer|min:1',
        ]);

        $compte = User::query()->findOrFail((int)auth()->id());
        $pose = $this->bubbles->dismiss($compte, (int)$donnees['version']);

        if (!$pose) {
            // Une version inconnue, ou une version que sa publication a declaree non masquable.
            return response()->json(['success' => false], 409);
        }

        return response()->json(['success' => true]);
    }

    /**
     * La validation commune aux trois gestes qui lisent le formulaire.
     *
     * Les champs sont **prefixes** : sans cela, `old('body')` serait partage avec le formulaire de messages.
     * Le sac d erreurs porte le meme nom, pour que la page sache quel onglet rouvrir.
     *
     * @return array{title: string, body: string|null, link_url: string|null, link_label: string|null, dismissible: bool}
     */
    private function valider(): array
    {
        $validateur = Validator::make(request()->all(), [
            'bulle.title' => 'required|string|max:120',
            'bulle.body' => 'nullable|string|max:4000',
            'bulle.link_url' => ['nullable', 'string', 'max:500', new SafeAnnouncementLink()],
            'bulle.link_label' => 'nullable|string|max:60',
        ]);

        $donnees = $validateur->validateWithBag('bulle');
        $bulle = $donnees['bulle'] ?? [];

        return [
            'title' => (string)($bulle['title'] ?? ''),
            'body' => $bulle['body'] ?? null,
            'link_url' => $bulle['link_url'] ?? null,
            'link_label' => $bulle['link_label'] ?? null,
            'dismissible' => request()->boolean('bulle.dismissible'),
        ];
    }
}
