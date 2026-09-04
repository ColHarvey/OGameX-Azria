//! # Battle Engine FFI
//!
//! `battle_engine_ffi` is the Rust implementation of the OGameX battle engine.
//!
//! This Rust library is called from the PHP client RustBattleEngine.php via FFI (Foreign Function Interface)
//! and takes the battle input in JSON, processes the battle rounds and returns the battle output in JSON.
//!
//! This battle engine is functionally equivalent to the OGameX PHP battle engine but is optimized
//! for performance and memory usage. It is up to 200x faster than the equivalent PHP implementation
//! and uses up to 10x less memory.
//!
//! # Multi-Attacker Support
//! This engine supports multiple attacker fleets (ACS Attack) and multiple defender fleets (ACS Defend).
//! Each fleet's units are tracked with their fleet_mission_id and owner_id, allowing for accurate
//! per-fleet result reporting.
use serde::{Deserialize, Serialize};
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use rand::Rng;
use std::collections::HashMap;
use memory_stats::memory_stats;

/// The version of the FFI contract this library speaks.
///
/// Version 2 adds, to every round, the losses of the round per fleet on both sides, and the hits
/// and damage per attacking fleet; it also names its version in the input and the output, exports
/// `battle_engine_abi_version()` and `free_battle_output()`. A PHP client declares those two symbols
/// when it loads the library: an older library fails at load time instead of answering with rounds
/// that say nothing about who lost what.
pub const ABI_VERSION: u32 = 2;

/// The source of every draw a battle makes: which target, whether a damaged hull explodes,
/// whether rapidfire is granted.
///
/// The three formulas (`draw_index`, `draw_explodes`, `draw_rapidfire`) are the ones the PHP engine
/// uses (`Draw` in `app/GameMissions/BattleEngine/Draws`): two engines fed the same draws fight the
/// same battle, and that is what the parity bench compares.
trait Draws {
    /// The next draw: a uniform thirty-two bit integer.
    fn next(&mut self) -> u32;
}

/// The game's draws: the system's randomness.
struct SystemDraws {
    rng: rand::rngs::ThreadRng,
}

impl Draws for SystemDraws {
    fn next(&mut self) -> u32 {
        self.rng.gen::<u32>()
    }
}

/// Replayable draws: a thirty-two bit xorshift from a seed, identical to PHP's `SeededDraws`.
struct SeededDraws {
    state: u32,
}

impl Draws for SeededDraws {
    fn next(&mut self) -> u32 {
        let mut x = self.state;
        x ^= x << 13;
        x ^= x >> 17;
        x ^= x << 5;
        self.state = x;
        x
    }
}

/// A uniform position among `count` candidates.
fn draw_index(draws: &mut dyn Draws, count: usize) -> usize {
    (draws.next() as usize) % count
}

/// Does a damaged hull explode? A whole percent from 0 to 100, strictly under the chance.
fn draw_explodes(draws: &mut dyn Draws, chance: f64) -> bool {
    ((draws.next() % 101) as f64) < chance
}

/// Is rapidfire granted? A hundredth of a percent from 0.01 to 100.00, at most the chance.
fn draw_rapidfire(draws: &mut dyn Draws, chance: f64) -> bool {
    ((1 + draws.next() % 10000) as f64) / 100.0 <= chance
}

/// Battle input which is provided by the PHP client.
#[derive(Serialize, Deserialize)]
pub struct BattleInput {
    /// The contract version the client believes it is speaking. Absent means version 1.
    #[serde(default)]
    schema: u32,
    /// A seed makes the battle replayable: the same seed draws the same sequence the PHP engine
    /// would draw. Absent, the draws come from the system. Zero is refused: it would leave the
    /// generator at zero forever.
    #[serde(default)]
    seed: Option<u32>,
    attacker_fleets: Vec<AttackerFleetInput>,
    defender_fleets: Vec<DefenderFleetInput>,
}

/// Input structure for a single attacker fleet.
#[derive(Serialize, Deserialize, Clone)]
struct AttackerFleetInput {
    fleet_mission_id: u32,
    owner_id: u32,
    units: HashMap<i16, BattleUnitInfo>,
}

/// Input structure for a single defender fleet.
#[derive(Serialize, Deserialize, Clone)]
struct DefenderFleetInput {
    fleet_mission_id: u32,
    owner_id: u32,
    units: HashMap<i16, BattleUnitInfo>,
}

/// Battle unit info which is provided by the PHP client.
///
/// This contains static information about the input units and their amount.
#[derive(Serialize, Deserialize, Clone)]
struct BattleUnitInfo {
    unit_id: i16,
    amount: u32,
    attack_power: f32,
    shield_points: f32,
    hull_plating: f32,
    rapidfire: HashMap<i16, u16>,
}

/// Battle unit count to keep track of the amount of units of a certain type.
#[derive(Serialize, Deserialize, Clone)]
struct BattleUnitCount {
    unit_id: i16,
    amount: u32,
}

