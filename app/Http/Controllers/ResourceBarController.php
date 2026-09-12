<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\ViewModels\ResourceBarViewModel;

/**
 * Le bandeau des ressources, resynchronise sans changer de page.
 *
 * Le jeu appelle `getAjaxResourcebox()` apres un echange chez le marchand, un achat, un objet
 * utilise — et le module `resource-bar.js` l'appelle a chaque mouvement de flotte annonce au
 * joueur, puis en veille. Jusqu'au 12 septembre 2026 cette fonction telechargeait la Vue generale
 * entiere et tentait de la lire comme du JSON : le bandeau ne bougeait jamais, et une requete
 * lourde partait pour rien.
 *
 * ## Une lecture, et rien qu'une lecture — la regle qui gouverne ce point d'entree
 *
 * Cette route ne passe **pas** par `globalgame`, et le controleur n'ecrit rien. Ce n'est pas une
 * economie : c'est la seule facon de ne rien changer au jeu. Toute requete qui traverse
 * `globalgame` fait avancer deux horloges — `users.time`, que lit « en ligne », et
 * `planets.time_last_update`, que lit **l'etoile d'activite de la Galaxie**
 * (`GalaxyController::getPlanetActivityStatus()`, quinze minutes). Une veille toutes les trente
 * secondes les tiendrait allumees en permanence pour tout joueur ayant un onglet ouvert : le
 * signal tactique par lequel on choisit une cible dans OGame serait change pour tout le monde,
 * sans decision. Elle ferait en outre traiter les missions du joueur dans une requete de fond,
 * concurrente de ses propres clics, et rejouerait cent vingt fois par heure une mission qui leve.
 *
 * **Et rien n'est perdu**, parce que personne n'attend ce point d'entree pour travailler : aucun
 * planificateur ne traite les missions, ce sont les requetes des joueurs qui le font, et la liste
 * d'evenements du jeu redemande deja son contenu a la fin de chaque compte a rebours — donc une
 * flotte qui rentre est livree a la seconde, par ce chemin-la, et l'annonce qui suit amene ce
 * bandeau a relire. Ce qu'un autre joueur pose chez soi est livre par la requete de cet autre
 * joueur. Ce point d'entree n'a qu'a dire la verite du moment.
 *
 * La production, elle, est **projetee** : la formule du jeu (`updateResources`) appliquee sans
 * sauvegarde. Le joueur voit donc exactement ce qu'une page lui montrerait, et la base ne bouge
 * pas d'un octet.
 *
 * ## La planete de la page, pas celle du compte
 *
 * `users.planet_current` est une colonne du **compte** : ouvrir une seconde planete dans un autre
 * onglet la change pour tout le monde. Sans precaution, le bandeau d'un onglet afficherait les
 * stocks de la planete ouverte dans l'autre — sous une page qui en montre une autre. La page
 * publie donc la planete qu'elle a rendue, et la demande la nomme (`body`). Le corps demande doit
 * appartenir au joueur ; sinon, et faute de parametre, la planete courante du compte repond.
 * Rien n'est persiste : demander le bandeau ne change jamais la planete courante.
 */
class ResourceBarController extends OGameController
{
    public function show(Request $request, PlayerServiceFactory $playerServiceFactory): JsonResponse
    {
        $player = $playerServiceFactory->make((int)Auth::id(), true);

        return response()->json(ResourceBarViewModel::of($player, $this->bodyOf($request, $player))->ticker);
    }

    /**
     * Le corps que la page affiche, quand il appartient bien au joueur.
     */
    private function bodyOf(Request $request, PlayerService $player): PlanetService|null
    {
        $asked = (int)$request->input('body', 0);

        if ($asked <= 0 || !$player->planets->planetExistsAndOwnedByPlayer($asked)) {
            return null;
        }

        return $player->planets->getById($asked);
    }
}
