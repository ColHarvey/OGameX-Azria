<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;

/**
 * La borne superieure du butin legalement pillable, calculee sans rien savoir de l'issue.
 *
 * ## Pourquoi une fonction pure
 *
 * C'est le seul calcul du combat persistant qui doit pouvoir etre refait a l'identique, des mois
 * plus tard, a partir de ce qui a ete journalise. Il ne lit donc ni base, ni service, ni horloge :
 * les ressources de la cible et la politique de pillage entrent, une borne sort.
 *
 * ## Ce qu'elle borne, et ce qu'elle ignore
 *
 * Elle borne `ressources x taux`, ressource par ressource. Elle **ignore volontairement la capacite
 * de fret de l'attaquant**, qui pourtant reduit souvent le butin reel : la faire entrer ici
 * ferait varier le solde disponible du defenseur avec la composition de la flotte adverse, et lui
 * apprendrait ce qu'il n'a pas encore le droit de savoir.
 *
 * Elle est donc, par construction, souvent plus large que ce qui sera reellement pris. C'est le
 * prix de la propriete anti-oracle, pas un defaut de precision.
 *
 * ## Pourquoi arrondir la ressource vers le haut
 *
 * Les ressources du jeu sont des flottants : la production les fait avancer par fractions. Une
 * borne calculee sur la partie entiere serait parfois **trop basse d'une unite**, et le reglage
 * echouerait faute de matiere reservee.
 *
 * L'exemple minimal, et il est atteignable : 1,9 de metal contre une cible inactive attaquee par
 * des Decouvreurs, soit 75 %. Le butin peut valoir 1 — `floor(1,9 x 0,75)` — alors qu'une borne
 * prise sur la partie entiere donnerait `floor(1 x 0,75) = 0`, et le reglage n'aurait rien a
 * prelever.
 *
 * Arrondir la ressource vers le haut avant de multiplier ne peut jamais sous-estimer, puisque le
 * taux ne depasse pas cent pour cent. La borne depasse alors le butin reel d'une unite au plus.
 */
final class LootBound
{
    /**
     * Cent pour cent, en points de base. Le denominateur de tous les taux.
     */
    private const int FULL_RATE = LootPolicy::FULL_RATE;

    /**
     * La borne a reserver pour cette cible et cette politique.
     *
     * **Le taux est suppose ne pas depasser cent pour cent**, sans quoi arrondir la ressource vers
     * le haut ne bornerait plus rien. La condition n'est pas revalidee ici : elle est garantie par
     * `LootPolicy::maximumRateInBasisPoints()` et verifiee par `LootPolicyTest`. La reverifier
     * donnerait une branche qu'aucun test ne peut atteindre, et une branche que rien n'atteint
     * n'est pas une protection.
     *
     * @param LootEnvelope $onTarget Ce que la cible a en caisse.
     * @param LootPolicy $policy La regle de pillage figee a l'ouverture.
     * @return LootEnvelope
     */
    public static function upperBoundFor(LootEnvelope $onTarget, LootPolicy $policy): LootEnvelope
    {
        $taux = $policy->maximumRateInBasisPoints();

        return new LootEnvelope(
            (float)self::boundFor($onTarget->metal, $taux),
            (float)self::boundFor($onTarget->crystal, $taux),
            (float)self::boundFor($onTarget->deuterium, $taux),
        );
    }

    /**
     * La borne d'une seule ressource.
     *
     * @param float $amount Le stock, positif ou nul et fini — `LootEnvelope` s'en porte garant.
     * @param int $rateInBasisPoints
     * @return int
     */
    private static function boundFor(float $amount, int $rateInBasisPoints): int
    {
        $plafond = ceil($amount);

        // **La verification precede la conversion, et pas l'inverse.** Depuis PHP 8.1, convertir un
        // flottant hors plage en entier emet une alerte que Laravel transforme en exception : le
        // controle serait alors arrive trop tard, et l'erreur remontee ne dirait pas ce qui s'est
        // reellement passe.
        if ($plafond >= (float)PHP_INT_MAX) {
            throw new InvalidArgumentException(
                'Un stock de ' . $amount . ' depasse la capacite d un entier : aucune mine du jeu ne peut l atteindre, '
                . 'et cette valeur signale une donnee corrompue plutot qu une fortune.'
            );
        }

        return ExactRatio::floorOfProductOverDivisor((int)$plafond, $rateInBasisPoints, self::FULL_RATE);
    }
}
