<?php

namespace OGame\GameMissions\BattleEngine;

use FFI;
use OGame\Combat\Exceptions\RustEngineContractMismatch;
use OGame\Combat\Support\LootContext;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;
use stdClass;

/**
 * Class RustBattleEngine.
 *
 * This class is responsible for handling the battle logic in the game, used primarily
 * by the AttackMission class. This is the Rust version of the BattleEngine which calls
 * the Rust battle engine library for improved memory usage and performance.
 *
 * @package OGame\GameMissions\BattleEngine
 */
class RustBattleEngine extends BattleEngine
{
    /**
     * La version du contrat FFI que ce client parle.
     *
     * Elle est ecrite dans l'entree, exigee dans la sortie, et lue sur la bibliotheque avant le
     * premier combat. La version 2 porte, dans chaque round, les pertes du round par flotte des
     * deux camps et les coups et degats par flotte attaquante — sans elles, le moteur partage
     * refuse le round, et une bibliotheque plus ancienne ne produirait aucune bataille.
     */
    public const int ABI_VERSION = 2;

    /**
     * @var FFI The FFI instance used to call the Rust battle engine.
     */
    private FFI $ffi;

    /**
     * RustBattleEngine constructor.
     *
     * @param array<AttackerFleet> $attackers All attacking fleets.
     * @param PlanetService $defenderPlanet The planet of the defender player (used for loot, moon calculation).
     * @param array<DefenderFleet> $defenders All defending fleets (planet owner + ACS defend fleets).
     * @param SettingsService $settings The settings service.
     * @param LootContext $lootContext Les faits de pillage, deja photographies.
     */
    public function __construct(array $attackers, PlanetService $defenderPlanet, array $defenders, SettingsService $settings, LootContext $lootContext)
    {
        parent::__construct($attackers, $defenderPlanet, $defenders, $settings, $lootContext);

        // **Les trois symboles du contrat, declares ensemble.** FFI resout chaque symbole au
        // chargement : une bibliotheque qui n'exporte pas la version ou la liberation est une
        // bibliotheque d'avant le contrat, et elle echoue ici — pas au milieu d'une bataille.
        $this->ffi = FFI::cdef(
            "char* fight_battle_rounds(const char* input_json);\n"
            . "unsigned int battle_engine_abi_version(void);\n"
            . "void free_battle_output(char* output);",
            base_path('storage/rust-libs/libbattle_engine_ffi.so')
        );

        // @phpstan-ignore-next-line
        $version = (int)$this->ffi->battle_engine_abi_version();

        if ($version !== self::ABI_VERSION) {
            throw RustEngineContractMismatch::becauseTheLibrarySpeaks($version, self::ABI_VERSION);
        }
    }

    /**
     * Fight the battle in max 6 rounds.
     *
     * @param BattleResult $result
     * @return array<BattleResultRound>
     */
    protected function fightBattleRounds(BattleResult $result): array
    {
        // Hamill Manoeuvre: General class Light Fighters have a chance to destroy one Deathstar before battle
        $this->checkHamillManoeuvre($result);

        // Convert PHP battle units to format expected by Rust
        $input = $this->prepareBattleInput($result);

        // Convert to JSON. Une entree qui ne s'encode pas s'arrete ici, pas dans la bibliotheque.
        $inputJson = json_encode($input, JSON_THROW_ON_ERROR);

        // Call Rust function
        // @phpstan-ignore-next-line
        $outputPtr = $this->ffi->fight_battle_rounds($inputJson);

        // **Un pointeur nul se lit par le contrat FFI**, pas par `=== null` : C rend un `CData` nul.
        if (RustEngineAnswer::isNullPointer($outputPtr)) {
            throw RustEngineContractMismatch::becauseTheAnswerIs('la bibliotheque n a rien rendu (pointeur nul)');
        }

        // **La chaine rendue est liberee quoi qu'il arrive en la lisant.** Elle fuyait a chaque
        // bataille, et une lecture qui echouait l'aurait encore laissee fuir.
        try {
            $output = FFI::string($outputPtr);
        } finally {
            // @phpstan-ignore-next-line
            $this->ffi->free_battle_output($outputPtr);
        }

        // **Une reponse qui n'est pas une bataille se refuse avec sa raison** — document illisible,
        // document d'erreur (le moteur Rust ne laisse plus passer de panique), autre version, aucun
        // round. Le jugement vit a part, pour etre eprouve sans bibliotheque.
        $battleOutput = RustEngineAnswer::battleOutputFrom($output, self::ABI_VERSION);

        // Ce que la bibliotheque a tire, quand elle avait une graine : le banc de parite le compare
        // au journal du moteur PHP.
        $journal = $battleOutput['draws'] ?? null;
        $result->drawsConsumed = is_array($journal) && isset($journal['count'], $journal['raw'], $journal['digest'])
            ? ['count' => (int)$journal['count'], 'raw' => (int)$journal['raw'], 'digest' => (string)$journal['digest']]
            : null;

        // Convert Rust output back to PHP battle rounds
        $rounds = $this->convertBattleOutput($result, $battleOutput);

        // Handle case where no battle occurred - all fleets keep their units
        if (count($rounds) === 0) {
            foreach ($result->attackerFleetResults as $fleetResult) {
                $fleetResult->unitsResult = clone $fleetResult->unitsStart;
                $fleetResult->completelyDestroyed = false;
            }
            foreach ($result->defenderFleetResults as $fleetResult) {
                $fleetResult->unitsResult = clone $fleetResult->unitsStart;
                $fleetResult->completelyDestroyed = false;
            }
        }

        return $rounds;
    }

