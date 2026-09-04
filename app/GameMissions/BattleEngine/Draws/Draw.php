<?php

namespace OGame\GameMissions\BattleEngine\Draws;

use InvalidArgumentException;

/**
 * Les trois usages d'un tirage, chacun une formule fixe sur un entier uniforme.
 *
 * ## Pourquoi les formules vivent ici, et nulle part ailleurs
 *
 * Le moteur Rust les reproduit a l'identique (`draw_index`, `draw_explodes`, `draw_rapidfire` dans
 * `lib.rs`). Une formule qui changerait d'un cote sans l'autre ferait diverger deux batailles
 * nourries des memes tirages, et le banc de parite le dirait — c'est son role. Les distributions
 * sont celles que le moteur PHP avait deja : une position uniforme, un pour-cent entier de 0 a 100
 * compare strictement, un centieme de pour-cent de 0,01 a 100,00 compare largement.
 */
final class Draw
{
    /**
     * Une position uniforme parmi `$count` candidats, de 0 a `$count - 1`.
     */
    public static function index(BattleDraws $draws, int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A target is drawn among at least one candidate, got ' . $count . '.');
        }

        return $draws->next() % $count;
    }

    /**
     * Une coque entamee explose-t-elle ? Un pour-cent entier de 0 a 100, strictement sous la chance.
     */
    public static function explodes(BattleDraws $draws, float $chance): bool
    {
        return ($draws->next() % 101) < $chance;
    }

    /**
     * Un tir rapide est-il accorde ? Un centieme de pour-cent de 0,01 a 100,00, au plus egal a la chance.
     */
    public static function rapidfire(BattleDraws $draws, float $chance): bool
    {
        return ((1 + $draws->next() % 10000) / 100) <= $chance;
    }
}
