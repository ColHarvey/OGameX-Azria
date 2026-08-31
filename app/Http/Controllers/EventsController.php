<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use OGame\Services\EventMissionService;
use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Page joueur de l'evenement de missions quotidiennes.
 */
class EventsController extends OGameController
{
    /**
     * Shows the event page: today's missions and the five ranks.
     *
     * @param PlayerService $player
     * @param EventMissionService $eventService
     * @return View
     */
    public function index(PlayerService $player, EventMissionService $eventService): View
    {
        if (!$eventService->isRunning()) {
            return view('ingame.events.closed');
        }

        return view('ingame.events.index', [
            'missions' => $eventService->getDailyMissions($player),
            'ranks' => $eventService->getRanks($player),
            'tritium' => $eventService->getTritium($player),
            'maxTritium' => $eventService->getMaxTritium($player),
            'start' => $eventService->getStart(),
            'end' => $eventService->getEnd(),
            'currentPlanetName' => $player->planets->current()->getPlanetName(),
        ]);
    }

    /**
     * Claims one of today's missions.
     *
     * @param PlayerService $player
     * @param EventMissionService $eventService
     * @return RedirectResponse
     */
    public function claimMission(PlayerService $player, EventMissionService $eventService): RedirectResponse
    {
        $data = request()->validate([
            'mission' => 'required|string|max:64',
        ]);

        try {
            $eventService->claimMission($player, $data['mission']);
        } catch (RuntimeException $e) {
            return redirect()->route('events.index')->with('error', $e->getMessage());
        }

        return redirect()->route('events.index')->with('status', __('t_ingame.events.claim_success'));
    }

    /**
     * Claims one rank reward.
     *
     * @param PlayerService $player
     * @param EventMissionService $eventService
     * @return RedirectResponse
     */
    public function claimRank(PlayerService $player, EventMissionService $eventService): RedirectResponse
    {
        $data = request()->validate([
            'rank' => 'required|integer|min:1|max:' . EventMissionService::RANK_COUNT,
            'reward' => 'required|string|max:64',
        ]);

        try {
            $eventService->claimRank($player, (int)$data['rank'], $data['reward']);
        } catch (RuntimeException $e) {
            return redirect()->route('events.index')->with('error', $e->getMessage());
        }

        return redirect()->route('events.index')->with('status', __('t_ingame.events.rank_claim_success'));
    }
}
