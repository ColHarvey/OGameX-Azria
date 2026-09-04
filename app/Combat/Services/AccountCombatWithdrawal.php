<?php

namespace OGame\Combat\Services;

use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatState;
use OGame\GameMissions\AttackMission;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use RuntimeException;

/**
 * Ce qu'un compte qui disparait fait de ses combats en cours.
 *
 * ## Pourquoi avant d'effacer quoi que ce soit
 *
 * La suppression d'un compte efface ses missions. Une mission inscrite dans un combat actif ne
 * s'efface pas : la bataille a ete calculee avec elle, et l'inscription — la photographie — est
 * immuable. Le combat s'annule d'abord, avec la cause qui dit pourquoi ; les flottes des deux camps
 * rentrent, la cible l'apprend, et seulement ensuite les missions peuvent disparaitre. L'inscription
 * reste, son lien vers la mission devenu nul.
 *
 * ## Les trois positions d'un compte dans un combat
 *
 * - **attaquant** — ouvreur ou vague inscrite : `AttackerRemoved`, la cause faite pour cela ;
 * - **cible** — le corps attaque lui appartient : `TargetDisappeared`, le corps va disparaitre ;
 * - **renfort defensif** dans le combat d'un autre : aucune cause ne dit « un allie est parti », et
 *   annuler la bataille d'un tiers parce qu'un allie efface son compte est une decision de jeu qui
 *   n'a pas ete prise. La suppression est **refusee** tant que ce combat n'est pas final ; un
 *   combat dure des heures, pas des jours.
 */
final class AccountCombatWithdrawal
{
    /**
     * Annule les combats que ce compte ouvre ou subit, et refuse s'il renforce ceux d'un autre.
     *
     * @param array<int, int> $planetIds Les corps du compte, ceux en attente de purge compris.
     * @return int Le nombre de combats annules.
     *
     * @throws RuntimeException Si le compte renforce un combat actif qui n'est pas le sien.
     */
    public function withdraw(int $userId, array $planetIds, int $now): int
    {
        $ouverts = CombatInstance::query()
            ->whereIn('status', array_map(static fn (CombatState $etat): string => $etat->value, self::statesStillRunning()))
            ->where(function ($requete) use ($userId, $planetIds): void {
                $requete
                    ->whereIn('target_planet_id', $planetIds)
                    ->orWhereIn('id', CombatParticipant::query()->select('combat_instance_id')->where('player_id', $userId));
            })
            ->orderBy('id')
            ->get();

        $annules = 0;

        foreach ($ouverts as $combat) {
            $cause = $this->causeFor($combat, $userId, $planetIds);

            $issue = resolve(AttackMission::class)->cancelPersistentCombat(
                (int)$combat->id,
                $cause,
                'suppression du compte ' . $userId,
                $now
            );

            if ($issue->cancelled) {
                $annules++;
            }
        }

        return $annules;
    }

    /**
     * @param array<int, int> $planetIds
     */
    private function causeFor(CombatInstance $combat, int $userId, array $planetIds): CombatCancellationCause
    {
        if (in_array((int)$combat->target_planet_id, $planetIds, true)) {
            return CombatCancellationCause::TargetDisappeared;
        }

        $attaquant = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('player_id', $userId)
            ->where('side', CombatParticipant::SIDE_ATTACKER)
            ->exists();

        if ($attaquant) {
            return CombatCancellationCause::AttackerRemoved;
        }

        throw new RuntimeException(
            'Le compte ' . $userId . ' renforce le combat ' . $combat->id . ' d un autre joueur, encore « '
            . $combat->status->value . ' » : la suppression attend que ce combat soit final.'
        );
    }

    /**
     * @return array<int, CombatState>
     */
    private static function statesStillRunning(): array
    {
        return array_values(array_filter(CombatState::cases(), static fn (CombatState $etat): bool => !$etat->isFinal()));
    }
}