/// Battle unit instance which is used to keep track of individual units and their current health during battle.
#[derive(Serialize, Deserialize, Clone)]
struct BattleUnitInstance {
    unit_id: i16,
    fleet_mission_id: u32,
    owner_id: u32,
    current_shield_points: f32,
    current_hull_plating: f32,
}

/// Battle round which is used to keep track of the battle statistics for a single round.
#[derive(Serialize, Deserialize)]
struct BattleRound {
    /// The units of the attacker remaining at the end of the round.
    attacker_ships: HashMap<i16, BattleUnitCount>,
    /// The units of the defender remaining at the end of the round.
    defender_ships: HashMap<i16, BattleUnitCount>,
    /// Unit losses of the attacker until now which includes previous rounds.
    attacker_losses: HashMap<i16, BattleUnitCount>,
    /// Unit losses of the defender until now which includes previous rounds.
    defender_losses: HashMap<i16, BattleUnitCount>,
    /// Unit losses of the attacker in this round.
    attacker_losses_in_round: HashMap<i16, BattleUnitCount>,
    /// Unit losses of the defender in this round.
    defender_losses_in_round: HashMap<i16, BattleUnitCount>,
    /// Total amount of damage absorbed by the attacker this round.
    absorbed_damage_attacker: f64,
    /// Total amount of damage absorbed by the defender this round.
    absorbed_damage_defender: f64,
    /// Total amount of full strength of the attacker at the start of the round.
    full_strength_attacker: f64,
    /// Total amount of full strength of the defender at the start of the round.
    full_strength_defender: f64,
    /// Total amount of hits the attacker made this round.
    hits_attacker: u32,
    /// Total amount of hits the defender made this round.
    hits_defender: u32,
    /// Per-fleet attacker results keyed by fleet_mission_id.
    attacker_fleet_results: HashMap<u32, AttackerFleetResult>,
    /// Per-fleet defender results keyed by fleet_mission_id.
    defender_fleet_results: HashMap<u32, DefenderFleetResult>,
    /// Unit losses of THIS round per attacker fleet_mission_id.
    attacker_losses_in_round_per_fleet: HashMap<u32, HashMap<i16, BattleUnitCount>>,
    /// Unit losses of THIS round per defender fleet_mission_id (0 is the garrison).
    defender_losses_in_round_per_fleet: HashMap<u32, HashMap<i16, BattleUnitCount>>,
    /// Hits made by each attacker fleet this round.
    hits_per_attacker_fleet: HashMap<u32, u32>,
    /// Damage dealt by each attacker fleet this round.
    damage_per_attacker_fleet: HashMap<u32, f64>,
}

/// Result for a single attacker fleet.
#[derive(Serialize, Deserialize, Clone)]
struct AttackerFleetResult {
    fleet_mission_id: u32,
    owner_id: u32,
    units_start: HashMap<i16, BattleUnitCount>,
    units_result: HashMap<i16, BattleUnitCount>,
    units_lost: HashMap<i16, BattleUnitCount>,
}

/// Result for a single defender fleet.
#[derive(Serialize, Deserialize, Clone)]
struct DefenderFleetResult {
    fleet_mission_id: u32,
    owner_id: u32,
    units_start: HashMap<i16, BattleUnitCount>,
    units_result: HashMap<i16, BattleUnitCount>,
    units_lost: HashMap<i16, BattleUnitCount>,
}

/// Memory metrics which is used to keep track of the peak memory usage during the battle.
///
/// This is only used for debugging purposes and not actually consumed by the PHP client.
#[derive(Serialize, Deserialize)]
struct MemoryMetrics {
    peak_memory: u64, // in kilobytes
}

/// Battle output which is returned to the PHP client.
///
/// This contains the battle statistics and memory metrics. Memory metrics are only used
/// for debugging purposes when called from battle_engine_debug Rust project.
#[derive(Serialize, Deserialize)]
pub struct BattleOutput {
    /// The contract version of this document: the client refuses any other.
    schema: u32,
    rounds: Vec<BattleRound>,
    memory_metrics: MemoryMetrics,
}

/// FFI interface to process the battle rounds and return the battle output.
///
/// This is the method which is called from the PHP client in RustBattleEngine.php.
#[no_mangle]
pub extern "C" fn fight_battle_rounds(input_json: *const c_char) -> *mut c_char {
    // A panic must never cross the FFI boundary: it would abort the PHP process. Every failure
    // becomes an error document the client can name.
    let answer = match unsafe { CStr::from_ptr(input_json) }.to_str() {
        Ok(input_str) => answer_to(input_str),
        Err(_) => error_document("input is not valid UTF-8"),
    };

    CString::new(answer).unwrap_or_default().into_raw()
}