    /**
     * Prepare the battle input for the Rust battle engine.
     *
     * @param BattleResult $result
     * @return array Array structure for JSON serialization to Rust battle engine
     */
    private function prepareBattleInput(BattleResult $result): array
    {
        // Build attacker fleets
        $attackerFleets = [];
        foreach ($this->attackers as $attackerFleet) {
            $attackerUnits = new stdClass();
            foreach ($attackerFleet->units->units as $unit) {
                $rapidfire = new stdClass();
                foreach ($unit->unitObject->rapidfire as $rapidfireObject) {
                    $targetUnit = ObjectService::getUnitObjectByMachineName($rapidfireObject->object_machine_name);
                    $rapidfire->{$targetUnit->id} = $rapidfireObject->amount;
                }

                $coquePleine = (int)floor($unit->unitObject->properties->structural_integrity->calculate($attackerFleet->player)->totalValue / 10);

                $attackerUnits->{$unit->unitObject->id} = (object)[
                    'unit_id' => $unit->unitObject->id,
                    'amount' => $unit->amount,
                    'shield_points' => $unit->unitObject->properties->shield->calculate($attackerFleet->player)->totalValue,
                    'attack_power' => $unit->unitObject->properties->attack->calculate($attackerFleet->player)->totalValue,
                    'hull_plating' => $coquePleine,
                    'rapidfire' => $rapidfire,
                    // **Les coques sont calculees ici, jamais de l autre cote.** Dupliquer la
                    // formule en Rust ferait deux implementations d une regle d arrondi, et deux
                    // implementations derivent — silencieusement, jusqu a ce qu une bataille se
                    // joue differemment selon le moteur. Rust ne fait qu appliquer ce tableau.
                    'initial_hulls' => self::initialHullsFor(
                        $attackerFleet->damagedHulls(),
                        $unit->unitObject->machine_name,
                        $unit->amount,
                        $coquePleine
                    ),
                ];
            }

            $attackerFleets[] = (object)[
                'fleet_mission_id' => $attackerFleet->fleetMissionId,
                'owner_id' => max(0, $attackerFleet->ownerId), // Ensure non-negative for u32
                'units' => $attackerUnits,
            ];
        }

        // Build defender fleets (planet owner + ACS defend fleets)
        //
        // **Chaque flotte defensive combat avec les technologies de son proprietaire**, comme dans
        // le moteur PHP. Toutes etaient evaluees avec celles du proprietaire de la planete : un
        // renfort ACS recevait, sous Rust, les boucliers et l'armement d'un autre joueur.
        $proprietaires = [];
        $degatsDefensifs = [];
        foreach ($this->defenders as $defenderFleet) {
            $proprietaires[$defenderFleet->fleetMissionId] = $defenderFleet->player;
            // La boucle qui suit parcourt les **resultats**, pas les flottes : les degats se
            // relevent ici, par identifiant, comme les proprietaires juste au-dessus.
            $degatsDefensifs[$defenderFleet->fleetMissionId] = $defenderFleet->damagedHulls();
        }

        $defenderFleets = [];
        foreach ($result->defenderFleetResults as $fleetResult) {
            $defenderPlayer = $proprietaires[$fleetResult->fleetMissionId] ?? null;
            if ($defenderPlayer === null) {
                throw new RuntimeException('Defending fleet ' . $fleetResult->fleetMissionId . ' has no owner among the defenders.');
            }

            $defenderUnits = new stdClass();
            foreach ($fleetResult->unitsStart->units as $unit) {
                $rapidfire = new stdClass();
                foreach ($unit->unitObject->rapidfire as $rapidfireObject) {
                    $targetUnit = ObjectService::getUnitObjectByMachineName($rapidfireObject->object_machine_name);
                    $rapidfire->{$targetUnit->id} = $rapidfireObject->amount;
                }

                $coquePleine = (int)floor($unit->unitObject->properties->structural_integrity->calculate($defenderPlayer)->totalValue / 10);

                $defenderUnits->{$unit->unitObject->id} = (object)[
                    'unit_id' => $unit->unitObject->id,
                    'amount' => $unit->amount,
                    'shield_points' => $unit->unitObject->properties->shield->calculate($defenderPlayer)->totalValue,
                    'attack_power' => $unit->unitObject->properties->attack->calculate($defenderPlayer)->totalValue,
                    'hull_plating' => $coquePleine,
                    'rapidfire' => $rapidfire,
                    // Meme regle cote defenseur, y compris pour la garnison : les coques viennent
                    // d ici, Rust les applique.
                    'initial_hulls' => self::initialHullsFor(
                        $degatsDefensifs[$fleetResult->fleetMissionId] ?? DamagedHulls::none(),
                        $unit->unitObject->machine_name,
                        $unit->amount,
                        $coquePleine
                    ),
                ];
            }

            $defenderFleets[] = (object)[
                'fleet_mission_id' => $fleetResult->fleetMissionId,
                'owner_id' => max(0, $fleetResult->ownerId), // Ensure non-negative for u32
                'units' => $defenderUnits,
            ];
        }

        $entree = [
            'schema' => self::ABI_VERSION,
            'attacker_fleets' => $attackerFleets,
            'defender_fleets' => $defenderFleets,
        ];

        // **La graine voyage avec l'entree** quand la source en a une : la bibliotheque tire alors
        // la meme suite que le moteur PHP tirerait. Sans graine, elle tire du systeme.
        if ($this->draws instanceof SeededDraws) {
            $entree['seed'] = $this->draws->seed();
        }

        return $entree;
    }

