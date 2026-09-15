<?php

namespace OGame\Lifeforms\Bonuses;

use Closure;

/**
 * La memoire des bonus resolus, pour la duree d une requete — et au plus une minute.
 *
 * ## Pourquoi une memoire, et pourquoi statique
 *
 * La production d une planete est recalculee a chaque mise a jour, objet par objet ; resoudre les bonus
 * a chaque objet relirait les niveaux, les emplacements et l experience de **toutes** les planetes du
 * compte plusieurs fois par page. Le resolveur est resolu par le conteneur a chaque appel (pas de
 * singleton), la memoire vit donc ici, statique, et meurt avec le processus PHP de la requete.
 *
 * ## Pourquoi une minute
 *
 * Un travailleur de longue duree (file, planificateur) garderait sinon une lecture perimee : toute
 * ecriture des formes de vie faite **dans ce processus** l invalide (`invalidate()`), et une ecriture
 * faite ailleurs est vue au plus tard une minute apres. L etat des emplacements depend aussi de la
 * population, qui bouge sans ecriture des niveaux : la meme minute borne ce retard.
 */
final class LifeformBonusCache
{
    public const int TTL = 60;

    private static int $generation = 0;

    /**
     * @var array<string, array{generation: int, at: int, value: mixed}>
     */
    private static array $entries = [];

    public static function invalidate(): void
    {
        self::$generation++;
        self::$entries = [];
    }

    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * Rend la valeur memorisee, ou la calcule. Un calcul qui rend `null` n est pas memorise.
     *
     * @param Closure(): mixed $compute
     */
    public static function remember(string $key, int $now, Closure $compute): mixed
    {
        $entree = self::$entries[$key] ?? null;
        if ($entree !== null && $entree['generation'] === self::$generation && $now - $entree['at'] < self::TTL) {
            return $entree['value'];
        }
        $valeur = $compute();
        if ($valeur !== null) {
            self::$entries[$key] = ['generation' => self::$generation, 'at' => $now, 'value' => $valeur];
        }

        return $valeur;
    }
}
