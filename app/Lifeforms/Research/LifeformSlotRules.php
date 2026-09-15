<?php

namespace OGame\Lifeforms\Research;

use InvalidArgumentException;

/**
 * Les dix-huit emplacements de recherche d une planete : trois paliers de six, chacun ouvert par une
 * population de son palier.
 *
 * ## Ce qui est observe, ce qui est une regle Azria
 *
 * - **Palier 2, observe** sur une page reelle du jeu (v11.16) : 1 200 000, 3 000 000, 5 000 000,
 *   7 000 000, 9 000 000 et 11 000 000 individus de palier 2.
 * - **Palier 1** : la FAQ officielle dit « de 200 000 a 1 000 000 » ; les quatre valeurs intermediaires
 *   ne sont publiees nulle part. Regle Azria : progression lineaire, comme le palier 2.
 * - **Palier 3** : « de 13 000 000 a 448 000 000 » (wiki communautaire) ; meme regle, lineaire.
 * - Le Modulateur psionique reduit toutes les exigences (2 % par niveau, plafond 30 %, fichier maitre).
 *
 * Le palier 1 se mesure sur la population entiere, le palier 2 sur les individus de palier 2, le
 * palier 3 sur ceux de palier 3. Un emplacement dont l exigence n est plus remplie (population tombee
 * apres une attaque) garde sa technologie et son niveau, mais son bonus se tait tant qu elle n est
 * pas revenue — c est le resolveur qui l applique.
 */
final class LifeformSlotRules
{
    public const int VERSION = 1;

    public const int SLOTS = 18;

    public const int PER_TIER = 6;

    /**
     * @var array<int, array<int, int>> population exigee par palier puis par position (1 a 6)
     */
    private const array REQUIREMENTS = [
        1 => [1 => 200000, 2 => 360000, 3 => 520000, 4 => 680000, 5 => 840000, 6 => 1000000],
        2 => [1 => 1200000, 2 => 3000000, 3 => 5000000, 4 => 7000000, 5 => 9000000, 6 => 11000000],
        3 => [1 => 13000000, 2 => 100000000, 3 => 187000000, 4 => 274000000, 5 => 361000000, 6 => 448000000],
    ];

    /**
     * Les artefacts que coute le choix d une technologie par artefacts, par palier (annonce officielle).
     *
     * @var array<int, int>
     */
    public const array ARTIFACT_COST = [1 => 200, 2 => 400, 3 => 600];

    public static function tierOf(int $slot): int
    {
        self::requireSlot($slot);

        return intdiv($slot - 1, self::PER_TIER) + 1;
    }

    public static function positionOf(int $slot): int
    {
        self::requireSlot($slot);

        return (($slot - 1) % self::PER_TIER) + 1;
    }

    public static function slotOf(int $tier, int $position): int
    {
        if ($tier < 1 || $tier > 3 || $position < 1 || $position > self::PER_TIER) {
            throw new InvalidArgumentException("Palier $tier, position $position : hors des dix-huit emplacements.");
        }

        return ($tier - 1) * self::PER_TIER + $position;
    }

    /**
     * La population du palier exigee par un emplacement, apres une reduction en fraction (0 a 0,3).
     */
    public static function populationRequired(int $slot, float $reduction = 0.0): float
    {
        $base = self::REQUIREMENTS[self::tierOf($slot)][self::positionOf($slot)];
        $reduction = max(0.0, min(0.99, $reduction));

        return $base * (1 - $reduction);
    }

    /**
     * @return array<int, int> les six emplacements d un palier
     */
    public static function slotsOfTier(int $tier): array
    {
        $resultat = [];
        for ($position = 1; $position <= self::PER_TIER; $position++) {
            $resultat[] = self::slotOf($tier, $position);
        }

        return $resultat;
    }

    private static function requireSlot(int $slot): void
    {
        if ($slot < 1 || $slot > self::SLOTS) {
            throw new InvalidArgumentException("Emplacement $slot : hors des dix-huit emplacements.");
        }
    }
}
