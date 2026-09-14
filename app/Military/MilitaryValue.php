<?php

namespace OGame\Military;

use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\Exceptions\UnknownMilitaryUnit;
use OGame\Services\ObjectService;

/**
 * La valeur militaire d'unités, dans l'unité des compteurs cumulés.
 *
 * ## La pondération, et pourquoi des demi-unités
 *
 * Le score militaire du jeu compte les défenses et les vaisseaux militaires à 100 %, les vaisseaux civils à
 * 50 %, puis divise la somme des ressources par mille. Appliquer ce demi à chaque événement obligerait à
 * arrondir : une sonde, qui vaut moins d'un point, perdrait sa contribution, et mille sondes perdraient mille
 * fois cette miette.
 *
 * Les valeurs sont donc **en demi-unités de ressources** : le poids vaut `2` pour une défense ou un vaisseau
 * militaire, `1` pour un vaisseau civil. La pondération n'est appliquée qu'une fois, et la conversion en points
 * n'a lieu qu'à la photographie du classement (`pointsOf()`).
 *
 * ## Une unité inconnue ne reçoit pas un poids inventé
 *
 * Les trois familles couvrent les vingt-sept unités du jeu. Si une unité neuve n'était rangée dans aucune, lui
 * donner « le poids le plus faible » écrirait un score faux dans un classement public, et une alerte ne le
 * rendrait pas juste. Cette classe **refuse** de l'évaluer (`UnknownMilitaryUnit`) ; l'appelant demande d'abord
 * `unknownUnitsIn()` et, le cas échéant, met l'événement **en attente** avec ses unités — aucun crédit, aucune
 * approximation, et une reprise idempotente l'applique une fois le catalogue corrigé.
 */
final class MilitaryValue
{
    /** Les demi-unités de ressources que vaut un point de classement. */
    public const int POINT = 2000;

    /** La version de la règle de pondération, écrite dans chaque événement. */
    public const string WEIGHTING_VERSION = 'v1';

    /** Le poids d'une défense ou d'un vaisseau militaire : la valeur entière. */
    private const int POIDS_PLEIN = 2;

    /** Le poids d'un vaisseau civil : la moitié. */
    private const int POIDS_MOITIE = 1;

    /** @var array<string, int>|null Le poids de chaque unité, par nom machine. */
    private static array|null $poids = null;

    /**
     * La valeur militaire d'une collection d'unités, en demi-unités de ressources.
     *
     * @throws UnknownMilitaryUnit si une unité n'appartient à aucune famille connue.
     */
    public static function ofUnits(UnitCollection $units): int
    {
        $valeur = 0;

        foreach ($units->units as $entree) {
            $valeur += self::ofObject($entree->unitObject, $entree->amount);
        }

        return $valeur;
    }

    /**
     * La valeur militaire d'un nombre d'unités d'un même objet, en demi-unités de ressources.
     *
     * @throws UnknownMilitaryUnit
     */
    public static function ofObject(UnitObject $object, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $prix = (int)ObjectService::getObjectRawPrice($object->machine_name)->sum();

        return $prix * $amount * self::poidsDe($object->machine_name);
    }

    /**
     * Les unités de cette collection qu'aucune famille ne sait pondérer.
     *
     * @return list<string> les noms machine, triés, sans doublon.
     */
    public static function unknownUnitsIn(UnitCollection $units): array
    {
        $inconnues = [];

        foreach ($units->units as $entree) {
            if ($entree->amount > 0 && !isset(self::table()[$entree->unitObject->machine_name])) {
                $inconnues[$entree->unitObject->machine_name] = true;
            }
        }

        $noms = array_keys($inconnues);
        sort($noms);

        return $noms;
    }

    /**
     * Les points de classement que vaut une valeur, arrondis vers le bas — **une seule fois, ici**.
     */
    public static function pointsOf(int $valeur): int
    {
        return intdiv(max(0, $valeur), self::POINT);
    }

    /**
     * @throws UnknownMilitaryUnit
     */
    private static function poidsDe(string $machineName): int
    {
        $poids = self::table()[$machineName] ?? null;

        if ($poids === null) {
            throw new UnknownMilitaryUnit($machineName);
        }

        return $poids;
    }

    /**
     * @return array<string, int>
     */
    private static function table(): array
    {
        if (self::$poids === null) {
            $poids = [];

            foreach (ObjectService::getDefenseObjects() as $objet) {
                $poids[$objet->machine_name] = self::POIDS_PLEIN;
            }

            foreach (ObjectService::getMilitaryShipObjects() as $objet) {
                $poids[$objet->machine_name] = self::POIDS_PLEIN;
            }

            foreach (ObjectService::getCivilShipObjects() as $objet) {
                $poids[$objet->machine_name] = self::POIDS_MOITIE;
            }

            self::$poids = $poids;
        }

        return self::$poids;
    }
}
