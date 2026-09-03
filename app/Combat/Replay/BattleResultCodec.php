<?php

namespace OGame\Combat\Replay;

use OGame\Combat\Exceptions\CorruptedBattleResult;
use OGame\Combat\Support\ResourceDiagnostic;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use RuntimeException;

/**
 * Le resultat d'une bataille, ecrit pour etre rejoue et relu tel qu'il a ete ecrit.
 *
 * ## Pourquoi le resultat se persiste
 *
 * Le moteur tire au sort — le tir rapide, l'ordre des cibles. Recalculer la bataille a l'echeance
 * ne redonnerait pas le meme resultat, et deux rejeux du meme combat divergeraient. Le resultat
 * est donc calcule une fois, a la cloture du ralliement, ecrit dans `combat_instances.battle_result`,
 * et c'est ce document que le reglement relit des heures plus tard. La duree du combat, le rapport
 * et le butin regle en decoulent tous : ils ne peuvent pas se contredire.
 *
 * ## Une porte de relecture, et ses refus
 *
 * Le document est relu **strictement** : un champ manquant, un champ inconnu, un entier devenu
 * chaine, une unite dont le nom n'existe plus, une clef de flotte qui n'est pas un identifiant —
 * chacun leve `CorruptedBattleResult` en nommant l'endroit. Aucun cast : un resultat relu autrement
 * qu'ecrit reglerait une autre bataille.
 *
 * ## Ce qui traverse JSON, et comment
 *
 * Les ressources sont des flottants — le moteur travaille ainsi — et JSON les rend a l'identique.
 * Les tableaux par flotte sont indexes par identifiant de mission : JSON en fait des clefs de
 * structure, et PHP les redonne en entiers au decodage ; une clef qui ne revient pas entiere est
 * refusee. `schema` numerote la forme du document : une forme future se relit par sa propre version,
 * jamais en devinant.
 */
final class BattleResultCodec
{
    public const int SCHEMA = 1;

    private const array KEYS = [
        'schema',
        'loot',
        'debris',
        'wreck_field',
        'moon_existed',
        'moon_chance',
        'moon_created',
        'loot_percentage',
        'loot_rate_in_basis_points',
        'loot_policy_version',
        'loot_allocator_version',
        'loot_frozen_facts',
        'loot_snapshot_fingerprint',
        'resource_diagnostics',
        'attacker_units_start',
        'attacker_units_result',
        'attacker_units_lost',
        'attacker_resource_loss',
        'defender_units_start',
        'defender_units_result',
        'defender_units_lost',
        'defender_resource_loss',
        'defender_fleet_results',
        'attacker_fleet_results',
        'attacker_weapon_level',
        'attacker_shield_level',
        'attacker_armor_level',
        'defender_weapon_level',
        'defender_shield_level',
        'defender_armor_level',
        'rounds',
        'repaired_defenses',
        'attacker_planet_id',
        'hamill_manoeuvre_triggered',
        'tactical_retreat_ratio',
        'tactical_retreat_attacker_points',
        'tactical_retreat_defender_points',
        'tactical_retreat_defender_fled',
        'tactical_retreat_attacker_also_retreated',
        'tactical_retreat_deuterium_cost',
        'tactical_retreat_fleeing_units',
    ];

    private const array ATTACKER_FLEET_KEYS = [
        'fleet_mission_id',
        'player_id',
        'units_start',
        'units_result',
        'units_lost',
        'resource_loss',
        'loot_share',
        'surviving_cargo',
        'surviving_cargo_capacity',
        'completely_destroyed',
    ];

    private const array DEFENDER_FLEET_KEYS = [
        'fleet_mission_id',
        'owner_id',
        'units_start',
        'units_result',
        'units_lost',
        'completely_destroyed',
    ];

