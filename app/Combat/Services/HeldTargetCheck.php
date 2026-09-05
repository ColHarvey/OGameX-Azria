<?php

namespace OGame\Combat\Services;

use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Services\SettingsService;

/**
 * Un corps qu'un combat tient ne recoit aucun nouveau lancement de missile.
 *
 * ## La regle, et pourquoi elle se verifie au lancement
 *
 * La matrice distingue le lancement de l'arrivee : cible deja verrouillee, le lancement est refuse ;
 * missile deja en vol et admissible avant la fermeture, son impact entre dans la photographie ;
 * arrivee pendant la bataille, l'impact attend le reglement. Sans ce refus au lancement, un joueur
 * pouvait tirer pendant un ralliement, et la matrice ne pouvait qu'annuler son missile a l'arrivee —
 * une anomalie qu'elle nomme, pas une autorisation.
 *
 * Le controle porte sur le **corps vise** : une barriere sur la planete ne dit rien de sa lune.
 *
 * ## Ou il s'applique
 *
 * `MissileMission::isMissionPossible()` et le point de lancement de la Galaxie, tous deux, avec le
 * meme message : l'interface n'est jamais le controle.
 */
final class HeldTargetCheck
{
    public function isHeld(int $bodyId): bool
    {
        if (!resolve(SettingsService::class)->persistentCombatEnabled()) {
            return false;
        }

        return CelestialBodyCombatBarrier::query()->where('target_body_id', $bodyId)->exists();
    }

    /**
     * Le message que le joueur lit, dans sa langue.
     */
    public function refusal(): string
    {
        return __('t_ingame.galaxy.missile_target_combat_locked');
    }
}
