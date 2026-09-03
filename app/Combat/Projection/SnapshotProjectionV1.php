<?php

namespace OGame\Combat\Projection;

/**
 * La premiere projection : un evenement apporte un ensemble de contributions, et une seule fois.
 *
 * ## Ce que cette version fixe
 *
 * Une flotte admise apporte des vaisseaux a son camp ; un etat de cible apporte son solde, ses
 * defenses et sa garnison ; une arrivee de passage n'apporte que des traces. Chaque evenement entre
 * **une fois** dans une photographie, avec l'ensemble de ses contributions.
 *
 * Une v2 changerait ce que ces mots signifient — par exemple en comptant une livraison autrement.
 * C'est pour cela qu'elle doit etre versionnee : deux combats lus sous deux projections
 * differentes n'ont pas la meme photographie, meme a partir des memes evenements.
 */
final class SnapshotProjectionV1 implements SnapshotProjectionRule
{
    /**
     * La version persistee. C'est cette chaine qui va dans `combat_instances.projection_version`.
     */
    public const string VERSION = 'v1';

    public function version(): string
    {
        return self::VERSION;
    }
}
