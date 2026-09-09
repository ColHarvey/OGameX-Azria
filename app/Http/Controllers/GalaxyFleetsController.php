<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OGame\Galaxy\FleetMovementProjection;
use OGame\Galaxy\GalaxyHeaderCounters;
use OGame\Galaxy\PatrolProjection;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolUpkeep;
use OGame\Patrol\SurveillanceProjection;
use OGame\Services\FleetMissionService;
use OGame\Services\PlayerService;

/**
 * Les mouvements de flotte d'un systeme, pour la carte tactique.
 *
 * Un point d'entree separe de `GalaxyController::ajax()`, et c'est voulu : la photographie du
 * systeme est **publique** — tout joueur connecte voit les memes planetes —, les mouvements sont
 * **prives** — chaque joueur voit les siens et ceux qui le visent. Deux natures d'information,
 * deux reponses ; une seule les aurait melangees, et la charge publique aurait fini par porter un
 * champ prive.
 *
 * Le joueur est celui de la session (`globalgame`), jamais un parametre. Le systeme, lui, est
 * demande : regarder un systeme lointain ne donne aucun droit de plus — le filtre part de ce que
 * le joueur sait deja, et ne fait que le decouper.
 *
 * Les patrouilles du joueur voyagent dans la meme reponse, sous `patrols` : elles sont privees au
 * meme titre, et la carte les redemande au meme signal.
 */
class GalaxyFleetsController extends OGameController
{
    public function index(
        Request $request,
        PlayerService $player,
        FleetMissionService $fleetMissionService,
        PatrolOrders $orders,
        PatrolUpkeep $upkeep,
    ): JsonResponse {
        $galaxy = (int)$request->input('galaxy', 0);
        $system = (int)$request->input('system', 0);

        if ($galaxy < 1 || $system < 1) {
            return response()->json(['success' => false, 'error' => __('t_ingame.galaxy.fleets_bad_system')], 422);
        }

        $projection = new FleetMovementProjection($fleetMissionService, $player);
        $now = $projection->serverNow();

        return response()->json([
            'success' => true,
            'galaxy' => $galaxy,
            'system' => $system,
            'server_now' => $now,
            'movements' => $projection->inSystem($galaxy, $system),
            'patrols' => (new PatrolProjection($player, $orders, $upkeep))->inSystem($galaxy, $system, $now),
            // **Les patrouilles etrangeres arrivent par une clef distincte, et reduites a leur droit.**
            // Les siennes et celles d autrui n obeissent pas aux memes regles : melanger les deux
            // listes ferait porter a une seule projection deux jeux de faits, et la moins stricte
            // finirait par gouverner. Ici la surveillance omet ce qu elle ne peut pas dire, et la
            // liste est vide pour qui n a pas de detecteur acquis.
            'surveillance' => resolve(SurveillanceProjection::class)->inSystem($player->getId(), $galaxy, $system, $now),
            // Les compteurs du bandeau, a jour a chaque mouvement : la carte redemande cette couche
            // sur le canal du joueur, la ou la photographie ne se relit qu'au changement de systeme.
            'counters' => GalaxyHeaderCounters::of($player),
        ]);
    }
}