/// The version of the contract, readable before any battle is fought.
#[no_mangle]
pub extern "C" fn battle_engine_abi_version() -> u32 {
    ABI_VERSION
}

/// Gives back to Rust the string that `fight_battle_rounds` handed out.
///
/// The output used to leak on every battle. The pointer must come from `fight_battle_rounds`;
/// a null pointer is ignored.
#[no_mangle]
pub extern "C" fn free_battle_output(output: *mut c_char) {
    if output.is_null() {
        return;
    }

    unsafe {
        drop(CString::from_raw(output));
    }
}

/// The JSON answer to a JSON input: the battle output, or an error document.
fn answer_to(input_str: &str) -> String {
    let battle_input: BattleInput = match serde_json::from_str(input_str) {
        Ok(input) => input,
        Err(erreur) => return error_document(&format!("input does not parse: {}", erreur)),
    };

    if battle_input.seed == Some(0) {
        return error_document("seed 0 is refused: it would leave the generator at zero forever");
    }

    if battle_input.schema != ABI_VERSION {
        return error_document(&format!(
            "input schema {} is not the contract version {} this library speaks",
            battle_input.schema, ABI_VERSION
        ));
    }

    match serde_json::to_string(&process_battle_rounds(battle_input)) {
        Ok(json) => json,
        Err(erreur) => error_document(&format!("output does not serialize: {}", erreur)),
    }
}

/// An error the client can read: it carries the version so the mismatch names itself.
fn error_document(message: &str) -> String {
    serde_json::json!({
        "schema": ABI_VERSION,
        "error": message,
    })
    .to_string()
}

/// Process the battle rounds and return the battle output.
fn process_battle_rounds(input: BattleInput) -> BattleOutput {
    let mut peak_memory = 0;
    let mut rounds = Vec::new();

    // Build fleet metadata maps for ownership tracking
    let mut attacker_fleet_metadata: HashMap<u32, HashMap<i16, BattleUnitInfo>> = HashMap::new();
    let mut attacker_fleet_owners: HashMap<u32, u32> = HashMap::new();
    for fleet in &input.attacker_fleets {
        attacker_fleet_metadata.insert(fleet.fleet_mission_id, fleet.units.clone());
        attacker_fleet_owners.insert(fleet.fleet_mission_id, fleet.owner_id);
    }

    let mut defender_fleet_metadata: HashMap<u32, HashMap<i16, BattleUnitInfo>> = HashMap::new();
    let mut defender_fleet_owners: HashMap<u32, u32> = HashMap::new();
    for fleet in &input.defender_fleets {
        defender_fleet_metadata.insert(fleet.fleet_mission_id, fleet.units.clone());
        defender_fleet_owners.insert(fleet.fleet_mission_id, fleet.owner_id);
    }

    // Create individual ships from provided battle unit info which contains the amount
    let mut attacker_units = expand_fleets(&input.attacker_fleets);
    let mut defender_units = expand_fleets(&input.defender_fleets);

    // One source for the whole battle: seeded when the client asked for a replayable one.
    let mut draws: Box<dyn Draws> = match input.seed {
        Some(seed) => Box::new(SeededDraws { state: seed }),
        None => Box::new(SystemDraws { rng: rand::thread_rng() }),
    };

    // Track peak memory usage for debugging purposes
    update_peak_memory(&mut peak_memory);

    // Fight up to 6 rounds
    for _ in 0..6 {
        if attacker_units.is_empty() || defender_units.is_empty() {
            break;
        }

        let mut round = BattleRound {
            attacker_ships: HashMap::new(),
            defender_ships: HashMap::new(),
            attacker_losses: HashMap::new(),
            defender_losses: HashMap::new(),
            attacker_losses_in_round: HashMap::new(),
            defender_losses_in_round: HashMap::new(),
            absorbed_damage_attacker: 0.0,
            absorbed_damage_defender: 0.0,
            full_strength_attacker: 0.0,
            full_strength_defender: 0.0,
            hits_attacker: 0,
            hits_defender: 0,
            attacker_fleet_results: HashMap::new(),
            defender_fleet_results: HashMap::new(),
            attacker_losses_in_round_per_fleet: HashMap::new(),
            defender_losses_in_round_per_fleet: HashMap::new(),
            hits_per_attacker_fleet: HashMap::new(),
            damage_per_attacker_fleet: HashMap::new(),
        };

        // Merge all fleet units for the metadata lookup (needed for combat calculations)
        let mut attacker_units_metadata: HashMap<i16, BattleUnitInfo> = HashMap::new();
        for fleet_units in attacker_fleet_metadata.values() {
            for (unit_id, unit_info) in fleet_units {
                attacker_units_metadata.insert(*unit_id, unit_info.clone());
            }
        }

        let mut defender_units_metadata: HashMap<i16, BattleUnitInfo> = HashMap::new();
        for fleet_units in defender_fleet_metadata.values() {
            for (unit_id, unit_info) in fleet_units {
                defender_units_metadata.insert(*unit_id, unit_info.clone());
            }
        }

        // Process combat
        process_combat(&mut attacker_units, &mut defender_units, &mut round, &attacker_units_metadata, &defender_units_metadata, true, draws.as_mut());
        process_combat(&mut defender_units, &mut attacker_units, &mut round, &defender_units_metadata, &attacker_units_metadata, false, draws.as_mut());

        // Cleanup round
        cleanup_round(&mut round, &mut attacker_units, &mut defender_units, &attacker_units_metadata, &defender_units_metadata);

        // Update round statistics
        round.attacker_ships = compress_units(&attacker_units);
        round.defender_ships = compress_units(&defender_units);

        // Calculate accumulated losses
        calculate_losses(&mut round, &attacker_units_metadata, &defender_units_metadata);

        // Calculate per-fleet results
        calculate_fleet_results(&mut round, &attacker_units, &defender_units, &attacker_fleet_metadata, &defender_fleet_metadata, &attacker_fleet_owners, &defender_fleet_owners);

        rounds.push(round);

         // Track peak memory usage for debugging purposes
        update_peak_memory(&mut peak_memory);
    }

    BattleOutput {
        schema: ABI_VERSION,
        rounds,
        memory_metrics: MemoryMetrics {
            peak_memory,
        },
    }
}

