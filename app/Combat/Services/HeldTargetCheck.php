<?php

namespace OGame\Combat\Services;

use OGame\Factories\PlanetServiceFactory;
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
     * Le message que le joueur lit, dans sa langue — et qui nomme le bon corps.
     *
     * Une lune et sa planete portent la meme adresse : dire « cette planete » quand la cible est une
     * lune laisserait le joueur croire qu il s est trompe de cible.
     */
    public function refusal(int $bodyId): string
    {
        $corps = resolve(PlanetServiceFactory::class)->make($bodyId, true);
        $lune = $corps !== null && $corps->isMoon();

        return __($lune ? 't_ingame.galaxy.missile_target_moon_combat_locked' : 't_ingame.galaxy.missile_target_combat_locked');
    }
}
