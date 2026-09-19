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
use OGame\GameMissions\BattleEngine\Models\HamillManoeuvre;
use OGame\GameObjects\Models\Enums\GameObjectType;
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
     * @var int|null La flotte defensive a qui la manoeuvre de Hamill prend son Etoile, ou `null`.
     *
     * Pose une seule fois, avant l entree, et lu deux fois : par l entree qui retire l unite, et par
     * l inscription qui comble ce que la bibliotheque ne pouvait pas savoir.
     */
    private int|null $hamillTakesTheDeathstarOf = null;

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

                // **Une flotte entamee ne rentre pas reparee.** Aucune bataille n a eu lieu : elle ressort
                // exactement comme elle est entree, et le moteur PHP le dit deja. Ne rien poser ici la
                // rendait intacte sous Rust — une divergence invisible, la projection canonique ne portant
                // pas les coques.
                $fleetResult->survivorHulls = $this->survivorHullsWithoutARound($fleetResult->fleetMissionId, $fleetResult->unitsStart, $this->attackers, false);
            }
            foreach ($result->defenderFleetResults as $fleetResult) {
                $fleetResult->unitsResult = clone $fleetResult->unitsStart;

                // **Ce que la manoeuvre a pris n est jamais un survivant**, meme quand aucun round ne
                // se joue — et c est precisement ce qui arrive quand elle vide la derniere defense :
                // la bibliotheque n a plus personne a opposer. Le moteur PHP balaye alors un tableau
                // etendu dont l Etoile a disparu, et rend la flotte detruite.
                if ($this->hamillTakesTheDeathstarOf === $fleetResult->fleetMissionId) {
                    $fleetResult->unitsResult->removeUnit(ObjectService::getShipObjectByMachineName('deathstar'), 1);
                }

                // Derive plutot qu impose a `false` : c est la regle du moteur PHP, et la seule qui
                // reste juste quand une flotte sort de la manoeuvre sans une unite.
                $fleetResult->completelyDestroyed = $fleetResult->unitsResult->getAmount() === 0;

                // Meme regle cote defenseur — l Etoile que la manoeuvre a prise en moins.
                $fleetResult->survivorHulls = $this->survivorHullsWithoutARound(
                    $fleetResult->fleetMissionId,
                    $fleetResult->unitsStart,
                    $this->defenders,
                    $this->hamillTakesTheDeathstarOf === $fleetResult->fleetMissionId
                );
            }
        }

        // Ce que la bibliotheque ne pouvait pas savoir, une fois ses resultats lus.
        $this->bookTheManoeuvre($result, $rounds);

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

            // **C est ici que la manoeuvre de Hamill prend son Etoile**, et nulle part ailleurs : cette
            // entree est la seule composition que la bibliotheque voit.
            $laManoeuvrePrendIci = $this->hamillTakesTheDeathstarOf === $fleetResult->fleetMissionId;

            $defenderUnits = new stdClass();
            foreach ($fleetResult->unitsStart->units as $unit) {
                $rapidfire = new stdClass();
                foreach ($unit->unitObject->rapidfire as $rapidfireObject) {
                    $targetUnit = ObjectService::getUnitObjectByMachineName($rapidfireObject->object_machine_name);
                    $rapidfire->{$targetUnit->id} = $rapidfireObject->amount;
                }

                $coquePleine = (int)floor($unit->unitObject->properties->structural_integrity->calculate($defenderPlayer)->totalValue / 10);

                $combien = $unit->amount;
                $coques = self::initialHullsFor(
                    $degatsDefensifs[$fleetResult->fleetMissionId] ?? DamagedHulls::none(),
                    $unit->unitObject->machine_name,
                    $combien,
                    $coquePleine
                );

                if ($laManoeuvrePrendIci && $unit->unitObject->machine_name === 'deathstar') {
                    $combien--;

                    // **La plus intacte part, pas la plus abimee.** Le moteur PHP retire la premiere
                    // unite etendue du type, et la suite de degats range les plus intactes d abord :
                    // retirer la derniere coque ferait combattre deux flottes differentes.
                    array_shift($coques);

                    if ($combien === 0) {
                        // La flotte reste dans l entree, vide : la bibliotheque refuse une liste
                        // defensive sans garnison, et une flotte sans unite n ajoute rien au champ.
                        continue;
                    }
                }

                $defenderUnits->{$unit->unitObject->id} = (object)[
                    'unit_id' => $unit->unitObject->id,
                    'amount' => $combien,
                    'shield_points' => $unit->unitObject->properties->shield->calculate($defenderPlayer)->totalValue,
                    'attack_power' => $unit->unitObject->properties->attack->calculate($defenderPlayer)->totalValue,
                    'hull_plating' => $coquePleine,
                    'rapidfire' => $rapidfire,
                    // Meme regle cote defenseur, y compris pour la garnison : les coques viennent
                    // d ici, Rust les applique.
                    'initial_hulls' => $coques,
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
            // **Et elles portent toutes les flottes, dans l ordre des flottes** : la bibliotheque n ecrit une entree
            // qu a la premiere perte, et ses tables de hachage ne se serialisent pas dans un ordre stable, la ou le moteur
            // PHP pose une entree vide pour chaque flotte a chaque round. Sans cette remise en forme, un renfort qui
            // traversait un round sans perte disparaissait du round (CI du 19 septembre 2026, journal §166).
            $missionsAttaquantes = array_map(static fn (AttackerFleet $f): int => $f->fleetMissionId, $this->attackers);
            $missionsDefensives = array_map(static fn (DefenderFleet $f): int => $f->fleetMissionId, $this->defenders);
            $round->attackerLossesInRoundPerFleet = RustRoundShape::unitsOfEveryFleet($this->convertUnitsByFleet($roundData['attacker_losses_in_round_per_fleet'] ?? []), $missionsAttaquantes, 'attaquant');
            $round->defenderLossesInRoundPerFleet = RustRoundShape::unitsOfEveryFleet($this->convertUnitsByFleet($roundData['defender_losses_in_round_per_fleet'] ?? []), $missionsDefensives, 'defenseur');
            $round->hitsPerAttackerFleet = RustRoundShape::numbersOfEveryFleet($this->convertIntByFleet($roundData['hits_per_attacker_fleet'] ?? []), $missionsAttaquantes, 'attaquant');
            $round->damagePerAttackerFleet = RustRoundShape::numbersOfEveryFleet($this->convertIntByFleet($roundData['damage_per_attacker_fleet'] ?? []), $missionsAttaquantes, 'attaquant');

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

            if ($this->hamillRule->theManoeuvreLeavesTheBattle()) {
                // **L Etoile quitte l entree envoyee a la bibliotheque, pas le decompte de depart.**
                // La retirer du seul `defenderUnitsStart` — ou des flottes, qui ne composent plus
                // l entree defensive — la laissait tirer, et la faisait disparaitre des pertes : la
                // manoeuvre ne detruisait rien. La flotte visee est designee ici, l entree la retire
                // (`prepareBattleInput()`) et le moteur inscrit ensuite ce que la bibliotheque ne
                // pouvait pas savoir (`bookTheManoeuvre()`).
                $this->hamillTakesTheDeathstarOf = $this->fleetTheManoeuvreTakesFrom();

                // **La victime et l auteur, enregistres a l instant du retrait** — la flotte designee ici, et
                // la flotte dont le General a ete consulte (`attackers[0]`, convention Azria) ; le moteur PHP
                // ecrit exactement la meme chose au meme point, et le banc de parite les compare.
                $result->hamill = $this->hamillTakesTheDeathstarOf === null
                    ? HamillManoeuvre::unnamed($this->hamillRule)
                    : HamillManoeuvre::named(
                        $this->defenderParticipantKey($this->hamillTakesTheDeathstarOf),
                        $this->attackerParticipantKey($this->attackers[0]->fleetMissionId),
                        $this->hamillRule
                    );
            } else {
                // Sous la regle telle que livree, aucune flotte ne perd l Etoile : la manoeuvre reste non nommee.
                $result->hamill = HamillManoeuvre::unnamed($this->hamillRule);
                // **La regle telle qu elle a ete livree**, gardee pour les combats ouverts avant la
                // correction : l Etoile disparait du depart annonce et continue de tirer. Aucun combat
                // neuf ne l emploie.
                $result->defenderUnitsStart->removeUnit(ObjectService::getShipObjectByMachineName('deathstar'), 1);
            }
        }
    }

    /**
     * La flotte defensive a qui la manoeuvre prend son Etoile : la premiere dans l ordre canonique.
     *
     * L ordre compte : les deux moteurs doivent detruire **la meme** Etoile, sinon ce sont deux batailles
     * differentes — les flottes n ont ni les memes technologies ni les memes coques. L ordre canonique est
     * celui des identifiants de mission, la garnison portant l identifiant zero ; le moteur PHP retire la
     * premiere Etoile de ses unites etendues, qu il construit dans ce meme ordre.
     *
     * L appelant a deja etabli qu une Etoile defend : `null` n arrive pas, et se traite comme une manoeuvre
     * qui ne prend rien plutot que par une exception, car aucune bataille ne doit s arreter ici.
     */
    private function fleetTheManoeuvreTakesFrom(): int|null
    {
        $flottes = $this->defenders;
        usort($flottes, static fn (DefenderFleet $a, DefenderFleet $b): int => $a->fleetMissionId <=> $b->fleetMissionId);

        foreach ($flottes as $flotte) {
            if ($flotte->units->getAmountByMachineName('deathstar') > 0) {
                return $flotte->fleetMissionId;
            }
        }

        return null;
    }

    /**
     * Inscrit ce que la bibliotheque ne pouvait pas savoir : l Etoile que la manoeuvre a prise.
     *
     * ## Ce que le moteur PHP produit, mesure et non suppose
     *
     * Le moteur PHP retire l unite de son tableau etendu, et **rien d autre** : le decompte des survivants
     * d un round part de `defenderUnitsStart` et ne baisse que sur une mort en round. L Etoile prise par la
     * manoeuvre y reste donc affichee jusqu au dernier round, et la perte est inscrite une fois, a part, par
     * `BattleEngine::simulateBattle()`. Le resultat par flotte, lui, est balaye sur les survivants : la
     * flotte visee la compte bien perdue.
     *
     * La bibliotheque, elle, ne recoit jamais cette Etoile. Ses rounds et ses pertes par flotte sont donc
     * exacts d un vaisseau pres, et c est cet ecart — le seul — qui se comble ici. **Ce n est pas une
     * correction de la regle du jeu** : c est ce qu il faut pour que les deux moteurs rendent la meme
     * bataille. L incoherence d affichage du moteur PHP (une Etoile detruite qui figure encore parmi les
     * survivants du rapport) est anterieure, commune aux deux moteurs une fois la parite tenue, et reste une
     * decision de jeu ouverte.
     *
     * @param array<BattleResultRound> $rounds
     */
    private function bookTheManoeuvre(BattleResult $result, array $rounds): void
    {
        if ($this->hamillTakesTheDeathstarOf === null) {
            return;
        }

        $etoile = ObjectService::getShipObjectByMachineName('deathstar');

        // **Les survivants affiches par round** : l Etoile y figure sous les regles qui la comptent
        // survivante, et le moteur PHP fait de meme. Sous la regle qui l en retire, aucun des deux ne
        // l ajoute — la bibliotheque ne l a jamais vue.
        if ($this->hamillRule->theDestroyedDeathstarStillCountsAsASurvivor()) {
            foreach ($rounds as $round) {
                $round->defenderShips->addUnit($etoile, 1);
            }
        }

        foreach ($result->defenderFleetResults as $fleetResult) {
            if ($fleetResult->fleetMissionId === $this->hamillTakesTheDeathstarOf) {
                $fleetResult->unitsLost->addUnit($etoile, 1);

                return;
            }
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
     * Les degats que gardent les survivants quand **aucun round** n a ete joue.
     *
     * Personne n a tire : chaque unite ressort avec les degats qu elle portait en entrant. Le moteur PHP
     * l obtient sans y penser — il balaie ses unites etendues, qui portent deja ces coques — tandis que la
     * bibliotheque n a rien rendu, faute de bataille.
     *
     * **L ordre d entree decide**, comme partout ailleurs : la suite range les plus intactes d abord, et
     * c est la premiere que la manoeuvre de Hamill emporte. Les defenses sont exclues, comme cote PHP.
     *
     * @param array<int, AttackerFleet|DefenderFleet> $flottes
     */
    private function survivorHullsWithoutARound(int $fleetMissionId, UnitCollection $depart, array $flottes, bool $laManoeuvreYAPris): DamagedHulls
    {
        $degats = null;

        foreach ($flottes as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                $degats = $flotte->damagedHulls();
                break;
            }
        }

        if ($degats === null || $degats->isEmpty()) {
            return DamagedHulls::none();
        }

        $paliers = [];

        foreach ($depart->units as $unite) {
            $type = $unite->unitObject->machine_name;

            if ($unite->unitObject->type !== GameObjectType::Ship) {
                continue;
            }

            $suite = $degats->damageSequenceFor($type, $unite->amount);

            if ($laManoeuvreYAPris && $type === 'deathstar') {
                array_shift($suite);
            }

            foreach ($suite as $niveau) {
                if ($niveau > 0) {
                    $paliers[$type][$niveau] = ($paliers[$type][$niveau] ?? 0) + 1;
                }
            }
        }

        return DamagedHulls::of($paliers);
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

            // **Les vaisseaux seulement**, comme le moteur PHP. Une defense a deja sa propre reparation,
            // automatique et gratuite : lui donner en plus une coque persistante creerait deux mecanismes
            // concurrents sur le meme objet. La bibliotheque, elle, rend l etat de **toutes** les unites —
            // c est ici que le tri se fait, et son absence etait une divergence entre les deux moteurs.
            if ($objet->type !== GameObjectType::Ship) {
                continue;
            }

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
