<?php

namespace OGame\Combat\Decisions;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\DecisionRequirement;
use OGame\Combat\Enums\InvariantCode;
use OGame\Combat\Enums\OpenCellCategory;
use OGame\Combat\Exceptions\NonFinalCombatReason;
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
        private CombatReasonCode|null $reason,
        public ReturnPlan|null $returnPlan = null,
        public bool $alreadyProcessed = false,
        private string|null $openQuestion = null,
        private self|null $continuation = null,
        private DecisionRequirement|null $requirement = null,
        private InvariantCode|null $invariant = null,
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
     * L'evenement attend la fin de la resolution, **avec ce qu'il faudra en faire ensuite**.
     *
     * ## Pourquoi la continuation est obligatoire
     *
     * Reporter puis rejouer l'arrivee telle quelle la ferait retomber sur un corps devenu libre :
     * une attaque tardive y ouvrirait un second combat, c'est-a-dire exactement la file d'attente
     * que le jeu refuse. Le report ne suspend donc pas la decision, il en differe seulement
     * l'application — une attaque tardive repart, un espionnage rentre intact, un missile frappe ce
     * qui reste.
     *
     * @param self $continuation Ce qui sera applique une fois la resolution close.
     * @return self
     */
    public static function deferUntilResolved(self $continuation): self
    {
        if (!$continuation->isResolved()) {
            throw new InvalidArgumentException(
                'Differer une arrivee sans savoir ce qu on en fera revient a la suspendre indefiniment : la '
                . 'continuation doit etre une decision tranchee.'
            );
        }

        if ($continuation->action === CombatMissionAction::DeferUntilResolved) {
            throw new InvalidArgumentException(
                'Une continuation qui differe a son tour ne termine jamais : l evenement serait repousse a '
                . 'chaque reprise.'
            );
        }

        return new self(
            CombatMissionAction::DeferUntilResolved,
            CombatReasonCode::ResolutionInProgress,
            null,
            false,
            null,
            $continuation
        );
    }

    /**
     * Le camp attaquant tranchera : la flotte y est admise, ou elle repart.
     *
     * **C'est une decision, pas un trou.** Ce qui manque n'est pas une regle mais un fait collectif
     * et persiste : la liste figee a l'ouverture, l'alliance de l'initiateur, les budgets du camp.
     * La matrice nomme le mecanisme qui tranche et exige qu'il le fasse sous verrou.
     */
    public static function selectByAttackAdmission(): self
    {
        return new self(
            CombatMissionAction::SelectByAttackAdmission,
            null,
            null,
            false,
            null,
            null,
            DecisionRequirement::RallyAdmission
        );
    }

    /**
     * Le camp defenseur tranchera, avec ses propres budgets.
     */
    public static function selectByDefenceAdmission(): self
    {
        return new self(
            CombatMissionAction::SelectByDefenceAdmission,
            null,
            null,
            false,
            null,
            null,
            DecisionRequirement::RallyAdmission
        );
    }

    /**
     * L'ordre des evenements tranchera, pas l'etat courant de la cible.
     */
    public static function selectByEventOrder(): self
    {
        return new self(
            CombatMissionAction::SelectByEventOrder,
            null,
            null,
            false,
            null,
            null,
            DecisionRequirement::CausalOrder
        );
    }

    /**
     * La flotte n'a nulle part ou se poser, et porte des actifs a preserver.
     *
     * **Distincte de `cancelWithoutImpact()`**, et la distinction n'est pas cosmetique : annuler
     * une flotte de joueur chargee ferait disparaitre ses vaisseaux et sa cargaison. Le cas ne
     * devrait pas se produire — la planete mere garantit normalement une destination —, et c'est
     * justement pourquoi il compte : s'il survient, c'est une corruption ou un etat administratif.
     *
     * @return self
     */
    public static function requiresAssetRecovery(): self
    {
        return new self(
            CombatMissionAction::RequiresAssetRecovery,
            null,
            null,
            false,
            null,
            null,
            null,
            InvariantCode::AssetsWithoutDestination
        );
    }

    /**
     * Cette situation ne releve pas de la matrice des corps celestes.
     *
     * Elle couvre deux cas : une cible qui n'est pas un corps celeste, et une situation qui ne peut
     * pas se produire. Dans une enumeration elles se rangent ; sur un chemin vivant,
     * `CombatSituation::ensureItCanOccur()` leve avant qu'on en arrive la.
     *
     * @param InvariantCode $code
     * @return self
     */
    public static function outsideMatrixDomain(InvariantCode $code): self
    {
        return new self(
            CombatMissionAction::OutsideMatrixDomain,
            null,
            null,
            false,
            null,
            null,
            null,
            $code
        );
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

    /**
     * La raison finale, celle qu'un joueur peut lire.
     *
     * **Elle echoue quand il n'y en a pas encore.** Une decision qui delegue porte une exigence,
     * une decision qui constate une contradiction porte un code d'invariant : ni l'un ni l'autre
     * n'est destine a un joueur. C'est ici que se ferme la regle « aucun code d'attente ne
     * survit dans un `FinalArrivalResolution` » — la question ne rend pas un code plausible,
     * elle echoue.
     */
    public function reason(): CombatReasonCode
    {
        if ($this->reason === null) {
            throw new NonFinalCombatReason(
                'Cette decision n a pas de raison finale : elle porte '
                . ($this->requirement !== null
                    ? 'l exigence « ' . $this->requirement->value . ' »'
                    : 'le code d invariant « ' . (string)$this->invariant?->value . ' »')
                . '. Servir un code d attente a la place laisserait un etat intermediaire du serveur '
                . 'passer pour une regle du jeu.'
            );
        }

        return $this->reason;
    }

    /**
     * Ce qu'il reste a consommer, ou `null` si la decision se suffit.
     */
    public function requirement(): DecisionRequirement|null
    {
        return $this->requirement;
    }

    /**
     * Le defaut constate, ou `null` s'il n'y en a pas.
     */
    public function invariant(): InvariantCode|null
    {
        return $this->invariant;
    }

    /**
     * Si cette decision peut entrer telle quelle dans une resolution finale.
     *
     * Ni question ouverte, ni exigence a consommer, ni code d'invariant : une action et une
     * raison qu'un joueur peut lire.
     */
    public function isFinal(): bool
    {
        return $this->openQuestion === null
            && $this->reason !== null
            && $this->reason !== CombatReasonCode::Undecided;
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
     * Ce qui sera applique une fois la resolution close, pour un report seulement.
     */
    public function continuation(): self|null
    {
        return $this->continuation;
    }

    /**
     * Pourquoi cette case n'est pas une action immediate, ou `null` si elle en est une.
     *
     * Trois des quatre categories sont des **decisions fermees** : la matrice a tranche, et ce
     * qu'elle a tranche est de deleguer a un mecanisme nomme. Seule `MissingRule` designe un trou.
     */
    public function openCellCategory(): OpenCellCategory|null
    {
        if ($this->openQuestion !== null) {
            return OpenCellCategory::MissingRule;
        }

        return match ($this->action) {
            CombatMissionAction::SelectByAttackAdmission,
            CombatMissionAction::SelectByDefenceAdmission => OpenCellCategory::NeedsRallyAdmission,
            CombatMissionAction::SelectByEventOrder => OpenCellCategory::NeedsCausalEligibility,
            CombatMissionAction::OutsideMatrixDomain => OpenCellCategory::StructurallyNotApplicable,
            CombatMissionAction::RequiresAssetRecovery => OpenCellCategory::NeedsAssetRecovery,
            default => null,
        };
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
                CombatReasonCode::PositionNoLongerFree,
                CombatReasonCode::WrongTargetBody,
                CombatReasonCode::NotAlreadyInFlight,
                CombatReasonCode::RallyWindowLimit,
                CombatReasonCode::CandidateRecalled,
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
                CombatReasonCode::PositionNoLongerFree,
                CombatReasonCode::NoReturnDestination,
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
