<?php

namespace OGame\Combat\Services;

use Illuminate\Database\Eloquent\Collection;
use OGame\Combat\Enums\CombatState;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;

/**
 * Les combats durables qui concernent un joueur, et qui ne sont pas finis.
 *
 * ## Trois liens, pas un
 *
 * Un joueur est partie a un combat par trois chemins qui n'existent pas tous en meme temps : le
 * corps vise lui appartient ; il est **inscrit** (`combat_participants`, apres la cloture — flotte
 * ou garnison) ; ou une de ses missions **porte le lien** (`fleet_missions.combat_instance_id`,
 * des le ralliement, avant toute inscription). Lire un seul de ces liens oublie une phase.
 *
 * Cette lecture sert au retrait d'un compte et a la presentation : les deux doivent voir les memes
 * combats, sinon un joueur pourrait etre retenu par un combat qu'aucune page ne lui montre.
 */
final class CombatsInvolvingPlayer
{
    /**
     * Les combats non finaux ou ce joueur est partie, par identifiant croissant.
     *
     * @param array<int, int> $planetIds Les corps du joueur.
     * @return Collection<int, CombatInstance>
     */
    public static function stillRunning(int $userId, array $planetIds): Collection
    {
        return CombatInstance::query()
            ->whereIn('status', array_map(static fn (CombatState $etat): string => $etat->value, self::statesStillRunning()))
            ->where(function ($requete) use ($userId, $planetIds): void {
                $requete
                    ->whereIn('target_planet_id', $planetIds)
                    ->orWhereIn('id', CombatParticipant::query()->select('combat_instance_id')->where('player_id', $userId))
                    ->orWhereIn('id', FleetMission::query()->select('combat_instance_id')->whereNotNull('combat_instance_id')->where('user_id', $userId));
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Ce joueur est-il partie a ce combat, par l'un des trois liens ?
     *
     * @param array<int, int> $planetIds
     */
    public static function isPartyTo(CombatInstance $combat, int $userId, array $planetIds): bool
    {
        if ($combat->target_planet_id !== null && in_array((int)$combat->target_planet_id, $planetIds, true)) {
            return true;
        }

        if (CombatParticipant::query()->where('combat_instance_id', $combat->id)->where('player_id', $userId)->exists()) {
            return true;
        }

        return FleetMission::query()->where('combat_instance_id', $combat->id)->where('user_id', $userId)->exists();
    }

    /**
     * Les etats qui ne sont pas finaux.
     *
     * @return array<int, CombatState>
     */
    public static function statesStillRunning(): array
    {
        return array_values(array_filter(CombatState::cases(), static fn (CombatState $etat): bool => !$etat->isFinal()));
    }
}
