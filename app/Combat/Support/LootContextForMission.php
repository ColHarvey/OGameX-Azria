<?php

namespace OGame\Combat\Support;

use Illuminate\Support\Facades\Log;
use OGame\Combat\Exceptions\UnsupportedActorSide;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\Services\PlanetService;

/**
 * La frontiere entre le refus de domaine et ce que la mission en fait.
 *
 * ## Pourquoi un repli, et pourquoi il n'est pas silencieux
 *
 * `LootPolicySelector` refuse un camp qu'aucune regle ne couvre — vide, melange de joueurs et
 * d'acteurs pilotes par le serveur, ou comprenant le compte systeme. C'est la bonne decision **de
 * domaine** : choisir une regle a la place du concepteur reviendrait a inventer une mecanique dans
 * un `else`.
 *
 * Mais une exception qui remonte jusqu'a l'ordonnanceur laisse la mission non traitee, et elle
 * revient a chaque passage. Une incoherence de donnees se transformerait en boucle d'echec : sur un
 * serveur de production, personne ne la verrait avant d'aller lire les journaux.
 *
 * Le combat se deroule donc, **sans pillage**, avec une raison nommee qui dit exactement pourquoi.
 * C'est une degradation visible, pas une regle de jeu : sa presence dans un resultat signale une
 * incoherence a corriger.
 *
 * ## Ce que ce repli n'attrape pas
 *
 * Rien d'autre. Une erreur technique — base injoignable, donnee corrompue — doit continuer de
 * remonter : la transformer en absence de butin masquerait une panne sous une regle de jeu, et le
 * combat s'appliquerait sur des donnees dont personne n'a verifie la validite.
 *
 * ## Pourquoi la fabrique est injectable
 *
 * Le camp invalide n'existe pas dans le jeu : aucune flotte pilotee par le serveur ne rejoint une
 * union. Le fabriquer en base pour un essai donnerait un montage spectaculaire qui ne prouve rien
 * de plus — il pretendrait qu'un tel etat est atteignable.
 *
 * Une couture permet de faire lever le refus par la fabrique et de traverser **la frontiere
 * reelle** : c'est la propriete voulue, demontree sans mise en scene.
 */
final class LootContextForMission
{
    /**
     * Le contexte d'un combat pillard, ou son repli controle.
     *
     * @param array<AttackerFleet> $attackers
     * @param PlanetService $target
     * @param string $missionKind Le genre de mission, pour le journal.
     * @param int $missionId L'identifiant de la mission qui a declenche le combat.
     * @param callable(array<AttackerFleet>, PlanetService): LootContext|null $snapshot La fabrique a
     *        employer. Celle du jeu par defaut ; un essai en fournit une qui refuse, pour verifier
     *        que le refus ne remonte pas a l'ordonnanceur.
     * @return LootContext
     */
    public static function lootingOrDegraded(
        array $attackers,
        PlanetService $target,
        string $missionKind,
        int $missionId,
        callable|null $snapshot = null,
    ): LootContext {
        $snapshot ??= static fn (array $flottes, PlanetService $cible): LootContext
            => LiveLootContextFactory::forBattle($flottes, $cible);

        try {
            return $snapshot($attackers, $target);
        } catch (UnsupportedActorSide $refus) {
            $identifiants = [];

            foreach ($attackers as $attacker) {
                $identifiants[] = $attacker->fleetMissionId . '/' . $attacker->ownerId;
            }

            Log::critical('Camp attaquant sans regle de pillage : combat degrade en combat sans butin.', [
                'mission_kind' => $missionKind,
                'mission_id' => $missionId,
                'side_reason' => $refus->reason->value,
                'target_body' => CombatParticipantKey::forBody($target),
                'attacking_fleets' => $identifiants,
                'detail' => $refus->getMessage(),
            ]);

            return LiveLootContextFactory::withoutLoot($refus->reason->noLootReason(), $attackers, $target);
        }
    }
}