    /**
     * Convert the battle output from Rust to PHP and populate per-fleet results.
     *
     * @param BattleResult $result The battle result to populate with fleet results
     * @param array<string, list<array<string, float|int|string>>> $battleOutput
     * @return array<BattleResultRound>
     */
    private function convertBattleOutput(BattleResult $result, array $battleOutput): array
    {
        $rounds = [];
        foreach ($battleOutput['rounds'] as $roundData) {
            $round = new BattleResultRound();

            // Initialize collections.
            $round->attackerShips = new UnitCollection();
            $round->defenderShips = new UnitCollection();
            $round->attackerLosses = new UnitCollection();
            $round->attackerLossesInRound = new UnitCollection();
            $round->defenderLosses = new UnitCollection();
            $round->defenderLossesInRound = new UnitCollection();

            // Convert unit arrays to UnitCollections.
            if (isset($roundData['attacker_ships']) && is_array($roundData['attacker_ships'])) {
                $round->attackerShips = $this->convertUnitArrayToUnitCollection($roundData['attacker_ships']);
            }

            if (isset($roundData['defender_ships']) && is_array($roundData['defender_ships'])) {
                $round->defenderShips = $this->convertUnitArrayToUnitCollection($roundData['defender_ships']);
            }

            if (isset($roundData['attacker_losses']) && is_array($roundData['attacker_losses'])) {
                $round->attackerLosses = $this->convertUnitArrayToUnitCollection($roundData['attacker_losses']);
            }

            if (isset($roundData['attacker_losses_in_round']) && is_array($roundData['attacker_losses_in_round'])) {
                $round->attackerLossesInRound = $this->convertUnitArrayToUnitCollection($roundData['attacker_losses_in_round']);
            }

            if (isset($roundData['defender_losses']) && is_array($roundData['defender_losses'])) {
                $round->defenderLosses = $this->convertUnitArrayToUnitCollection($roundData['defender_losses']);
            }

            if (isset($roundData['defender_losses_in_round']) && is_array($roundData['defender_losses_in_round'])) {
                $round->defenderLossesInRound = $this->convertUnitArrayToUnitCollection($roundData['defender_losses_in_round']);
            }

            // **Les cartes par flotte, des deux camps.** Le contrat du round Rust les porte depuis
            // le schema 2 ; le moteur partage refuse un round dont l'attribution ne recouvre pas
            // les pertes du camp, donc une bibliotheque qui ne les rendrait pas ne produirait
            // aucun resultat plutot qu'un resultat muet.
            $round->attackerLossesInRoundPerFleet = $this->convertUnitsByFleet($roundData['attacker_losses_in_round_per_fleet'] ?? []);
            $round->defenderLossesInRoundPerFleet = $this->convertUnitsByFleet($roundData['defender_losses_in_round_per_fleet'] ?? []);
            $round->hitsPerAttackerFleet = $this->convertIntByFleet($roundData['hits_per_attacker_fleet'] ?? []);
            $round->damagePerAttackerFleet = $this->convertIntByFleet($roundData['damage_per_attacker_fleet'] ?? []);

            // Pertes cumulees et effectif par flotte attaquante : le round Rust les porte deja dans
            // ses resultats par flotte, calcules a la fin de chaque round.
            if (isset($roundData['attacker_fleet_results']) && is_array($roundData['attacker_fleet_results'])) {
                foreach ($roundData['attacker_fleet_results'] as $fleetResult) {
                    if (!is_array($fleetResult) || !isset($fleetResult['fleet_mission_id'])) {
                        continue;
                    }

                    $mission = (int)$fleetResult['fleet_mission_id'];
                    $round->attackerLossesPerFleet[$mission] = $this->convertUnitArrayToUnitCollection(is_array($fleetResult['units_lost'] ?? null) ? $fleetResult['units_lost'] : []);
                    $round->attackerShipsPerFleet[$mission] = $this->convertUnitArrayToUnitCollection(is_array($fleetResult['units_result'] ?? null) ? $fleetResult['units_result'] : []);
                }
            }

            // Extract other properties.
            $round->hitsAttacker = (int)($roundData['hits_attacker'] ?? 0);
            $round->hitsDefender = (int)($roundData['hits_defender'] ?? 0);
            $round->absorbedDamageAttacker = (int)($roundData['absorbed_damage_attacker'] ?? 0);
            $round->absorbedDamageDefender = (int)($roundData['absorbed_damage_defender'] ?? 0);
            $round->fullStrengthAttacker = (int)($roundData['full_strength_attacker'] ?? 0);
            $round->fullStrengthDefender = (int)($roundData['full_strength_defender'] ?? 0);

            // Populate per-fleet results from Rust (use last round for final results)
            if (isset($roundData['attacker_fleet_results']) && is_array($roundData['attacker_fleet_results'])) {
                foreach ($roundData['attacker_fleet_results'] as $fleetResult) {
                    $fleetMissionId = (int)$fleetResult['fleet_mission_id'];
                    $ownerId = (int)$fleetResult['owner_id'];

                    // Find the corresponding fleet result in the BattleResult
                    foreach ($result->attackerFleetResults as $attackerFleetResult) {
                        if ($attackerFleetResult->fleetMissionId === $fleetMissionId && $attackerFleetResult->playerId === $ownerId) {
                            // Populate units_result from Rust data
                            if (isset($fleetResult['units_result']) && is_array($fleetResult['units_result'])) {
                                $attackerFleetResult->unitsResult = $this->convertUnitArrayToUnitCollection($fleetResult['units_result']);
                            }

                            // Populate units_lost from Rust data
                            if (isset($fleetResult['units_lost']) && is_array($fleetResult['units_lost'])) {
                                $attackerFleetResult->unitsLost = $this->convertUnitArrayToUnitCollection($fleetResult['units_lost']);
                            }

                            // **L etat des survivants, pas seulement leur nombre.** Rust rend la
                            // coque de chacun ; c est ici qu elle redevient des degats stockables.
                            $attackerFleetResult->survivorHulls = self::survivorHullsFrom(
                                $fleetResult["survivor_hulls"] ?? null,
                                $attackerFleetResult->fleetMissionId,
                                $this->attackers
                            );

                            // Check if completely destroyed
                            $attackerFleetResult->completelyDestroyed = $attackerFleetResult->unitsResult->getAmount() === 0;
                            break;
                        }
                    }
                }
            }

            if (isset($roundData['defender_fleet_results']) && is_array($roundData['defender_fleet_results'])) {
                foreach ($roundData['defender_fleet_results'] as $fleetResult) {
                    $fleetMissionId = (int)$fleetResult['fleet_mission_id'];
                    $ownerId = (int)$fleetResult['owner_id'];

                    // Find the corresponding fleet result in the BattleResult
                    foreach ($result->defenderFleetResults as $defenderFleetResult) {
                        // Note: NPC owner IDs are negative (-1/-2) in PHP but clamped to 0 when sent to Rust.
                        // Use max(0, ...) here to match the same clamping applied in prepareBattleInput().
                        if ($defenderFleetResult->fleetMissionId === $fleetMissionId && max(0, $defenderFleetResult->ownerId) === $ownerId) {
                            // Populate units_result from Rust data
                            if (isset($fleetResult['units_result']) && is_array($fleetResult['units_result'])) {
                                $defenderFleetResult->unitsResult = $this->convertUnitArrayToUnitCollection($fleetResult['units_result']);
                            }

                            // Populate units_lost from Rust data
                            if (isset($fleetResult['units_lost']) && is_array($fleetResult['units_lost'])) {
                                $defenderFleetResult->unitsLost = $this->convertUnitArrayToUnitCollection($fleetResult['units_lost']);
                            }

                            // Check if completely destroyed
                            // Meme derivation cote defenseur, garnison comprise.
                            $defenderFleetResult->survivorHulls = self::survivorHullsFrom(
                                $fleetResult["survivor_hulls"] ?? null,
                                $defenderFleetResult->fleetMissionId,
                                $this->defenders
                            );

                            $defenderFleetResult->completelyDestroyed = $defenderFleetResult->unitsResult->getAmount() === 0;
                            break;
                        }
                    }
                }
            }

            $rounds[] = $round;
        }
        return $rounds;
    }