/// Expand fleet inputs into individual unit instances with ownership tracking.
fn expand_fleets<F: FleetInput>(fleets: &Vec<F>) -> Vec<BattleUnitInstance> {
    let mut expanded = Vec::new();

    // Fleets in ascending mission id, so that the order the client listed them in changes
    // nothing: a permutation of the input must fight the same battle.
    let mut ordered: Vec<&F> = fleets.iter().collect();
    ordered.sort_by_key(|fleet| fleet.get_fleet_mission_id());

    for fleet in ordered {
        // Canonical order: a HashMap iterates in an order that changes from one run to the next,
        // and two runs fed the same draws would not pick the same targets.
        let mut units: Vec<&BattleUnitInfo> = fleet.get_units().values().collect();
        units.sort_by_key(|unit| unit.unit_id);

        for unit in units {
            for _ in 0..unit.amount {
                expanded.push(BattleUnitInstance {
                    unit_id: unit.unit_id.clone(),
                    fleet_mission_id: fleet.get_fleet_mission_id(),
                    owner_id: fleet.get_owner_id(),
                    current_shield_points: unit.shield_points,
                    current_hull_plating: unit.hull_plating
                });
            }
        }
    }
    expanded
}

/// Trait for fleet input structures.
trait FleetInput {
    fn get_fleet_mission_id(&self) -> u32;
    fn get_owner_id(&self) -> u32;
    fn get_units(&self) -> &HashMap<i16, BattleUnitInfo>;
}

impl FleetInput for AttackerFleetInput {
    fn get_fleet_mission_id(&self) -> u32 {
        self.fleet_mission_id
    }

    fn get_owner_id(&self) -> u32 {
        self.owner_id
    }

    fn get_units(&self) -> &HashMap<i16, BattleUnitInfo> {
        &self.units
    }
}

impl FleetInput for DefenderFleetInput {
    fn get_fleet_mission_id(&self) -> u32 {
        self.fleet_mission_id
    }

    fn get_owner_id(&self) -> u32 {
        self.owner_id
    }

    fn get_units(&self) -> &HashMap<i16, BattleUnitInfo> {
        &self.units
    }
}

/// Compress individual unit instances into a single unit metadata object which stores the amount of units
/// instead of having a separate object for each unit. This is for only passing data about total amount
/// of units per type.
fn compress_units(units: &Vec<BattleUnitInstance>) -> HashMap<i16, BattleUnitCount> {
    units.iter()
        // Loop over all units and count the amount of units per unit_id.
        .fold(HashMap::new(), |mut counts, unit| {
            // Increment count for each unit_id
            *counts.entry(unit.unit_id).or_insert(0) += 1;
            counts
        })
        .into_iter()
        // Convert counts hashmap to expected BattleUnitCount hashmap
        .map(|(unit_id, count)| {
            (unit_id, BattleUnitCount {
                unit_id,
                amount: count,
            })
        })
        .collect()
}

