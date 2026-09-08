<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OGame\Galaxy\FleetMovementProjection;
use OGame\Galaxy\GalaxyHeaderCounters;
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
 */
class GalaxyFleetsController extends OGameController
{
    public function index(Request $request, PlayerService $player, FleetMissionService $fleetMissionService): JsonResponse
    {
        $galaxy = (int)$request->input('galaxy', 0);
        $system = (int)$request->input('system', 0);

        if ($galaxy < 1 || $system < 1) {
            return response()->json(['success' => false, 'error' => __('t_ingame.galaxy.fleets_bad_system')], 422);
        }

        $projection = new FleetMovementProjection($fleetMissionService, $player);

        return response()->json([
            'success' => true,
            'galaxy' => $galaxy,
            'system' => $system,
            'server_now' => $projection->serverNow(),
            'movements' => $projection->inSystem($galaxy, $system),
            // Les compteurs du bandeau, a jour a chaque mouvement : la carte redemande cette couche
            // sur le canal du joueur, la ou la photographie ne se relit qu'au changement de systeme.
            'counters' => GalaxyHeaderCounters::of($player),
        ]);
    }
}
