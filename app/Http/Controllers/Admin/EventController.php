<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use OGame\GameMessages\EventStarted;
use OGame\Http\Controllers\OGameController;
use OGame\Models\EventMissionDraw;
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
            'rankStep' => $settings->eventRankStep(),
            'rankCount' => EventMissionService::RANK_COUNT,
            'potential' => $eventService->getPotential(),
            'running' => $eventService->isRunning(),
            'locked' => $this->configurationEstFigee($settings),
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
            'rank_step' => 'required|integer|min:100|max:100000',
        ]);

        $enabled = (bool)($data['enabled'] ?? false);
        $figee = $this->configurationEstFigee($settings);

        // Une fois que des joueurs ont recu leur tirage, les regles du jeu ne bougent
        // plus : le tirage est fige en base, les missions sont creditees a leur valeur
        // de l'epoque, et changer la date de debut orpheliniserait tout l'historique.
        // Seules la date de fin et la fermeture restent ouvertes.
        if ($figee) {
            $data['start'] = $settings->eventMissionsStart();
            $data['missions_per_day'] = $settings->eventMissionsPerDay();
            $data['rank_step'] = $settings->eventRankStep();

            // Raccourcir l'evenement en deca des jours deja joues effacerait du
            // tritium acquis : la fin ne peut pas remonter avant aujourd'hui.
            if ($data['end'] < Date::now()->format('Y-m-d')) {
                $data['end'] = $settings->eventMissionsEnd();
            }
        }

        // L'annonce part uniquement sur la bascule arret -> marche. Enregistrer a nouveau le
        // formulaire d'un evenement deja ouvert ne doit pas renvoyer un message a tout le
        // monde : c'est l'erreur qu'on ne peut pas rattraper une fois les messages ecrits.
        $etaitOuvert = $settings->eventMissionsEnabled();

        $settings->set('event_missions_enabled', $enabled ? '1' : '0');
        $settings->set('event_missions_start', $data['start']);
        $settings->set('event_missions_end', $data['end']);
        $settings->set('event_missions_per_day', (int)$data['missions_per_day']);
        $settings->set('event_rank_step', (int)$data['rank_step']);

        if ($enabled && !$etaitOuvert) {
            $nombre = $this->announce($data['start'], $data['end']);

            return redirect()->route('admin.event.index')
                ->with('status', 'Evenement ouvert. Annonce envoyee a ' . $nombre . ' joueur(s).');
        }

        $message = $enabled ? 'Evenement mis a jour.' : 'Evenement ferme.';

        if ($figee && $enabled) {
            $message .= ' Les tirages ayant commence, seule la date de fin a pu changer.';
        }

        return redirect()->route('admin.event.index')->with('status', $message);
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

    /**
     * Returns whether the event configuration can no longer be changed.
     *
     * Le verrou se declenche des qu'un joueur a recu son premier tirage, et non a
     * l'ouverture : entre les deux, l'administrateur peut encore corriger une date sans
     * consequence.
     *
     * @param SettingsService $settings
     * @return bool
     */
    private function configurationEstFigee(SettingsService $settings): bool
    {
        $debut = $settings->eventMissionsStart();

        if (!$settings->eventMissionsEnabled() || $debut === '') {
            return false;
        }

        return EventMissionDraw::whereDate('event_start', $debut)->exists();
    }
}
