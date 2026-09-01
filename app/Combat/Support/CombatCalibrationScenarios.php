<?php

namespace OGame\Combat\Support;

use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;

/**
 * Des batailles ecrites a la main, pour calibrer sans dependre du hasard.
 *
 * Elles vivent dans le code applicatif et non dans les tests, parce que la commande de
 * calibrage et les tests doivent regarder exactement les memes batailles : deux jeux de
 * chiffres qui derivent l un de l autre ne calibreraient plus rien.
 *
 * Un vrai combat ne convient pas ici : son nombre de rounds et ses tirs varient d'une
 * execution a l'autre, et une duree qui change toute seule ne se calibre pas. Ces scenarios
 * fixent des chiffres plausibles et **reproductibles**, seule facon de comparer deux reglages
 * du coefficient de rythme.
 *
 * Les grandeurs sont celles que le moteur produit reellement : tirs portes — tirs rapides
 * compris —, degats absorbes par les boucliers, et force pleine engagee de chaque camp.
 */
class CombatCalibrationScenarios
{
    /**
     * Build a battle result from raw per-round figures.
     *
     * @param array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}> $rounds
     *        Par round : tirs attaquant, tirs defenseur, absorbe attaquant, absorbe defenseur,
     *        force attaquante, force defensive.
     * @return BattleResult
     */
    public static function fromRounds(array $rounds): BattleResult
    {
        $resultat = new BattleResult();
        $resultat->rounds = [];

        foreach ($rounds as [$tirsA, $tirsD, $absA, $absD, $forceA, $forceD]) {
            $round = new BattleResultRound();
            $round->hitsAttacker = $tirsA;
            $round->hitsDefender = $tirsD;
            $round->absorbedDamageAttacker = $absA;
            $round->absorbedDamageDefender = $absD;
            $round->fullStrengthAttacker = $forceA;
            $round->fullStrengthDefender = $forceD;

            $resultat->rounds[] = $round;
        }

        return $resultat;
    }

    /**
     * The scenarios used to calibrate the pace coefficient.
     *
     * Quatre situations que le jeu produit vraiment, du plus expeditif au plus disputé. Ce
     * sont elles qui doivent encadrer le choix du coefficient : si un seul nombre ne peut pas
     * donner des durees acceptables aux quatre, ce n'est pas le nombre qu'il faut changer.
     *
     * @return array<string, BattleResult>
     */
    public static function all(): array
    {
        return [
            // Une poignee de chasseurs contre une planete lourdement defendue. Le defenseur
            // n'est pas entame, l'attaquant disparait : presque aucun echange equilibre.
            'Ecrasement — petite flotte contre grosse defense' => self::fromRounds([
                [40, 900, 1_200, 60_000, 120_000, 9_500_000],
                [6, 880, 200, 58_000, 18_000, 9_480_000],
            ]),

            // Deux flottes moyennes qui se valent. Les boucliers encaissent, les deux camps
            // tiennent plusieurs rounds.
            'Forces moyennes comparables' => self::fromRounds([
                [3_100, 3_400, 420_000, 445_000, 4_200_000, 4_500_000],
                [2_700, 2_900, 380_000, 405_000, 3_500_000, 3_800_000],
                [2_100, 2_200, 295_000, 310_000, 2_600_000, 2_900_000],
                [1_400, 1_300, 190_000, 175_000, 1_600_000, 1_700_000],
            ]),

            // Une grande flotte se jette sur une grande defense fixe. Beaucoup de tirs, mais
            // le desequilibre reste marque.
            'Grande flotte contre grande defense' => self::fromRounds([
                [46_000, 22_000, 5_600_000, 12_400_000, 88_000_000, 41_000_000],
                [44_000, 15_000, 5_300_000, 9_800_000, 84_000_000, 27_000_000],
                [42_000, 8_600, 5_050_000, 6_100_000, 81_000_000, 14_000_000],
                [40_000, 3_100, 4_800_000, 2_400_000, 79_000_000, 5_200_000],
                [39_000, 700, 4_650_000, 620_000, 78_000_000, 1_100_000],
            ]),

            // Un nuage de sondes : beaucoup de tirs, presque aucune force. Le cas qui verifie
            // que le nombre de tirs seul ne fait pas durer un combat.
            'Nuee de sondes contre une defense' => self::fromRounds([
                [1_200, 2_400, 900, 48_000, 24_000, 6_800_000],
            ]),

            // Une planete sans defense : le defenseur n'oppose rien. Doit se resoudre
            // immediatement, quel que soit le poids de l'attaquant.
            'Flotte contre planete sans defense' => self::fromRounds([
                [18_000, 0, 0, 2_100_000, 52_000_000, 0],
            ]),

            // Deux flottes proches sans etre egales. Entre l'ecrasement et le duel parfait :
            // c'est le cas le plus frequent en jeu, et celui qui doit tomber entre les deux.
            'Attaquant legerement superieur' => self::fromRounds([
                [6_400, 5_100, 810_000, 690_000, 9_800_000, 7_400_000],
                [5_900, 3_800, 745_000, 520_000, 9_100_000, 5_200_000],
                [5_500, 2_200, 690_000, 305_000, 8_600_000, 2_900_000],
            ]),

            // Peu d'unites, une force enorme, peu de tirs : la forme d'un raid d'Etoiles de la
            // mort. Elle verifie que la puissance sans echanges ne fait pas durer.
            'Etoiles de la mort contre grande defense' => self::fromRounds([
                [220, 9_400, 1_900_000, 26_000_000, 74_000_000, 38_000_000],
                [218, 4_100, 1_880_000, 11_400_000, 73_600_000, 16_000_000],
                [216, 900, 1_860_000, 2_500_000, 73_200_000, 3_400_000],
            ]),

            // Deux armadas de meme poids : six rounds, resistance mutuelle jusqu'au bout.
            'Tres grandes forces equilibrees' => self::fromRounds([
                [118_000, 121_000, 18_900_000, 19_400_000, 302_000_000, 311_000_000],
                [109_000, 112_000, 17_400_000, 17_900_000, 276_000_000, 284_000_000],
                [96_000, 98_000, 15_300_000, 15_700_000, 241_000_000, 247_000_000],
                [79_000, 81_000, 12_600_000, 12_900_000, 198_000_000, 203_000_000],
                [58_000, 59_000, 9_200_000, 9_400_000, 146_000_000, 149_000_000],
                [34_000, 35_000, 5_400_000, 5_600_000, 86_000_000, 88_000_000],
            ]),
        ];
    }
}
