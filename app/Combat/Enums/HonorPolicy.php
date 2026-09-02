<?php

namespace OGame\Combat\Enums;

/**
 * Le systeme d'honneur, et son etat dans ce fork.
 *
 * **Il n'est pas implemente dans OGameX**, et ce n'est pas une omission de ce chantier :
 * `TacticalRetreatService` le dit en toutes lettres, et `GalaxyController` conserve
 * `isHonorableTarget()` et `isOutlaw()` en commentaire. Les taux de pillage a 75 % pour une cible
 * honorable et 100 % pour un bandit n'ont donc aucune existence ici.
 *
 * Plutot que de laisser la question ouverte — ce qui bloquerait l'activation des combats
 * persistants pour une mecanique qui leur est etrangere — l'etat est **nomme et desactive**. La
 * politique de pillage sait qu'il existe, sait qu'il ne s'applique pas, et le dira le jour ou il
 * s'appliquera.
 *
 * Quand ce systeme sera developpe, les regles se combineront **par maximum, jamais par
 * addition** :
 *
 *     taux effectif = max(taux de classe, taux d honneur)
 *
 * Un bandit attaque par un Decouvreur reste a 100 %, pas a 175 %.
 */
enum HonorPolicy: string
{
    /**
     * Aucun systeme d'honneur : il n'ajoute rien au taux.
     */
    case Disabled = 'disabled';

    /**
     * Le taux que l'honneur impose, en points de base.
     *
     * Zero tant que le systeme n'existe pas — donc jamais gagnant dans le maximum.
     *
     * @return int
     */
    public function minimumRateInBasisPoints(): int
    {
        return match ($this) {
            self::Disabled => 0,
        };
    }
}
