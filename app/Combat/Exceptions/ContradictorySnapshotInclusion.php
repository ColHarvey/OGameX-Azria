<?php

namespace OGame\Combat\Exceptions;

/**
 * Le meme evenement a deja ete inclus dans ce combat, avec autre chose.
 *
 * ## Pourquoi ce n'est pas un doublon a absorber
 *
 * Rejouer une fermeture doit etre sans effet : meme evenement, meme ensemble, meme instant — la
 * seconde tentative constate et s'arrete. C'est l'idempotence, et elle est voulue.
 *
 * Mais **meme evenement avec un ensemble different n'est pas un rejeu** : c'est deux verites sur un
 * meme fait. Ecraser la premiere ferait dependre la photographie de l'ordre dans lequel les
 * tentatives sont arrivees ; garder les deux compterait deux fois. Il n'y a pas de troisieme
 * comportement honnete.
 *
 * C'est le defaut que `updateOrCreate()` laissait passer : il ecrasait sans rien dire.
 */
class ContradictorySnapshotInclusion extends CorruptedSnapshotInclusion
{
    /**
     * @param string $eventIdentity L'evenement concerne.
     * @param array<int, string> $existing Ce qui etait deja inscrit.
     * @param array<int, string> $offered Ce qu'on voulait ecrire.
     */
    public function __construct(
        public readonly string $eventIdentity,
        public readonly array $existing,
        public readonly array $offered,
    ) {
        parent::__construct(
            'l evenement « ' . $eventIdentity . ' » figure deja dans cette photographie avec « '
            . implode(', ', $existing) . " », et on tente d y ecrire « " . implode(', ', $offered)
            . ' »',
            ['existing' => $existing, 'offered' => $offered]
        );
    }
}
