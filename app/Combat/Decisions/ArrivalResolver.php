<?php

namespace OGame\Combat\Decisions;

use LogicException;
use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\InvariantCode;
use OGame\Combat\Enums\SnapshotObligation;
use OGame\Combat\Enums\SnapshotSource;
use OGame\Combat\Exceptions\ArrivalOutsideMatrixDomain;
use OGame\Combat\Exceptions\ContradictoryDelegatedOutcome;
use OGame\Combat\Support\ReturnPlan;

/**
 * Le consommateur des 396 situations : il ferme ce que la matrice delegue.
 *
 * ## Pourquoi il existe
 *
 * Trois des quatre categories de cases ouvertes sont des **directives** : la matrice a decide, et
 * ce qu'elle a decide est de deleguer a un mecanisme nomme. Cette reserve etait ecrite noir sur
 * blanc dans `CombatDecisionMatrixTest` :
 *
 * > Ces essais prouvent que la matrice delegue ; ils ne prouvent pas encore que quelqu'un recoit.
 *
 * Cette classe est le receveur. Elle prend une situation, le plan de retour et les actifs figes
 * sous verrou, plus les reponses des mecanismes delegues, et rend une `FinalArrivalResolution` —
 * un resultat ferme, ou une panne. Jamais un « peut-etre » de plus.
 *
 * ## Trois facons de refuser, et aucune valeur par defaut
 *
 *     regle jamais arretee            -> UnresolvedCombatDecision
 *     reponse deleguee manquante      -> MissingDelegatedOutcome
 *     reponse que la question interdit -> ContradictoryDelegatedOutcome
 *
 * Aucune ne produit de comportement. C'est la doctrine du dossier depuis le debut : une case sans
 * regle n'a pas de valeur de repli, parce qu'un repli silencieux modifie le jeu sous une regle que
 * personne n'a approuvee.
 *
 * ## Ce qu'il ne fait pas
 *
 * Il ne lit pas la base, ne prend aucun verrou, n'ecrit rien. Les faits lui arrivent deja figes —
 * c'est `RallyClosureService` qui detiendra le verrou et persistera. Cette separation est ce qui
 * permet d'eprouver les 396 situations sans univers, sans joueur et sans horloge.
 */
final class ArrivalResolver
{
    /**
     * @param CombatDecisionMatrix $matrix La matrice qui decide du mouvement.
     */
    public function __construct(private CombatDecisionMatrix $matrix = new CombatDecisionMatrix())
    {
    }

    /**
     * Le sort ferme d'une arrivee.
     *
     * @param CombatSituation $situation La situation, a l'heure **planifiee** de l'evenement.
     * @param ReturnPlan $returnPlan Ou la flotte se poserait si elle devait faire demi-tour, resolu sous verrou.
     * @param ArrivingAssets $assets Ce que la flotte transporte, et qu'une annulation ferait disparaitre.
     * @param DelegatedOutcomes $outcomes Les reponses des mecanismes auxquels la matrice delegue.
     * @return FinalArrivalResolution
     */
    public function resolve(
        CombatSituation $situation,
        ReturnPlan $returnPlan,
        ArrivingAssets $assets,
        DelegatedOutcomes $outcomes,
    ): FinalArrivalResolution {
        // Des donnees reelles, donc le garde-fou documente : une case impossible se range dans une
        // enumeration, elle ne se traite pas.
        $situation->ensureItCanOccur();

        $verdict = $this->matrix->verdictOf($situation, $returnPlan, $assets);
        $mouvement = $verdict->movement;

        if (!$mouvement->isResolved()) {
            throw new UnresolvedCombatDecision((string)$mouvement->openQuestion());
        }

        // **Un report ne suspend pas la decision, il en differe l'application.** Ce qui sera montre
        // au joueur est la continuation, jamais « resolution en cours ».
        $reporte = $mouvement->action() === CombatMissionAction::DeferUntilResolved;
        $aTrancher = $reporte ? ($mouvement->continuation() ?? $mouvement) : $mouvement;

        // **L'ordre des deux questions n'est pas indifferent.** L'obligation de photographie est
        // calculee sur le mouvement d'avant les delegations : une candidate qui va etre refusee la
        // porte encore. La demander tout de suite obligerait donc a fournir une reponse causale
        // pour une flotte qui repart — une reponse que personne ne lira.
        $pourLeMouvement = $aTrancher->action() === CombatMissionAction::SelectByEventOrder
            ? $outcomes->causalOrder($situation->describe())
            : null;

        [$final, $alerte, $corpsDeRecuperation] = $this->settle(
            $situation,
            $returnPlan,
            $assets,
            $aTrancher,
            $outcomes,
            $pourLeMouvement
        );

        $pourLaPhoto = $verdict->snapshot === SnapshotObligation::RequiresCausalDecision
            && $final->action()->mayTouchTheSnapshot()
                ? $pourLeMouvement ?? $outcomes->causalOrder($situation->describe())
                : null;

        return FinalArrivalResolution::of(
            FinalCombatDecision::of($final),
            $this->snapshotOf($situation, $verdict->snapshot, $final, $pourLaPhoto),
            $pourLaPhoto ?? $pourLeMouvement,
            $reporte,
            $final->alreadyProcessed,
            $corpsDeRecuperation,
            $alerte
        );
    }

