<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use OGame\Services\PlayerService;
use OGame\Services\StarterAidService;
use RuntimeException;

class RewardsController extends OGameController
{
    /**
     * Shows the rewards index page
     *
     * @param PlayerService $player
     * @param StarterAidService $starterAidService
     * @return View
     */
    public function index(PlayerService $player, StarterAidService $starterAidService): View
    {
        $overview = $starterAidService->getOverview($player);

        // Le regroupement est fait ici pour que la vue reste purement declarative.
        return view('ingame.rewards.index', [
            'available' => array_filter($overview, fn (array $r): bool => $r['state'] === 'claimable'),
            'upcoming' => array_filter($overview, fn (array $r): bool => in_array($r['state'], ['locked', 'unavailable'], true)),
            'collected' => array_filter($overview, fn (array $r): bool => $r['state'] === 'claimed'),
            'playerName' => $player->getUsername(false),
        ]);
    }

    /**
     * Claims a single starter aid reward.
     *
     * @param PlayerService $player
     * @param StarterAidService $starterAidService
     * @return RedirectResponse
     */
    public function claim(PlayerService $player, StarterAidService $starterAidService): RedirectResponse
    {
        $data = request()->validate([
            'day' => 'required|integer|min:1|max:' . StarterAidService::TOTAL_DAYS,
        ]);

        try {
            $starterAidService->claim($player, (int)$data['day']);
        } catch (RuntimeException $e) {
            return redirect()->route('rewards.index')->with('error', $e->getMessage());
        }

        return redirect()->route('rewards.index')->with('status', __('t_ingame.rewards.claim_success'));
    }
}
