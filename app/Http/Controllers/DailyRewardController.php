<?php

namespace OGame\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use OGame\Facades\AppUtil;
use OGame\Models\User;
use OGame\Services\DailyRewardService;

/**
 * La recompense quotidienne : lire son etat, et la reclamer.
 *
 * **Le serveur decide de tout** — la journee, le montant, l eligibilite. L horloge du navigateur n accorde aucun
 * credit : elle ne sert qu a animer un compte a rebours dont les secondes viennent d ici.
 *
 * ## Pourquoi ce controleur ne demande pas de `PlayerService`
 *
 * Ces trois routes vivent **hors de `globalgame`**, a cote du bandeau des ressources et pour la meme raison :
 * `globalgame` fait avancer `users.time` (« en ligne »), `planets.time_last_update` (l etoile d activite de la
 * Galaxie) et le traitement des missions. Une fenetre laissee ouverte qui se resynchronise au passage de minuit
 * ferait alors tourner tout cela dans une requete de fond — le piege deja paye par le bandeau (journal §170).
 *
 * La recompense n a besoin ni des planetes, ni des missions, ni de l activite : **le compte suffit**. Le prendre
 * sur la session plutot que par `PlayerService` evite le detour et le risque.
 *
 * Premiere version : ces routes prenaient un `PlayerService` injecte. Hors de `globalgame`, il n est pas charge —
 * `getUser()->id` valait **zero**, et l insertion tombait sur la cle etrangere. Les essais ne l avaient pas vu
 * parce qu ils passaient leur propre modele ; c est le navigateur qui l a montre.
 */
class DailyRewardController extends OGameController
{
    public function __construct(private DailyRewardService $rewards)
    {
    }

    /**
     * L etat courant, tel que la fenetre et le bouton doivent le montrer.
     *
     * Emploi : ouverture de la fenetre, retour sur un onglet inactif, et resynchronisation au passage de minuit.
     */
    public function state(): JsonResponse
    {
        return response()->json($this->presente());
    }

    /**
     * Reclamer la recompense du jour.
     *
     * Deux clics, deux onglets ou deux demandes simultanees ne creditent qu une fois : la contrainte unique de
     * `daily_rewards` tranche, et une seconde demande rend simplement l etat deja reclame.
     */
    public function claim(): JsonResponse
    {
        $issue = $this->rewards->claim($this->compte(), Date::now());

        if ($issue === DailyRewardService::CLOSED) {
            return response()->json([
                'success' => false,
                'message' => __('t_ingame.daily_reward.closed'),
            ] + $this->presente(), 409);
        }

        return response()->json([
            'success' => true,
            // **Deja reclamee n est pas une erreur** : c est le cas d une reponse perdue, et le joueur doit voir
            // son etat, pas un refus.
            'claimed_now' => $issue === DailyRewardService::CLAIMED,
            'message' => $issue === DailyRewardService::CLAIMED
                ? __('t_ingame.daily_reward.claimed')
                : __('t_ingame.daily_reward.already'),
        ] + $this->presente());
    }

    /**
     * La fenetre elle-meme, rendue par le serveur avec son etat de depart.
     *
     * Elle passe par le mecanisme de fenetre du jeu (`openOverlay`), comme l abandon de planete : meme habillage,
     * meme fermeture, meme comportement sur petit ecran.
     */
    public function overlay(): View
    {
        return view('ingame.dailyreward.overlay', ['state' => $this->presente()]);
    }

    /**
     * Le compte de la session, relu en base — jamais un modele garde en memoire.
     */
    private function compte(): User
    {
        $identifiant = (int)auth()->id();

        return User::query()->findOrFail($identifiant);
    }

    /**
     * L etat, plus ce que la page doit reecrire sans se recharger : le compteur de matiere noire.
     *
     * @return array<string, mixed>
     */
    private function presente(): array
    {
        // Le solde se relit sur le compte **apres** le credit, jamais reconstitue par addition cote navigateur.
        $utilisateur = $this->compte();
        $etat = $this->rewards->stateFor($utilisateur, Date::now());

        return $etat + [
            'dark_matter' => (int)$utilisateur->dark_matter,
            'dark_matter_formatted' => AppUtil::formatNumber((int)$utilisateur->dark_matter),
            'amount_formatted' => AppUtil::formatNumber($etat['amount']),
        ];
    }
}
