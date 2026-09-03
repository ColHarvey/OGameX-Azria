<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * L'ensemble de versions lu n'a pas la forme qu'on lui avait donnee.
 *
 * ## Pourquoi lever, et non lire une chaine vide
 *
 * C'est le meme defaut que celui de la photographie d'alliance, applique aux regles. Une clef
 * absente devenait `''`, et une chaine vide ne resout aucun registre : le combat se serait rejoue
 * sous une regle indeterminee, ou aurait echoue bien plus loin, sur un message qui ne parle plus de
 * la cause.
 *
 * **Les quatre versions font partie de l'identite du resultat.** Elles entrent dans l'empreinte et
 * dans les cles d'idempotence : une version vide y entrerait aussi, et deux combats gouvernes par
 * des regles differentes finiraient par partager une empreinte.
 *
 * Ne pas savoir sous quelles regles un combat s'est ouvert n'est pas la meme chose que savoir qu'il
 * n'en avait pas. Le seul comportement qui ne ment pas est de s'arreter.
 */
class CorruptedRuleVersionSet extends RuntimeException
{
    /**
     * @param string $defect Ce qui n'allait pas, en clair.
     * @param mixed $received Ce qui a ete lu, pour l'enquete.
     */
    public function __construct(
        public readonly string $defect,
        public readonly mixed $received = null,
    ) {
        parent::__construct(
            'L ensemble de versions de regle est inexploitable : ' . $defect . '. Il n est pas '
            . 'complete par des valeurs vides : une version indeterminee entrerait dans l empreinte '
            . 'du combat et le rendrait comparable a un autre. Lu : ' . self::describe($received) . '.'
        );
    }

    /**
     * Ce qui a ete lu, sous une forme lisible dans un journal.
     */
    private static function describe(mixed $received): string
    {
        if ($received === null) {
            return 'null';
        }

        $rendu = json_encode($received);

        return $rendu === false ? gettype($received) : $rendu;
    }
}
