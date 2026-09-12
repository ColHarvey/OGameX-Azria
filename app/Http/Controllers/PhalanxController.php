<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\AllianceClassService;
use OGame\Services\PhalanxService;
use OGame\Services\PlayerService;

class PhalanxController extends OGameController
{
    /**
     * Scan a planet using sensor phalanx.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param PlanetServiceFactory $planetServiceFactory
     * @param PhalanxService $phalanxService
     * @return JsonResponse
     * @throws Exception
     */
    public function scan(Request $request, PlayerService $player, PlanetServiceFactory $planetServiceFactory, PhalanxService $phalanxService): JsonResponse
    {
        // Validate request
        $request->validate([
            'galaxy' => 'required|integer|min:1',
            'system' => 'required|integer|min:1|max:' . UniverseConstants::MAX_SYSTEM_COUNT,
            'position' => 'required|integer|min:1|max:' . UniverseConstants::MAX_PLANET_POSITION,
        ]);

        // Create default response structure
        $response = [
            'success' => true,
            'server_time' => time(),
            'target' => [
                'galaxy' => (int)$request->input('galaxy'),
                'system' => (int)$request->input('system'),
                'position' => (int)$request->input('position'),
                'planet_name' => '',
                'player_name' => '',
            ],
        ];

        $current_planet = $player->planets->current();

        // Check if current planet is a moon
        if (!$current_planet->isMoon()) {
            $response['is_error'] = true;
            $response['error_message'] = 'Sensor Phalanx can only be used from a moon.';
            return response()->json($response);
        }

        // Get sensor phalanx level
        $phalanx_level = $current_planet->getObjectLevel('sensor_phalanx');

        if ($phalanx_level === 0) {
            $response['is_error'] = true;
            $response['error_message'] = 'No Sensor Phalanx built on this moon.';
            return response()->json($response);
        }

        // Create target coordinates
        $target_coordinate = new Coordinate(
            (int)$request->input('galaxy'),
            (int)$request->input('system'),
            (int)$request->input('position')
        );

        // Get moon coordinates
        $moon_coordinates = $current_planet->getPlanetCoordinates();

        // Check if target is in range (includes Discoverer +20% bonus if applicable)
        if (!$phalanxService->canScanTarget($moon_coordinates->galaxy, $moon_coordinates->system, $phalanx_level, $target_coordinate, $player->getId())) {
            $max_range = $phalanxService->calculatePhalanxRange($phalanx_level, $player->getId());
            $response['is_error'] = true;
            $response['error_message'] = 'Target is out of range. Your sensor phalanx (Level ' . $phalanx_level . ') can scan up to ' . $max_range . ' systems away.';
            return response()->json($response);
        }

        // Check if enough deuterium
        if (!$phalanxService->hasEnoughDeuterium($current_planet->deuterium()->get())) {
            $response['is_error'] = true;
            $response['error_message'] = 'Not enough Deuterium!';
            return response()->json($response);
        }

        // Load target planet
        try {
            $target_planet = $planetServiceFactory->makePlanetForCoordinate($target_coordinate);
        } catch (Exception $e) {
            $response['is_error'] = true;
            $response['error_message'] = 'No planet found at these coordinates.';
            return response()->json($response);
        }

        if ($target_planet === null) {
            $response['is_error'] = true;
            $response['error_message'] = 'No planet found at these coordinates.';
            return response()->json($response);
        }

        $target_player = $target_planet->getPlayer();
        if ($target_player === null) {
            $response['is_error'] = true;
            $response['error_message'] = 'No planet found at these coordinates.';
            return response()->json($response);
        }

        // Cannot scan moons (OGame rule)
        if ($target_planet->isMoon()) {
            $response['target']['planet_name'] = $target_planet->getPlanetName();
            $response['target']['player_name'] = $target_player->getUsername();
            $response['is_error'] = true;
            $response['error_message'] = 'Moons cannot be scanned with Sensor Phalanx.';
            return response()->json($response);
        }

        // Cannot scan admin planets
        if ($target_player->isAdmin()) {
            $response['target']['planet_name'] = $target_planet->getPlanetName();
            $response['target']['player_name'] = $target_player->getUsername();
            $response['is_error'] = true;
            $response['error_message'] = 'Administrator planets cannot be scanned.';
            return response()->json($response);
        }

        // Cannot scan own planets
        if ($target_player->getId() === $player->getId()) {
            $response['target']['planet_name'] = $target_planet->getPlanetName();
            $response['target']['player_name'] = $target_player->getUsername();
            $response['is_error'] = true;
            $response['error_message'] = 'You cannot scan your own planets.';
            return response()->json($response);
        }

        // Perform scan
        $fleet_movements = $phalanxService->scanPlanetFleets($target_planet->getPlanetId(), $player->getId());

        // Deduct deuterium cost
        $scan_cost = new Resources(0, 0, $phalanxService->getScanCost(), 0);
        $current_planet->deductResources($scan_cost);

        $content_html = view('ingame.phalanx.content', [
            'fleet_movements' => $fleet_movements,
            'server_time' => time(),
            'scanner_player_id' => $player->getId(),
        ])->render();

        // Return scan results
        return response()->json([
            'success' => true,
            'server_time' => time(),
            'target' => [
                'galaxy' => $target_coordinate->galaxy,
                'system' => $target_coordinate->system,
                'position' => $target_coordinate->position,
                'planet_name' => $target_planet->getPlanetName(),
                'player_name' => $target_player->getUsername(),
            ],
            'scan_cost' => $phalanxService->getScanCost(),
            'fleet_count' => count($fleet_movements),
            'content_html' => $content_html,
        ]);
    }

