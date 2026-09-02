<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un contexte de pillage relu dont les valeurs derivees ne correspondent plus a ses faits.
 *
 * Un contexte persiste porte a la fois **ce qui a ete observe** — inactivite, fret engage, part des
 * Decouvreurs — et **ce qui en a ete deduit** : le taux et la version de la regle. Les seconds sont
 * conserves pour l'audit, pour qu'un rapport puisse dire des mois plus tard quel taux avait ete
 * applique sans avoir a refaire le calcul.
 *
 * Cette redondance est utile, et elle est dangereuse : une ligne modifiee en base, une migration
 * maladroite ou une reponse falsifiee ferait diverger les deux. Le rechargement recalcule donc les
 * valeurs derivees a partir des faits et refuse de rendre un contexte ou elles ne coincident pas.
 *
 * **Refuser plutot que choisir.** Rien ne permet de decider laquelle des deux versions dit vrai :
 * preferer silencieusement les faits recalcules effacerait la trace d'une alteration, et preferer
 * les valeurs stockees appliquerait un taux que personne n'a calcule.
 */
class FalsifiedLootContext extends RuntimeException
{
    /**
     * Le taux stocke ne correspond pas a celui que les faits produisent.
     *
     * @param int $stored
     * @param int $recomputed
     * @return self
     */
    public static function becauseTheRateDoesNotMatchTheFacts(int $stored, int $recomputed): self
    {
        return new self(
            'Le taux de pillage conserve (' . $stored . ' points de base) ne correspond pas a celui que les faits '
            . 'produisent (' . $recomputed . ') : les faits ou le taux ont ete alteres depuis la photographie.'
        );
    }

    /**
     * La version de la regle stockee n'est pas celle qui a servi.
     *
     * @param string $stored
     * @param string $expected
     * @return self
     */
    public static function becauseThePolicyVersionDoesNotMatch(string $stored, string $expected): self
    {
        return new self(
            'Le contexte annonce la regle « ' . $stored . ' » alors que ces faits relevent de « ' . $expected . ' ». '
            . 'Un combat garde la version sous laquelle il a commence : relire ses faits sous une autre regle '
            . 'donnerait un taux que personne n a applique.'
        );
    }

    /**
     * Un champ obligatoire manque, ou n'a pas le type attendu.
     *
     * @param string $field
     * @return self
     */
    public static function becauseTheFieldIsMissingOrMalformed(string $field): self
    {
        return new self(
            'Le champ « ' . $field . ' » manque au contexte de pillage relu, ou n a pas le type attendu : '
            . 'un contexte incomplet ne peut pas etre complete par des valeurs par defaut sans inventer un fait.'
        );
    }

    /**
     * Le contexte a ete construit pour d'autres flottes, ou pour une autre cible.
     *
     * @param string $expected
     * @param string $found
     * @return self
     */
    public static function becauseItDoesNotBindToTheseFleets(string $expected, string $found): self
    {
        return new self(
            'Ce contexte de pillage a ete photographie pour une autre composition (' . $found . ') que celle qui '
            . 'se presente (' . $expected . ') : appliquer le contexte d un combat aux flottes d un autre donnerait '
            . 'un taux etranger a la bataille en cours.'
        );
    }
}