    /**
     * Une carte « identifiant de mission => unites » telle que le round Rust la rend.
     *
     * @param mixed $parFlotte
     * @return array<int, UnitCollection>
     */
    private function convertUnitsByFleet(mixed $parFlotte): array
    {
        if (!is_array($parFlotte)) {
            return [];
        }

        $cartes = [];

        foreach ($parFlotte as $mission => $unites) {
            $cartes[(int)$mission] = $this->convertUnitArrayToUnitCollection(is_array($unites) ? $unites : []);
        }

        return $cartes;
    }

    /**
     * Une carte « identifiant de mission => entier » telle que le round Rust la rend.
     *
     * @param mixed $parFlotte
     * @return array<int, int>
     */
    private function convertIntByFleet(mixed $parFlotte): array
    {
        if (!is_array($parFlotte)) {
            return [];
        }

        $cartes = [];

        foreach ($parFlotte as $mission => $valeur) {
            $cartes[(int)$mission] = (int)$valeur;
        }

        return $cartes;
    }

    /**
     * Convert unit array received from the Rust FFI output to a UnitCollection.
     *
     * @param array<array<string, float|int|string>> $unitData
     * @return UnitCollection
     */
    private function convertUnitArrayToUnitCollection(array $unitData): UnitCollection
    {
        $unitCollection = new UnitCollection();
        foreach ($unitData as $unit) {
            $unitObject = ObjectService::getUnitObjectById((int)$unit['unit_id']);
            $unitCollection->addUnit($unitObject, (int)$unit['amount']);
        }
        return $unitCollection;
    }

