<?php

namespace OGame\Lifeforms\Research;

/**
 * L experience d un compte dans une espece, et le bonus qu elle donne aux technologies de cette
 * espece.
 *
 * Officiel (FAQ et annonce) : 0,1 % par niveau, jusqu au niveau 100, soit 10 % au plus ; l experience
 * se gagne par les decouvertes. **Regle Azria** pour le bareme, que rien ne publie : le niveau N
 * coute 1 000 × N points au-dela du niveau N − 1 (1 000 pour le premier, 2 000 pour le deuxieme...),
 * soit 5 050 000 points pour le niveau 100.
 */
final class LifeformExperience
{
    public const int VERSION = 1;

    public const int MAX_LEVEL = 100;

    public const float BONUS_PER_LEVEL = 0.001;

    private const int POINTS_PER_STEP = 1000;

    public static function levelOf(int $experience): int
    {
        $experience = max(0, $experience);
        $niveau = 0;
        $seuil = 0;
        while ($niveau < self::MAX_LEVEL) {
            $seuil += self::POINTS_PER_STEP * ($niveau + 1);
            if ($experience < $seuil) {
                break;
            }
            $niveau++;
        }

        return $niveau;
    }

    /**
     * Les points deja acquis dans le niveau courant, et ceux que le niveau suivant demande.
     *
     * @return array{0: int, 1: int}
     */
    public static function progressOf(int $experience): array
    {
        $experience = max(0, $experience);
        $niveau = self::levelOf($experience);
        if ($niveau >= self::MAX_LEVEL) {
            return [0, 0];
        }
        $acquis = 0;
        for ($n = 1; $n <= $niveau; $n++) {
            $acquis += self::POINTS_PER_STEP * $n;
        }

        return [$experience - $acquis, self::POINTS_PER_STEP * ($niveau + 1)];
    }

    /**
     * Le bonus d experience, en fraction : 0,001 par niveau.
     */
    public static function bonusFraction(int $level): float
    {
        return max(0, min(self::MAX_LEVEL, $level)) * self::BONUS_PER_LEVEL;
    }
}
