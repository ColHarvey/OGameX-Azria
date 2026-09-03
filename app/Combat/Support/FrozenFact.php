<?php

namespace OGame\Combat\Support;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;

/**
 * La lecture d'un fait gele, sans conversion.
 *
 * ## Pourquoi ces cinq lecteurs existent
 *
 * Les relectures du plan lunaire castaient leurs faits — `(int)`, `(float)`, `(string)` — et chaque
 * cast acceptait ce qu'il ne devait pas : une chaine numerique, un booleen, un nombre a la place
 * d'un texte. Le resultat etait toujours plausible, et jamais verifie.
 *
 * Un fait gele se relit **tel qu'il a ete ecrit**, ou pas du tout. Ces lecteurs refusent tout ce
 * qui n'a pas exactement le type attendu, en nommant le champ : une exception qui dit « moon_id
 * est une chaine » se traite, une valeur convertie en silence se decouvre dans un rejeu qui ne
 * correspond plus a l'original.
 *
 * ## Une seule tolerance, et elle est dite
 *
 * `number()` accepte un entier **ou** un flottant. Une chance de destruction ecrite `100` est relue
 * `100` par le decodeur JSON, pas `100.0` : exiger un flottant refuserait un fait exact. Mais une
 * chaine « 0.5 » reste refusee — elle ne vient pas du meme ecrivain.
 */
final class FrozenFact
{
    /**
     * Un entier, exactement.
     *
     * @param array<string, mixed> $facts
     */
    public static function int(array $facts, string $field): int
    {
        $valeur = self::present($facts, $field);

        if (!is_int($valeur)) {
            throw new CorruptedFrozenMoonPlan(
                'le fait « ' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un entier',
                $facts
            );
        }

        return $valeur;
    }

    /**
     * Un entier, ou l'absence explicite d'un entier.
     *
     * @param array<string, mixed> $facts
     */
    public static function intOrNull(array $facts, string $field): int|null
    {
        if (!array_key_exists($field, $facts)) {
            throw new CorruptedFrozenMoonPlan('le fait « ' . $field . ' » manque', $facts);
        }

        $valeur = $facts[$field];

        if ($valeur !== null && !is_int($valeur)) {
            throw new CorruptedFrozenMoonPlan(
                'le fait « ' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un entier ni null',
                $facts
            );
        }

        return $valeur;
    }

    /**
     * Un nombre — entier ou flottant, jamais une chaine.
     *
     * @param array<string, mixed> $facts
     */
    public static function number(array $facts, string $field): float
    {
        $valeur = self::present($facts, $field);

        if (!is_int($valeur) && !is_float($valeur)) {
            throw new CorruptedFrozenMoonPlan(
                'le fait « ' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un nombre',
                $facts
            );
        }

        return (float)$valeur;
    }

    /**
     * Un texte, exactement.
     *
     * @param array<string, mixed> $facts
     */
    public static function string(array $facts, string $field): string
    {
        $valeur = self::present($facts, $field);

        if (!is_string($valeur)) {
            throw new CorruptedFrozenMoonPlan(
                'le fait « ' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un texte',
                $facts
            );
        }

        return $valeur;
    }

    /**
     * Une structure.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    public static function array(array $facts, string $field): array
    {
        $valeur = self::present($facts, $field);

        if (!is_array($valeur)) {
            throw new CorruptedFrozenMoonPlan(
                'le fait « ' . $field . ' » est un ' . get_debug_type($valeur) . ' et non une structure',
                $facts
            );
        }

        return $valeur;
    }

    /**
     * Une liste de structures, dans l'ordre ou elle a ete ecrite.
     *
     * @param array<string, mixed> $facts
     * @return array<int, array<string, mixed>>
     */
    public static function listOfArrays(array $facts, string $field): array
    {
        $liste = self::array($facts, $field);

        if (!array_is_list($liste)) {
            throw new CorruptedFrozenMoonPlan('le fait « ' . $field . ' » n est pas une liste', $facts);
        }

        foreach ($liste as $rang => $element) {
            if (!is_array($element)) {
                throw new CorruptedFrozenMoonPlan(
                    'l element ' . $rang . ' de « ' . $field . ' » est un ' . get_debug_type($element)
                    . ' et non une structure',
                    $facts
                );
            }
        }

        return $liste;
    }

    /**
     * La valeur d'un champ present, `null` compris.
     *
     * @param array<string, mixed> $facts
     */
    private static function present(array $facts, string $field): mixed
    {
        if (!array_key_exists($field, $facts)) {
            throw new CorruptedFrozenMoonPlan('le fait « ' . $field . ' » manque', $facts);
        }

        return $facts[$field];
    }
}
