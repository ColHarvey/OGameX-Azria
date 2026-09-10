<?php

namespace OGame\Patrol;

use OGame\Models\Patrol;
use OGame\Models\User;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Services\SettingsService;

/**
 * Qui peut lancer une attaque contre une patrouille, et sur quoi.
 *
 * ## Ce que cette porte decide, et ce qu elle ne decide pas
 *
 * Elle decide de la **cible** : est-elle connue de l attaquant, protegee, a lui, encore posee ?
 * Elle ne decide rien de la flotte qui part — vaisseaux, creneau, carburant, distance sont les
 * regles ordinaires d un lancement, et les dupliquer ici ferait deux moteurs de decision pour une
 * seule question.
 *
 * ## L ordre des refus n est pas indifferent
 *
 * La detection est verifiee **avant** les protections du proprietaire. Repondre « ce joueur est en
 * vacances » sur une patrouille qu on ne detecte pas apprendrait a l attaquant qu il y a quelqu un
 * la, et lequel : le refus deviendrait un detecteur gratuit, ce que tout le chantier de la
 * surveillance existe pour empecher. Sa propre patrouille passe avant tout : on la connait
 * toujours, et le dire ne revele rien.
 *
 * ## Ce qui vaut « connue »
 *
 * Un contact acquis (`SurveillanceWatch::acquiredTierFor()`), c est-a-dire un detecteur en service
 * dont le delai d acquisition est passe. Le palier n entre pas en compte : au premier niveau on
 * sait qu il y a quelque chose, et cela suffit a lui envoyer une flotte (revue 121, R7 : « planete,
 * lune, patrouille detectee »). Ce qu on ignore, on le decouvre en arrivant.
 */
final class PatrolAttackEligibility
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SurveillanceWatch $watch,
    ) {
    }

    /**
     * La cible gelee, ou le refus que le joueur lira.
     *
     * @param int $attackerId le joueur qui lance
     * @param Patrol $target la patrouille visee
     * @param int $now l instant du lancement
     *
     * @throws PatrolOrderRefused
     */
    public function frozenTargetFor(int $attackerId, Patrol $target, int $now): FrozenPatrolTarget
    {
        if (!$this->settings->patrolsEnabled()) {
            throw new PatrolOrderRefused('disabled');
        }

        if ((int)$target->user_id === $attackerId) {
            throw new PatrolOrderRefused('target_is_your_own_patrol');
        }

        if ($this->watch->acquiredTierFor($attackerId, (int)$target->id, $now) === null) {
            throw new PatrolOrderRefused('target_not_detected');
        }

        $proprietaire = User::query()->find((int)$target->user_id);

        if ($proprietaire === null) {
            throw new PatrolOrderRefused('target_not_detected');
        }

        if ($proprietaire->username === User::SYSTEM_ACCOUNT_USERNAME) {
            throw new PatrolOrderRefused('target_is_protected');
        }

        if ((bool)$proprietaire->vacation_mode) {
            throw new PatrolOrderRefused('target_owner_on_vacation');
        }

        // En dernier, parce que c est le seul refus qui peut changer d une seconde a l autre : la
        // patrouille peut partir entre le devis et l ordre. Le gel refuse ce qui n est pas pose.
        return FrozenPatrolTarget::of($target);
    }
}
