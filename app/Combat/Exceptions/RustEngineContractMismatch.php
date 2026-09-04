<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * La bibliotheque Rust chargee ne parle pas le contrat que ce client attend.
 *
 * ## Pourquoi un refus au chargement, et non un resultat degrade
 *
 * Le contrat FFI est un document JSON dans les deux sens. Une bibliotheque plus ancienne
 * repondrait avec des rounds qui ne disent pas de quelle flotte vient chaque perte ; une plus
 * recente pourrait en dire davantage que ce client ne sait lire. Dans les deux cas le resultat
 * aurait l'air d'une bataille, et rien ne le distinguerait d'une bataille juste avant qu'une
 * chronologie ou un banc de parite ne s'y casse. La version se lit avant le premier combat, et
 * un desaccord arrete le moteur ici, avec les deux numeros.
 */
final class RustEngineContractMismatch extends RuntimeException
{
    public static function becauseTheLibrarySpeaks(int $libraryVersion, int $expectedVersion): self
    {
        return new self(sprintf(
            'La bibliotheque Rust parle le contrat FFI %d ; ce client attend le contrat %d. Recompiler la bibliotheque (rust/compile.sh) ou deployer le client qui va avec.',
            $libraryVersion,
            $expectedVersion
        ));
    }

    public static function becauseTheAnswerIs(string $reason): self
    {
        return new self('La reponse du moteur Rust n est pas une bataille : ' . $reason);
    }
}
