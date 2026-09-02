<?php

namespace OGame\Combat\Causality;

/**
 * Ce que la reconciliation causale decide d'un evenement.
 *
 * Une sortie **fermee** : cinq cas, et aucune autre issue. Un consommateur qui les traite tous ne
 * peut rien laisser passer par defaut.
 */
enum CausalAdmission: string
{
    /**
     * L'engagement qui a ouvert le combat.
     *
     * Un fait fondateur, verifie separement : il n'a pas a etre anterieur a une ouverture qu'il a
     * lui-meme provoquee. Il survit meme a une fenetre nulle.
     */
    case FoundingInitiator = 'founding_initiator';

    /**
     * Admissible : son effet sera applique, puis il entrera dans la photographie.
     */
    case AppliedBeforeSnapshot = 'applied_before_snapshot';

    /**
     * Deja reflete dans l'etat d'ouverture : il entre dans la photographie **sans etre rejoue**.
     *
     * ## Le cas qui separe une reconciliation juste d'une reconciliation qui double
     *
     * Les deux barrieres disent oui — l'engagement etait bien anterieur a l'ouverture, l'effet bien
     * anterieur a la fermeture. Seule la provenance dit que c'est deja fait. Sans ce cas distinct,
     * il se confondrait avec `AppliedBeforeSnapshot`, et son effet serait ajoute une seconde fois.
     */
    case AlreadyInOpeningState = 'already_in_opening_state';

    /**
     * Hors photographie : l'effet a lieu, mais il n'entre pas dans ce combat.
     */
    case OutsideSnapshot = 'outside_snapshot';

    /**
     * L'evenement ne concerne pas ce combat, ou n'est plus valide.
     */
    case NotApplicable = 'not_applicable';

    /**
     * Si cette issue demande que l'effet soit applique avant la photographie.
     *
     * **`AlreadyInOpeningState` rend faux**, et c'est tout l'objet de la distinction.
     */
    public function requiresApplication(): bool
    {
        return $this === self::AppliedBeforeSnapshot;
    }

    /**
     * Si cette issue entre dans la photographie.
     */
    public function entersTheSnapshot(): bool
    {
        return match ($this) {
            self::FoundingInitiator, self::AppliedBeforeSnapshot, self::AlreadyInOpeningState => true,
            self::OutsideSnapshot, self::NotApplicable => false,
        };
    }
}
