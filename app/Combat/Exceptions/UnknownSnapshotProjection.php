<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un combat a ete ouvert sous une version de projection que ce code ne connait pas.
 *
 * ## Pourquoi s'arreter plutot que de reprendre la version courante
 *
 * Une inclusion dit ce qu'un evenement apporte a la photographie. Si sa version est inconnue, la
 * phrase est ecrite dans une langue que ce code ne parle plus : la relire sous la version courante
 * donnerait une lecture plausible et fausse, et le combat se resoudrait sur une photographie que
 * personne n'a jamais figee.
 *
 * Le repli est d'autant plus tentant qu'il marcherait presque toujours — les projections se
 * ressemblent d'une version a l'autre. C'est exactement ce qui le rend dangereux : le jour ou elles
 * different, rien ne le dira.
 */
class UnknownSnapshotProjection extends RuntimeException
{
    /**
     * @param string $version La version lue sur l'instance.
     * @param array<int, string> $known Celles que ce code sait interpreter.
     */
    public function __construct(
        public readonly string $version,
        public readonly array $known,
    ) {
        parent::__construct(
            'Le combat a ete ouvert sous la projection « ' . $version . ' », que ce code ne sait pas '
            . 'interpreter (connues : ' . implode(', ', $known) . '). La relire sous la version '
            . 'courante donnerait une photographie plausible et fausse.'
        );
    }
}
