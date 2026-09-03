<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * La photographie d'appartenance lue n'a pas la forme qu'on lui avait donnee.
 *
 * ## Pourquoi lever, et non lire « aucune alliance »
 *
 * C'est le defaut que cette exception existe pour rendre impossible. Une photographie illisible
 * degradee en « personne n'appartenait a l'alliance » **est une decision de jeu** : tous les allies
 * deviennent inadmissibles, l'attaque coordonnee que des joueurs ont organisee et payee se
 * decompose, et rien dans le journal ne dit pourquoi. Une corruption de persistance se serait
 * transformee en regle silencieuse.
 *
 * Il n'existe pas de repli honnete. Ne pas savoir qui etait membre n'est pas la meme chose que
 * savoir que personne ne l'etait, et le seul comportement qui ne ment pas est de s'arreter.
 *
 * ## Ce que la forme canonique exige
 *
 * Colonne nulle : aucune alliance ne gouverne. Sinon, et sans exception : `alliance_id` entier
 * strictement positif, `members` liste d'entiers strictement positifs, triee, sans doublon, sans
 * autre cle. Une seule representation par etat — deux formes pour « aucune alliance » finiraient par
 * diverger, et une comparaison d'empreintes les dirait differentes.
 */
class CorruptedFrozenMembership extends RuntimeException
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
            'La photographie d appartenance a l alliance est inexploitable : ' . $defect . '. Elle '
            . 'n est pas relue comme « aucune alliance » : ce repli ecarterait silencieusement tous '
            . 'les allies d un combat en cours. Lu : ' . self::describe($received) . '.'
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
