<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use OGame\Http\Controllers\Abstracts\AbstractBuildingsController;
use OGame\Hull\DamagedHulls;
use OGame\Hull\HullRepairPanel;
use OGame\Hull\HullRepairService;
use OGame\Models\HullRepairOrder;
use OGame\Services\BuildingQueueService;
use OGame\Services\HalvingService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;

class FacilitiesController extends AbstractBuildingsController
{
    /**
     * ResourcesController constructor.
     */
    public function __construct(
        BuildingQueueService $queue,
        private WreckFieldService $wreckFieldService,
        private HullRepairService $hullRepairs,
        private HullRepairPanel $hullRepairPanel,
    ) {
        $this->route_view_index = 'facilities.index';
        parent::__construct($queue);
    }

    /**
     * Shows the facilities index page
     *
     * @param Request $request
     * @param PlayerService $player
     * @return View
     * @throws Exception
     */
    public function index(Request $request, PlayerService $player): View
    {
        $this->setBodyId('station');
        $this->planet = $player->planets->current();

        // Prepare custom properties.
        // Header filename objects are the building IDs that make up the header filename
        // to be used in the background image of the page header.
        // IMPORTANT: Must be in OGame's build order (dependency chain), NOT numerical order.
        // 14->21->31->34->15->33 matches the actual image filenames from OGame.
        if ($this->planet->isPlanet()) {
            $this->header_filename_objects = [14, 21, 31, 34, 15, 33];
            $this->objects = [
                ['robot_factory', 'shipyard', 'research_lab', 'alliance_depot', 'missile_silo', 'nano_factory', 'terraformer', 'space_dock'],
            ];

            // **Le catalogue le connait toujours ; la page ne l offre que si les patrouilles sont
            // armees.** Les deux ne se confondent pas : un objet retire du catalogue ferait
            // disparaitre le niveau deja construit, sa file et son decompte de points. Ici seule
            // l offre de construction est conditionnee — proposer un reseau de surveillance avant
            // qu il existe des patrouilles a surveiller n aurait aucun sens, et l interrupteur est
            // baisse tant que le chantier n est pas complet.
            if (resolve(SettingsService::class)->patrolsEnabled()) {
                $this->objects[0][] = 'surveillance_network';
            }
        } elseif ($this->planet->isMoon()) {
            $this->header_filename_objects = [41, 42, 43];
            $this->objects = [
                ['robot_factory', 'shipyard', 'lunar_base', 'sensor_phalanx', 'jump_gate'],
            ];
        }

        $params = $this->indexPageParams($request, $player);

        // Add alliance depot level for button visibility
        if ($this->planet->isPlanet()) {
            $params['alliance_depot_level'] = $this->planet->getObjectLevel('alliance_depot');
        }

        return view('ingame.facilities.index')->with($params);
    }

    /**
     * Override indexPageParams to add wreck field data.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return array
     * @throws Exception
     */
    public function indexPageParams(Request $request, PlayerService $player): array
    {
        // Get parent parameters
        $params = parent::indexPageParams($request, $player);

        // Only expose wreck field data when the player has a Space Dock (level >= 1).
        $spaceDockLevel = $this->planet->getObjectLevel('space_dock');
        $wreckFieldData = $spaceDockLevel >= 1
            ? $this->wreckFieldService->getWreckFieldForCurrentPlanet($this->planet)
            : null;
        $params['wreckField'] = $wreckFieldData;

        // **La seconde fonction du dock, et elle reste separee de la premiere.** La recuperation
        // d epaves ci-dessus est gratuite et inchangee ; celle-ci repare des survivants contre
        // paiement. Les deux partagent un batiment, rien d autre — ni emplacement, ni file.
        $params['hullRepair'] = $this->hullRepairPanel->forPlanet($this->planet);

        return $params;
    }

