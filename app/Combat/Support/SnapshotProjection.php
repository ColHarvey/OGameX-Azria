<?php

namespace OGame\Combat\Support;

use OGame\Combat\Exceptions\UnknownSnapshotProjection;

/**
 * Ce qu'une ligne d'inclusion **signifie**, et sous quelle version elle a ete ecrite.
 *
 * ## Pourquoi une version separee des quatre regles
 *
 * Les quatre versions gelees disent comment un combat *decide* : ordre causal, politique de
 * pillage, allocateur, destruction de lune. Celle-ci dit tout autre chose — comment une inclusion
 * se **lit**. Elles bougent pour des raisons independantes : on peut corriger la facon de projeter
 * une arrivee en photographie sans toucher a une seule regle de jeu, et l'inverse est vrai aussi.
 *
 * Les confondre obligerait a faire avancer les quatre pour un changement qui n'en concerne aucune,
 * et rendrait incomparables des combats que rien ne separe reellement.
 *
 * ## Pourquoi elle est ecrite avec l'instance, et pas lue au moment d'inclure
 *
 * Le meme motif que partout ailleurs : une fermeture qui lirait `CURRENT` deux heures apres
 * l'ouverture ecrirait ses inclusions sous une version que le combat n'a jamais connue. La
 * contrainte d'unicite porte sur le triplet combat / evenement / version — deux versions au sein
 * d'un meme combat feraient donc entrer le meme evenement **deux fois**, et la garnison serait
 * comptee double.
 *
 * Une seule frontiere a le droit de lire `CURRENT` : l'ouverture. Tout le reste relit la colonne.
 *
 * ## La lecture, quand une version inconnue se presente
 *
 * Elle s'arrete. Un combat ouvert sous une projection que ce code ne connait plus ne se rejoue pas
 * « au mieux » : ses inclusions signifieraient autre chose que ce qu'elles disent, et personne ne
 * le verrait.
 */
final class SnapshotProjection
{
    /**
     * La version sous laquelle une ouverture d'aujourd'hui ecrit ses inclusions.
     *
     * **Ne la lisez pas ailleurs qu'a l'ouverture.** La colonne `projection_version` de l'instance
     * fait foi partout ensuite.
     */
    public const string CURRENT = 'v1';

    /**
     * Les versions que ce code sait interpreter.
     *
     * @var array<int, string>
     */
    private const array KNOWN = [self::CURRENT];

    /**
     * La version persistee, verifiee.
     *
     * @throws UnknownSnapshotProjection Si le combat a ete ouvert sous une projection inconnue.
     */
    public static function ensureKnown(string $version): string
    {
        if (!in_array($version, self::KNOWN, true)) {
            throw new UnknownSnapshotProjection($version, self::KNOWN);
        }

        return $version;
    }
}
