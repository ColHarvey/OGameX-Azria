<?php

namespace OGame\GameMissions\BattleEngine\Draws;

/**
 * Les deux formules qui transforment un tirage en decision : explosion, tir rapide.
 *
 * ## Pourquoi elles vivent ici, et nulle part ailleurs
 *
 * Le moteur Rust les reproduit a l'identique (`draw_explodes`, `draw_rapidfire` dans `lib.rs`).
 * Une formule qui changerait d'un cote sans l'autre ferait diverger deux batailles nourries des
 * memes tirages, et le banc de parite le dirait — c'est son role. Les frontieres sont celles que
 * le moteur PHP avait deja et qu'un essai epingle en valeurs ecrites en dur : un pour-cent entier
 * de 0 a 100 compare **strictement** a la chance, un centieme de pour-cent de 0,01 a 100,00
 * compare **largement**.
 */
final class Draw
{
    /**
     * Une coque entamee explose-t-elle ? `rand(0, 100) < chance`, comme avant.
     */
    public static function explodes(BattleDraws $draws, float $chance): bool
    {
        return $draws->explosionPercent() < $chance;
    }

    /**
     * Un tir rapide est-il accorde ? `random_int(1, 10000) / 100 <= chance`, comme avant.
     */
    public static function rapidfire(BattleDraws $draws, float $chance): bool
    {
        return ($draws->rapidfireCentipercent() / 100) <= $chance;
    }
}
