<?php

namespace OGame\Lifeforms\Research;

/**
 * L experience d un compte dans une espece, et le bonus qu elle donne aux technologies de cette
 * espece.
 *
 * Officiel (FAQ et annonce) : 0,1 % par niveau, jusqu au niveau 100, soit 10 % au plus ; l experience
 * se gagne par les decouvertes.
 *
 * **Le bareme est officiel lui aussi**, releve sur la page des bonus capturee (`lfbonuses.html`) : la barre
 * d experience y affiche « Level 0: 0/900 XP » et, pour deux especes de niveau 4, « 2173/4500 XP » et
 * « 591/4500 XP ». Le denominateur est donc le cout du **niveau suivant**, et il vaut 900 × N : 900 pour le
 * premier niveau, 4 500 pour le cinquieme. Soit 4 545 000 points pour le niveau 100.
 *
 * Ce bareme remplace la regle Azria de la tranche 3, qui comptait 1 000 × N faute d avoir lu ce chiffre
 * (journal §155.7). La forme n a pas change, seule la constante.
 */
final class LifeformExperience
{
    public const int VERSION = 2;

    public const int MAX_LEVEL = 100;

    public const float BONUS_PER_LEVEL = 0.001;

    /**
     * Les points que coute le passage au niveau N, divises par N : 900, releves sur la page reelle.
     */
    public const int POINTS_PER_STEP = 900;

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
