<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un solde de ressources trop grand pour tenir dans un entier de la plateforme.
 *
 * ## Une quantite reelle, mais hors d'atteinte
 *
 * Ce n'est pas une corruption : la valeur est finie, positive, et decrit une fortune que quelqu'un
 * possede vraiment. Elle depasse simplement ce qu'un entier de soixante-quatre bits peut porter.
 *
 * La distinguer de `CorruptedResourceAmount` importe pour le diagnostic : l'une signale une donnee
 * abimee, l'autre un domaine trop etroit. Les confondre ferait chercher une corruption la ou il n'y
 * a qu'une limite.
 *
 * ## Ce cas n'a pas encore de sortie operationnelle
 *
 * Personne n'a decide ce qui doit arriver a une planete dont le stock depasse cette limite : ni
 * combat, ni collecte, ni recyclage ne savent quoi faire. **Tant que cette question n'est pas
 * tranchee, elle est une condition d'activation du pipeline exact sur le chemin joueur.**
 *
 * La correction de fond est ailleurs : un type decimal exact ou un domaine de grands entiers pour
 * les colonnes de ressources. C'est un chantier separe.
 */
class UnrepresentableResourceAmount extends RuntimeException
{
    /**
     * @param string $field
     * @param float $amount
     * @return self
     */
    public static function because(string $field, float $amount): self
    {
        return new self(
            'Le solde « ' . $field . ' » vaut ' . $amount . ', au-dela de ce qu un entier de la plateforme peut '
            . 'porter. La quantite est reelle, mais le domaine entier ne la represente pas : aucune sortie '
            . 'operationnelle n a encore ete decidee pour ce cas.'
        );
    }
}
