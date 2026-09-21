<?php

namespace OGame\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OGame\Models\AnnouncementBubble;
use OGame\Models\AnnouncementBubbleDismissal;
use OGame\Models\AnnouncementBubbleVersion;
use OGame\Models\User;

/**
 * La bulle d annonce : un brouillon que l administrateur ecrit, des versions publiees que les joueurs lisent,
 * et des fermetures par compte.
 *
 * ## Les quatre gestes, et ce que chacun touche
 *
 * | geste | brouillon | version | fermetures | visible |
 * |---|---|---|---|---|
 * | enregistrer | ecrit | — | — | non |
 * | apercu | — | — | — | non |
 * | activer / desactiver | `enabled` | — | — | oui / non |
 * | publier | lu | **+1** | rendues caduques | oui |
 *
 * **Seule la publication consomme une version.** C est ce qui fait qu une desactivation suivie d une
 * reactivation ne ressuscite rien, et qu un simple enregistrement n efface aucune fermeture.
 *
 * ## Les ecritures concurrentes
 *
 * Le singleton est tenu par un index unique, pas par une lecture prealable. La publication vit dans une
 * transaction qui **verrouille la ligne du brouillon** : deux publications simultanees se serialisent, et le
 * numero de version est calcule sous ce verrou — jamais par un `max() + 1` lu avant. L index unique sur
 * `version` est le dernier juge si un chemin l oubliait.
 */
class AnnouncementBubbleService
{
    /**
     * La configuration, creee si elle n existe pas.
     *
     * **La course de creation est fermee par la base** : deux requetes qui inserent en meme temps heurtent
     * l index unique de `singleton`, et la perdante relit au lieu de poser une seconde ligne.
     */
    public function configuration(): AnnouncementBubble
    {
        $ligne = AnnouncementBubble::query()->where('singleton', 1)->first();
        if ($ligne !== null) {
            return $ligne;
        }

        try {
            // **Relue apres creation.** Les valeurs par defaut vivent dans le schema, pas dans le modele :
            // l instance que `create()` rend porte `null` la ou la table porte `''`, et un appelant lirait
            // deux choses differentes selon qu il vient de creer la ligne ou de la trouver.
            $creee = AnnouncementBubble::query()->create(['singleton' => 1]);

            return $creee->fresh() ?? $creee;
        } catch (QueryException) {
            return AnnouncementBubble::query()->where('singleton', 1)->firstOrFail();
        }
    }

    /**
     * Enregistrer le brouillon. **Ne publie rien** et ne touche ni version ni fermeture.
     *
     * @param array{title: string, body: string|null, link_url: string|null, link_label: string|null, dismissible: bool} $champs
     */
    public function saveDraft(array $champs): AnnouncementBubble
    {
        $configuration = $this->configuration();
        $configuration->fill([
            'draft_title' => $champs['title'],
            'draft_body' => $champs['body'],
            'draft_link_url' => $champs['link_url'],
            'draft_link_label' => $champs['link_label'],
            'draft_dismissible' => $champs['dismissible'],
        ]);
        $configuration->save();

        return $configuration;
    }

    /**
     * Retirer ou remettre la bulle. **La version ne bouge pas**, donc aucune fermeture n est effacee.
     */
    public function setEnabled(bool $actif): AnnouncementBubble
    {
        $configuration = $this->configuration();
        $configuration->enabled = $actif;
        $configuration->save();

        return $configuration;
    }

    /**
     * Publier le brouillon comme nouvelle version.
     *
     * **Sous verrou, et le numero se calcule la.** Un `max(version) + 1` lu hors transaction laisserait deux
     * publications simultanees viser le meme numero ; ici la ligne du brouillon est verrouillee le temps du
     * geste, et l index unique sur `version` reste le dernier juge.
     *
     * La publication **rend les fermetures caduques sans les effacer** : elles visent une version que la bulle
     * n affiche plus. L historique reste donc lisible, et rien n est perdu.
     */
    public function publish(Carbon $maintenant): AnnouncementBubbleVersion
    {
        return DB::transaction(function () use ($maintenant): AnnouncementBubbleVersion {
            $configuration = AnnouncementBubble::query()
                ->where('singleton', 1)
                ->lockForUpdate()
                ->first();

            if ($configuration === null) {
                $configuration = $this->configuration();
                $configuration = AnnouncementBubble::query()
                    ->whereKey($configuration->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $derniere = (int)AnnouncementBubbleVersion::query()->lockForUpdate()->max('version');

            return AnnouncementBubbleVersion::query()->create([
                'version' => $derniere + 1,
                'title' => $configuration->draft_title,
                'body' => $configuration->draft_body,
                'link_url' => $configuration->draft_link_url,
                'link_label' => $configuration->draft_link_label,
                'dismissible' => $configuration->draft_dismissible,
                'published_at' => $maintenant,
            ]);
        }, 5);
    }

    /**
     * La derniere version publiee, ou `null` si rien ne l a jamais ete.
     */
    public function currentVersion(): AnnouncementBubbleVersion|null
    {
        return AnnouncementBubbleVersion::query()->orderByDesc('version')->first();
    }

    /**
     * Ce qu un compte doit voir, ou `null`.
     *
     * Quatre raisons de ne rien montrer, et chacune est une regle : la bulle est desactivee, rien n a jamais
     * ete publie, ce compte a ferme **cette version**, ou il n y a pas de compte.
     */
    public function visibleFor(User|null $compte): AnnouncementBubbleVersion|null
    {
        if ($compte === null || !$this->configuration()->enabled) {
            return null;
        }

        $version = $this->currentVersion();
        if ($version === null) {
            return null;
        }

        $fermee = AnnouncementBubbleDismissal::query()
            ->where('user_id', $compte->id)
            ->where('version', $version->version)
            ->exists();

        return $fermee ? null : $version;
    }

    /**
     * Fermer une version pour un compte.
     *
     * **La version vient du joueur, et elle est verifiee.** Sans cela, une fermeture partie pendant qu une
     * nouvelle publication arrivait masquerait la nouvelle — un clic sur l ancienne bulle effacerait une
     * annonce que le joueur n a jamais lue (precaution de Keven, 20 septembre 2026).
     *
     * Deux refus, et ils ne disent pas la meme chose : une version inconnue, et une version que sa publication
     * a declaree **non masquable**. Le drapeau est lu **sur cette version-la**, pas sur le brouillon courant.
     *
     * Rend `true` si la fermeture est posee ou l etait deja.
     */
    public function dismiss(User $compte, int $version): bool
    {
        $publication = AnnouncementBubbleVersion::query()->where('version', $version)->first();
        if ($publication === null || !$publication->dismissible) {
            return false;
        }

        try {
            AnnouncementBubbleDismissal::query()->create([
                'user_id' => $compte->id,
                'version' => $version,
            ]);
        } catch (QueryException) {
            // Le couple est unique : une seconde fermeture n est pas une erreur, c est le meme resultat.
        }

        return true;
    }
}
