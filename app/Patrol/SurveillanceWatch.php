<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Patrol;
use OGame\Models\SurveillanceContact;
use OGame\Patrol\Enums\SurveillanceTier;

/**
 * Ce que les Reseaux de surveillance apprennent d une patrouille etrangere, et ce qu ils oublient.
 *
 * ## Un contact derive des faits, il ne se decide pas
 *
 * Une ligne de `surveillance_contacts` n est jamais une opinion : elle dit qu une patrouille est
 * entree dans le systeme d un corps equipe, a quel instant, et a partir de quand ce corps le
 * saura — l entree plus le delai du palier de ce corps. Rien d autre n y est ecrit. Le **contenu**
 * du renseignement se lit au moment de la lecture, sur le niveau que le corps porte alors, et
 * jamais ici : geler le contenu ferait survivre a la demolition du reseau ce que la demolition doit
 * retirer.
 *
 * ## Ce qui ouvre, ce qui ferme
 *
 * Ouvre : l entree dans un systeme. Une manoeuvre **a l interieur** du systeme n ouvre rien et ne
 * relance rien — c est `entered_system_at` qui fait foi, et la revue 120 interdit qu une
 * acquisition de trente minutes soit remise a zero par de petits sauts.
 *
 * Ferme : la patrouille quitte le systeme, rentre, ou cesse d exister ; le corps observateur perd
 * son reseau, change de mains ou disparait. Dans tous les cas le contact est **revoque**, pas
 * efface : ce qui a ete su l a ete, et une ligne revoquee reste lisible pour un audit. Un retour
 * dans le systeme ouvre une **nouvelle** ligne — donc une nouvelle acquisition, depuis zero.
 *
 * ## L amelioration est retroactive, la perte ne l est pas
 *
 * Monter le reseau raccourcit le delai : le contact ouvert recalcule son instant de visibilite, qui
 * peut tomber dans le passe — le contact devient alors visible **immediatement**, ce qui est la
 * regle voulue. Descendre le reseau rallonge ce delai de la meme facon ; ce n est pas une punition
 * ajoutee, c est la meme formule lue dans l autre sens. Perdre le reseau entierement revoque.
 */
final class SurveillanceWatch
{
    /**
     * Les contacts qu une patrouille ouvre dans le systeme ou elle vient d entrer.
     *
     * Idempotent : appele deux fois pour la meme entree, il n ouvre rien de plus. La ligne ouverte
     * est reconnue par le couple (corps observateur, patrouille), avec `revoked_at` nul.
     */
    public function acquire(Patrol $patrol, int $now): void
    {
        $entree = $patrol->entered_system_at === null ? $now : (int)$patrol->entered_system_at;
        $galaxie = $patrol->galaxy;
        $systeme = $patrol->system;

        if ($galaxie === null || $systeme === null) {
            return;
        }

        // **Les corps etrangers equipes de ce systeme, et eux seuls.** Un joueur voit deja ses
        // propres patrouilles sans aucun batiment : lui ouvrir un contact sur les siennes ferait
        // dependre d un reseau une information qui n en depend pas.
        $observateurs = DB::table('planets')
            ->where('galaxy', (int)$galaxie)
            ->where('system', (int)$systeme)
            ->where('planet_type', PlanetType::Planet->value)
            ->where('user_id', '!=', (int)$patrol->user_id)
            ->where(function ($requete): void {
                $requete->whereNull('destroyed')->orWhere('destroyed', 0);
            })
            ->orderBy('id')
            ->get(['id', 'user_id', 'surveillance_network']);

        foreach ($observateurs as $observateur) {
            // **Le niveau decide ici, et nulle part ailleurs.** Un filtre `surveillance_network >= 1`
            // dans la requete dirait la meme chose une seconde fois : les deux gardes se
            // rattraperaient l une l autre, aucune mutation ne les distinguerait, et un essai ne
            // pourrait plus prouver la regle. Mesure faite : cette mutation-la survivait.
            $palier = SurveillanceTier::fromLevel((int)$observateur->surveillance_network);

            if ($palier === null) {
                continue;
            }

            $ouvert = SurveillanceContact::query()
                ->where('observer_planet_id', (int)$observateur->id)
                ->where('patrol_id', (int)$patrol->id)
                ->whereNull('revoked_at')
                ->exists();

            if ($ouvert) {
                continue;
            }

            SurveillanceContact::query()->create([
                'observer_planet_id' => (int)$observateur->id,
                'observer_user_id' => (int)$observateur->user_id,
                'patrol_id' => (int)$patrol->id,
                'entered_system_at' => $entree,
                'visible_from' => $entree + $palier->acquisitionSeconds(),
            ]);
        }
    }

    /**
     * Tout ce que cette patrouille laissait voir cesse de se voir.
     *
     * Employe quand elle quitte le systeme, rentre, ou cesse d exister. Le contact est revoque,
     * jamais efface.
     */
    public function revokeAllFor(Patrol $patrol, int $now): int
    {
        return SurveillanceContact::query()
            ->where('patrol_id', (int)$patrol->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    /**
     * Ce corps n observe plus : reseau demoli, corps perdu ou detruit.
     *
     * **Perdre la couverture retire le renseignement qu elle seule autorisait.** C est le point que
     * la revue de l etape 5 place au-dessus des autres : un joueur qui perd son reseau ne garde pas
     * ce qu il avait appris, et surtout ne continue pas d apprendre.
     */
    public function revokeAllFrom(int $observerPlanetId, int $now): int
    {
        return SurveillanceContact::query()
            ->where('observer_planet_id', $observerPlanetId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    /**
     * Le reseau d un corps a change de niveau : ses contacts ouverts recalculent leur echeance.
     *
     * Le niveau zero — reseau demoli — revoque au lieu de recalculer. Pour tout autre niveau,
     * l instant de visibilite est recalcule depuis l entree : monter le reseau peut le faire tomber
     * dans le passe, et le contact devient visible aussitot.
     */
    public function networkLevelChanged(int $observerPlanetId, int $level, int $now): void
    {
        $palier = SurveillanceTier::fromLevel($level);

        if ($palier === null) {
            $this->revokeAllFrom($observerPlanetId, $now);

            return;
        }

        // Une seule ecriture, calculee par la base a partir de l entree deja inscrite : relire puis
        // reecrire ligne par ligne ferait dependre le resultat de ce qui bouge entre les deux.
        SurveillanceContact::query()
            ->where('observer_planet_id', $observerPlanetId)
            ->whereNull('revoked_at')
            ->update(['visible_from' => DB::raw('entered_system_at + ' . $palier->acquisitionSeconds())]);
    }

    /**
     * Le meilleur palier dont ce joueur dispose dans ce systeme, ou `null` s il n en a aucun.
     *
     * **Aucun detecteur, aucun renseignement.** Le meilleur, jamais la somme : c est
     * `SurveillanceTier::bestOf()` qui porte la regle, et cette methode ne fait que lui donner les
     * niveaux que le joueur possede reellement a cet instant.
     */
    public function bestTierOf(int $userId, int $galaxy, int $system): SurveillanceTier|null
    {
        $niveaux = DB::table('planets')
            ->where('user_id', $userId)
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('planet_type', PlanetType::Planet->value)
            ->where(function ($requete): void {
                $requete->whereNull('destroyed')->orWhere('destroyed', 0);
            })
            ->pluck('surveillance_network')
            ->all();

        return SurveillanceTier::bestOf(array_map(static fn ($niveau): int => (int)$niveau, $niveaux));
    }
}
