<?php

namespace OGame\Support;

use OGame\Exceptions\UnrepresentableWholeUnits;

/**
 * Le domaine des entiers que cette plateforme porte reellement, et rien d'autre.
 *
 * ## Pourquoi une primitive neutre
 *
 * Deux endroits ont besoin de la meme question : la frontiere economique du combat, qui convertit
 * des soldes flottants venus de la base, et le credit d'un corps, qui refuse d'ajouter ce qu'il ne
 * sait pas ajouter. Chacun ecrivant sa version, les deux auraient fini par diverger — et la version
 * la plus indulgente aurait fait autorite le jour ou un nombre passerait par elle.
 *
 * Cette classe ne connait ni les ressources, ni le combat, ni la base : elle repond a une question
 * de plateforme, et c'est tout. C'est ce qui lui permet d'etre partagee sans creer de dependance
 * entre un service general et le pipeline de combat.
 *
 * ## La borne, et pourquoi elle ne se compare pas a `PHP_INT_MAX`
 *
 * `PHP_INT_MAX` n'est pas representable exactement en flottant : le comparer a un flottant compare
 * en realite a deux puissance soixante-trois, et le test ment d'une unite. Le refus porte donc sur
 * `>= 2^63` — exactement representable, et hors du domaine d'un entier signe de soixante-quatre
 * bits — puis la conversion verifie son **propre resultat** par un aller-retour.
 *
 * Sans cette borne, une valeur comme `1e30` traversait : finie, positive, egale a son plancher. Le
 * transtypage la rendait negative ou nulle selon la plateforme, et un credit disparaissait — ou pire,
 * devenait un debit.
 */
final class WholeUnits
{
    /**
     * Deux puissance soixante-trois : exactement representable en flottant, et hors du domaine
     * d'un entier signe de soixante-quatre bits.
     */
    public const float INTEGER_DOMAIN_LIMIT = 9223372036854775808.0;

    /**
     * L'entier que ce flottant represente, ou un refus.
     *
     * Le montant doit deja etre entier : cette classe ne decide d'aucun arrondi, elle ne fait que
     * garder le domaine. L'appelant qui veut un plancher ou un plafond l'applique avant.
     *
     * @param float $amount
     * @param string $field Le nom du montant, pour nommer precisement ce qui est refuse.
     * @return int
     *
     * @throws UnrepresentableWholeUnits
     */
    public static function of(float $amount, string $field): int
    {
        if (!is_finite($amount)) {
            throw UnrepresentableWholeUnits::becauseItIsNotFinite($field, $amount);
        }

        if ($amount <= -self::INTEGER_DOMAIN_LIMIT || $amount >= self::INTEGER_DOMAIN_LIMIT) {
            throw UnrepresentableWholeUnits::becauseItLeavesTheIntegerDomain($field, $amount);
        }

        $entier = (int)$amount;

        // **L'aller-retour, et non la seule borne.** Une plateforme dont l'entier ne ferait pas
        // soixante-quatre bits, ou un flottant dont la conversion se comporterait autrement, se
        // verrait ici plutot qu'a la premiere ecriture fausse.
        if ((float)$entier !== $amount) {
            throw UnrepresentableWholeUnits::becauseTheConversionIsIncoherent($field, $amount);
        }

        return $entier;
    }

    /**
     * Si ce flottant tient dans le domaine entier de cette plateforme.
     *
     * @param float $amount
     * @return bool
     */
    public static function representable(float $amount): bool
    {
        if (!is_finite($amount)) {
            return false;
        }

        if ($amount <= -self::INTEGER_DOMAIN_LIMIT || $amount >= self::INTEGER_DOMAIN_LIMIT) {
            return false;
        }

        return (float)(int)$amount === $amount;
    }
}
