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
        if ($snapshot !== SnapshotObligation::NotConcerned && !$this->mayTouchTheSnapshot()) {
            throw new LogicException(
                'Une arrivee qui repart n a rien a faire dans une photographie : lui attacher une '
                . 'obligation « ' . $snapshot->value . ' » ferait chercher un effet la ou il n y en a '
                . 'aucun.'
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
        return self::classify($movement, landingOnly: true);
    }

    /**
     * Si cette arrivee peut modifier la photographie, de quelque facon que ce soit.
     *
     * ## Pourquoi la question n'est pas « depose-t-elle des vaisseaux »
     *
     * Un missile modifie des defenses, un chantier acheve ajoute des unites, une recherche change
     * des caracteristiques de combat. Aucun ne pose de flotte, et tous les trois modifient la
     * photographie. Limiter l'obligation aux mouvements qui atterrissent les laisserait entrer
     * sans qu'aucune decision causale ne soit demandee.
     *
     * Seul un depart n'y touche pas : la flotte repart, ou disparait.
     */
    public function mayTouchTheSnapshot(): bool
    {
        return self::decisionMayTouchTheSnapshot($this->movement);
    }

    /**
     * Si une decision peut modifier la photographie.
     *
     * @param ArrivalDecision $movement
     * @return bool
     */
    public static function decisionMayTouchTheSnapshot(ArrivalDecision $movement): bool
    {
        return self::classify($movement, landingOnly: false);
    }

    /**
     * Le classement d'une action, selon qu'on demande l'atterrissage ou l'effet.
     *
     * @param ArrivalDecision $movement
     * @param bool $landingOnly
     * @return bool
     */
    private static function classify(ArrivalDecision $movement, bool $landingOnly): bool
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

            // Ceux-la ne posent aucune flotte, et modifient pourtant ce que le moteur verra : un
            // impact de missile, une admission encore a prononcer, un effet dont l'ordre reste a
            // trancher.
            CombatMissionAction::DeferImpact,
            CombatMissionAction::SelectByAttackAdmission,
            CombatMissionAction::SelectByDefenceAdmission,
            CombatMissionAction::SelectByEventOrder => !$landingOnly,

            // Un depart, lui, ne touche a rien.
            CombatMissionAction::ReturnToOrigin,
            CombatMissionAction::CancelWithoutImpact,
            CombatMissionAction::DeferUntilResolved,
            CombatMissionAction::RefuseLaunch,
            CombatMissionAction::RequiresAssetRecovery,
            CombatMissionAction::OutsideMatrixDomain => false,
        };
    }
}