/// Compress individual unit instances into per-fleet results.
fn compress_fleet_results(units: &Vec<BattleUnitInstance>, fleet_mission_id: u32, _owner_id: u32, initial_units: &HashMap<i16, BattleUnitInfo>) -> (HashMap<i16, BattleUnitCount>, HashMap<i16, BattleUnitCount>, HashMap<i16, BattleUnitCount>) {
    // Filter units by fleet
    let fleet_units: Vec<&BattleUnitInstance> = units.iter()
        .filter(|u| u.fleet_mission_id == fleet_mission_id)
        .collect();

    // Count survivors by unit type
    let mut units_result: HashMap<i16, BattleUnitCount> = HashMap::new();
    for unit in &fleet_units {
        increment_battle_unit_count_amount(&mut units_result, unit.unit_id, 1);
    }

    // Build units_start from initial metadata
    let mut units_start: HashMap<i16, BattleUnitCount> = HashMap::new();
    for (unit_id, unit_info) in initial_units {
        units_start.insert(*unit_id, BattleUnitCount {
            unit_id: *unit_id,
            amount: unit_info.amount,
        });
    }

    // Calculate losses
    let mut units_lost: HashMap<i16, BattleUnitCount> = HashMap::new();
    for (unit_id, start_unit) in &units_start {
        let result_amount = units_result.get(unit_id).map(|u| u.amount).unwrap_or(0);
        if start_unit.amount > result_amount {
            units_lost.insert(*unit_id, BattleUnitCount {
                unit_id: *unit_id,
                amount: start_unit.amount - result_amount,
            });
        }
    }

    (units_start, units_result, units_lost)
}

/// Simulates combat for a single round between two groups of units.
///
/// # Why:
/// This function handles the core mechanics of combat by calculating damage, updating
/// unit health, and determining if a unit can attack again (via rapidfire). It also
/// updates statistics for the battle round to reflect the results.
///
/// # Parameters:
/// - `attackers`: Units attacking in this phase.
/// - `defenders`: Units being attacked in this phase.
/// - `round`: Stores round statistics, such as hits and absorbed damage.
/// - `attacker_unit_metadata`: Metadata for attacker units to determine damage, rapidfire, etc.
/// - `defender_unit_metadata`: Metadata for defender units to determine max shield points etc.
/// - `is_attacker`: Whether the current phase is attacker-to-defender or vice versa.
fn process_combat(
    attackers: &mut Vec<BattleUnitInstance>,
    defenders: &mut Vec<BattleUnitInstance>,
    round: &mut BattleRound,
    attacker_unit_metadata: &HashMap<i16, BattleUnitInfo>,
    defender_unit_metadata: &HashMap<i16, BattleUnitInfo>,
    is_attacker: bool,
    draws: &mut dyn Draws,
) {
    for attacker in attackers.iter() {
        let mut continue_attacking = true;

        // Get metadata of the attacking unit.
        let attacker_metadata = attacker_unit_metadata.get(&attacker.unit_id).unwrap();
        let damage = attacker_metadata.attack_power;

        while continue_attacking {
            continue_attacking = false;

            // Select a random defender as a target
            let target_idx = draw_index(draws, defenders.len());
            let target = &mut defenders[target_idx];

            // Get metadata of the defending unit.
            let target_metadata = defender_unit_metadata.get(&target.unit_id).unwrap();

            // Check if the damage is less than 1% of the target's shield points. If so,
            // attack is negated.
            if damage < (0.01 * target_metadata.shield_points) {
                continue
            }

            // Apply damage to shields first, then hull plating
            let mut shield_absorption = 0.0;
            if target.current_shield_points > 0.0 {
                if damage <= target.current_shield_points {
                    shield_absorption = damage;
                    target.current_shield_points -= damage;
                } else {
                    shield_absorption = target.current_shield_points;
                    target.current_hull_plating -= damage - target.current_shield_points;
                    target.current_shield_points = 0.0;
                }
            } else {
                target.current_hull_plating -= damage;
            }

            // If hull integrity < 70%, then unit can explode randomly. Roll dice to see if it does.
            // The ratio is computed in f64 from exact operands, as PHP computes it: an f32 division
            // rounds differently near the bound, and the two engines could disagree on an explosion.
            let hull_ratio = target.current_hull_plating as f64 / target_metadata.hull_plating as f64;
            if hull_ratio < 0.7 {
                let explosion_chance = (1.0 - hull_ratio) * 100.0;
                if draw_explodes(draws, explosion_chance) {
                    // Unit explodes, set current hull plating and shield points to 0.
                    target.current_hull_plating = 0.0;
                    target.current_shield_points = 0.0;
                }
            }

            // Update round statistics for hits and damage absorbed
            if is_attacker {
                round.hits_attacker += 1;
                round.full_strength_attacker += damage as f64;
                round.absorbed_damage_defender += shield_absorption as f64;
                *round.hits_per_attacker_fleet.entry(attacker.fleet_mission_id).or_insert(0) += 1;
                *round.damage_per_attacker_fleet.entry(attacker.fleet_mission_id).or_insert(0.0) += damage as f64;
            } else {
                round.hits_defender += 1;
                round.full_strength_defender += damage as f64;
                round.absorbed_damage_attacker += shield_absorption as f64;
            }

            // Check if the current unit has rapidfire against the target unit. If so, then
            // roll dice to see if the current unit can attack again.
            continue_attacking = if let Some(rapidfire_amount) = attacker_metadata.rapidfire.get(&target.unit_id) {
                // Rapidfire chance is calculated as 100 - (100 / amount). For example:
                // - rapidfire amount of 4 means 100 - (100 / 4) = 75% chance.
                // - rapidfire amount of 10 means 100 - (100 / 10) = 90% chance.
                // - rapidfire amount of 33 means 100 - (100 / 33) = 96.97%
                let chance = 100.0 / *rapidfire_amount as f64;
                let rounded_chance = (chance * 100.0).floor() / 100.0;
                let rapidfire_chance = 100.0 - rounded_chance;

                // The same draw as the PHP engine: a hundredth of a percent, at most the chance.
                draw_rapidfire(draws, rapidfire_chance)
            } else {
                false
            }
        }
    }
}

