<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;

/**
 * La preuve qu'un effet precis a deja ete applique, et lequel.
 *
 * ## Pourquoi l'identite ne suffit pas
 *
 * Savoir que `mission:42` a deja ete appliquee ne dit pas **quel** effet de `mission:42` est present
 * dans l'etat protege. Si la mission a ete modifiee entre son application et la relecture — une
 * cargaison changee, une cible corrigee —, la seule correspondance d'identifiant declarerait
 * « deja fait » un effet qui ne l'est pas.
 *
 * Le recu lie donc l'identite a **ce qui a reellement ete applique** :
 *
 *     meme identite + meme empreinte    -> deja reflete, ne pas rejouer
 *     meme identite + contenu different -> contradiction, pas une admission
 *     identites differentes + meme montant -> deux evenements reels distincts
 *
 * Le troisieme cas compte autant que les deux premiers : deux transports de 100 metal sont deux
 * livraisons, et les confondre en supprimerait une.
 *
 * ## L'instant d'application
 *
 * Il permet de detecter une contradiction temporelle : un effet declare present dans l'etat
 * d'ouverture alors qu'il etait prevu **apres** la capture. Ce n'est pas une admission a trancher,
 * c'est un defaut — la provenance et la chronologie ne peuvent pas se contredire.
 */
final readonly class AppliedEffectReceipt
{
    /**
     * @param string $eventIdentity L'identite de l'evenement applique.
     * @param string $kindVersion Le genre versionne sous lequel il a ete applique.
     * @param string $effectFingerprint L'empreinte canonique de l'effet reellement applique.
     * @param int $aggregateId L'agregat touche : le corps exact, jamais des coordonnees.
     * @param int $appliedAt L'instant d'application, en secondes.
     * @param string $receiptId L'identifiant persiste du recu.
     */
    public function __construct(
        public string $eventIdentity,
        public string $kindVersion,
        public string $effectFingerprint,
        public int $aggregateId,
        public int $appliedAt,
        public string $receiptId,
    ) {
        if ($eventIdentity === '' || $kindVersion === '' || $effectFingerprint === '') {
            throw new InvalidArgumentException(
                'Un recu d application doit lier une identite, un genre versionne et l empreinte de l effet '
                . 'reellement applique. Sans les trois, il ne dit que « quelque chose a eu lieu ».'
            );
        }

        if ($aggregateId < 1) {
            throw new InvalidArgumentException(
                'Un recu d application designe un agregat persiste : une planete et sa lune partagent leurs '
                . 'coordonnees, et un effet applique a l une ne concerne pas l autre.'
            );
        }

        if ($receiptId === '') {
            throw new InvalidArgumentException(
                'Un recu sans identifiant persiste ne peut pas etre retrouve : rien ne distinguerait « deja '
                . 'applique » de « suppose applique ».'
            );
        }
    }
}
