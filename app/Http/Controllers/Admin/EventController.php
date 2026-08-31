<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use OGame\GameMessages\EventStarted;
use OGame\Http\Controllers\OGameController;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Services\EventMissionService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Ouverture et fermeture de l'evenement de missions quotidiennes.
 */
class EventController extends OGameController
{
    /**
     * Shows the event configuration form.
     *
     * @param PlayerService $player
     * @param SettingsService $settings
     * @param EventMissionService $eventService
     * @return View
     */
    public function index(PlayerService $player, SettingsService $settings, EventMissionService $eventService): View
    {
        return view('ingame.admin.event', [
            'enabled' => $settings->eventMissionsEnabled(),
            'start' => $settings->eventMissionsStart(),
            'end' => $settings->eventMissionsEnd(),
            'missionsPerDay' => $settings->eventMissionsPerDay(),
            'running' => $eventService->isRunning(),
        ]);
    }

    /**
     * Saves the event configuration, announcing it when it is switched on.
     *
     * @param PlayerService $player
     * @param SettingsService $settings
     * @return RedirectResponse
     */
    public function update(PlayerService $player, SettingsService $settings): RedirectResponse
    {
        $data = request()->validate([
            'enabled' => 'nullable|boolean',
            'start' => 'required|date_format:Y-m-d',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start',
            'missions_per_day' => 'required|integer|min:1|max:15',
        ]);

        $enabled = (bool)($data['enabled'] ?? false);

        // L'annonce part uniquement sur la bascule arret -> marche. Enregistrer a nouveau le
        // formulaire d'un evenement deja ouvert ne doit pas renvoyer un message a tout le
        // monde : c'est l'erreur qu'on ne peut pas rattraper une fois les messages ecrits.
        $etaitOuvert = $settings->eventMissionsEnabled();

        $settings->set('event_missions_enabled', $enabled ? '1' : '0');
        $settings->set('event_missions_start', $data['start']);
        $settings->set('event_missions_end', $data['end']);
        $settings->set('event_missions_per_day', (int)$data['missions_per_day']);

        if ($enabled && !$etaitOuvert) {
            $nombre = $this->announce($data['start'], $data['end']);

            return redirect()->route('admin.event.index')
                ->with('status', 'Evenement ouvert. Annonce envoyee a ' . $nombre . ' joueur(s).');
        }

        return redirect()->route('admin.event.index')
            ->with('status', $enabled ? 'Evenement mis a jour.' : 'Evenement ferme.');
    }

    /**
     * Sends the opening announcement to every player.
     *
     * L'insertion se fait par lots comme pour les annonces d'administration : sur un univers
     * peuple, une insertion par joueur saturerait la requete.
     *
     * @param string $start
     * @param string $end
     * @return int Nombre de joueurs prevenus.
     */
    private function announce(string $start, string $end): int
    {
        $key = resolve(EventStarted::class)->getKey();
        $params = [
            'start' => Date::parse($start)->format('d/m/Y'),
            'end' => Date::parse($end)->format('d/m/Y'),
        ];

        $count = 0;

        User::query()->select('id')->chunkById(200, function ($users) use ($key, $params, &$count) {
            $rows = [];
            foreach ($users as $user) {
                $rows[] = [
                    'user_id' => $user->id,
                    'key' => $key,
                    'params' => json_encode($params),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $count++;
            }
            Message::insert($rows);
        });

        return $count;
    }
}
