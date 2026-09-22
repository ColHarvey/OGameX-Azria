<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use OGame\Empire\EmpireOrder;
use OGame\Empire\EmpireProjection;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\PlayerService;

/**
 * **La vue Empire : une ouverture qui fait avancer le compte, une actualisation qui ne touche a rien.**
 *
 * Les deux ne peuvent pas vivre sur la meme route. `globalgame` **ecrit** par construction — il avance le joueur, sa
 * planete courante, ses missions, ses demenagements dus — et c est exactement ce qu on attend en **ouvrant** une page
 * de jeu. Une **actualisation**, elle, doit pouvoir etre demandee sans que le compte bouge : sinon le joueur
 * paraitrait actif dans la Galaxie et « en ligne » a chaque clic sur le bouton, et ses missions seraient traitees dans
 * une requete concurrente de ses propres gestes. C est la raison, deja ecrite au-dessus du groupe de routes, pour
 * laquelle le bandeau des ressources et les non-lus du chat vivent hors de ce middleware.
 *
 * D ou deux routes :
 *
 * - `GET /empire` — groupe `globalgame`, comme toute page de jeu ;
 * - `GET /ajax/empire` — `auth` et `banned` seulement : **aucune ecriture**, ni de jeu ni de session.
 *
 * `POST /ajax/empire/order` reste dans le groupe complet : ranger ses colonnes est un geste du joueur, pas une veille.
 */
class EmpireController extends OGameController
{
    /**
     * La page, servie a tout joueur connecte.
     *
     * **Aucun officier n est exige** : c est une adaptation assumee d Azria — le jeu officiel reserve cette vue au
     * Commandant. La difference est documentee, pas cachee.
     */
    public function index(Request $request, PlayerService $player, EmpireProjection $projection): View
    {
        // La feuille heritee habille toute la page par `#empire` : le fond, le pied, la boite de message.
        $this->setBodyId('empire');

        $moons = $this->moonsAsked($request, $player);

        return view('ingame.empire.index', [
            'empire' => $projection->of($player, $moons, (int)Date::now()->timestamp),
            'moons' => $moons,
            // Les quelques phrases dont le navigateur a besoin apres le premier rendu : elles sont traduites ici,
            // jamais dans le script.
            'empireLoca' => [
                'legend' => __('t_ingame.empire.tech_legend'),
                'averageLevel' => __('t_ingame.empire.average_level_short'),
                'noMoons' => __('t_ingame.empire.no_moons'),
                'noMoonsHint' => __('t_ingame.empire.no_moons_hint'),
                'refreshFailed' => __('t_ingame.empire.refresh_failed'),
            ],
        ]);
    }

    /**
     * L actualisation : la meme photographie, en JSON, **sans rien ecrire**.
     *
     * Le joueur est celui de la session — jamais un identifiant de la requete —, et la projection ne parcourt que
     * `planets->allPlanets()` / `allMoons()` de ce joueur : aucun corps d un tiers ne peut y entrer.
     */
    public function refresh(Request $request, PlayerServiceFactory $playerServiceFactory, EmpireProjection $projection): JsonResponse
    {
        $player = $playerServiceFactory->make((int)Auth::id(), true);
        $moons = $this->moonsAsked($request, $player);

        return response()->json($projection->of($player, $moons, (int)Date::now()->timestamp));
    }

    /**
     * L ordre des colonnes, range par le joueur.
     *
     * Seuls ses corps sont retenus : un identifiant qui ne lui appartient pas est **ecarte en silence**, il ne peut ni
     * entrer dans sa preference ni lui reveler quoi que ce soit. `reset` oublie l ordre du genre demande.
     */
    public function order(Request $request, PlayerService $player): JsonResponse
    {
        // Les deux genres que le code client envoie, et le mot qu il emploie pour oublier l ordre.
        $type = (string)$request->input('type', '');
        $moons = $type === 'impSortOrderMoon' || $request->boolean('moons');

        if ($type === 'reset') {
            EmpireOrder::forget($player->getUser(), $moons);

            return response()->json(['success' => true, 'order' => []]);
        }

        $asked = $request->input('planets', []);
        if (!is_array($asked)) {
            $asked = [];
        }

        $owned = [];
        foreach ($moons ? $player->planets->allMoons() : $player->planets->allPlanets() as $body) {
            $owned[$body->getPlanetId()] = true;
        }

        $order = [];
        foreach ($asked as $id) {
            // Le code client range des identifiants d elements (`planet123`), et `planet0` designe la colonne des
            // totaux : elle garde sa place dans l ordre sans etre un corps.
            $id = (int)preg_replace('/\D+/', '', (string)$id);
            if (($id === 0 || isset($owned[$id])) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        EmpireOrder::store($player->getUser(), $moons, $order);

        return response()->json(['success' => true, 'order' => $order]);
    }

    /**
     * L onglet demande. Un compte sans lune n a pas d onglet des lunes : la demande retombe sur les planetes, et la
     * page dit pourquoi plutot que de dessiner une grille vide.
     */
    private function moonsAsked(Request $request, PlayerService $player): bool
    {
        return (int)$request->query('planetType', '0') === 1 && $player->planets->allMoons() !== [];
    }
}
