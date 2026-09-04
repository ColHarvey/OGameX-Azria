<?php

namespace OGame\Combat\Support;

use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\FleetMission;

/**
 * L'ordre de retour d'une flotte refusee : ou elle se pose, et de quel instant elle part.
 *
 * ## Pourquoi l'instant n'appartient pas a la mission
 *
 * Chaque genre de mission choisissait l'instant de depart de son retour : l'attaque prenait
 * l'arrivee de l'aller, la Defense ACS l'horloge du travailleur. Le retard du travailleur changeait
 * donc l'heure de retour d'un renfort, alors qu'une disposition prise a la fermeture possede deja
 * son instant canonique.
 *
 * ## L'instant, et pourquoi ce n'est pas seulement `decided_at`
 *
 * Une flotte part de la ou elle est. Refusee a la fermeture alors qu'elle est deja posee, elle
 * repart a la fermeture. Refusee a la fermeture alors qu'elle vole encore — candidate en vol, jugee
 * avant de se presenter —, elle ne peut pas repartir d'un point qu'elle n'a pas atteint : elle se
 * presente, trouve porte close, et rebondit a son arrivee. Jamais jugee, son arrivee est sa
 * decision. Dans les trois cas : le plus tardif de la decision et de l'arrivee physique.
 *
 * Aucun de ces instants ne depend du travailleur qui finit par executer le mouvement. C'est la
 * garantie : un passage ponctuel et un passage en retard ecrivent les memes heures.
 */
final readonly class ReturnOrder
{
    public function __construct(
        public ResolvedReturnDestination $destination,
        public int $departureAt,
    ) {
    }

    /**
     * L'instant ou une flotte refusee repart, pour une decision prise a cet instant-la.
     */
    public static function departureInstant(int $decidedAt, FleetMission $mission): int
    {
        return max($decidedAt, self::physicalArrivalOf($mission));
    }

    /**
     * La duree du trajet aller, lue sur les faits intacts de la mission.
     *
     * C'est la duree que le retour doit prendre : le protocole l'attend de l'enfant cree, et un
     * retour plus court ou plus long ne correspond pas a l'ordre.
     */
    public static function tripDurationOf(FleetMission $mission): int
    {
        return self::physicalArrivalOf($mission) - (int)$mission->time_departure;
    }

    /**
     * L'instant ou la flotte s'est reellement posee sur le corps.
     *
     * Pour une Defense ACS, `time_arrival` porte la fin du stationnement : l'arrivee physique est en
     * amont. Pour les autres genres, le stationnement est nul et les deux se confondent.
     */
    public static function physicalArrivalOf(FleetMission $mission): int
    {
        return (int)$mission->time_arrival - (int)($mission->time_holding ?? 0);
    }
}
