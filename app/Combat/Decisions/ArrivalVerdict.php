<?php

namespace OGame\Combat\Decisions;

use LogicException;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\SnapshotObligation;

/**
 * Ce que la matrice rend : un mouvement **et** une obligation de photographie.
 *
 * ## Pourquoi les deux voyagent ensemble
 *
 * `ArrivalDecision` ne decrit que le mouvement, et c'est deliberé : melanger les deux permettrait
 * de construire « repartir a l'origine tout en comptant parmi les defenseurs ». Mais les separer
 * sans les lier avait un autre defaut, plus discret : un appelant qui recevait `AllowNormally`
 * pouvait conclure que rien d'autre ne restait a decider, et inclure ou exclure la flotte de son
 * propre chef.
 *
 * Le verdict resout les deux problemes a la fois. Les contrats restent distincts — on ne peut
 * toujours pas fabriquer un mouvement qui decide la photographie — mais **on ne peut plus obtenir
 * l'un sans l'autre**.
 *
 * ## L'invariant que cet objet fait respecter
 *
 * Une arrivee qui **se pose** pendant que la photographie n'est pas prise porte forcement
 * `RequiresCausalDecision`. C'est la garantie positive : il ne suffit pas d'interdire le mauvais
 * resultat, il faut obliger a demander le bon.
 */
final readonly class ArrivalVerdict
{
    /**
     * @param ArrivalDecision $movement Ce qu'il advient physiquement de la flotte.
     * @param SnapshotObligation $snapshot Ce qui reste a obtenir au sujet de la photographie.
     */
    public function __construct(
        public ArrivalDecision $movement,
        public SnapshotObligation $snapshot,
    ) {
        if ($snapshot !== SnapshotObligation::NotConcerned && !$this->landsOnTheBody()) {
            throw new LogicException(
                'Une arrivee qui ne se pose pas n a rien a faire dans une photographie : lui attacher une '
                . 'obligation « ' . $snapshot->value . ' » ferait chercher une contribution la ou il n y a '
                . 'aucune flotte.'
            );
        }
    }

    /**
     * Si cette arrivee depose reellement quelque chose sur le corps celeste.
     *
     * Un report ne pose rien **pour l'instant** : c'est sa continuation qui le dira.
     */
    public function landsOnTheBody(): bool
    {
        return self::decisionLands($this->movement);
    }

    /**
     * Si une decision depose quelque chose sur le corps celeste.
     *
     * Statique parce que la matrice doit poser la question **avant** de pouvoir batir le verdict :
     * l'obligation de photographie depend de la reponse, et le constructeur la verifie.
     *
     * @param ArrivalDecision $movement
     * @return bool
     */
    public static function decisionLands(ArrivalDecision $movement): bool
    {
        $decision = $movement->continuation() ?? $movement;

        if (!$decision->isResolved()) {
            return false;
        }

        return match ($decision->action()) {
            CombatMissionAction::AllowNormally,
            CombatMissionAction::JoinAttack,
            CombatMissionAction::JoinDefence,
            CombatMissionAction::LandOutsideSnapshot => true,

            CombatMissionAction::ReturnToOrigin,
            CombatMissionAction::CancelWithoutImpact,
            CombatMissionAction::DeferImpact,
            CombatMissionAction::DeferUntilResolved,
            CombatMissionAction::RefuseLaunch,
            CombatMissionAction::SelectByAttackAdmission,
            CombatMissionAction::SelectByDefenceAdmission,
            CombatMissionAction::SelectByEventOrder,
            CombatMissionAction::OutsideMatrixDomain => false,
        };
    }
}
