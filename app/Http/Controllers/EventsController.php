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

        // Le credit est une operation a part entiere, distincte de l affichage, et il
        // porte sur tous les jours de l evenement : un joueur qui accomplit ses missions
        // le lundi et n ouvre la page que le vendredi retrouve son tritium intact.
        $eventService->creditEventDays($player);

        $missions = $eventService->getDailyMissions($player);

        return view('ingame.events.index', [
            'missions' => $missions,
            'ranks' => $eventService->getRanks($player),
            'tritium' => $eventService->getTritium($player),
            'start' => $eventService->getStart(),
            'end' => $eventService->getEnd(),
            'currentPlanetName' => $player->planets->current()->getPlanetName(),
            'staffActive' => $eventService->hasCommandingStaff($player),
            'daysLeft' => $eventService->getDaysLeft(),
        ]);
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
