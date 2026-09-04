<?php

namespace OGame\Exceptions;

use RuntimeException;

/**
 * Un montant que le domaine entier de cette plateforme ne porte pas.
 *
 * ## Ce que cette erreur dit, et ce qu'elle ne dit pas
 *
 * Elle ne juge pas la valeur : elle constate que la convertir en entier donnerait autre chose
 * qu'elle-meme. `1e30` est une quantite parfaitement decrivable ; simplement, aucun entier signe de
 * soixante-quatre bits ne la porte, et le transtypage la rendrait negative ou nulle selon la
 * plateforme. Un credit deviendrait un debit sans que rien ne le dise.
 *
 * Elle ne juge pas non plus le sens metier — une cargaison abimee, un stock corrompu, un artefact
 * d'arrondi. Ces distinctions appartiennent aux frontieres qui l'appellent ; celle-ci ne connait que
 * la plateforme.
 */
class UnrepresentableWholeUnits extends RuntimeException
{
    /**
     * @param string $field
     * @param float $amount
     * @return self
     */
    public static function becauseItIsNotFinite(string $field, float $amount): self
    {
        return new self(
            'Le montant « ' . $field . ' » vaut ' . var_export($amount, true) . ' : ce n est pas un nombre, '
            . 'et aucun entier ne le represente.'
        );
    }

    /**
     * @param string $field
     * @param float $amount
     * @return self
     */
    public static function becauseItLeavesTheIntegerDomain(string $field, float $amount): self
    {
        return new self(
            'Le montant « ' . $field . ' » vaut ' . $amount . ', hors du domaine des entiers signes de '
            . 'soixante-quatre bits. Le transtyper rendrait une valeur qui ne lui correspond pas — souvent '
            . 'negative, parfois nulle — et l ecriture qui suit serait fausse sans le dire.'
        );
    }

    /**
     * @param string $field
     * @param float $amount
     * @return self
     */
    public static function becauseTheConversionIsIncoherent(string $field, float $amount): self
    {
        return new self(
            'La conversion du montant « ' . $field . ' » (' . $amount . ') en entier rend une valeur qui ne lui '
            . 'correspond plus. La donnee ou la plateforme ne se comportent pas comme prevu.'
        );
    }
}
