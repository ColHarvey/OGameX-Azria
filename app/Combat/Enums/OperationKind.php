<?php

namespace OGame\Combat\Enums;

/**
 * Le genre d'operation qui a produit des diagnostics de conversion.
 *
 * ## Pourquoi le genre entre dans la cle, et pas seulement l'identifiant
 *
 * Les identifiants viennent de tables differentes : une instance de combat et une mission de flotte
 * peuvent porter le meme nombre. Sans le genre, l'attaque numero 42 et le combat persistant numero
 * 42 partageraient une cle d'operation — et deux incidents sans rapport pourraient fusionner.
 */
enum OperationKind: string
{
    /**
     * Une attaque resolue a l'arrivee de la flotte, dans le meme instant.
     */
    case ImmediateAttack = 'immediate_attack';

    /**
     * Un combat persistant, resolu a l'echeance de son instance.
     */
    case PersistentCombat = 'persistent_combat';

    /**
     * Une destruction de lune.
     */
    case MoonDestruction = 'moon_destruction';

    /**
     * Une recolte de champ de debris.
     */
    case Recycling = 'recycling';
}
