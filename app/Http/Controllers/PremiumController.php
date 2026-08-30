<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use OGame\Services\DarkMatterService;
use OGame\Services\OfficerService;
use OGame\Services\PlayerService;
use RuntimeException;

class PremiumController extends OGameController
{
    /**
     * Shows the premium/officers index page
     *
     * @param PlayerService $player
     * @param OfficerService $officerService
     * @param DarkMatterService $darkMatterService
     * @return View
     */
    public function index(PlayerService $player, OfficerService $officerService, DarkMatterService $darkMatterService): View
    {
        $this->setBodyId('premium');

        return view('ingame.premium.index', [
            'darkMatter' => $darkMatterService->getBalance($player->getUser()),
            'officers' => $officerService->getOverview($player),
            'activeCount' => $officerService->countActive($player->getUser()),
            'totalOfficers' => count(OfficerService::OFFICERS),
        ]);
    }

    /**
     * Hires an officer for a given duration, paid in dark matter.
     *
     * @param PlayerService $player
     * @param OfficerService $officerService
     * @return RedirectResponse
     */
    public function hire(PlayerService $player, OfficerService $officerService): RedirectResponse
    {
        $data = request()->validate([
            'officer' => 'required|string|in:' . implode(',', OfficerService::OFFICERS),
            'days' => 'required|integer|in:' . implode(',', $officerService->getDurations()),
        ]);

        try {
            $officerService->hire($player, $data['officer'], (int)$data['days']);
        } catch (RuntimeException $e) {
            return redirect()->route('premium.index')->with('error', $e->getMessage());
        }

        return redirect()->route('premium.index')->with('status', __('t_ingame.premium.hire_success'));
    }
}
