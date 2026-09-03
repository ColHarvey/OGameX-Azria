<?php

namespace OGame\Combat\Services;

use Illuminate\Database\Eloquent\Builder;
use OGame\Combat\Enums\CombatState;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;

/**
 * Une flotte est-elle engagee dans un combat durable qui n'est pas termine ?
 *
 * ## La regle, et ou elle s'applique
 *
 * « Une flotte engagee dans un combat ne se rappelle plus » — `CombatState::allowsRecall()` ne rend
 * vrai dans aucun etat, et `CombatLockedActions` nomme la route. La bataille est calculee a la
 * fermeture avec cette flotte ; un rappel accepte ensuite la ferait a la fois combattre et rentrer,
 * et ses vaisseaux existeraient deux fois.
 *
 * Ce controle est le filet central que la validation serveur appelle : tout rappel passe par
 * `FleetMissionService::cancelMission()`, quel que soit le chemin qui y mene. Une interface qui
 * grise le bouton n'est jamais la protection.
 *
 * ## Deux facons d'etre engagee
 *
 * - **Arrivee a un combat** : `fleet_missions.combat_instance_id` est pose par l'arrivee, avant
 *   meme la fermeture. La flotte est sur place ; elle n'en repart que par la fermeture qui la
 *   refuse, le reglement, ou l'annulation. La regle amont — « pas encore arrivee » se lit
 *   `time_arrival < now` — laisse passer un rappel a la seconde meme de l'arrivee ; celle-ci non.
 * - **Inscrite par la fermeture** : une ligne de `combat_participants`. C'est le seul lien d'un
 *   renfort defensif, qui ne passe pas par l'arrivee d'une attaque et que la regle amont laisse
 *   rappeler pendant tout son stationnement.
 *
 * L'engagement dure tant que le combat n'est pas final : regle ou annule, la flotte redevient
 * libre — un renfort en stationnement se rappelle de nouveau.
 */
final class EngagedFleetCheck
{
    public function isEngaged(FleetMission $mission): bool
    {
        $ouverts = $this->combatsStillRunning();

        if ($mission->combat_instance_id !== null
            && (clone $ouverts)->whereKey($mission->combat_instance_id)->exists()) {
            return true;
        }

        return CombatParticipant::query()
            ->where('fleet_mission_id', $mission->id)
            ->whereIn('combat_instance_id', $ouverts)
            ->exists();
    }

    /**
     * Les combats qui ne sont pas termines : ceux qui engagent encore leurs flottes.
     *
     * @return Builder<CombatInstance>
     */
    private function combatsStillRunning(): Builder
    {
        $enCours = [];

        foreach (CombatState::cases() as $etat) {
            if (!$etat->isFinal()) {
                $enCours[] = $etat->value;
            }
        }

        return CombatInstance::query()->select('id')->whereIn('status', $enCours);
    }
}