    /**
     * Check and execute the Hamill Manoeuvre special ability.
     * General class Light Fighters have a small chance to instantly destroy one Deathstar before battle.
     *
     * @param BattleResult $result
     * @return void
     */
    private function checkHamillManoeuvre(BattleResult $result): void
    {
        // Check if attacker is General class
        $attackerPlayer = $this->getAttackerPlayer();
        $characterClassService = app(CharacterClassService::class);
        if (!$characterClassService->isGeneral($attackerPlayer->getUser())) {
            return;
        }

        // Check if attacker has at least one Light Fighter
        $hasLightFighter = $result->attackerUnitsStart->getAmountByMachineName('light_fighter') > 0;

        if (!$hasLightFighter) {
            return;
        }

        // Check if defender has at least one Deathstar
        $hasDeathstar = $result->defenderUnitsStart->getAmountByMachineName('deathstar') > 0;

        if (!$hasDeathstar) {
            return;
        }

        // Roll the dice for Hamill Manoeuvre
        $settings = app(SettingsService::class);
        $probability = $settings->hamillManoeuvreChance();

        // Une chance sur `$probability`, tiree de la source de la bataille — la meme que le
        // moteur PHP, au meme instant : avant les rounds.
        if ($this->draws->chanceOutOf($probability) === 1) {
            // Hamill Manoeuvre triggered! Destroy one Deathstar
            $result->hamillManoeuvreTriggered = true;

            // Remove the Deathstar from defender units so it doesn't participate in battle
            $deathstarObject = ObjectService::getShipObjectByMachineName('deathstar');
            $result->defenderUnitsStart->removeUnit($deathstarObject, 1);

            // NOTE: The loss will be properly calculated after battle rounds complete
            // by comparing the modified defenderUnitsStart with defenderUnitsResult.
        }
    }

