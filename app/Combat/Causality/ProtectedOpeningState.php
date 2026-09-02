<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;
use OGame\Combat\Enums\TargetScope;

/**
 * L'etat du corps vise au moment de l'ouverture, avec **ce qu'il reflete deja**.
 *
 * ## Pourquoi l'etat et sa provenance sont indissociables
 *
 * Un solde tout seul ne dit pas quels evenements l'ont produit. Le reconciliateur retrouverait alors
 * comme « engagements anterieurs » des livraisons qui sont deja dans ce solde, et les ajouterait une
 * seconde fois — sans qu'aucune comparaison temporelle ne soit fausse.
 *
 * Les deux voyagent donc ensemble, et il n'existe pas de fabrique qui donne l'un sans l'autre.
 *
 * ## La reserve de butin commence ici
 *
 * La production continue physiquement pendant le combat, mais elle **n'augmente pas** la reserve
 * pillable. Celle-ci vaut :
 *
 *     ressources protegees = base reservee a l'ouverture
 *                          + livraisons causalement admissibles
 *
 * Tout ce qui est produit ou acquis apres l'ouverture reste un solde libre, hors butin du combat
 * courant. Reconstruire les ressources pillables depuis le solde vivant a la fermeture reviendrait a
 * offrir a l'attaquant deux heures de production.
 */
final readonly class ProtectedOpeningState
{
    /**
     * @param int $combatInstanceId Le combat auquel cet etat appartient.
     * @param int $targetBodyId Le corps **exact** : planete et lune ne se confondent pas.
     * @param OpeningProvenance $provenance Ce que cet etat reflete deja.
     * @param string $stateFingerprint L'empreinte des faits relus a l'ouverture.
     */
    public function __construct(
        public int $combatInstanceId,
        public int $targetBodyId,
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
}