    /**
     * Le mouvement definitif, une fois les delegations consommees.
     *
     * @param CombatSituation $situation
     * @param ReturnPlan $returnPlan
     * @param ArrivingAssets $assets
     * @param ArrivalDecision $settled
     * @param DelegatedOutcomes $outcomes
     * @param CausalAdmission|null $causalAdmission
     * @return array{0: ArrivalDecision, 1: InvariantCode|null, 2: int|null}
     */
    private function settle(
        CombatSituation $situation,
        ReturnPlan $returnPlan,
        ArrivingAssets $assets,
        ArrivalDecision $settled,
        DelegatedOutcomes $outcomes,
        CausalAdmission|null $causalAdmission,
    ): array {
        return match ($settled->action()) {
            CombatMissionAction::SelectByAttackAdmission => $this->afterAdmission(
                $situation,
                $returnPlan,
                $assets,
                $outcomes,
                ArrivalDecision::joinAttack()
            ),

            CombatMissionAction::SelectByDefenceAdmission => $this->afterAdmission(
                $situation,
                $returnPlan,
                $assets,
                $outcomes,
                ArrivalDecision::joinDefence()
            ),

            CombatMissionAction::SelectByEventOrder => $this->afterEventOrder(
                $situation,
                $causalAdmission ?? throw new LogicException('L ordre causal aurait du etre demande.')
            ),

            // Les actifs sont deposes ailleurs, et la mission s'arrete. « Sans impact » qualifie le
            // combat, pas la cargaison : rien de ce qui est preserve ne touche la cible.
            CombatMissionAction::RequiresAssetRecovery => [
                ArrivalDecision::cancelWithoutImpact(CombatReasonCode::NoReturnDestination),
                InvariantCode::AssetsWithoutDestination,
                $outcomes->assetRecovery($situation->describe())->bodyId,
            ],

            // L'espace profond et les situations impossibles : a router ailleurs, ou a corriger la
            // ou elles ont ete produites.
            CombatMissionAction::OutsideMatrixDomain => throw new ArrivalOutsideMatrixDomain(
                $settled->invariant() ?? InvariantCode::SituationCannotOccur,
                $situation->describe()
            ),

            CombatMissionAction::AllowNormally,
            CombatMissionAction::JoinAttack,
            CombatMissionAction::JoinDefence,
            CombatMissionAction::ReturnToOrigin,
            CombatMissionAction::LandOutsideSnapshot,
            CombatMissionAction::DeferImpact,
            CombatMissionAction::CancelWithoutImpact => [$settled, null, null],

            // Ni l'un ni l'autre ne peut etre le mouvement d'une arrivee : `RefuseLaunch` appartient
            // au moment du lancement, et un report ne peut pas etre sa propre continuation.
            CombatMissionAction::RefuseLaunch,
            CombatMissionAction::DeferUntilResolved => throw new LogicException(
                'L action « ' . $settled->action()->value . ' » n est pas un mouvement d arrivee : '
                . $situation->describe()
            ),
        };
    }

