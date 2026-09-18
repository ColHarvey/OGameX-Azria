<?php

namespace OGame\Enums;

/**
 * Le camp d'une mission de flotte aux yeux d'un joueur : la sienne, celle d'un autre qui le sert,
 * celle d'un autre qui le vise. Habille la boite d'evenements, la Galaxie et l'alarme du bandeau.
 *
 * **Une seule regle, lue par tous.** La boite d'evenements, la projection de la Galaxie et l'alarme
 * d'attaque du bandeau disaient chacune la leur : l'alarme ignorait les missiles que la boite
 * comptait hostiles, et ne regardait pas `canceled` que la boite excluait. Un joueur lisait « 1 vol
 * hostile » sans que l'icone rouge s'allume. Depuis le 18 septembre 2026 (journal §156), c'est ici
 * que le camp se decide, et nulle part ailleurs.
 */
enum FleetMissionStatus: string
{
    case Friendly = 'friendly';
    case Neutral = 'neutral';
    case Hostile = 'hostile';

    /**
     * Les genres qu'un joueur subit quand un autre les lui envoie : attaque (1), attaque groupee (2),
     * espionnage (6), destruction de lune (9), missiles (10).
     *
     * @var array<int, int>
     */
    public const array HOSTILE_MISSION_TYPES = [1, 2, 6, 9, 10];

    /**
     * Les genres qu'un autre joueur lui envoie sans le viser : transport (3), defense groupee (5).
     *
     * @var array<int, int>
     */
    public const array NEUTRAL_MISSION_TYPES = [3, 5];

    /**
     * Le camp d'une mission d'un genre donne, selon qu'elle est celle du joueur ou celle d'un autre.
     */
    public static function ofMissionType(int $missionType, bool $ownMission): self
    {
        if ($ownMission) {
            return self::Friendly;
        }
        if (in_array($missionType, self::HOSTILE_MISSION_TYPES, true)) {
            return self::Hostile;
        }
        if (in_array($missionType, self::NEUTRAL_MISSION_TYPES, true)) {
            return self::Neutral;
        }

        return self::Friendly;
    }
}
