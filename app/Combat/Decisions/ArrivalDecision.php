<?php

namespace OGame\Combat\Decisions;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Support\ReturnPlan;

/**
 * Ce qu'il advient **physiquement** d'une flotte au moment ou elle atteint sa cible.
 *
 * **Le mouvement, et rien d'autre.** Ce contrat ne dit jamais ce qui entre dans la photographie :
 * une flotte peut se poser sans y figurer, et y figurer sans que son mouvement change. Melanger
 * les deux permettrait de construire « repartir a l'origine tout en comptant parmi les
 * defenseurs », ce qui n'a aucun sens. La photographie releve de `SnapshotDecision`.
 *
 * ## Les invariants que cet objet fait respecter
 *
 * - **`ReturnToOrigin` exige un plan de retour praticable.** Renvoyer une flotte sans savoir ou
 *   equivaut a la perdre ;
 * - **`CancelWithoutImpact` interdit tout plan.** C'est le cas de celui qui n'a nulle part ou
 *   aller ; lui attacher une destination serait se contredire ;
 * - **`AllowNormally` ne s'obtient que par une decision explicite**, jamais par defaut. C'est la
 *   lecon de la premiere version, ou une case oubliee laissait la mission s'executer ;
 * - **`DeferUntilResolved` ne touche a rien** : ni flotte, ni cible, ni rapport. L'evenement sera
 *   repris tel quel une fois la resolution close ;
 * - **action et raison forment une paire coherente.** Une flotte ne repart pas « parce que le
 *   combat n'a rien a dire », et ne se pose pas « parce que la limite de flottes est atteinte ».
 */