    /**
     * Ce qu'il advient d'une candidate apres le verdict de son camp.
     *
     * **Une refusee sans destination n'est pas supprimee sans egard.** Le cas se produit : une
     * attaque partie d'une lune detruite pendant son vol. Si elle transporte quelque chose, la
     * recuperation d'actifs s'applique, comme pour toute flotte sans destination.
     *
     * @param CombatSituation $situation
     * @param ReturnPlan $returnPlan
     * @param ArrivingAssets $assets
     * @param DelegatedOutcomes $outcomes
     * @param ArrivalDecision $ifAdmitted
     * @return array{0: ArrivalDecision, 1: InvariantCode|null, 2: int|null}
     */
    private function afterAdmission(
        CombatSituation $situation,
        ReturnPlan $returnPlan,
        ArrivingAssets $assets,
        DelegatedOutcomes $outcomes,
        ArrivalDecision $ifAdmitted,
    ): array {
        $admission = $outcomes->rallyAdmission($situation->describe());

        if ($admission->admitted) {
            return [$ifAdmitted, null, null];
        }

        $refus = $admission->refusal ?? CombatReasonCode::Undecided;

        if ($returnPlan->isPossible()) {
            return [ArrivalDecision::returnToOrigin($returnPlan, $refus), null, null];
        }

        if ($assets->arePreservable()) {
            return [
                ArrivalDecision::cancelWithoutImpact($refus),
                InvariantCode::AssetsWithoutDestination,
                $outcomes->assetRecovery($situation->describe())->bodyId,
            ];
        }

        return [ArrivalDecision::cancelWithoutImpact($refus), null, null];
    }

    /**
     * Ce qu'il advient d'un effet dont seul l'ordre des evenements decide.
     *
     * Deux genres seulement y arrivent, et ils ne se comportent pas de la meme facon :
     *
     *     missile  -> son impact s'applique, a deja ete applique, ou n'aurait pas du exister
     *     recyclage -> il recolte ce que son rang lui donne, et ne touche jamais le corps
     *
     * @param CombatSituation $situation
     * @param CausalAdmission $admission
     * @return array{0: ArrivalDecision, 1: InvariantCode|null, 2: int|null}
     */
    private function afterEventOrder(CombatSituation $situation, CausalAdmission $admission): array
    {
        $contradiction = fn (): never => throw new ContradictoryDelegatedOutcome(
            'ordre causal des evenements',
            $admission->value,
            $situation->describe()
        );

        return match ($situation->mission) {
            CombatMissionKind::Missile => match ($admission) {
                // Engage avant l'ouverture, prevu avant la fermeture : l'impact s'applique, puis la
                // photographie lira des defenses qui en tiennent deja compte.
                CausalAdmission::AppliedBeforeSnapshot => [ArrivalDecision::completeNormally(), null, null],

                // Deja reflete : le rejouer retrancherait les memes defenses une seconde fois.
                CausalAdmission::AlreadyInOpeningState => [ArrivalDecision::alreadyProcessed(), null, null],

                // Cree apres l'ouverture malgre le verrou : anomalie. Ni applique — la photographie
                // est prise — ni silencieux.
                CausalAdmission::OutsideSnapshot => [
                    ArrivalDecision::cancelWithoutImpact(CombatReasonCode::TargetCombatLocked),
                    InvariantCode::EffectCreatedAfterTheLock,
                    null,
                ],

                // Un missile n'ouvre pas de combat, et il ne peut pas etre etranger a celui sur
                // lequel on vient de l'interroger.
                CausalAdmission::FoundingInitiator, CausalAdmission::NotApplicable => $contradiction(),
            },

            // Le champ de debris n'appartient pas a la photographie du corps : quel que soit son
            // rang, le recycleur repart avec ce qui existait a son heure.
            CombatMissionKind::Recycle => match ($admission) {
                CausalAdmission::AppliedBeforeSnapshot,
                CausalAdmission::AlreadyInOpeningState,
                CausalAdmission::OutsideSnapshot => [ArrivalDecision::completeNormally(), null, null],

                CausalAdmission::FoundingInitiator, CausalAdmission::NotApplicable => $contradiction(),
            },

            CombatMissionKind::Attack,
            CombatMissionKind::AcsAttack,
            CombatMissionKind::AcsDefend,
            CombatMissionKind::MoonDestruction,
            CombatMissionKind::Transport,
            CombatMissionKind::Deployment,
            CombatMissionKind::Espionage,
            CombatMissionKind::Colonisation,
            CombatMissionKind::Expedition => throw new LogicException(
                'Aucune case ne delegue « ' . $situation->mission->value . ' » a l ordre des evenements : '
                . $situation->describe()
            ),
        };
    }

