<?php

namespace OGame\Combat\Support;

/**
 * Combien d'unites d'un lot sont terminees a un instant : la formule du jeu, pure.
 *
 * ## Une seule implementation
 *
 * `PlanetService::updateUnitQueue()` materialise un lot unite par unite — une toutes les
 * `(fin − debut) / quantite` secondes, et le reste a la fin. La fermeture d'un combat durable a besoin
 * du meme compte pour savoir ce qu'un lot decide avant l'ouverture apporte a la photographie : les
 * unites terminees avant la fermeture, moins celles deja materialisees a l'ouverture. Deux formules
 * divergeraient d'une unite a la premiere arrondie ; il n'y en a qu'une, et le monde l'emploie aussi.
 *
 * Le compte est **inclusif** : une unite qui finit exactement a l'instant demande est comptee, comme
 * le monde la materialise a ce passage. La fermeture, qui tient l'egalite avec sa barriere pour
 * « apres », demande l'instant precedent.
 */
final class UnitQueueProduction
{
    public static function unitsFinishedBy(int $timeStart, int $timeEnd, int $amount, int $instant): int
    {
        if ($amount <= 0 || $instant < $timeStart) {
            return 0;
        }
        if ($instant >= $timeEnd || $timeEnd <= $timeStart) {
            return $amount;
        }

        $perUnit = ($timeEnd - $timeStart) / $amount;

        return min($amount, (int)floor(($instant - $timeStart) / $perUnit));
    }

    /**
     * La premiere seconde entiere ou la n-ieme unite d'un lot compte (n a partir de 1), ou le debut si
     * n vaut zero. Le monde et la fermeture lisent des secondes entieres : une unite qui finit a 161,25
     * compte a 162, pas a 161 — d'ou le plafond, jamais le plancher.
     */
    public static function finishInstantOf(int $timeStart, int $timeEnd, int $amount, int $n): int
    {
        if ($n <= 0 || $amount <= 0) {
            return $timeStart;
        }
        if ($n >= $amount) {
            return $timeEnd;
        }

        return (int)ceil($timeStart + (($timeEnd - $timeStart) / $amount) * $n);
    }
}
