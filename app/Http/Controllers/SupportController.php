<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use OGame\Services\PlayerService;

class SupportController extends OGameController
{
    public function index(PlayerService $player): View
    {
        return view('ingame.support.index');
    }

    public function send(PlayerService $player): RedirectResponse
    {
        $data = request()->validate([
            'subject' => 'required|string|max:150',
            'body' => 'required|string|min:10|max:5000',
        ]);

        $user = $player->getUser();
        $cle = 'support:' . $user->id;

        if (RateLimiter::tooManyAttempts($cle, 3)) {
            $secondes = RateLimiter::availableIn($cle);
            return back()->withErrors([
                'body' => 'Trop de demandes envoyées. Réessayez dans ' . ceil($secondes / 60) . ' minutes.',
            ])->withInput();
        }

        RateLimiter::hit($cle, 3600);

        $corps = "Nouvelle demande de support\n\n"
            . "Joueur  : " . $user->username . " (ID " . $user->id . ")\n"
            . "Email   : " . $user->email . "\n"
            . "Pays    : " . ($user->country ?: 'inconnu') . "\n"
            . "IP      : " . request()->ip() . "\n"
            . "Date    : " . now()->format('d/m/Y H:i') . "\n\n"
            . "Sujet   : " . $data['subject'] . "\n\n"
            . "Message :\n" . $data['body'] . "\n";

        try {
            Mail::raw($corps, function ($m) use ($data, $user) {
                $m->to('admin@azriagaming.ca')
                  ->replyTo($user->email, $user->username)
                  ->subject('[Support] ' . $data['subject']);
            });
        } catch (Exception $e) {
            Log::warning('Support mail failed: ' . $e->getMessage());
            return back()->withErrors([
                'body' => "L'envoi a échoué. Réessayez plus tard ou écrivez directement à admin@azriagaming.ca",
            ])->withInput();
        }

        return redirect()->route('support.index')
            ->with('status', 'Votre demande a bien été envoyée. Vous recevrez une réponse par email.');
    }
}