/// Clean up the round after all units have attacked each other.
///
/// This method handles:
/// - Removing destroyed units from the attacker and defender unit arrays.
/// - Rolling dice for hull integrity < 70% of original if the unit is also destroyed.
/// - Applying shield regeneration.
/// - Calculate the total damage dealt by the attacker and defender and calculate shield absorption stats.
fn cleanup_round(
    round: &mut BattleRound,
    attackers: &mut Vec<BattleUnitInstance>,
    defenders: &mut Vec<BattleUnitInstance>,
    units_metadata_attacker: &HashMap<i16, BattleUnitInfo>,
    units_metadata_defender: &HashMap<i16, BattleUnitInfo>,
) {
    // -------
    // Cleanup attacker units.
    // -------
    // First remove destroyed units.
    attackers.retain(|unit| {
        // Check if unit is fully destroyed.
        if unit.current_hull_plating <= 0.0 {
            increment_battle_unit_count_amount(&mut round.attacker_losses_in_round, unit.unit_id, 1);
            increment_battle_unit_count_amount(round.attacker_losses_in_round_per_fleet.entry(unit.fleet_mission_id).or_default(), unit.unit_id, 1);
            return false;
        }

        true
    });

    // Then update shields in separate pass
    for unit in attackers.iter_mut() {
        let unit_metadata = units_metadata_attacker.get(&unit.unit_id).unwrap();
        unit.current_shield_points = unit_metadata.shield_points;
    }

    // -------
    // Cleanup defender units.
    // -------
    // First remove destroyed units.
    defenders.retain(|unit| {
        // Check if unit is fully destroyed.
        if unit.current_hull_plating <= 0.0 {
            increment_battle_unit_count_amount(&mut round.defender_losses_in_round, unit.unit_id, 1);
            increment_battle_unit_count_amount(round.defender_losses_in_round_per_fleet.entry(unit.fleet_mission_id).or_default(), unit.unit_id, 1);
            return false;
        }

        true
    });

    // Then update shields in separate pass for remaining units.
    for unit in defenders.iter_mut() {
        let unit_metadata = units_metadata_defender.get(&unit.unit_id).unwrap();
        unit.current_shield_points = unit_metadata.shield_points;
    }
}

/// Calculate the losses for the attacker and defender in this round compared to the starting
/// units before the battle.
fn calculate_losses(
    round: &mut BattleRound,
    initial_attacker: &HashMap<i16, BattleUnitInfo>,
    initial_defender: &HashMap<i16, BattleUnitInfo>,
) {
    // Calculate losses by comparing current counts with initial counts
    for (_, unit) in initial_attacker {
        let initial_count = unit.amount;
        let current_count = round.attacker_ships.get(&unit.unit_id).map(|unit| unit.amount).unwrap_or(0);

        if current_count < initial_count {
            let loss_amount = initial_count - current_count;
            increment_battle_unit_count_amount(&mut round.attacker_losses, unit.unit_id, loss_amount);
        }
    }

    // Do the same for defender
    for (_, unit) in initial_defender {
        let initial_count = unit.amount;
        let current_count = round.defender_ships.get(&unit.unit_id).map(|unit| unit.amount).unwrap_or(0);

        if current_count < initial_count {
            let loss_amount = initial_count - current_count;
            increment_battle_unit_count_amount(&mut round.defender_losses, unit.unit_id, loss_amount);
        }
    }
}

