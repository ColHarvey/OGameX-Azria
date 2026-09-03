<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Exceptions\ContradictoryOpeningProvenance;

/**
 * L'etat du corps vise au moment de l'ouverture, avec **ce qu'il reflete deja**.
 *
 * ## Pourquoi l'etat et sa provenance sont indissociables
 *
 * Un solde tout seul ne dit pas quels effets l'ont produit. Le reconciliateur retrouverait alors
 * comme « engagements anterieurs » des livraisons deja encaissees, et les ajouterait une seconde
 * fois — sans qu'aucune comparaison temporelle ne soit fausse.
 *
 * Les deux voyagent donc ensemble, et il n'existe pas de fabrique qui donne l'un sans l'autre.
 *
 * ## La reserve de butin commence ici
 *
 * La production continue physiquement pendant le combat, mais elle **n'augmente pas** la reserve
 * pillable :
 *
 *     ressources protegees = base reservee a l'ouverture
 *                          + livraisons causalement admissibles
 *
 * Tout ce qui est produit ou acquis apres l'ouverture reste un solde libre, hors butin du combat
 * courant. Reconstruire les ressources pillables depuis le solde vivant a la fermeture reviendrait a
 * offrir a l'attaquant toute la production du combat.
 */
final readonly class ProtectedOpeningState
{
    /**
     * @param int $combatInstanceId Le combat auquel cet etat appartient.
     * @param int $targetBodyId Le corps **exact** : planete et lune ne se confondent pas.
     * @param int $capturedAt L'instant de la capture, en secondes.
     * @param OpeningProvenance $provenance Ce que cet etat reflete deja.
     * @param string $stateFingerprint L'empreinte des faits relus a l'ouverture.
     */
    public function __construct(
        public int $combatInstanceId,
        public int $targetBodyId,
        public int $capturedAt,
        public OpeningProvenance $provenance,
        public string $stateFingerprint,
    ) {
        if ($combatInstanceId < 1 || $targetBodyId < 1) {
            throw new InvalidArgumentException(
                'Un etat d ouverture appartient a un combat et a un corps persistes.'
            );
        }

        if ($stateFingerprint === '') {
            throw new InvalidArgumentException(
                'Sans empreinte, deux lectures divergentes du meme etat d ouverture passeraient pour '
                . 'identiques, et la photographie dependrait de celle qu on a lue.'
            );
        }
    }

    /**
     * Si cet evenement concerne bien le corps que cet etat protege.
     *
     * **Une egalite de coordonnees ne suffit pas.** Une planete et sa lune les partagent ; un champ
     * de debris et une position de colonisation aussi. Seul l'identifiant du corps, et une portee de
     * corps celeste, font qu'un evenement entre dans cette photographie.
     */
    public function coversTheTargetOf(CausalEvent $event): bool
    {
        return $event->targetBodyId === $this->targetBodyId
            && $event->targetScope === TargetScope::CelestialBody;
    }

    /**
     * Si l'etat protege reflete deja **cet effet-la**, et non seulement cet identifiant.
     *
     * ## Ce que cette methode refuse plutot que d'admettre
     *
     * Cinq desaccords ne sont pas des issues a trancher mais des defauts. Les admettre en silence
     * ferait passer une corruption pour une regle de jeu :
     *
     * - **une empreinte differente** : la mission a change entre son application et cette relecture ;
     * - **un genre versionne different** : l'effet a ete applique sous une autre forme ;
     * - **un agregat different** : le recu concerne un autre corps ;
     * - **une contradiction temporelle** : l'effet est declare present dans un etat capture avant lui ;
     * - **un recu vide**, deja refuse a la construction du recu.
     *
     * @param CausalEvent $event
     * @return bool
     */
    public function alreadyReflects(CausalEvent $event): bool
    {
        $recu = $this->provenance->receiptFor($event);

        if ($recu === null) {
            return false;
        }

        if ($recu->kindVersion !== $event->kindVersion) {
            throw new ContradictoryOpeningProvenance(
                'L evenement « ' . $event->identity .' » est reflete sous le genre « ' . $recu->kindVersion
                . ' » et relu sous « ' . $event->kindVersion . ' » : l effet applique n est pas celui qu on '
                . 'renonce a appliquer.'
            );
        }

        if ($recu->effectFingerprint !== $event->effectFingerprint) {
            throw new ContradictoryOpeningProvenance(
                'L evenement « ' . $event->identity . ' » a ete applique avec un effet different de celui '
                . 'qu on relit. Le declarer deja reflete sur la seule foi de son identifiant renoncerait a '
                . 'appliquer un effet qui, lui, ne l a pas ete.'
            );
        }

        if ($recu->aggregateId !== $event->targetBodyId) {
            throw new ContradictoryOpeningProvenance(
                'Le recu de « ' . $event->identity . ' » concerne le corps ' . $recu->aggregateId
                . ', et l evenement relu vise le corps ' . $event->targetBodyId . '.'
            );
        }

        // **Une contradiction temporelle, pas une admission.** Un effet ne peut pas figurer dans un
        // etat capture avant qu'il n'ait lieu.
        if ($recu->appliedAt > $this->capturedAt) {
            throw new ContradictoryOpeningProvenance(
                'L evenement « ' . $event->identity . ' » est declare present dans un etat capture a '
                . $this->capturedAt . ' alors qu il a ete applique a ' . $recu->appliedAt . '.'
            );
        }

        return true;
    }
}
