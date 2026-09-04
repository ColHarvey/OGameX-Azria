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
        return $this->engagedAmong([(int)$mission->id]) !== [];
    }

    /**
     * Celles de ces missions qui sont engagees, par l'un ou l'autre lien.
     *
     * ## Pourquoi la meme definition, en gros
     *
     * « Engagee » a deux preuves, et une seule des deux suffit. Un appelant qui n'en regarderait
     * qu'une se tromperait exactement la ou l'autre parle : la colonne est nulle pour un renfort
     * defensif que seule la fermeture a inscrit, et pour une attaque groupee non ouvreuse dont le
     * travailleur n'est pas encore passe.
     *
     * Le retrait d'un compte a besoin de la question pour un ensemble de missions, pas pour une
     * seule. La poser flotte par flotte marcherait, mais la definition finirait par exister deux
     * fois : celle-ci est la seule, et `isEngaged()` n'en est qu'un cas particulier.
     *
     * @param array<int, int> $missionIds
     * @return array<int, int> Les identifiants engages, par ordre croissant.
     */
    public function engagedAmong(array $missionIds): array
    {
        if ($missionIds === []) {
            return [];
        }

        $ouverts = $this->combatsStillRunning();

        $parLeLien = FleetMission::query()
            ->whereIn('id', $missionIds)
            ->whereNotNull('combat_instance_id')
            ->whereIn('combat_instance_id', (clone $ouverts))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        $parLInscription = CombatParticipant::query()
            ->whereIn('fleet_mission_id', $missionIds)
            ->whereIn('combat_instance_id', (clone $ouverts))
            ->pluck('fleet_mission_id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        $engagees = array_values(array_unique(array_merge($parLeLien, $parLInscription)));
        sort($engagees);

        return $engagees;
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