    /**
     * Le devis pour reparer une selection d unites endommagees.
     *
     * **Le serveur calcule, le navigateur affiche.** Un prix compose cote client serait un prix que
     * le joueur pourrait choisir. La reponse porte son empreinte, que la confirmation renverra : ce
     * qui suit ne peut donc pas payer autre chose que ce qui a ete montre.
     */
    public function quoteHullRepair(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $selection = DamagedHulls::fromStorage($request->input('units'));
            $devis = $this->hullRepairs->quoteFor($this->planet, $selection);

            return response()->json([
                'success' => true,
                'cost' => [
                    'metal' => (int)$devis->cost->metal->get(),
                    'crystal' => (int)$devis->cost->crystal->get(),
                    'deuterium' => (int)$devis->cost->deuterium->get(),
                ],
                'duration_seconds' => $devis->durationSeconds,
                'dock_level' => $devis->dockLevel,
                'fingerprint' => $devis->fingerprint(),
            ]);
        } catch (Exception $refus) {
            return response()->json(['success' => false, 'reason' => $refus->getMessage()], 422);
        }
    }

    /**
     * Confirme un ordre de reparation : paie, reserve, ecrit — ou refuse en le disant.
     */
    public function startHullRepair(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $ordre = $this->hullRepairs->confirm(
                $this->planet,
                DamagedHulls::fromStorage($request->input('units')),
                (string)$request->input('fingerprint', ''),
                (int)Date::now()->timestamp,
            );

            return response()->json([
                'success' => true,
                'order_id' => (int)$ordre->id,
                'seconds_remaining' => max(0, $ordre->completed_at - (int)Date::now()->timestamp),
            ]);
        } catch (Exception $refus) {
            // **La raison est rendue telle quelle** : chaque refus porte une clef que l interface
            // traduit. Un « echec » sans raison forcerait le joueur a deviner.
            return response()->json(['success' => false, 'reason' => $refus->getMessage()], 422);
        }
    }

    /**
     * Annule un ordre en cours : le travail fait reste acquis, le reste est rembourse.
     */
    public function cancelHullRepair(Request $request, PlayerService $player): JsonResponse
    {
        $ordre = $this->hullRepairs->runningOrderOn($this->planet->getPlanetId());

        if ($ordre === null) {
            return response()->json(['success' => false, 'reason' => 'hull_repair.refused.no_order'], 422);
        }

        // **Le proprietaire est verifie ici aussi**, pas seulement par la session : l ordre nomme son
        // joueur, et c est ce lien qui decide — jamais le corps courant seul.
        if ((int)$ordre->player_id !== $player->getId()) {
            return response()->json(['success' => false, 'reason' => 'hull_repair.refused.not_owner'], 403);
        }

        $fait = $this->hullRepairs->endEarly($ordre, HullRepairOrder::BECAUSE_PLAYER, (int)Date::now()->timestamp);

        return response()->json(['success' => $fait]);
    }

    /**
     * Handles the facilities page AJAX requests.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     * @throws Exception
     */
    public function ajax(Request $request, PlayerService $player): JsonResponse
    {
        return $this->ajaxHandler($request, $player);
    }

    /**
     * Halve a building queue item using Dark Matter.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param HalvingService $halvingService
     * @return JsonResponse
     */
    public function halveBuilding(Request $request, PlayerService $player, HalvingService $halvingService): JsonResponse
    {
        try {
            $queueItemId = (int)$request->input('queue_item_id');

            if ($queueItemId <= 0) {
                return response()->json([
                    'success' => false,
                    'error' => true,
                    'message' => 'Invalid queue item ID',
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            $result = $halvingService->halveBuilding(
                $player->getUser(),
                $queueItemId,
                $player->planets->current()
            );

            return response()->json([
                'success' => true,
                'error' => false,
                'newAjaxToken' => csrf_token(),
                'new_time_end' => $result['new_time_end'],
                'cost' => $result['cost'],
                'new_balance' => $result['new_balance'],
                'remaining_time' => $result['remaining_time'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ]);
        }
    }

    /**
     * Complete a building queue item instantly using Dark Matter.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param HalvingService $halvingService
     * @return JsonResponse
     */
    public function completeBuilding(Request $request, PlayerService $player, HalvingService $halvingService): JsonResponse
    {
        try {
            $queueItemId = (int)$request->input('queue_item_id');

            if ($queueItemId <= 0) {
                return response()->json([
                    'success' => false,
                    'error' => true,
                    'message' => 'Invalid queue item ID',
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            $result = $halvingService->completeBuilding(
                $player->getUser(),
                $queueItemId,
                $player->planets->current()
            );

            session()->flash('success', __('You have successfully accelerated the order.'));

            return response()->json([
                'success' => true,
                'error' => false,
                'newAjaxToken' => csrf_token(),
                'cost' => $result['cost'],
                'new_balance' => $result['new_balance'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ]);
        }
    }

    /**
     * Start repairs for the wreck field.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function startRepairs(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $planetService = $player->planets->current();

            $wreckFieldService = new WreckFieldService($player, app(SettingsService::class));
            $wreckField = $wreckFieldService->getWreckFieldForCurrentPlanet($planetService);

            if (!$wreckField) {
                return response()->json([
                    'success' => false,
                    'error' => true,
                    'message' => __('wreck_field.error_no_wreck_field'),
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            // Get the space dock level from the current planet
            $spaceDockLevel = $planetService->getObjectLevel('space_dock');

            // Check if space dock exists (level >= 1)
            if ($spaceDockLevel < 1) {
                return response()->json([
                    'success' => false,
                    'error' => true,
                    'message' => __('wreck_field.error_space_dock_required'),
                    'newAjaxToken' => csrf_token(),
                ])->setStatusCode(400)->header('Content-Type', 'application/json');
            }

            // Load the wreck field for repairs
            $wreckFieldService->loadForCoordinates($planetService->getPlanetCoordinates());
            $wreckFieldService->startRepairs($spaceDockLevel);

            // Get updated data
            $updatedData = $wreckFieldService->getWreckFieldForCurrentPlanet($planetService);

            return response()->json([
                'success' => true,
                'error' => false,
                'newAjaxToken' => csrf_token(),
                'message' => __('wreck_field.repairs_started'),
                'wreckField' => $updatedData,
            ])->header('Content-Type', 'application/json');
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ])->setStatusCode(400)->header('Content-Type', 'application/json');
        }
    }

    /**
     * Complete repairs and add ships to planet.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function completeRepairs(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $planetService = $player->planets->current();
            $wreckFieldService = new WreckFieldService($player, app(SettingsService::class));
            $result = $wreckFieldService->collectRepairedShipsAtomic(
                $planetService->getPlanetCoordinates(),
                $planetService->getPlanetId()
            );
            // Manual recommission is treated as an invalid action when there is no collectible wreck field,
            // so this endpoint returns 400 instead of the legacy 200 used by some other wreck field actions.
            $statusCode = $result['success'] ? 200 : 400;

            return response()->json([
                ...$result,
                'newAjaxToken' => csrf_token(),
            ], $statusCode)->header('Content-Type', 'application/json');
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ])->setStatusCode(400)->header('Content-Type', 'application/json');
        }
    }

    /**
     * Burn the wreck field.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function burnWreckField(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $planetService = $player->planets->current();

            // Create a new WreckFieldService instance with the correct player
            $wreckFieldService = new WreckFieldService($player, app(SettingsService::class));
            $wreckField = $wreckFieldService->getWreckFieldForCurrentPlanet($planetService);

            if (!$wreckField) {
                return response()->json([
                    'success' => false,
                    'error' => true,
                    'message' => __('wreck_field.error_no_wreck_field'),
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            if ($wreckField['is_repairing']) {
                return response()->json([
                    'success' => false,
                    'error' => true,
                    'message' => __('wreck_field.cannot_burn'),
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            // Load the wreck field to burn it
            $wreckFieldService->loadForCoordinates($planetService->getPlanetCoordinates());

            $wreckFieldService->burnWreckField();

            return response()->json([
                'success' => true,
                'error' => false,
                'newAjaxToken' => csrf_token(),
                'message' => __('wreck_field.wreck_field_burned_success'),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ]);
        }
    }

    /**
     * Get wreck field status for AJAX updates.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function getWreckFieldStatus(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $planetService = $player->planets->current();

            $wreckFieldService = new WreckFieldService($player, app(SettingsService::class));

            $spaceDockLevel = $planetService->getObjectLevel('space_dock');
            $wreckField = $spaceDockLevel >= 1
                ? $wreckFieldService->getWreckFieldForCurrentPlanet($planetService)
                : null;

            return response()->json([
                'success' => true,
                'error' => false,
                'newAjaxToken' => csrf_token(),
                'wreckField' => $wreckField,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ]);
        }
    }

    /**
     * Show the destroy rockets overlay.
     *
     * @param PlayerService $player
     * @return View
     */
    public function destroyRocketsOverlay(PlayerService $player): View
    {
        $planetService = $player->planets->current();

        // Get current missile counts
        $ipm_count = $planetService->getObjectAmount('interplanetary_missile');
        $abm_count = $planetService->getObjectAmount('anti_ballistic_missile');

        // Get missile silo level and calculate capacity
        $silo_level = $planetService->getObjectLevel('missile_silo');
        $max_ipm_capacity = $silo_level * 5;  // 5 IPM per level
        $max_abm_capacity = $silo_level * 10; // 10 ABM per level

        return view('ingame.facilities.destroyrockets', [
            'ipm_count' => $ipm_count,
            'abm_count' => $abm_count,
            'silo_level' => $silo_level,
            'max_ipm_capacity' => $max_ipm_capacity,
            'max_abm_capacity' => $max_abm_capacity,
        ]);
    }

    /**
     * Destroy missiles (IPM/ABM) from the missile silo.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function destroyRockets(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $planetService = $player->planets->current();

            // Validate inputs
            $ipm_amount = (int)$request->input('ipm_amount', 0);
            $abm_amount = (int)$request->input('abm_amount', 0);

            // Validate at least one missile selected
            if ($ipm_amount <= 0 && $abm_amount <= 0) {
                return response()->json([
                    'success' => false,
                    'error' => __('Please select at least one missile to destroy'),
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            // Validate amounts don't exceed available
            $current_ipm = $planetService->getObjectAmount('interplanetary_missile');
            $current_abm = $planetService->getObjectAmount('anti_ballistic_missile');

            if ($ipm_amount > $current_ipm) {
                return response()->json([
                    'success' => false,
                    'error' => __('You do not have that many Interplanetary Missiles'),
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            if ($abm_amount > $current_abm) {
                return response()->json([
                    'success' => false,
                    'error' => __('You do not have that many Anti-Ballistic Missiles'),
                    'newAjaxToken' => csrf_token(),
                ]);
            }

            // Destroy missiles (no resource refund)
            if ($ipm_amount > 0) {
                $planetService->removeUnit('interplanetary_missile', $ipm_amount);
            }

            if ($abm_amount > 0) {
                $planetService->removeUnit('anti_ballistic_missile', $abm_amount);
            }

            // Build success message
            $message_parts = [];
            if ($ipm_amount > 0) {
                $message_parts[] = $ipm_amount . ' ' . __('Interplanetary Missile(s)');
            }
            if ($abm_amount > 0) {
                $message_parts[] = $abm_amount . ' ' . __('Anti-Ballistic Missile(s)');
            }
            $message = __('Destroyed') . ': ' . implode(', ', $message_parts);

            return response()->json([
                'success' => true,
                'message' => $message,
                'newAjaxToken' => csrf_token(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ]);
        }
    }
}
