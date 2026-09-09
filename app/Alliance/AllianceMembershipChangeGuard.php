<?php

namespace OGame\Alliance;

use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Services\CombatsInvolvingPlayer;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\SettingsService;

/**
 * Un changement d alliance ne reunit jamais deux adversaires d une bataille en cours.
 *
 * ## La regle, et ce qu elle ne fait pas
 *
 * Plan approuve du 9 septembre 2026, section 2 : « Interdire un changement d alliance qui reunirait
 * dans une meme alliance des adversaires d une bataille active jusqu a son reglement. **Ne jamais
 * annuler une bataille pour ce motif.** »
 *
 * La seconde phrase est aussi importante que la premiere. Sans elle, la protection d alliance
 * deviendrait une **sortie de secours** : perdre une bataille, rejoindre l alliance de l attaquant,
 * et voir le combat s annuler. Ici c est l adhesion qui attend, jamais la bataille.
 *
 * ## Pourquoi les camps se lisent ici, et non par le lecteur d effectif
 *
 * `CombatRosterReader` raisonne par **flottes** et refuse tout effectif incoherent — c est son role
 * a la cloture. Cette porte-ci raisonne par **joueurs**, et doit repondre pour un combat encore en
 * ralliement, ou personne n est inscrit. Elle ne redecide rien pour autant : le camp d une flotte
 * non inscrite vient de `CombatMissionKind::reinforcesTheDefence()`, la meme regle que le lecteur
 * emploie, et le camp d une inscription vient de la colonne que le combat a ecrite.
 */
final class AllianceMembershipChangeGuard
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Faire entrer ce joueur dans cette alliance reunirait-il des adversaires d une bataille active ?
     *
     * @param int $joiningUserId celui qui entre
     * @param int $allianceId l alliance qu il rejoint
     */
    public function reunitesAdversaries(int $joiningUserId, int $allianceId): bool
    {
        if (!$this->settings->allianceOffensiveProtectionEnabled()) {
            return false;
        }

        $membres = User::query()
            ->where('alliance_id', $allianceId)
            ->where('id', '!=', $joiningUserId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        if ($membres === []) {
            return false;
        }

        $combats = CombatsInvolvingPlayer::stillRunning($joiningUserId, $this->bodiesOf($joiningUserId));

        foreach ($combats as $combat) {
            $camps = $this->sidesOf($combat);
            $sien = $this->sideOf($joiningUserId, $camps);

            if ($sien === null) {
                continue;
            }

            $adverse = $sien === CombatParticipant::SIDE_ATTACKER
                ? CombatParticipant::SIDE_DEFENDER
                : CombatParticipant::SIDE_ATTACKER;

            if (array_intersect($membres, $camps[$adverse]) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les corps d un joueur, tels que la lecture des combats les attend.
     *
     * @return array<int, int>
     */
    private function bodiesOf(int $userId): array
    {
        return Planet::query()
            ->where('user_id', $userId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();
    }

    /**
     * Les joueurs de chaque camp d un combat.
     *
     * Trois sources, dans cet ordre : le proprietaire du corps vise est defenseur ; les inscriptions
     * portent leur camp ; et une flotte liee mais pas encore inscrite — un ralliement en cours —
     * tient le sien de son genre.
     *
     * @return array<string, array<int, int>>
     */
    private function sidesOf(CombatInstance $combat): array
    {
        $camps = [CombatParticipant::SIDE_ATTACKER => [], CombatParticipant::SIDE_DEFENDER => []];

        if ($combat->target_planet_id !== null) {
            $proprietaire = Planet::query()->whereKey($combat->target_planet_id)->value('user_id');

            if ($proprietaire !== null) {
                $camps[CombatParticipant::SIDE_DEFENDER][] = (int)$proprietaire;
            }
        }

        foreach (CombatParticipant::query()->where('combat_instance_id', $combat->id)->get(['player_id', 'side']) as $inscription) {
            $camp = (string)$inscription->side;

            if (isset($camps[$camp])) {
                $camps[$camp][] = (int)$inscription->player_id;
            }
        }

        $missions = FleetMission::query()
            ->where('combat_instance_id', $combat->id)
            ->where('processed', 0)
            ->get(['user_id', 'mission_type']);

        foreach ($missions as $mission) {
            $genre = CombatMissionKind::byMissionType()[(int)$mission->mission_type] ?? null;

            if ($genre === null) {
                continue;
            }

            $camp = $genre->reinforcesTheDefence()
                ? CombatParticipant::SIDE_DEFENDER
                : CombatParticipant::SIDE_ATTACKER;

            $camps[$camp][] = (int)$mission->user_id;
        }

        return [
            CombatParticipant::SIDE_ATTACKER => array_values(array_unique($camps[CombatParticipant::SIDE_ATTACKER])),
            CombatParticipant::SIDE_DEFENDER => array_values(array_unique($camps[CombatParticipant::SIDE_DEFENDER])),
        ];
    }

    /**
     * Le camp de ce joueur dans ces camps, ou null s il n y figure pas.
     *
     * **Un joueur present des deux cotes n a pas de camp exploitable.** Le cas ne devrait pas
     * exister, et repondre « attaquant » par defaut ferait decider une regle de jeu sur une
     * incoherence. On s abstient, et le combat suivant est examine.
     *
     * @param array<string, array<int, int>> $camps
     */
    private function sideOf(int $userId, array $camps): string|null
    {
        $attaquant = in_array($userId, $camps[CombatParticipant::SIDE_ATTACKER], true);
        $defenseur = in_array($userId, $camps[CombatParticipant::SIDE_DEFENDER], true);

        if ($attaquant === $defenseur) {
            return null;
        }

        return $attaquant ? CombatParticipant::SIDE_ATTACKER : CombatParticipant::SIDE_DEFENDER;
    }
}