    private const array ROUND_KEYS = [
        'attacker_losses',
        'attacker_losses_in_round',
        'attacker_losses_per_fleet',
        'attacker_losses_in_round_per_fleet',
        'attacker_ships_per_fleet',
        'hits_per_attacker_fleet',
        'damage_per_attacker_fleet',
        'defender_losses',
        'defender_losses_in_round',
        'hits_attacker',
        'hits_defender',
        'absorbed_damage_attacker',
        'absorbed_damage_defender',
        'full_strength_attacker',
        'full_strength_defender',
        'attacker_ships',
        'defender_ships',
    ];

    private const array RESOURCE_KEYS = ['metal', 'crystal', 'deuterium', 'energy'];

    private const array DIAGNOSTIC_KEYS = ['code', 'phase', 'subject', 'resource', 'units'];

    /**
     * Le document a ecrire, dans l'ordre des clefs, pret pour la colonne JSON.
     *
     * @return array<string, mixed>
     */
    public static function toStorage(BattleResult $result): array
    {
        $flottesAttaquantes = [];
        foreach ($result->attackerFleetResults as $flotte) {
            $flottesAttaquantes[] = [
                'fleet_mission_id' => $flotte->fleetMissionId,
                'player_id' => $flotte->playerId,
                'units_start' => $flotte->unitsStart->toArray(),
                'units_result' => $flotte->unitsResult->toArray(),
                'units_lost' => $flotte->unitsLost->toArray(),
                'resource_loss' => self::resourcesToStorage($flotte->resourceLoss),
                'loot_share' => self::resourcesToStorage($flotte->lootShare),
                'surviving_cargo' => self::resourcesToStorage($flotte->survivingCargo),
                'surviving_cargo_capacity' => $flotte->survivingCargoCapacity,
                'completely_destroyed' => $flotte->completelyDestroyed,
            ];
        }

        $flottesDefensives = [];
        foreach ($result->defenderFleetResults as $flotte) {
            $flottesDefensives[] = [
                'fleet_mission_id' => $flotte->fleetMissionId,
                'owner_id' => $flotte->ownerId,
                'units_start' => $flotte->unitsStart->toArray(),
                'units_result' => $flotte->unitsResult->toArray(),
                'units_lost' => $flotte->unitsLost->toArray(),
                'completely_destroyed' => $flotte->completelyDestroyed,
            ];
        }

        $rounds = [];
        foreach ($result->rounds as $round) {
            $rounds[] = [
                'attacker_losses' => $round->attackerLosses->toArray(),
                'attacker_losses_in_round' => $round->attackerLossesInRound->toArray(),
                'attacker_losses_per_fleet' => self::unitsByFleetToStorage($round->attackerLossesPerFleet),
                'attacker_losses_in_round_per_fleet' => self::unitsByFleetToStorage($round->attackerLossesInRoundPerFleet),
                'attacker_ships_per_fleet' => self::unitsByFleetToStorage($round->attackerShipsPerFleet),
                'hits_per_attacker_fleet' => $round->hitsPerAttackerFleet,
                'damage_per_attacker_fleet' => $round->damagePerAttackerFleet,
                'defender_losses' => $round->defenderLosses->toArray(),
                'defender_losses_in_round' => $round->defenderLossesInRound->toArray(),
                'hits_attacker' => $round->hitsAttacker,
                'hits_defender' => $round->hitsDefender,
                'absorbed_damage_attacker' => $round->absorbedDamageAttacker,
                'absorbed_damage_defender' => $round->absorbedDamageDefender,
                'full_strength_attacker' => $round->fullStrengthAttacker,
                'full_strength_defender' => $round->fullStrengthDefender,
                'attacker_ships' => $round->attackerShips->toArray(),
                'defender_ships' => $round->defenderShips->toArray(),
            ];
        }

        $diagnostics = [];
        foreach ($result->resourceDiagnostics->occurrences as $diagnostic) {
            $diagnostics[] = [
                'code' => $diagnostic->code,
                'phase' => $diagnostic->phase,
                'subject' => $diagnostic->subject,
                'resource' => $diagnostic->resource,
                'units' => $diagnostic->units,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'loot' => self::resourcesToStorage($result->loot),
            'debris' => self::resourcesToStorage($result->debris),
            'wreck_field' => $result->wreckField,
            'moon_existed' => $result->moonExisted,
            'moon_chance' => $result->moonChance,
            'moon_created' => $result->moonCreated,
            'loot_percentage' => $result->lootPercentage,
            'loot_rate_in_basis_points' => $result->lootRateInBasisPoints,
            'loot_policy_version' => $result->lootPolicyVersion,
            'loot_allocator_version' => $result->lootAllocatorVersion,
            'loot_frozen_facts' => $result->lootFrozenFacts,
            'loot_snapshot_fingerprint' => $result->lootSnapshotFingerprint,
            'resource_diagnostics' => $diagnostics,
            'attacker_units_start' => $result->attackerUnitsStart->toArray(),
            'attacker_units_result' => $result->attackerUnitsResult->toArray(),
            'attacker_units_lost' => $result->attackerUnitsLost->toArray(),
            'attacker_resource_loss' => self::resourcesToStorage($result->attackerResourceLoss),
            'defender_units_start' => $result->defenderUnitsStart->toArray(),
            'defender_units_result' => $result->defenderUnitsResult->toArray(),
            'defender_units_lost' => $result->defenderUnitsLost->toArray(),
            'defender_resource_loss' => self::resourcesToStorage($result->defenderResourceLoss),
            'defender_fleet_results' => $flottesDefensives,
            'attacker_fleet_results' => $flottesAttaquantes,
            'attacker_weapon_level' => $result->attackerWeaponLevel,
            'attacker_shield_level' => $result->attackerShieldLevel,
            'attacker_armor_level' => $result->attackerArmorLevel,
            'defender_weapon_level' => $result->defenderWeaponLevel,
            'defender_shield_level' => $result->defenderShieldLevel,
            'defender_armor_level' => $result->defenderArmorLevel,
            'rounds' => $rounds,
            'repaired_defenses' => $result->repairedDefenses->toArray(),
            'attacker_planet_id' => $result->attackerPlanetId,
            'hamill_manoeuvre_triggered' => $result->hamillManoeuvreTriggered,
            'tactical_retreat_ratio' => $result->tacticalRetreatRatio,
            'tactical_retreat_attacker_points' => $result->tacticalRetreatAttackerPoints,
            'tactical_retreat_defender_points' => $result->tacticalRetreatDefenderPoints,
            'tactical_retreat_defender_fled' => $result->tacticalRetreatDefenderFled,
            'tactical_retreat_attacker_also_retreated' => $result->tacticalRetreatAttackerAlsoRetreated,
            'tactical_retreat_deuterium_cost' => $result->tacticalRetreatDeuteriumCost,
            'tactical_retreat_fleeing_units' => $result->tacticalRetreatFleeingUnits?->toArray(),
        ];
    }

    /**
     * Le resultat relu, exactement comme il a ete ecrit — ou un refus qui nomme le champ.
     */
    public static function fromStorage(mixed $stored): BattleResult
    {
        if (!is_array($stored)) {
            throw new CorruptedBattleResult('le document est un ' . get_debug_type($stored) . ' et non une structure', $stored);
        }

        self::shape($stored, self::KEYS, 'resultat');

        $schema = self::int($stored, 'schema', 'resultat');

        if ($schema !== self::SCHEMA) {
            throw new CorruptedBattleResult('le schema ' . $schema . ' est inconnu, seul le schema ' . self::SCHEMA . ' se relit', $stored);
        }

        $result = new BattleResult();
        $result->loot = self::resources($stored, 'loot', 'resultat');
        $result->debris = self::resources($stored, 'debris', 'resultat');
        $result->wreckField = self::array($stored, 'wreck_field', 'resultat');
        $result->moonExisted = self::bool($stored, 'moon_existed', 'resultat');
        $result->moonChance = self::int($stored, 'moon_chance', 'resultat');
        $result->moonCreated = self::bool($stored, 'moon_created', 'resultat');
        $result->lootPercentage = self::int($stored, 'loot_percentage', 'resultat');
        $result->lootRateInBasisPoints = self::int($stored, 'loot_rate_in_basis_points', 'resultat');
        $result->lootPolicyVersion = self::string($stored, 'loot_policy_version', 'resultat');
        $result->lootAllocatorVersion = self::string($stored, 'loot_allocator_version', 'resultat');
        $result->lootFrozenFacts = self::array($stored, 'loot_frozen_facts', 'resultat');
        $result->lootSnapshotFingerprint = self::string($stored, 'loot_snapshot_fingerprint', 'resultat');
        $result->resourceDiagnostics = self::diagnostics($stored, 'resource_diagnostics', 'resultat');
        $result->attackerUnitsStart = self::units($stored, 'attacker_units_start', 'resultat');
        $result->attackerUnitsResult = self::units($stored, 'attacker_units_result', 'resultat');
        $result->attackerUnitsLost = self::units($stored, 'attacker_units_lost', 'resultat');
        $result->attackerResourceLoss = self::resources($stored, 'attacker_resource_loss', 'resultat');
        $result->defenderUnitsStart = self::units($stored, 'defender_units_start', 'resultat');
        $result->defenderUnitsResult = self::units($stored, 'defender_units_result', 'resultat');
        $result->defenderUnitsLost = self::units($stored, 'defender_units_lost', 'resultat');
        $result->defenderResourceLoss = self::resources($stored, 'defender_resource_loss', 'resultat');

        $result->defenderFleetResults = [];
        foreach (self::list($stored, 'defender_fleet_results', 'resultat') as $rang => $document) {
            $result->defenderFleetResults[] = self::defenderFleet(self::structure($document, 'resultat.defender_fleet_results[' . $rang . ']'), 'resultat.defender_fleet_results[' . $rang . ']');
        }

        $result->attackerFleetResults = [];
        foreach (self::list($stored, 'attacker_fleet_results', 'resultat') as $rang => $document) {
            $result->attackerFleetResults[] = self::attackerFleet(self::structure($document, 'resultat.attacker_fleet_results[' . $rang . ']'), 'resultat.attacker_fleet_results[' . $rang . ']');
        }

        $result->attackerWeaponLevel = self::int($stored, 'attacker_weapon_level', 'resultat');
        $result->attackerShieldLevel = self::int($stored, 'attacker_shield_level', 'resultat');
        $result->attackerArmorLevel = self::int($stored, 'attacker_armor_level', 'resultat');
        $result->defenderWeaponLevel = self::int($stored, 'defender_weapon_level', 'resultat');
        $result->defenderShieldLevel = self::int($stored, 'defender_shield_level', 'resultat');
        $result->defenderArmorLevel = self::int($stored, 'defender_armor_level', 'resultat');

        $result->rounds = [];
        foreach (self::list($stored, 'rounds', 'resultat') as $rang => $document) {
            $result->rounds[] = self::round(self::structure($document, 'resultat.rounds[' . $rang . ']'), 'resultat.rounds[' . $rang . ']');
        }

        $result->repairedDefenses = self::units($stored, 'repaired_defenses', 'resultat');
        $result->attackerPlanetId = self::int($stored, 'attacker_planet_id', 'resultat');
        $result->hamillManoeuvreTriggered = self::bool($stored, 'hamill_manoeuvre_triggered', 'resultat');
        $result->tacticalRetreatRatio = self::int($stored, 'tactical_retreat_ratio', 'resultat');
        $result->tacticalRetreatAttackerPoints = self::int($stored, 'tactical_retreat_attacker_points', 'resultat');
        $result->tacticalRetreatDefenderPoints = self::int($stored, 'tactical_retreat_defender_points', 'resultat');
        $result->tacticalRetreatDefenderFled = self::bool($stored, 'tactical_retreat_defender_fled', 'resultat');
        $result->tacticalRetreatAttackerAlsoRetreated = self::bool($stored, 'tactical_retreat_attacker_also_retreated', 'resultat');
        $result->tacticalRetreatDeuteriumCost = self::int($stored, 'tactical_retreat_deuterium_cost', 'resultat');
        $result->tacticalRetreatFleeingUnits = $stored['tactical_retreat_fleeing_units'] === null
            ? null
            : self::units($stored, 'tactical_retreat_fleeing_units', 'resultat');

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function attackerFleet(array $document, string $path): AttackerFleetResult
    {
        self::shape($document, self::ATTACKER_FLEET_KEYS, $path);

        $flotte = new AttackerFleetResult(
            self::int($document, 'fleet_mission_id', $path),
            self::int($document, 'player_id', $path),
            self::units($document, 'units_start', $path)
        );
        $flotte->unitsResult = self::units($document, 'units_result', $path);
        $flotte->unitsLost = self::units($document, 'units_lost', $path);
        $flotte->resourceLoss = self::resources($document, 'resource_loss', $path);
        $flotte->lootShare = self::resources($document, 'loot_share', $path);
        $flotte->survivingCargo = self::resources($document, 'surviving_cargo', $path);
        $flotte->survivingCargoCapacity = self::int($document, 'surviving_cargo_capacity', $path);
        $flotte->completelyDestroyed = self::bool($document, 'completely_destroyed', $path);

        return $flotte;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function defenderFleet(array $document, string $path): DefenderFleetResult
    {
        self::shape($document, self::DEFENDER_FLEET_KEYS, $path);

        $flotte = new DefenderFleetResult(
            self::int($document, 'fleet_mission_id', $path),
            self::int($document, 'owner_id', $path),
            self::units($document, 'units_start', $path)
        );
        $flotte->unitsResult = self::units($document, 'units_result', $path);
        $flotte->unitsLost = self::units($document, 'units_lost', $path);
        $flotte->completelyDestroyed = self::bool($document, 'completely_destroyed', $path);

        return $flotte;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function round(array $document, string $path): BattleResultRound
    {
        self::shape($document, self::ROUND_KEYS, $path);

        $round = new BattleResultRound();
        $round->attackerLosses = self::units($document, 'attacker_losses', $path);
        $round->attackerLossesInRound = self::units($document, 'attacker_losses_in_round', $path);
        $round->attackerLossesPerFleet = self::unitsByFleet($document, 'attacker_losses_per_fleet', $path);
        $round->attackerLossesInRoundPerFleet = self::unitsByFleet($document, 'attacker_losses_in_round_per_fleet', $path);
        $round->attackerShipsPerFleet = self::unitsByFleet($document, 'attacker_ships_per_fleet', $path);
        $round->hitsPerAttackerFleet = self::intByFleet($document, 'hits_per_attacker_fleet', $path);
        $round->damagePerAttackerFleet = self::intByFleet($document, 'damage_per_attacker_fleet', $path);
        $round->defenderLosses = self::units($document, 'defender_losses', $path);
        $round->defenderLossesInRound = self::units($document, 'defender_losses_in_round', $path);
        $round->hitsAttacker = self::int($document, 'hits_attacker', $path);
        $round->hitsDefender = self::int($document, 'hits_defender', $path);
        $round->absorbedDamageAttacker = self::int($document, 'absorbed_damage_attacker', $path);
        $round->absorbedDamageDefender = self::int($document, 'absorbed_damage_defender', $path);
        $round->fullStrengthAttacker = self::int($document, 'full_strength_attacker', $path);
        $round->fullStrengthDefender = self::int($document, 'full_strength_defender', $path);
        $round->attackerShips = self::units($document, 'attacker_ships', $path);
        $round->defenderShips = self::units($document, 'defender_ships', $path);

        return $round;
    }

    /**
     * @return array<string, float>
     */
    private static function resourcesToStorage(Resources $resources): array
    {
        return [
            'metal' => $resources->metal->get(),
            'crystal' => $resources->crystal->get(),
            'deuterium' => $resources->deuterium->get(),
            'energy' => $resources->energy->get(),
        ];
    }

    /**
     * @param array<int, UnitCollection> $parFlotte
     * @return array<int, array<string, int>>
     */
    private static function unitsByFleetToStorage(array $parFlotte): array
    {
        $document = [];
        foreach ($parFlotte as $flotte => $unites) {
            $document[$flotte] = $unites->toArray();
        }

        return $document;
    }

    /**
     * Refuse les clefs inconnues ; les clefs manquantes sont refusees par les lecteurs, qui les nomment.
     *
     * @param array<mixed, mixed> $document
     * @param array<int, string> $keys
     */
    private static function shape(array $document, array $keys, string $path): void
    {
        $inconnues = array_diff(array_keys($document), $keys);

        if ($inconnues !== []) {
            throw new CorruptedBattleResult(
                '« ' . $path . ' » porte des clefs inconnues (' . implode(', ', array_map('strval', $inconnues)) . ')',
                $document
            );
        }
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function present(array $document, string $field, string $path): mixed
    {
        if (!array_key_exists($field, $document)) {
            throw new CorruptedBattleResult('le champ « ' . $path . '.' . $field . ' » manque', $document);
        }

        return $document[$field];
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function int(array $document, string $field, string $path): int
    {
        $valeur = self::present($document, $field, $path);

        if (!is_int($valeur)) {
            throw new CorruptedBattleResult('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un entier', $document);
        }

        return $valeur;
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function bool(array $document, string $field, string $path): bool
    {
        $valeur = self::present($document, $field, $path);

        if (!is_bool($valeur)) {
            throw new CorruptedBattleResult('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un booleen', $document);
        }

        return $valeur;
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function string(array $document, string $field, string $path): string
    {
        $valeur = self::present($document, $field, $path);

        if (!is_string($valeur)) {
            throw new CorruptedBattleResult('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un texte', $document);
        }

        return $valeur;
    }

    /**
     * Un nombre — entier ou flottant, jamais une chaine. Un montant ecrit `50000` revient entier
     * du decodeur JSON, pas `50000.0` : exiger un flottant refuserait un fait exact.
     *
     * @param array<mixed, mixed> $document
     */
    private static function number(array $document, string $field, string $path): float
    {
        $valeur = self::present($document, $field, $path);

        if (!is_int($valeur) && !is_float($valeur)) {
            throw new CorruptedBattleResult('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un nombre', $document);
        }

        return (float)$valeur;
    }

    /**
     * @param array<mixed, mixed> $document
     * @return array<mixed, mixed>
     */
    private static function array(array $document, string $field, string $path): array
    {
        $valeur = self::present($document, $field, $path);

        if (!is_array($valeur)) {
            throw new CorruptedBattleResult('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non une structure', $document);
        }

        return $valeur;
    }

    /**
     * @param array<mixed, mixed> $document
     * @return array<int, mixed>
     */
    private static function list(array $document, string $field, string $path): array
    {
        $valeur = self::array($document, $field, $path);

        if (!array_is_list($valeur)) {
            throw new CorruptedBattleResult('le champ « ' . $path . '.' . $field . ' » n est pas une liste', $document);
        }

        return $valeur;
    }

    /**
     * Un element de liste qui doit etre une structure.
     *
     * @return array<mixed, mixed>
     */
    private static function structure(mixed $valeur, string $path): array
    {
        if (!is_array($valeur)) {
            throw new CorruptedBattleResult('« ' . $path . ' » est un ' . get_debug_type($valeur) . ' et non une structure', $valeur);
        }

        return $valeur;
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function resources(array $document, string $field, string $path): Resources
    {
        $structure = self::array($document, $field, $path);
        self::shape($structure, self::RESOURCE_KEYS, $path . '.' . $field);

        return new Resources(
            self::number($structure, 'metal', $path . '.' . $field),
            self::number($structure, 'crystal', $path . '.' . $field),
            self::number($structure, 'deuterium', $path . '.' . $field),
            self::number($structure, 'energy', $path . '.' . $field),
        );
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function units(array $document, string $field, string $path): UnitCollection
    {
        return self::unitsFrom(self::array($document, $field, $path), $path . '.' . $field);
    }

    /**
     * @param array<mixed, mixed> $entrees
     */
    private static function unitsFrom(array $entrees, string $path): UnitCollection
    {
        $collection = new UnitCollection();

        foreach ($entrees as $machine => $montant) {
            if (!is_string($machine)) {
                throw new CorruptedBattleResult('« ' . $path . ' » porte une unite sans nom (' . get_debug_type($machine) . ')', $entrees);
            }

            if (!is_int($montant) || $montant < 0) {
                throw new CorruptedBattleResult('« ' . $path . '.' . $machine . ' » est ' . get_debug_type($montant) . ' et non un entier positif ou nul', $entrees);
            }

            try {
                $objet = ObjectService::getUnitObjectByMachineName($machine);
            } catch (RuntimeException) {
                throw new CorruptedBattleResult('« ' . $path . ' » nomme une unite inconnue : ' . $machine, $entrees);
            }

            $collection->addUnit($objet, $montant);
        }

        return $collection;
    }

    /**
     * @param array<mixed, mixed> $document
     * @return array<int, UnitCollection>
     */
    private static function unitsByFleet(array $document, string $field, string $path): array
    {
        $parFlotte = [];

        foreach (self::array($document, $field, $path) as $flotte => $unites) {
            if (!is_int($flotte)) {
                throw new CorruptedBattleResult('« ' . $path . '.' . $field . ' » porte une clef de flotte qui n est pas un identifiant (' . get_debug_type($flotte) . ')', $document);
            }

            $parFlotte[$flotte] = self::unitsFrom(self::structure($unites, $path . '.' . $field . '[' . $flotte . ']'), $path . '.' . $field . '[' . $flotte . ']');
        }

        return $parFlotte;
    }

    /**
     * @param array<mixed, mixed> $document
     * @return array<int, int>
     */
    private static function intByFleet(array $document, string $field, string $path): array
    {
        $parFlotte = [];

        foreach (self::array($document, $field, $path) as $flotte => $valeur) {
            if (!is_int($flotte)) {
                throw new CorruptedBattleResult('« ' . $path . '.' . $field . ' » porte une clef de flotte qui n est pas un identifiant (' . get_debug_type($flotte) . ')', $document);
            }

            if (!is_int($valeur)) {
                throw new CorruptedBattleResult('« ' . $path . '.' . $field . '[' . $flotte . '] » est un ' . get_debug_type($valeur) . ' et non un entier', $document);
            }

            $parFlotte[$flotte] = $valeur;
        }

        return $parFlotte;
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function diagnostics(array $document, string $field, string $path): ResourceNormalizationDiagnostics
    {
        $diagnostics = ResourceNormalizationDiagnostics::none();

        foreach (self::list($document, $field, $path) as $rang => $entree) {
            $chemin = $path . '.' . $field . '[' . $rang . ']';
            $structure = self::structure($entree, $chemin);
            self::shape($structure, self::DIAGNOSTIC_KEYS, $chemin);

            $diagnostics = $diagnostics->mergedWith(ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
                self::string($structure, 'code', $chemin),
                self::string($structure, 'phase', $chemin),
                self::string($structure, 'subject', $chemin),
                self::string($structure, 'resource', $chemin),
                self::int($structure, 'units', $chemin),
            )));
        }

        return $diagnostics;
    }
}