/// Calculate per-fleet results for attackers and defenders.
fn calculate_fleet_results(
    round: &mut BattleRound,
    attacker_units: &Vec<BattleUnitInstance>,
    defender_units: &Vec<BattleUnitInstance>,
    attacker_fleets: &HashMap<u32, HashMap<i16, BattleUnitInfo>>,
    defender_fleets: &HashMap<u32, HashMap<i16, BattleUnitInfo>>,
    attacker_fleet_owners: &HashMap<u32, u32>,
    defender_fleet_owners: &HashMap<u32, u32>,
) {
    // Calculate attacker fleet results
    for (&fleet_mission_id, initial_units) in attacker_fleets {
        let owner_id = *attacker_fleet_owners.get(&fleet_mission_id).unwrap_or(&0);

        let (units_start, units_result, units_lost) =
            compress_fleet_results(attacker_units, fleet_mission_id, owner_id, initial_units);

        round.attacker_fleet_results.insert(fleet_mission_id, AttackerFleetResult {
            fleet_mission_id,
            owner_id,
            units_start,
            units_result,
            units_lost,
        });
    }

    // Calculate defender fleet results
    for (&fleet_mission_id, initial_units) in defender_fleets {
        let owner_id = *defender_fleet_owners.get(&fleet_mission_id).unwrap_or(&0);

        let (units_start, units_result, units_lost) =
            compress_fleet_results(defender_units, fleet_mission_id, owner_id, initial_units);

        round.defender_fleet_results.insert(fleet_mission_id, DefenderFleetResult {
            fleet_mission_id,
            owner_id,
            units_start,
            units_result,
            units_lost,
        });
    }
}

/// Helper method to increment the amount property of a BattleUnitCount struct.
fn increment_battle_unit_count_amount(hash_map: &mut HashMap<i16, BattleUnitCount>, unit_id: i16, amount_to_increment: u32) {
    let count = hash_map.entry(unit_id).or_insert(BattleUnitCount {
        unit_id,
        amount: 0,
    });
    count.amount += amount_to_increment;
}