    /**
     * Analyser un systeme entier depuis une lune — bonus d'une alliance de Chercheurs.
     *
     * **Le droit est verifie ici, pas seulement dans la page.** Un relevé de systeme entier au prix
     * d'un seul relevé est un avantage economique : un bouton absent ne protege rien, la requete se
     * rejoue. Les autres conditions sont exactement celles de l'analyse ordinaire — une lune, une
     * Phalange, la portee, le deuterium —, verifiees dans le meme ordre pour que le joueur lise
     * toujours le premier refus qui le concerne.
     *
     * @throws Exception
     */
    public function scanSystem(Request $request, PlayerService $player, PhalanxService $phalanxService, AllianceClassService $allianceClassService): JsonResponse
    {
        $request->validate([
            'galaxy' => 'required|integer|min:1',
            'system' => 'required|integer|min:1|max:' . UniverseConstants::MAX_SYSTEM_COUNT,
        ]);

        $galaxy = (int)$request->input('galaxy');
        $system = (int)$request->input('system');

        $response = [
            'success' => true,
            'server_time' => time(),
            'target' => [
                'galaxy' => $galaxy,
                'system' => $system,
            ],
        ];

        $refus = static function (string $message) use ($response): JsonResponse {
            $response['is_error'] = true;
            $response['error_message'] = $message;

            return response()->json($response);
        };

        if (!$allianceClassService->mayPhalanxWholeSystems($player->getUser())) {
            return $refus(__('t_ingame.galaxy.system_phalanx_not_allowed'));
        }

        $current_planet = $player->planets->current();

        if (!$current_planet->isMoon()) {
            return $refus(__('t_ingame.galaxy.system_phalanx_needs_moon'));
        }

        $phalanx_level = $current_planet->getObjectLevel('sensor_phalanx');

        if ($phalanx_level === 0) {
            return $refus(__('t_ingame.galaxy.system_phalanx_needs_phalanx'));
        }

        $moon_coordinates = $current_planet->getPlanetCoordinates();

        // **La portee ne depend pas de la position.** Un systeme entier est a une seule distance de
        // la lune ; la position posee ici ne sert qu'a former une coordonnee complete.
        $target_coordinate = new Coordinate($galaxy, $system, 1);

        if (!$phalanxService->canScanTarget($moon_coordinates->galaxy, $moon_coordinates->system, $phalanx_level, $target_coordinate, $player->getId())) {
            return $refus(__('t_ingame.galaxy.system_phalanx_out_of_range', [
                'level' => $phalanx_level,
                'range' => $phalanxService->calculatePhalanxRange($phalanx_level, $player->getId()),
            ]));
        }

        if (!$phalanxService->hasEnoughDeuterium($current_planet->deuterium()->get())) {
            return $refus(__('t_ingame.galaxy.system_phalanx_not_enough_deuterium'));
        }

        /*
         * **Un systeme sans rien a analyser ne se paie pas.** L'analyse d'une seule planete refuse
         * gratuitement une case vide ; celle du systeme faisait payer un relevé vide.
         */
        if ($phalanxService->scannableBodiesInSystem($galaxy, $system, $player->getId()) === []) {
            return $refus(__('t_ingame.galaxy.system_phalanx_nothing_to_scan'));
        }

        /*
         * **Payer d'abord, relever ensuite — et le prix est celui d'un seul relevé.**
         *
         * Le controle du solde ci-dessus lit la valeur chargee au debut de la requete : un envoi de
         * flotte depuis la meme lune peut la faire tomber entre-temps. Le debit est un `UPDATE`
         * conditionnel, qui ne passe jamais sous zero, et **son refus devient le refus lisible du
         * joueur** — au lieu de l'exception qui rendait une erreur 500 apres un relevé deja calcule.
         * Rien n'est releve pour qui n'a pas paye.
         */
        if (!$current_planet->deductResourcesAtomic(new Resources(0, 0, $phalanxService->getScanCost(), 0))) {
            return $refus(__('t_ingame.galaxy.system_phalanx_not_enough_deuterium'));
        }

        $fleet_movements = $phalanxService->scanSystemFleets($galaxy, $system, $player->getId());

        $content_html = view('ingame.phalanx.content', [
            'fleet_movements' => $fleet_movements,
            'server_time' => time(),
            'scanner_player_id' => $player->getId(),
        ])->render();

        return response()->json([
            'success' => true,
            'server_time' => time(),
            'target' => [
                'galaxy' => $galaxy,
                'system' => $system,
            ],
            'scan_cost' => $phalanxService->getScanCost(),
            'fleet_count' => count($fleet_movements),
            'content_html' => $content_html,
        ]);
    }
}