    /**
     * Les coques avec lesquelles chaque unite d un type entre dans la bataille.
     *
     * **La formule vit d un seul cote de la frontiere.** Rust pourrait deriver ces valeurs d un
     * rapport de degats, mais ce serait une seconde implementation d une regle d arrondi — et deux
     * implementations derivent. Elles se seraient separees sur un `floor` un jour, et la bataille se
     * serait jouee differemment selon le moteur, sans que rien ne le signale. PHP calcule, Rust
     * applique.
     *
     * Une flotte intacte rend un tableau vide : Rust retombe alors sur la coque pleine, et la couture
     * se comporte exactement comme avant que ce champ existe.
     *
     * @return array<int, int>
     */
    private static function initialHullsFor(DamagedHulls $degats, string $type, int $combien, int $coquePleine): array
    {
        if ($degats->isEmpty() || $degats->damagedCountOf($type) === 0) {
            return [];
        }

        $coques = [];

        foreach ($degats->damageSequenceFor($type, $combien) as $niveau) {
            $coques[] = DamagedHulls::hullFromDamage($coquePleine, $niveau);
        }

        return $coques;
    }

    /**
     * Les degats des survivants, depuis les coques que Rust rend.
     *
     * Rust ne connait pas la coque pleine de chaque type une fois les technologies appliquees : il
     * rend donc **toutes** les coques, y compris pleines, et c est ici que la comparaison se fait.
     * Le tri par (type, coque) est ce qui redonne un histogramme.
     *
     * @param mixed $brut Le champ `survivor_hulls` de la sortie Rust, ou `null` s il est absent.
     * @param array<int, AttackerFleet|DefenderFleet> $flottes
     */
    private static function survivorHullsFrom(mixed $brut, int $fleetMissionId, array $flottes): DamagedHulls
    {
        if (!is_array($brut) || $brut === []) {
            return DamagedHulls::none();
        }

        // Le joueur de cette flotte : c est lui qui donne la coque pleine de chaque type, et il doit
        // etre celui de la bataille — jamais un joueur relu.
        $joueur = null;

        foreach ($flottes as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                $joueur = $flotte->player;
                break;
            }
        }

        if ($joueur === null) {
            return DamagedHulls::none();
        }

        $paliers = [];

        foreach ($brut as $unitId => $coques) {
            if (!is_array($coques) || $coques === []) {
                continue;
            }

            $objet = ObjectService::getUnitObjectById((int)$unitId);
            $coquePleine = (int)floor($objet->properties->structural_integrity->calculate($joueur)->totalValue / 10);

            foreach ($coques as $coque) {
                $degats = DamagedHulls::damageFromHull((int)$coque, $coquePleine);

                if ($degats > 0) {
                    $paliers[$objet->machine_name][$degats] = ($paliers[$objet->machine_name][$degats] ?? 0) + 1;
                }
            }
        }

        return DamagedHulls::of($paliers);
    }
}
