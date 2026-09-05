<?php

namespace OGame\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Presentation\CombatPanelService;
use OGame\Models\CombatInstance;
use OGame\Services\PlayerService;

/**
 * Le panneau des combats durables et le fil des pertes visibles.
 *
 * ## Ce que ces deux entrees garantissent
 *
 * Le joueur est celui de la session, l'heure est celle du serveur : aucun parametre du navigateur
 * ne choisit l'un ni l'autre. Un combat qui n'est pas celui du joueur repond 403, un combat inconnu
 * 404. Le fil ne rend que les pertes du joueur deja visibles a l'instant du serveur, sans numero de
 * round, sans rien du futur, et **sans aucune echeance de la bataille** — ni instant, ni secondes
 * restantes, que l'heure du serveur suffirait a retourner en instant.
 */
class CombatController extends OGameController
{
    /**
     * Les cartes de combat seules, telles que le deroulant « Evenements » les porte.
     *
     * Le navigateur les redemande pour rafraichir les batailles **sans** toucher aux lignes de
     * mouvement : remplacer le deroulant entier fermerait un detail ouvert et deplacerait le
     * defilement du joueur.
     */
    public function panel(PlayerService $player, CombatPanelService $panneau): View
    {
        return view('ingame.fleetevents.combatrows', [
            'combatPanel' => $panneau->forPlayer($player, (int)Date::now()->timestamp),
        ]);
    }

    /**
     * Les pertes du joueur deja visibles pour ce combat, apres un rang.
     */
    public function timeline(int $combatId, Request $request, PlayerService $player, CombatPanelService $panneau): JsonResponse
    {
        $combat = CombatInstance::query()->find($combatId);

        if ($combat === null) {
            return new JsonResponse(['error' => 'unknown_combat'], 404);
        }

        if (!$panneau->isPartyTo($combat, $player)) {
            return new JsonResponse(['error' => 'not_a_party'], 403);
        }

        $apres = max(0, (int)$request->query('after', '0'));
        $maintenant = (int)Date::now()->timestamp;
        $pertes = $panneau->visibleLosses($combat, $player->getId(), $maintenant, $apres);
        $dernier = $pertes === [] ? $apres : (int)$pertes[array_key_last($pertes)]['sequence'];

        return new JsonResponse([
            'server_now' => $maintenant,
            'status' => $combat->status->value,
            'status_label' => __('t_ingame.combat.status_' . $combat->status->value),
            'events' => $pertes,
            'next_after' => $dernier,
        ]);
    }
}
