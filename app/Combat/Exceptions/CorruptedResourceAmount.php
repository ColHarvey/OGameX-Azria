<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un solde de ressources qui ne decrit aucune quantite reelle.
 *
 * ## Ce qui releve de cette erreur, et ce qui n'en releve pas
 *
 * `NaN`, `INF`, `-INF`, un stock materiellement negatif : ces valeurs ne sont pas de petites
 * imprecisions, ce sont des donnees qui ont perdu leur sens. Les convertir donnerait un nombre, et
 * ce nombre serait invente.
 *
 * **Une perte de precision n'en releve pas.** Un stock au-dela de deux puissance cinquante-trois
 * reste une quantite reelle : le `double` ne distingue simplement plus tous les entiers voisins.
 * Le refuser rendrait une planete assez riche impossible a piller — une immunite economique gagnee
 * en jouant.
 *
 * ## Pourquoi une exception distincte du refus de camp
 *
 * `UnsupportedActorSide` se degrade en combat sans butin : c'est une composition qu'aucune regle ne
 * couvre, et la mission peut continuer. Une corruption numerique, elle, ne doit **jamais** se
 * transformer en simple absence de pillage : le combat s'appliquerait sur des donnees dont personne
 * n'a verifie la validite, et la corruption resterait invisible.
 */
class CorruptedResourceAmount extends RuntimeException
{
    /**
     * @param string $field
     * @param float $amount
     * @return self
     */
    public static function becauseItIsNotFinite(string $field, float $amount): self
    {
        return new self(
            'Le solde « ' . $field . ' » vaut ' . var_export($amount, true) . ' : ce n est pas une quantite. '
            . 'Aucune conversion ne peut en tirer un nombre d unites sans l inventer.'
        );
    }

    /**
     * @param string $field
     * @param float $amount
     * @param float $tolerance
     * @return self
     */
    public static function becauseItIsMateriallyNegative(string $field, float $amount, float $tolerance): self
    {
        return new self(
            'Le solde « ' . $field . ' » vaut ' . $amount . ', au-dela de la tolerance de ' . $tolerance . ' unite '
            . 'accordee aux artefacts d arrondi. Une dette de cette taille n est pas une imprecision : '
            . 'la ramener a zero masquerait un etat qui merite d etre vu.'
        );
    }

    /**
     * Une cargaison qui ne porte pas un nombre entier d unites.
     *
     * Un stock de planete avance par fractions ; **une cargaison, non**. Elle est posee une fois au
     * depart de la flotte, en unites entieres, et rien ne la fait produire en vol. Une fraction
     * dessus est une donnee abimee, et l arrondir ferait disparaitre la difference sans que
     * personne ne l apprenne : la colonne du retour est entiere, donc `10.9` deviendrait `10` des
     * deux cotes de la comparaison, et neuf dixiemes d unite seraient perdus par une egalite.
     *
     * @param string $field
     * @param float $amount
     * @return self
     */
    public static function becauseItIsNotAWholeUnit(string $field, float $amount): self
    {
        return new self(
            'La cargaison « ' . $field . ' » vaut ' . var_export($amount, true) . ', qui n est pas un nombre entier '
            . 'd unites. Une cargaison se pose entiere au depart et ne produit rien en vol : arrondir ici perdrait '
            . 'la fraction en silence au lieu de signaler la donnee abimee.'
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
            'La conversion du solde « ' . $field . ' » (' . $amount . ') en unites entieres rend une valeur qui ne '
            . 'lui correspond plus. La donnee ou la plateforme ne se comportent pas comme prevu.'
        );
    }
}
