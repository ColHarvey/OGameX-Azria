<?php

namespace OGame\Combat\Projection;

use OGame\GameObjects\Models\DefenseObject;
use OGame\Services\ObjectService;

/**
 * Ce qu'une salve de missiles detruit sur un ensemble de defenses : la formule du jeu, pure.
 *
 * ## Une seule implementation, deux lecteurs
 *
 * `MissileMission` frappe le monde avec elle. La fermeture d'un combat durable **projette** avec elle
 * la meme salve sur la photographie admissible — l'effectif d'ouverture plus les seuls effets
 * admissibles, dans l'ordre des effets —, parce que le missile n'est pas lineaire : il detruit dans un
 * tas qui contient aussi les defenses inadmissibles, et par priorite. Le delta que le monde a subi ne
 * dit pas ce que la photographie aurait perdu ; seule la formule rejouee sur la photographie le dit.
 * Deux implementations divergeraient au premier reglage change ; il n'y en a qu'une.
 *
 * ## La formule
 *
 * - puissance = missiles effectifs × 12 000 × (1 + 0,1 × technologie d'armes de l'attaquant) ;
 * - armure d'une defense = integrite structurelle × (1 + 0,1 × technologie de blindage) / 10 ;
 * - dans l'ordre de priorite, chaque type perd `min(floor(puissance / armure), presentes)`, et la
 *   puissance decroit d'autant ; les missiles eux-memes ne sont jamais vises.
 *
 * Les interceptions se decident avant : `intercepted()` rend ce que les antimissiles disponibles
 * arretent, et le reste frappe.
 */
final class MissileStrikeProjection
{
    public const int POWER_PER_MISSILE = 12000;

    /**
     * Combien de missiles les antimissiles disponibles arretent.
     */
    public static function intercepted(int $missiles, int $interceptorsAvailable): int
    {
        return max(0, min($missiles, $interceptorsAvailable));
    }

    /**
     * Les defenses detruites par une salve, type par type, dans l'ordre de priorite.
     *
     * @param array<string, int> $defences Ce que le corps (ou la photographie) porte : nom de machine => nombre.
     * @return array<string, int> Nom de machine => nombre detruit ; seules les entrees non nulles.
     */
    public static function destroyedOn(array $defences, int $effectiveMissiles, int $weaponTech, int $armorTech, int $priorityCode): array
    {
        if ($effectiveMissiles <= 0) {
            return [];
        }

        $power = $effectiveMissiles * self::POWER_PER_MISSILE * (1 + 0.1 * $weaponTech);
        $destroyed = [];

        foreach (self::orderedDefenceObjects(self::decodePriority($priorityCode)) as $defence) {
            if ($power <= 0) {
                break;
            }
            if (in_array($defence->machine_name, ['interplanetary_missile', 'anti_ballistic_missile'], true)) {
                continue;
            }

            $present = (int)($defences[$defence->machine_name] ?? 0);
            if ($present <= 0) {
                continue;
            }

            $armor = $defence->properties->structural_integrity->rawValue * (1 + 0.1 * $armorTech) / 10;
            $lost = min((int)floor($power / $armor), $present);
            if ($lost > 0) {
                $destroyed[$defence->machine_name] = $lost;
                $power -= $lost * $armor;
            }
        }

        return $destroyed;
    }

    public static function decodePriority(int $code): string
    {
        $priorities = [
            0 => 'cheapest',
            1 => 'expensive',
            2 => 'rocket_launcher',
            3 => 'light_laser',
            4 => 'heavy_laser',
            5 => 'gauss_cannon',
            6 => 'ion_cannon',
            7 => 'plasma_turret',
            8 => 'small_shield_dome',
            9 => 'large_shield_dome',
        ];

        return $priorities[$code] ?? 'cheapest';
    }

    /**
     * Les objets de defense dans l'ordre ou la salve les vise. Le prix compte metal et cristal,
     * jamais le deuterium.
     *
     * @return array<int, DefenseObject>
     */
    public static function orderedDefenceObjects(string $priority): array
    {
        $objects = ObjectService::getDefenseObjects();
        $price = static fn (DefenseObject $d): float => $d->price->resources->metal->get() + $d->price->resources->crystal->get();

        if ($priority === 'cheapest') {
            usort($objects, static fn (DefenseObject $a, DefenseObject $b): int => $price($a) <=> $price($b));
        } elseif ($priority === 'expensive') {
            usort($objects, static fn (DefenseObject $a, DefenseObject $b): int => $price($b) <=> $price($a));
        } else {
            usort($objects, static function (DefenseObject $a, DefenseObject $b) use ($priority, $price): int {
                $aFirst = $a->machine_name === $priority;
                $bFirst = $b->machine_name === $priority;
                if ($aFirst !== $bFirst) {
                    return $aFirst ? -1 : 1;
                }

                return $price($a) <=> $price($b);
            });
        }

        return $objects;
    }
}
