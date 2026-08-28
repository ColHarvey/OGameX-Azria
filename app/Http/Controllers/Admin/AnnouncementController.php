<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use OGame\GameMessages\AdminAnnouncement;
use OGame\Http\Controllers\OGameController;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Services\PlayerService;

class AnnouncementController extends OGameController
{
    public function index(PlayerService $player): View
    {
        return view('ingame.admin.announcement');
    }

    public function send(PlayerService $player): RedirectResponse
    {
        $data = request()->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
        ]);

        $key = resolve(AdminAnnouncement::class)->getKey();
        $count = 0;

        User::query()->select('id')->chunkById(200, function ($users) use ($key, $data, &$count) {
            $rows = [];
            foreach ($users as $user) {
                $rows[] = [
                    'user_id' => $user->id,
                    'key' => $key,
                    'params' => json_encode([
                        'subject' => $data['subject'],
                        'body' => $data['body'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $count++;
            }
            Message::insert($rows);
        });

        return redirect()->route('admin.announcement.index')
            ->with('status', "Annonce envoyée à {$count} joueur(s).");
    }
}
