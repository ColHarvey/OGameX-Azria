<?php

namespace OGame\GameMissions\BattleEngine\Parity;

use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;

/**
 * Ce qu'une bataille a produit, sous une forme que deux moteurs doivent rendre a l'identique.
 *
 * ## Pourquoi une projection, et non le resultat lui-meme
 *
 * Deux `BattleResult` ne se comparent pas en bloc : l'ordre des flottes, celui des unites dans une
 * collection, un flottant `36000.0` contre un entier `36000`, sont des differences de forme, pas de
 * bataille. La projection range tout — participants par clef, unites par nom, rounds par rang — et
 * ne garde que ce qui decide d'un transfert ou d'un recit : survivants et pertes par participant et
 * par periode, capacites survivantes, taux et versions de pillage, butin et parts, debris, lune.
 *
 * ## Le premier chemin divergent
 *
 * `firstDivergence()` ne dit pas « different » : il nomme le premier chemin ou les deux projections
 * ne s'accordent pas, avec les deux valeurs. Un `assertEquals` sur deux gros tableaux ne le dirait
 * pas, et c'est ce chemin-la qu'on lit quand un banc rougit.
 */
final class CanonicalProjection
{
    /**
     * @return array<string, mixed>
     */
    public static function of(BattleResult $result): array
    {
        $rounds = [];

        foreach ($result->rounds as $rang => $round) {
            $parParticipant = [];

            foreach ($round->lossesInRoundByParticipant as $participant => $pertes) {
                $parParticipant[$participant] = self::units($pertes);
            }

            ksort($parParticipant);

            $rounds[$rang + 1] = [
                'attacker_losses_in_round' => self::units($round->attackerLossesInRound),
                'defender_losses_in_round' => self::units($round->defenderLossesInRound),
                'losses_in_round_by_participant' => $parParticipant,
                'attacker_ships' => self::units($round->attackerShips),
                'defender_ships' => self::units($round->defenderShips),
                'hits_attacker' => $round->hitsAttacker,
                'hits_defender' => $round->hitsDefender,
                'absorbed_damage_attacker' => $round->absorbedDamageAttacker,
                'absorbed_damage_defender' => $round->absorbedDamageDefender,
                'full_strength_attacker' => $round->fullStrengthAttacker,
                'full_strength_defender' => $round->fullStrengthDefender,
            ];
        }

        $attaquantes = [];

        foreach ($result->attackerFleetResults as $flotte) {
            $attaquantes[$flotte->fleetMissionId] = [
                'units_result' => self::units($flotte->unitsResult),
                'units_lost' => self::units($flotte->unitsLost),
                'loot_share' => self::resources($flotte->lootShare),
                'surviving_cargo' => self::resources($flotte->survivingCargo),
                'starting_cargo_capacity' => $flotte->startingCargoCapacity,
                'surviving_cargo_capacity' => $flotte->survivingCargoCapacity,
                'completely_destroyed' => $flotte->completelyDestroyed,
            ];
        }

        ksort($attaquantes);

        $defensives = [];

        foreach ($result->defenderFleetResults as $flotte) {
            $defensives[$flotte->fleetMissionId] = [
                'units_result' => self::units($flotte->unitsResult),
                'units_lost' => self::units($flotte->unitsLost),
                'starting_cargo_capacity' => $flotte->startingCargoCapacity,
                'surviving_cargo_capacity' => $flotte->survivingCargoCapacity,
                'completely_destroyed' => $flotte->completelyDestroyed,
            ];
        }

        ksort($defensives);

        return [
            'rounds' => $rounds,
            'attacker_units_result' => self::units($result->attackerUnitsResult),
            'defender_units_result' => self::units($result->defenderUnitsResult),
            'attacker_units_lost' => self::units($result->attackerUnitsLost),
            'defender_units_lost' => self::units($result->defenderUnitsLost),
            'attacker_fleets' => $attaquantes,
            'defender_fleets' => $defensives,
            'attacker_surviving_cargo_capacity' => $result->attackerSurvivingCargoCapacity,
            'attacker_reaper_cargo_capacity' => $result->attackerReaperCargoCapacity,
            'defender_reaper_cargo_capacity' => $result->defenderReaperCargoCapacity,
            'loot' => self::resources($result->loot),
            'loot_rate_in_basis_points' => $result->lootRateInBasisPoints,
            'loot_policy_version' => $result->lootPolicyVersion,
            'loot_allocator_version' => $result->lootAllocatorVersion,
            'debris' => self::resources($result->debris),
            'moon_chance' => $result->moonChance,
            'repaired_defenses' => self::units($result->repairedDefenses),
        ];
    }

    /**
     * Le premier chemin ou deux projections different, avec les deux valeurs — ou null.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function firstDivergence(array $a, array $b, string $chemin = ''): string|null
    {
        $clefs = array_unique(array_merge(array_keys($a), array_keys($b)));
        sort($clefs);

        foreach ($clefs as $clef) {
            $ici = $chemin === '' ? (string)$clef : $chemin . '.' . $clef;

            if (!array_key_exists($clef, $a) || !array_key_exists($clef, $b)) {
                return $ici . ' : present d un seul cote (' . (array_key_exists($clef, $a) ? 'PHP' : 'Rust') . ')';
            }

            if (is_array($a[$clef]) && is_array($b[$clef])) {
                $divergence = self::firstDivergence($a[$clef], $b[$clef], $ici);

                if ($divergence !== null) {
                    return $divergence;
                }

                continue;
            }

            if ($a[$clef] !== $b[$clef]) {
                return $ici . ' : PHP ' . var_export($a[$clef], true) . ' / Rust ' . var_export($b[$clef], true);
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private static function units(UnitCollection $unites): array
    {
        $parNom = [];

        foreach ($unites->units as $entree) {
            if ($entree->amount > 0) {
                $parNom[$entree->unitObject->machine_name] = $entree->amount;
            }
        }

        ksort($parNom);

        return $parNom;
    }

    /**
     * @return array<string, int>
     */
    private static function resources(Resources $ressources): array
    {
        return [
            'metal' => (int)$ressources->metal->get(),
            'crystal' => (int)$ressources->crystal->get(),
            'deuterium' => (int)$ressources->deuterium->get(),
        ];
    }
}