/// Update the peak memory usage statistics. Only used for debugging purposes.
fn update_peak_memory(current_peak: &mut u64) {
    if let Some(usage) = memory_stats() {
        *current_peak = (*current_peak).max(usage.physical_mem as u64 / 1024);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn unit(unit_id: i16, amount: u32, attack: f32, shield: f32, hull: f32) -> (i16, BattleUnitInfo) {
        (
            unit_id,
            BattleUnitInfo {
                unit_id,
                amount,
                attack_power: attack,
                shield_points: shield,
                hull_plating: hull,
                rapidfire: HashMap::new(),
            },
        )
    }

    /// A garrison of rocket launchers, a reinforcement of light fighters, and an attack that
    /// bites into both without wiping either before several rounds.
    fn a_battle_with_a_garrison_and_a_reinforcement() -> BattleInput {
        BattleInput {
            schema: ABI_VERSION,
            attacker_fleets: vec![AttackerFleetInput {
                fleet_mission_id: 4242,
                owner_id: 1,
                units: HashMap::from([unit(204, 150, 50.0, 10.0, 400.0), unit(206, 20, 400.0, 50.0, 2700.0)]),
            }],
            defender_fleets: vec![
                DefenderFleetInput {
                    fleet_mission_id: 0,
                    owner_id: 2,
                    units: HashMap::from([unit(401, 150, 80.0, 20.0, 200.0)]),
                },
                DefenderFleetInput {
                    fleet_mission_id: 777,
                    owner_id: 5,
                    units: HashMap::from([unit(204, 60, 50.0, 10.0, 400.0)]),
                },
            ],
        }
    }

    fn total(units: &HashMap<i16, BattleUnitCount>) -> HashMap<i16, u32> {
        units.iter().map(|(id, count)| (*id, count.amount)).collect()
    }

    fn sum_per_fleet(per_fleet: &HashMap<u32, HashMap<i16, BattleUnitCount>>) -> HashMap<i16, u32> {
        let mut somme: HashMap<i16, u32> = HashMap::new();
        for units in per_fleet.values() {
            for (id, count) in units {
                *somme.entry(*id).or_insert(0) += count.amount;
            }
        }
        somme
    }

    #[test]
    fn every_loss_of_every_round_is_attributed_to_a_fleet_on_both_sides() {
        let output = process_battle_rounds(a_battle_with_a_garrison_and_a_reinforcement());

        assert!(output.rounds.len() > 1, "the battle lasted one round: nothing would distinguish a per-round attribution from a final one");

        let mut garrison_lost = 0;
        let mut reinforcement_lost = 0;

        for round in &output.rounds {
            assert_eq!(total(&round.defender_losses_in_round), sum_per_fleet(&round.defender_losses_in_round_per_fleet), "defending attributions do not add up to the defending losses");
            assert_eq!(total(&round.attacker_losses_in_round), sum_per_fleet(&round.attacker_losses_in_round_per_fleet), "attacking attributions do not add up to the attacking losses");

            garrison_lost += round.defender_losses_in_round_per_fleet.get(&0).map(|u| u.values().map(|c| c.amount).sum::<u32>()).unwrap_or(0);
            reinforcement_lost += round.defender_losses_in_round_per_fleet.get(&777).map(|u| u.values().map(|c| c.amount).sum::<u32>()).unwrap_or(0);
        }

        // Both fleets lost something, otherwise "everything to the garrison" would pass too.
        assert!(garrison_lost > 0, "the garrison lost nothing");
        assert!(reinforcement_lost > 0, "the reinforcement lost nothing");

        // The cumulative attribution is exactly what the last round's fleet result records.
        let last = output.rounds.last().unwrap();
        let recorded: u32 = last.defender_fleet_results.get(&777).unwrap().units_lost.values().map(|c| c.amount).sum();
        assert_eq!(recorded, reinforcement_lost, "the losses attributed round by round to the reinforcement are not the losses its fleet result records");
    }

    #[test]
    fn hits_and_damage_per_attacker_fleet_add_up_to_the_round_totals() {
        let output = process_battle_rounds(a_battle_with_a_garrison_and_a_reinforcement());

        for round in &output.rounds {
            let hits: u32 = round.hits_per_attacker_fleet.values().sum();
            let damage: f64 = round.damage_per_attacker_fleet.values().sum();
            assert_eq!(hits, round.hits_attacker);
            assert!((damage - round.full_strength_attacker).abs() < 0.5);
        }
    }

    #[test]
    fn the_output_names_the_contract_version() {
        let output = process_battle_rounds(a_battle_with_a_garrison_and_a_reinforcement());
        assert_eq!(ABI_VERSION, output.schema);
        assert_eq!(ABI_VERSION, battle_engine_abi_version());
    }

    #[test]
    fn an_input_of_another_version_is_refused_with_a_readable_document() {
        let mut input = a_battle_with_a_garrison_and_a_reinforcement();
        input.schema = 1;

        let answer: serde_json::Value = serde_json::from_str(&answer_to(&serde_json::to_string(&input).unwrap())).unwrap();

        assert_eq!(ABI_VERSION, answer["schema"]);
        assert!(answer["error"].as_str().unwrap().contains("input schema 1"));
        assert!(answer.get("rounds").is_none());
    }

    #[test]
    fn an_input_that_does_not_parse_is_refused_without_a_panic() {
        let answer: serde_json::Value = serde_json::from_str(&answer_to("{not json")).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("does not parse"));
    }

    #[test]
    fn the_output_can_be_handed_out_and_freed_repeatedly() {
        let input = CString::new(serde_json::to_string(&a_battle_with_a_garrison_and_a_reinforcement()).unwrap()).unwrap();

        for _ in 0..3 {
            let output = fight_battle_rounds(input.as_ptr());
            let text = unsafe { CStr::from_ptr(output) }.to_str().unwrap().to_owned();
            assert!(text.contains("\"rounds\""));
            free_battle_output(output);
        }

        free_battle_output(std::ptr::null_mut());
    }

    #[test]
    fn the_same_seed_fights_the_same_battle_and_the_seed_zero_is_refused() {
        let mut input = a_battle_with_a_garrison_and_a_reinforcement();
        input.seed = Some(20260904);

        // Values, not strings: two HashMaps of one process iterate in different orders, and two
        // equal outputs could serialize to two different strings.
        let first = serde_json::to_value(&process_battle_rounds(a_seeded(&input))).unwrap();
        let second = serde_json::to_value(&process_battle_rounds(a_seeded(&input))).unwrap();
        assert_eq!(first, second, "two battles fed the same seed differ");

        input.seed = Some(0);
        let answer: serde_json::Value = serde_json::from_str(&answer_to(&serde_json::to_string(&input).unwrap())).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("seed 0"));
    }

    #[test]
    fn a_permutation_of_the_fleets_fights_the_same_battle() {
        let mut input = a_battle_with_a_garrison_and_a_reinforcement();
        input.seed = Some(77);
        let straight = serde_json::to_value(&process_battle_rounds(a_seeded(&input))).unwrap();

        input.defender_fleets.reverse();
        let reversed = serde_json::to_value(&process_battle_rounds(a_seeded(&input))).unwrap();

        assert_eq!(straight, reversed, "the order the fleets were listed in changed the battle");
    }

    #[test]
    fn the_xorshift_matches_the_php_sequence() {
        // Computed by hand from the algorithm; the PHP test asserts the same three values.
        let mut draws = SeededDraws { state: 1 };
        assert_eq!(270369, draws.next());
        assert_eq!(67634689, draws.next());
        assert_eq!(2647435461, draws.next());
    }

    fn a_seeded(input: &BattleInput) -> BattleInput {
        serde_json::from_str(&serde_json::to_string(input).unwrap()).unwrap()
    }

    #[test]
    fn the_expansion_order_is_canonical() {
        let input = a_battle_with_a_garrison_and_a_reinforcement();
        let first: Vec<(i16, u32)> = expand_fleets(&input.attacker_fleets).iter().map(|u| (u.unit_id, u.fleet_mission_id)).collect();
        let second: Vec<(i16, u32)> = expand_fleets(&input.attacker_fleets).iter().map(|u| (u.unit_id, u.fleet_mission_id)).collect();

        assert_eq!(first, second);
        assert_eq!(204, first[0].0, "units are not expanded in ascending unit id order");
        assert_eq!(206, first[150].0);
    }
}