final readonly class ArrivalDecision implements CombatDecision
{
    /**
     * @param CombatMissionAction $action
     * @param CombatReasonCode $reason
     * @param ReturnPlan|null $returnPlan
     * @param bool $alreadyProcessed
     * @param string|null $openQuestion
     */
    private function __construct(
        private CombatMissionAction $action,
        private CombatReasonCode $reason,
        public ReturnPlan|null $returnPlan = null,
        public bool $alreadyProcessed = false,
        private string|null $openQuestion = null,
    ) {
    }

    /**
     * La mission se termine comme elle l'aurait fait sans combat.
     *
     * Une decision, pas un repli : elle affirme que le combat n'a rien a dire sur cette arrivee.
     */
    public static function completeNormally(): self
    {
        return new self(CombatMissionAction::AllowNormally, CombatReasonCode::NoCombatEffect);
    }

    /**
     * La flotte reste sur place et se battra du cote attaquant.
     */
    public static function joinAttack(): self
    {
        return new self(CombatMissionAction::JoinAttack, CombatReasonCode::NoCombatEffect);
    }

    /**
     * La flotte reste sur place et se battra du cote defenseur.
     */
    public static function joinDefence(): self
    {
        return new self(CombatMissionAction::JoinDefence, CombatReasonCode::NoCombatEffect);
    }

    /**
     * La flotte fait demi-tour vers la destination que le plan a retenue.
     *
     * @param ReturnPlan $plan
     * @param CombatReasonCode $reason
     * @return self
     */
    public static function returnToOrigin(ReturnPlan $plan, CombatReasonCode $reason): self
    {
        if (!$plan->isPossible()) {
            throw new InvalidArgumentException(
                'Renvoyer une flotte sans destination praticable revient a la perdre. Utiliser cancelWithoutImpact() quand il n y a nulle part ou aller.'
            );
        }

        self::guardReason($reason, CombatMissionAction::ReturnToOrigin);

        return new self(CombatMissionAction::ReturnToOrigin, $reason, $plan);
    }

    /**
     * La flotte se pose, hors photographie, et ne repartira qu'a la fin du combat.
     *
     * @param CombatReasonCode $reason
     * @return self
     */
    public static function landOutsideSnapshot(CombatReasonCode $reason): self
    {
        self::guardReason($reason, CombatMissionAction::LandOutsideSnapshot);

        return new self(CombatMissionAction::LandOutsideSnapshot, $reason);
    }

    /**
     * L'effet de jeu est reporte apres la resolution du combat.
     *
     * Le cas d'un missile prevu apres la fermeture : il ne peut ni modifier une defense deja
     * photographiee, ni disparaitre sans raison.
     */
    public static function deferImpact(CombatReasonCode $reason): self
    {
        self::guardReason($reason, CombatMissionAction::DeferImpact);

        return new self(CombatMissionAction::DeferImpact, $reason);
    }

    /**
     * L'evenement attend la fin de la resolution, sans rien modifier.
     */
    public static function deferUntilResolved(): self
    {
        return new self(CombatMissionAction::DeferUntilResolved, CombatReasonCode::ResolutionInProgress);
    }

    /**
     * La flotte disparait sans effet, avec une trace au journal.
     *
     * @param CombatReasonCode $reason
     * @return self
     */
    public static function cancelWithoutImpact(CombatReasonCode $reason): self
    {
        self::guardReason($reason, CombatMissionAction::CancelWithoutImpact);

        return new self(CombatMissionAction::CancelWithoutImpact, $reason);
    }

    /**
     * L'evenement a deja ete traite : il n'y a rien a rejouer.
     *
     * **Un resultat technique, pas une decision de jeu.** Une file de messages peut livrer deux
     * fois la meme arrivee ; la seconde ne doit ni consommer une place, ni deplacer une echeance,
     * ni produire un second rapport. Rendre ici la decision d'origine serait pire : elle serait
     * appliquee une seconde fois.
     */
    public static function alreadyProcessed(): self
    {
        return new self(CombatMissionAction::AllowNormally, CombatReasonCode::NoCombatEffect, null, true);
    }

    /**
     * La regle de cette case n'est pas arretee.
     *
     * @param string $question
     * @return self
     */
    public static function unresolved(string $question): self
    {
        return new self(CombatMissionAction::AllowNormally, CombatReasonCode::Undecided, null, false, $question);
    }

    /**
     * Ce qu'il faut faire de la flotte. Leve si la case n'est pas tranchee.
     */
    public function action(): CombatMissionAction
    {
        if ($this->openQuestion !== null) {
            throw new UnresolvedCombatDecision($this->openQuestion);
        }

        return $this->action;
    }

    public function reason(): CombatReasonCode
    {
        return $this->reason;
    }

    public function isResolved(): bool
    {
        return $this->openQuestion === null;
    }

    public function openQuestion(): string|null
    {
        return $this->openQuestion;
    }

    /**
     * Les raisons admissibles pour chaque action.
     *
     * Enumerees plutot que laissees libres : une flotte ne repart pas « parce que le combat n'a
     * rien a dire », et ne se pose pas « parce que la limite de flottes est atteinte ». Une paire
     * incoherente rendrait le journal d'audit trompeur, ce qui est pire qu'un journal absent.
     *
     * @param CombatReasonCode $reason
     * @param CombatMissionAction $action
     * @return void
     */
    private static function guardReason(CombatReasonCode $reason, CombatMissionAction $action): void
    {
        $admissibles = match ($action) {
            CombatMissionAction::ReturnToOrigin => [
                CombatReasonCode::RallyClosed,
                CombatReasonCode::AllianceNotEligible,
                CombatReasonCode::FleetLimitReached,
                CombatReasonCode::PlayerLimitReached,
                CombatReasonCode::NpcSideNotReinforceable,
                CombatReasonCode::TargetCombatLocked,
            ],
            CombatMissionAction::LandOutsideSnapshot => [
                CombatReasonCode::OwnFleetComingHome,
            ],
            CombatMissionAction::DeferImpact => [
                CombatReasonCode::RallyClosed,
                CombatReasonCode::TargetCombatLocked,
            ],
            CombatMissionAction::CancelWithoutImpact => [
                CombatReasonCode::RallyClosed,
                CombatReasonCode::AllianceNotEligible,
                CombatReasonCode::FleetLimitReached,
                CombatReasonCode::PlayerLimitReached,
                CombatReasonCode::TargetCombatLocked,
            ],
            default => [],
        };

        if (!in_array($reason, $admissibles, true)) {
            throw new InvalidArgumentException(
                'La raison « ' . $reason->value . ' » ne va pas avec l action « ' . $action->value . ' » : '
                . 'une paire incoherente rendrait le journal d audit trompeur.'
            );
        }
    }
}