    /**
     * Ce que la photographie recoit, ou pourquoi elle ne recoit rien.
     *
     * @param CombatSituation $situation
     * @param SnapshotObligation $obligation
     * @param ArrivalDecision $settled
     * @param CausalAdmission|null $causalAdmission
     * @return SnapshotDecision
     */
    private function snapshotOf(
        CombatSituation $situation,
        SnapshotObligation $obligation,
        ArrivalDecision $settled,
        CausalAdmission|null $causalAdmission,
    ): SnapshotDecision {
        // Une flotte qui repart ou qu'on annule ne figure nulle part, quelle qu'ait ete l'obligation
        // avant que les delegations ne repondent. Un refus d'admission passe par ici.
        if (!$settled->action()->mayTouchTheSnapshot()) {
            return SnapshotDecision::exclude($settled->reason());
        }

        return match ($obligation) {
            SnapshotObligation::NotConcerned => SnapshotDecision::exclude(CombatReasonCode::NoCombatEffect),

            SnapshotObligation::SettledOutsideSnapshot => SnapshotDecision::exclude(CombatReasonCode::RallyClosed),

            SnapshotObligation::RequiresCausalDecision => $this->duringTheRally(
                $situation,
                $settled,
                $causalAdmission ?? throw new LogicException('L ordre causal aurait du etre demande.')
            ),
        };
    }

    /**
     * La place d'une arrivee qui se pose pendant que la photographie n'est pas encore prise.
     *
     * ## Le missile deja applique n'apporte rien de plus
     *
     * Ses degats sont **dans les defenses lues** au moment de la photographie. Les declarer une
     * seconde fois les retrancherait deux fois — c'est le double comptage que la table des
     * provenances existe pour empecher, et `TargetDefences` n'est d'ailleurs admis que depuis
     * l'etat global de la cible.
     *
     * La resolution porte l'admission causale a cote de l'exclusion : « deja reflete » et « hors
     * photographie » restent distinguables sans inventer un code destine au joueur.
     *
     * @param CombatSituation $situation
     * @param ArrivalDecision $settled
     * @param CausalAdmission $admission
     * @return SnapshotDecision
     */
    private function duringTheRally(
        CombatSituation $situation,
        ArrivalDecision $settled,
        CausalAdmission $admission,
    ): SnapshotDecision {
        if (!$admission->entersTheSnapshot()) {
            return SnapshotDecision::exclude(
                $admission === CausalAdmission::OutsideSnapshot
                    ? CombatReasonCode::RallyClosed
                    : CombatReasonCode::NoCombatEffect
            );
        }

        if ($situation->mission === CombatMissionKind::Missile) {
            return SnapshotDecision::exclude(CombatReasonCode::NoCombatEffect);
        }

        // La liste n'est jamais vide ici : la matrice ne pose l'obligation causale que si le genre
        // declare une projection. Le verifier une seconde fois donnerait l'illusion d'une garde que
        // rien ne peut declencher.
        $projections = $situation->possibleProjections();

        $retenue = $settled->action() === CombatMissionAction::JoinAttack
            || $settled->action() === CombatMissionAction::JoinDefence;

        return $retenue
            ? SnapshotDecision::includeSelectedRallyCandidate($projections)
            : SnapshotDecision::includeWithoutExtendingWindow($projections, SnapshotSource::IncidentalArrival);
    }
}
