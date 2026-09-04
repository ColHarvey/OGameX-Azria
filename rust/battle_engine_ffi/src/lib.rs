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
//! per-fleet result reporting. **Every unit keeps the technologies of its own fleet**: two fleets
//! fielding the same ship type fight with their own attack, shields and hull.
//!
//! # The contract
//! The input and the output name their version (`ABI_VERSION`). No panic crosses the FFI boundary:
//! every failure — a null pointer, an input that does not parse, a version this library does not
//! speak, duplicated fleet identities, or an internal panic — comes back as an error document.
use serde::{Deserialize, Serialize};
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::panic::{catch_unwind, AssertUnwindSafe};
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

/// The garrison's fleet mission id: the stationary forces of the target body have no mission.
const GARRISON: u64 = 0;

// ---------------------------------------------------------------------------------------------
// Draws: a semantic band, not a bare sequence of numbers.
// ---------------------------------------------------------------------------------------------

/// The source of every draw a battle makes: which target, whether a damaged hull explodes,
/// whether rapidfire is granted.
///
/// Each draw names its **kind and bound**. The PHP engine draws through the same three methods
/// (`BattleDraws` in `app/GameMissions/BattleEngine/Draws`), with the same formulas; two engines
/// fed the same seed fight the same battle, and a seeded source keeps a digest of everything it
/// drew so the parity bench can check that both engines consumed the band **entirely and
/// identically** — a bare sequence of numbers could shift silently and still look like parity.
trait Draws {
    /// A uniform position among `count` candidates, from 0 to `count - 1`.
    fn target_index(&mut self, count: usize) -> usize;
    /// A whole percent from 0 to 100, for the explosion of a damaged hull.
    fn explosion_percent(&mut self) -> u32;
    /// A hundredth of a percent from 1 to 10000, for rapidfire.
    fn rapidfire_centipercent(&mut self) -> u32;
    /// How many draws were made, and a digest of them — `None` for the system's randomness.
    fn journal(&self) -> Option<DrawJournal>;
}

/// What a seeded source drew: how many times, and a digest over kind, bound and value.
#[derive(Serialize, Deserialize, Clone, PartialEq, Debug)]
struct DrawJournal {
    count: u64,
    /// FNV-1a over `kind:bound:value;` for every draw, in order, as a hexadecimal string.
    digest: String,
}

/// The game's draws: the system's randomness. No journal — nothing to compare it to.
struct SystemDraws {
    rng: rand::rngs::ThreadRng,
}

impl Draws for SystemDraws {
    fn target_index(&mut self, count: usize) -> usize {
        (self.rng.random::<u32>() as usize) % count
    }

    fn explosion_percent(&mut self) -> u32 {
        self.rng.random::<u32>() % 101
    }

    fn rapidfire_centipercent(&mut self) -> u32 {
        1 + self.rng.random::<u32>() % 10000
    }

    fn journal(&self) -> Option<DrawJournal> {
        None
    }
}

/// Replayable draws: a thirty-two bit xorshift from a seed, identical to PHP's `SeededDraws`,
/// with a journal of every draw made.
struct SeededDraws {
    state: u32,
    count: u64,
    digest: u64,
}

impl SeededDraws {
    fn new(seed: u32) -> Self {
        SeededDraws { state: seed, count: 0, digest: FNV_OFFSET }
    }

    fn next(&mut self) -> u32 {
        let mut x = self.state;
        x ^= x << 13;
        x ^= x >> 17;
        x ^= x << 5;
        self.state = x;
        x
    }

    fn record(&mut self, kind: &str, bound: u64, value: u64) {
        self.count += 1;
        for byte in format!("{}:{}:{};", kind, bound, value).bytes() {
            self.digest ^= byte as u64;
            self.digest = self.digest.wrapping_mul(FNV_PRIME);
        }
    }
}

const FNV_OFFSET: u64 = 0xcbf29ce484222325;
const FNV_PRIME: u64 = 0x100000001b3;

impl Draws for SeededDraws {
    fn target_index(&mut self, count: usize) -> usize {
        let value = (self.next() as usize) % count;
        self.record("target", count as u64, value as u64);
        value
    }

    fn explosion_percent(&mut self) -> u32 {
        let value = self.next() % 101;
        self.record("explosion", 101, value as u64);
        value
    }

    fn rapidfire_centipercent(&mut self) -> u32 {
        let value = 1 + self.next() % 10000;
        self.record("rapidfire", 10000, value as u64);
        value
    }

    fn journal(&self) -> Option<DrawJournal> {
        Some(DrawJournal { count: self.count, digest: format!("{:016x}", self.digest) })
    }
}

/// Does a damaged hull explode? A whole percent, strictly under the chance — PHP's `rand(0, 100) < chance`.
fn draw_explodes(draws: &mut dyn Draws, chance: f64) -> bool {
    (draws.explosion_percent() as f64) < chance
}

/// Is rapidfire granted? A hundredth of a percent, at most the chance — PHP's `random_int(1, 10000) / 100 <= chance`.
fn draw_rapidfire(draws: &mut dyn Draws, chance: f64) -> bool {
    (draws.rapidfire_centipercent() as f64) / 100.0 <= chance
}

// ---------------------------------------------------------------------------------------------
// The contract: input and output documents.
// ---------------------------------------------------------------------------------------------

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
    /// A test seam: panics inside the simulation, after the input was decoded. It exists so that a
    /// real FFI test can prove that a panic comes back as an error document instead of aborting the
    /// process. A game client never sets it.
    #[serde(default)]
    provoke_panic: bool,
    attacker_fleets: Vec<AttackerFleetInput>,
    defender_fleets: Vec<DefenderFleetInput>,
}

/// Input structure for a single attacker fleet.
#[derive(Serialize, Deserialize, Clone)]
struct AttackerFleetInput {
    fleet_mission_id: u64,
    owner_id: u64,
    units: HashMap<i16, BattleUnitInfo>,
}

/// Input structure for a single defender fleet.
#[derive(Serialize, Deserialize, Clone)]
struct DefenderFleetInput {
    fleet_mission_id: u64,
    owner_id: u64,
    units: HashMap<i16, BattleUnitInfo>,
}

/// Battle unit info which is provided by the PHP client.
///
/// This contains static information about the input units and their amount, computed by the
/// client with the technologies of the fleet's own owner.
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

/// The identity of a unit type **within a fleet**: two fleets fielding the same type keep their
/// own technologies, so the type alone identifies nothing.
type FleetUnitKey = (u64, i16);

/// Battle unit instance which is used to keep track of individual units and their current health during battle.
#[derive(Serialize, Deserialize, Clone)]
struct BattleUnitInstance {
    unit_id: i16,
    fleet_mission_id: u64,
    owner_id: u64,
    current_shield_points: f32,
    current_hull_plating: f32,
}

impl BattleUnitInstance {
    fn key(&self) -> FleetUnitKey {
        (self.fleet_mission_id, self.unit_id)
    }
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
    attacker_fleet_results: HashMap<u64, FleetResult>,
    /// Per-fleet defender results keyed by fleet_mission_id.
    defender_fleet_results: HashMap<u64, FleetResult>,
    /// Unit losses of THIS round per attacker fleet_mission_id.
    attacker_losses_in_round_per_fleet: HashMap<u64, HashMap<i16, BattleUnitCount>>,
    /// Unit losses of THIS round per defender fleet_mission_id (0 is the garrison).
    defender_losses_in_round_per_fleet: HashMap<u64, HashMap<i16, BattleUnitCount>>,
    /// Hits made by each attacker fleet this round.
    hits_per_attacker_fleet: HashMap<u64, u32>,
    /// Damage dealt by each attacker fleet this round.
    damage_per_attacker_fleet: HashMap<u64, f64>,
}

/// Result for a single fleet, on either side.
#[derive(Serialize, Deserialize, Clone)]
struct FleetResult {
    fleet_mission_id: u64,
    owner_id: u64,
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
#[derive(Serialize, Deserialize)]
pub struct BattleOutput {
    /// The contract version of this document: the client refuses any other.
    schema: u32,
    rounds: Vec<BattleRound>,
    /// What the seeded source drew, for the parity bench; absent for the system's randomness.
    #[serde(skip_serializing_if = "Option::is_none")]
    draws: Option<DrawJournal>,
    memory_metrics: MemoryMetrics,
}

// ---------------------------------------------------------------------------------------------
// The FFI boundary. No panic crosses it.
// ---------------------------------------------------------------------------------------------

/// FFI interface to process the battle rounds and return the battle output.
///
/// This is the method which is called from the PHP client in RustBattleEngine.php. A null pointer
/// is refused before it is read; an invalid non-null pointer remains, by nature, a precondition of
/// the C caller. Everything else — parse, simulate, serialize — runs under `catch_unwind`, and an
/// unwind comes back as an error document.
#[no_mangle]
pub extern "C" fn fight_battle_rounds(input_json: *const c_char) -> *mut c_char {
    let answer = if input_json.is_null() {
        error_document("input pointer is null")
    } else {
        match catch_unwind(AssertUnwindSafe(|| match unsafe { CStr::from_ptr(input_json) }.to_str() {
            Ok(input_str) => answer_to(input_str),
            Err(_) => error_document("input is not valid UTF-8"),
        })) {
            Ok(answer) => answer,
            Err(_) => error_document("the simulation panicked; the panic was caught at the FFI boundary"),
        }
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

    if battle_input.schema != ABI_VERSION {
        return error_document(&format!(
            "input schema {} is not the contract version {} this library speaks",
            battle_input.schema, ABI_VERSION
        ));
    }

    if battle_input.seed == Some(0) {
        return error_document("seed 0 is refused: it would leave the generator at zero forever");
    }

    if let Err(reason) = refuse_duplicated_identities(&battle_input) {
        return error_document(&reason);
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

/// Two fleets with the same identity would overwrite each other in every map keyed by it.
///
/// Exactly one defending garrison (id 0); at most one ephemeral attacker (id 0); every other id
/// unique within its side. A persistent combat never sends an ephemeral attacker — its roster is
/// made of enrolments, and an enrolment names a mission — but this library serves the instant
/// path too, where the counter-espionage probe fights without a mission.
fn refuse_duplicated_identities(input: &BattleInput) -> Result<(), String> {
    let mut seen: HashMap<u64, u32> = HashMap::new();
    for fleet in &input.attacker_fleets {
        *seen.entry(fleet.fleet_mission_id).or_insert(0) += 1;
    }
    for (id, count) in &seen {
        if *count > 1 {
            return Err(format!("attacker fleet id {} appears {} times", id, count));
        }
    }

    let mut seen: HashMap<u64, u32> = HashMap::new();
    for fleet in &input.defender_fleets {
        *seen.entry(fleet.fleet_mission_id).or_insert(0) += 1;
    }
    for (id, count) in &seen {
        if *count > 1 {
            return Err(format!("defender fleet id {} appears {} times", id, count));
        }
    }
    if !input.defender_fleets.is_empty() && !seen.contains_key(&GARRISON) {
        return Err("no defending garrison (fleet id 0) among the defender fleets".to_string());
    }

    Ok(())
}

// ---------------------------------------------------------------------------------------------
// The simulation.
// ---------------------------------------------------------------------------------------------

/// Process the battle rounds and return the battle output.
fn process_battle_rounds(input: BattleInput) -> BattleOutput {
    let mut peak_memory = 0;
    let mut rounds = Vec::new();

    if input.provoke_panic {
        panic!("panic provoked by the test seam, after the input was decoded");
    }

    // **Every unit type keeps the technologies of its own fleet.** The statistics are keyed by
    // (fleet, type), never by type alone: two fleets fielding the same ship type would otherwise
    // overwrite each other, in an order that changes from one run to the next.
    let attacker_stats = stats_by_fleet_unit(&input.attacker_fleets);
    let defender_stats = stats_by_fleet_unit(&input.defender_fleets);

    let attacker_fleets: Vec<(u64, u64, HashMap<i16, BattleUnitInfo>)> = input
        .attacker_fleets
        .iter()
        .map(|fleet| (fleet.fleet_mission_id, fleet.owner_id, fleet.units.clone()))
        .collect();
    let defender_fleets: Vec<(u64, u64, HashMap<i16, BattleUnitInfo>)> = input
        .defender_fleets
        .iter()
        .map(|fleet| (fleet.fleet_mission_id, fleet.owner_id, fleet.units.clone()))
        .collect();

    // The starting totals of a side per type **add** the fleets: a type present in two fleets
    // starts with the sum, not with whichever fleet was looked at last.
    let attacker_start_totals = totals_by_unit(&attacker_fleets);
    let defender_start_totals = totals_by_unit(&defender_fleets);

    // Create individual ships from provided battle unit info which contains the amount
    let mut attacker_units = expand_fleets(&input.attacker_fleets);
    let mut defender_units = expand_fleets(&input.defender_fleets);

    // One source for the whole battle: seeded when the client asked for a replayable one.
    let mut draws: Box<dyn Draws> = match input.seed {
        Some(seed) => Box::new(SeededDraws::new(seed)),
        None => Box::new(SystemDraws { rng: rand::rng() }),
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

        // Process combat
        process_combat(&mut attacker_units, &mut defender_units, &mut round, &attacker_stats, &defender_stats, true, draws.as_mut());
        process_combat(&mut defender_units, &mut attacker_units, &mut round, &defender_stats, &attacker_stats, false, draws.as_mut());

        // Cleanup round
        cleanup_round(&mut round, &mut attacker_units, &mut defender_units, &attacker_stats, &defender_stats);

        // Update round statistics
        round.attacker_ships = compress_units(&attacker_units);
        round.defender_ships = compress_units(&defender_units);

        // Calculate accumulated losses
        calculate_losses(&mut round, &attacker_start_totals, &defender_start_totals);

        // Calculate per-fleet results
        for (fleet_mission_id, owner_id, initial_units) in &attacker_fleets {
            round.attacker_fleet_results.insert(*fleet_mission_id, fleet_result(&attacker_units, *fleet_mission_id, *owner_id, initial_units));
        }
        for (fleet_mission_id, owner_id, initial_units) in &defender_fleets {
            round.defender_fleet_results.insert(*fleet_mission_id, fleet_result(&defender_units, *fleet_mission_id, *owner_id, initial_units));
        }

        rounds.push(round);

        // Track peak memory usage for debugging purposes
        update_peak_memory(&mut peak_memory);
    }

    BattleOutput {
        schema: ABI_VERSION,
        rounds,
        draws: draws.journal(),
        memory_metrics: MemoryMetrics {
            peak_memory,
        },
    }
}

/// The immutable statistics of every unit type, keyed by (fleet, type).
fn stats_by_fleet_unit<F: FleetInput>(fleets: &Vec<F>) -> HashMap<FleetUnitKey, BattleUnitInfo> {
    let mut stats = HashMap::new();
    for fleet in fleets {
        for (unit_id, info) in fleet.get_units() {
            stats.insert((fleet.get_fleet_mission_id(), *unit_id), info.clone());
        }
    }
    stats
}

/// The starting amount of every unit type on a side, summed across its fleets.
fn totals_by_unit(fleets: &Vec<(u64, u64, HashMap<i16, BattleUnitInfo>)>) -> HashMap<i16, u32> {
    let mut totals = HashMap::new();
    for (_, _, units) in fleets {
        for (unit_id, info) in units {
            *totals.entry(*unit_id).or_insert(0) += info.amount;
        }
    }
    totals
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
                    unit_id: unit.unit_id,
                    fleet_mission_id: fleet.get_fleet_mission_id(),
                    owner_id: fleet.get_owner_id(),
                    current_shield_points: unit.shield_points,
                    current_hull_plating: unit.hull_plating,
                });
            }
        }
    }
    expanded
}

/// Trait for fleet input structures.
trait FleetInput {
    fn get_fleet_mission_id(&self) -> u64;
    fn get_owner_id(&self) -> u64;
    fn get_units(&self) -> &HashMap<i16, BattleUnitInfo>;
}

impl FleetInput for AttackerFleetInput {
    fn get_fleet_mission_id(&self) -> u64 {
        self.fleet_mission_id
    }

    fn get_owner_id(&self) -> u64 {
        self.owner_id
    }

    fn get_units(&self) -> &HashMap<i16, BattleUnitInfo> {
        &self.units
    }
}

impl FleetInput for DefenderFleetInput {
    fn get_fleet_mission_id(&self) -> u64 {
        self.fleet_mission_id
    }

    fn get_owner_id(&self) -> u64 {
        self.owner_id
    }

    fn get_units(&self) -> &HashMap<i16, BattleUnitInfo> {
        &self.units
    }
}

/// Compress individual unit instances into a count per unit type.
fn compress_units(units: &Vec<BattleUnitInstance>) -> HashMap<i16, BattleUnitCount> {
    let mut counts: HashMap<i16, BattleUnitCount> = HashMap::new();
    for unit in units {
        increment_battle_unit_count_amount(&mut counts, unit.unit_id, 1);
    }
    counts
}

/// The result of one fleet at the end of a round: what it started with, what survives, what it lost.
fn fleet_result(units: &Vec<BattleUnitInstance>, fleet_mission_id: u64, owner_id: u64, initial_units: &HashMap<i16, BattleUnitInfo>) -> FleetResult {
    let mut units_result: HashMap<i16, BattleUnitCount> = HashMap::new();
    for unit in units.iter().filter(|u| u.fleet_mission_id == fleet_mission_id) {
        increment_battle_unit_count_amount(&mut units_result, unit.unit_id, 1);
    }

    let mut units_start: HashMap<i16, BattleUnitCount> = HashMap::new();
    for (unit_id, unit_info) in initial_units {
        units_start.insert(*unit_id, BattleUnitCount { unit_id: *unit_id, amount: unit_info.amount });
    }

    let mut units_lost: HashMap<i16, BattleUnitCount> = HashMap::new();
    for (unit_id, start_unit) in &units_start {
        let result_amount = units_result.get(unit_id).map(|u| u.amount).unwrap_or(0);
        if start_unit.amount > result_amount {
            units_lost.insert(*unit_id, BattleUnitCount { unit_id: *unit_id, amount: start_unit.amount - result_amount });
        }
    }

    FleetResult { fleet_mission_id, owner_id, units_start, units_result, units_lost }
}

/// Simulates combat for a single phase between two groups of units.
///
/// Every unit fires with the statistics of **its own fleet**, and every target defends with the
/// statistics of its own fleet: the lookups are keyed by (fleet, type).
fn process_combat(
    attackers: &mut Vec<BattleUnitInstance>,
    defenders: &mut Vec<BattleUnitInstance>,
    round: &mut BattleRound,
    attacker_stats: &HashMap<FleetUnitKey, BattleUnitInfo>,
    defender_stats: &HashMap<FleetUnitKey, BattleUnitInfo>,
    is_attacker: bool,
    draws: &mut dyn Draws,
) {
    for attacker in attackers.iter() {
        let mut continue_attacking = true;

        // The statistics exist by construction: every instance was expanded from these fleets.
        let attacker_metadata = attacker_stats.get(&attacker.key()).expect("an attacking unit without its fleet's statistics");
        let damage = attacker_metadata.attack_power;

        while continue_attacking {
            continue_attacking = false;

            // Select a random defender as a target
            let target_idx = draws.target_index(defenders.len());
            let target = &mut defenders[target_idx];

            let target_metadata = defender_stats.get(&target.key()).expect("a targeted unit without its fleet's statistics");

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

            // If hull integrity < 70%, then unit can explode randomly. The ratio is computed in
            // f64 from exact operands, as PHP computes it.
            let hull_ratio = target.current_hull_plating as f64 / target_metadata.hull_plating as f64;
            if hull_ratio < 0.7 {
                let explosion_chance = (1.0 - hull_ratio) * 100.0;
                if draw_explodes(draws, explosion_chance) {
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

            // Rapidfire: chance = 100 - floor((100 / amount) * 100) / 100, the PHP formula.
            continue_attacking = if let Some(rapidfire_amount) = attacker_metadata.rapidfire.get(&target.unit_id) {
                let chance = 100.0 / *rapidfire_amount as f64;
                let rounded_chance = (chance * 100.0).floor() / 100.0;
                let rapidfire_chance = 100.0 - rounded_chance;

                draw_rapidfire(draws, rapidfire_chance)
            } else {
                false
            }
        }
    }
}

/// Clean up the round after all units have attacked each other: remove destroyed units,
/// attribute every loss to its fleet, regenerate shields with each fleet's own statistics.
fn cleanup_round(
    round: &mut BattleRound,
    attackers: &mut Vec<BattleUnitInstance>,
    defenders: &mut Vec<BattleUnitInstance>,
    attacker_stats: &HashMap<FleetUnitKey, BattleUnitInfo>,
    defender_stats: &HashMap<FleetUnitKey, BattleUnitInfo>,
) {
    attackers.retain(|unit| {
        if unit.current_hull_plating <= 0.0 {
            increment_battle_unit_count_amount(&mut round.attacker_losses_in_round, unit.unit_id, 1);
            increment_battle_unit_count_amount(round.attacker_losses_in_round_per_fleet.entry(unit.fleet_mission_id).or_default(), unit.unit_id, 1);
            return false;
        }

        true
    });

    for unit in attackers.iter_mut() {
        let unit_metadata = attacker_stats.get(&unit.key()).expect("a surviving attacking unit without its fleet's statistics");
        unit.current_shield_points = unit_metadata.shield_points;
    }

    defenders.retain(|unit| {
        if unit.current_hull_plating <= 0.0 {
            increment_battle_unit_count_amount(&mut round.defender_losses_in_round, unit.unit_id, 1);
            increment_battle_unit_count_amount(round.defender_losses_in_round_per_fleet.entry(unit.fleet_mission_id).or_default(), unit.unit_id, 1);
            return false;
        }

        true
    });

    for unit in defenders.iter_mut() {
        let unit_metadata = defender_stats.get(&unit.key()).expect("a surviving defending unit without its fleet's statistics");
        unit.current_shield_points = unit_metadata.shield_points;
    }
}

/// Calculate the accumulated losses of each side, from the starting totals summed across fleets.
fn calculate_losses(
    round: &mut BattleRound,
    attacker_start_totals: &HashMap<i16, u32>,
    defender_start_totals: &HashMap<i16, u32>,
) {
    for (unit_id, initial_count) in attacker_start_totals {
        let current_count = round.attacker_ships.get(unit_id).map(|unit| unit.amount).unwrap_or(0);
        if current_count < *initial_count {
            increment_battle_unit_count_amount(&mut round.attacker_losses, *unit_id, initial_count - current_count);
        }
    }

    for (unit_id, initial_count) in defender_start_totals {
        let current_count = round.defender_ships.get(unit_id).map(|unit| unit.amount).unwrap_or(0);
        if current_count < *initial_count {
            increment_battle_unit_count_amount(&mut round.defender_losses, *unit_id, initial_count - current_count);
        }
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

    fn attackers(fleets: Vec<(u64, u64, Vec<(i16, BattleUnitInfo)>)>) -> Vec<AttackerFleetInput> {
        fleets.into_iter().map(|(id, owner, units)| AttackerFleetInput { fleet_mission_id: id, owner_id: owner, units: units.into_iter().collect() }).collect()
    }

    fn defenders(fleets: Vec<(u64, u64, Vec<(i16, BattleUnitInfo)>)>) -> Vec<DefenderFleetInput> {
        fleets.into_iter().map(|(id, owner, units)| DefenderFleetInput { fleet_mission_id: id, owner_id: owner, units: units.into_iter().collect() }).collect()
    }

    fn input(attacker_fleets: Vec<AttackerFleetInput>, defender_fleets: Vec<DefenderFleetInput>, seed: Option<u32>) -> BattleInput {
        BattleInput { schema: ABI_VERSION, seed, provoke_panic: false, attacker_fleets, defender_fleets }
    }

    /// A garrison of rocket launchers, a reinforcement of light fighters, and an attack that
    /// bites into both without wiping either before several rounds.
    fn a_battle_with_a_garrison_and_a_reinforcement() -> BattleInput {
        input(
            attackers(vec![(4242, 1, vec![unit(204, 150, 50.0, 10.0, 400.0), unit(206, 20, 400.0, 50.0, 2700.0)])]),
            defenders(vec![
                (GARRISON, 2, vec![unit(401, 150, 80.0, 20.0, 200.0)]),
                (777, 5, vec![unit(204, 60, 50.0, 10.0, 400.0)]),
            ]),
            None,
        )
    }

    fn total(units: &HashMap<i16, BattleUnitCount>) -> HashMap<i16, u32> {
        units.iter().map(|(id, count)| (*id, count.amount)).collect()
    }

    fn sum_per_fleet(per_fleet: &HashMap<u64, HashMap<i16, BattleUnitCount>>) -> HashMap<i16, u32> {
        let mut somme: HashMap<i16, u32> = HashMap::new();
        for units in per_fleet.values() {
            for (id, count) in units {
                *somme.entry(*id).or_insert(0) += count.amount;
            }
        }
        somme
    }

    fn a_seeded(input: &BattleInput) -> BattleInput {
        serde_json::from_str(&serde_json::to_string(input).unwrap()).unwrap()
    }

    /// The battle as a value — rounds and draws, without the memory metric, which is a debug
    /// measurement that changes from one run to the next and says nothing about the battle.
    fn as_value(input: &BattleInput) -> serde_json::Value {
        let mut value = serde_json::to_value(&process_battle_rounds(a_seeded(input))).unwrap();
        value.as_object_mut().unwrap().remove("memory_metrics");
        value
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

            garrison_lost += round.defender_losses_in_round_per_fleet.get(&GARRISON).map(|u| u.values().map(|c| c.amount).sum::<u32>()).unwrap_or(0);
            reinforcement_lost += round.defender_losses_in_round_per_fleet.get(&777).map(|u| u.values().map(|c| c.amount).sum::<u32>()).unwrap_or(0);
        }

        assert!(garrison_lost > 0, "the garrison lost nothing");
        assert!(reinforcement_lost > 0, "the reinforcement lost nothing");

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

    /// The same ship type in the garrison and in a reinforcement, with different technologies:
    /// each keeps its own. The reinforcement's fighters have shields no attacking shot can dent,
    /// the garrison's have none — the garrison loses fighters, the reinforcement loses none, and
    /// the side's starting total is the sum of both.
    #[test]
    fn two_defending_fleets_with_the_same_type_keep_their_own_technologies() {
        let mut battle = input(
            attackers(vec![(4242, 1, vec![unit(204, 200, 50.0, 10.0, 400.0)])]),
            defenders(vec![
                (GARRISON, 2, vec![unit(204, 40, 50.0, 0.0, 400.0)]),
                (777, 5, vec![unit(204, 40, 50.0, 100_000.0, 400.0)]),
            ]),
            Some(3),
        );

        for _ in 0..3 {
            let output = process_battle_rounds(a_seeded(&battle));
            let last = output.rounds.last().expect("no round was fought");

            let garrison_lost: u32 = last.defender_fleet_results.get(&GARRISON).unwrap().units_lost.values().map(|c| c.amount).sum();
            let reinforcement_lost: u32 = last.defender_fleet_results.get(&777).unwrap().units_lost.values().map(|c| c.amount).sum();

            assert!(garrison_lost > 0, "the unshielded garrison lost nothing");
            assert_eq!(0, reinforcement_lost, "the reinforcement lost fighters despite shields no shot can dent: it fought with another fleet's technologies");

            let side_start_total = 80;
            let survivors = last.defender_ships.get(&204).map(|c| c.amount).unwrap_or(0);
            let side_losses = last.defender_losses.get(&204).map(|c| c.amount).unwrap_or(0);
            assert_eq!(side_start_total, survivors + side_losses, "the side's losses are not computed from the sum of both fleets");

            battle.defender_fleets.reverse();
        }
    }

    /// The symmetric case on the attacking side: two attackers with the same type and different
    /// technologies. The shielded fleet loses nothing.
    #[test]
    fn two_attacking_fleets_with_the_same_type_keep_their_own_technologies() {
        let battle = input(
            attackers(vec![
                (1000, 1, vec![unit(204, 40, 50.0, 0.0, 400.0)]),
                (1001, 3, vec![unit(204, 40, 50.0, 100_000.0, 400.0)]),
            ]),
            defenders(vec![(GARRISON, 2, vec![unit(401, 200, 80.0, 20.0, 200.0)])]),
            Some(5),
        );

        let output = process_battle_rounds(a_seeded(&battle));
        let last = output.rounds.last().expect("no round was fought");

        let unshielded_lost: u32 = last.attacker_fleet_results.get(&1000).unwrap().units_lost.values().map(|c| c.amount).sum();
        let shielded_lost: u32 = last.attacker_fleet_results.get(&1001).unwrap().units_lost.values().map(|c| c.amount).sum();

        assert!(unshielded_lost > 0, "the unshielded attacker lost nothing");
        assert_eq!(0, shielded_lost, "the shielded attacker lost fighters: it fought with another fleet's technologies");
    }

    #[test]
    fn every_permutation_of_the_fleets_fights_the_same_battle_with_the_same_draws() {
        let mut battle = input(
            attackers(vec![
                (1000, 1, vec![unit(204, 150, 50.0, 10.0, 400.0), unit(206, 20, 400.0, 50.0, 2700.0)]),
                (1001, 3, vec![unit(205, 30, 150.0, 25.0, 1000.0)]),
            ]),
            defenders(vec![
                (GARRISON, 2, vec![unit(401, 100, 80.0, 20.0, 200.0)]),
                (2000, 5, vec![unit(204, 60, 50.0, 10.0, 400.0)]),
                (2001, 6, vec![unit(205, 25, 150.0, 25.0, 1000.0)]),
            ]),
            Some(77),
        );

        let reference = as_value(&battle);
        let reference_journal = process_battle_rounds(a_seeded(&battle)).draws.expect("no journal for a seeded battle");

        // Three other orders: attackers reversed, defenders reversed, both reversed.
        battle.attacker_fleets.reverse();
        assert_eq!(reference, as_value(&battle), "reversing the attackers changed the battle");
        battle.defender_fleets.reverse();
        assert_eq!(reference, as_value(&battle), "reversing both sides changed the battle");
        battle.attacker_fleets.reverse();
        assert_eq!(reference, as_value(&battle), "reversing the defenders changed the battle");

        assert_eq!(reference_journal, process_battle_rounds(a_seeded(&battle)).draws.unwrap(), "the same battle did not consume the same draws");
        assert!(reference_journal.count > 0);
    }

    #[test]
    fn the_output_names_the_contract_version() {
        let output = process_battle_rounds(a_battle_with_a_garrison_and_a_reinforcement());
        assert_eq!(ABI_VERSION, output.schema);
        assert_eq!(ABI_VERSION, battle_engine_abi_version());
        assert!(output.draws.is_none(), "an unseeded battle carries a journal");
    }

    #[test]
    fn an_input_of_another_version_is_refused_with_a_readable_document() {
        let mut battle = a_battle_with_a_garrison_and_a_reinforcement();
        battle.schema = 1;

        let answer: serde_json::Value = serde_json::from_str(&answer_to(&serde_json::to_string(&battle).unwrap())).unwrap();

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
    fn duplicated_identities_and_a_missing_garrison_are_refused() {
        let mut battle = a_battle_with_a_garrison_and_a_reinforcement();
        battle.defender_fleets.push(battle.defender_fleets[1].clone());
        let answer: serde_json::Value = serde_json::from_str(&answer_to(&serde_json::to_string(&battle).unwrap())).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("defender fleet id 777 appears 2 times"));

        let mut battle = a_battle_with_a_garrison_and_a_reinforcement();
        battle.attacker_fleets.push(battle.attacker_fleets[0].clone());
        let answer: serde_json::Value = serde_json::from_str(&answer_to(&serde_json::to_string(&battle).unwrap())).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("attacker fleet id 4242 appears 2 times"));

        let mut battle = a_battle_with_a_garrison_and_a_reinforcement();
        battle.defender_fleets.remove(0);
        let answer: serde_json::Value = serde_json::from_str(&answer_to(&serde_json::to_string(&battle).unwrap())).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("no defending garrison"));
    }

    #[test]
    fn a_null_input_and_a_panic_after_decoding_come_back_as_error_documents() {
        let output = fight_battle_rounds(std::ptr::null());
        let answer: serde_json::Value = serde_json::from_str(unsafe { CStr::from_ptr(output) }.to_str().unwrap()).unwrap();
        free_battle_output(output);
        assert!(answer["error"].as_str().unwrap().contains("null"));

        let mut battle = a_battle_with_a_garrison_and_a_reinforcement();
        battle.provoke_panic = true;
        let text = CString::new(serde_json::to_string(&battle).unwrap()).unwrap();
        let output = fight_battle_rounds(text.as_ptr());
        let answer: serde_json::Value = serde_json::from_str(unsafe { CStr::from_ptr(output) }.to_str().unwrap()).unwrap();
        free_battle_output(output);
        assert!(answer["error"].as_str().unwrap().contains("panicked"), "a panic after decoding did not come back as an error document");
        assert_eq!(ABI_VERSION, answer["schema"]);
    }

    #[test]
    fn the_output_can_be_handed_out_and_freed_repeatedly() {
        let text = CString::new(serde_json::to_string(&a_battle_with_a_garrison_and_a_reinforcement()).unwrap()).unwrap();

        for _ in 0..3 {
            let output = fight_battle_rounds(text.as_ptr());
            let answer = unsafe { CStr::from_ptr(output) }.to_str().unwrap().to_owned();
            assert!(answer.contains("\"rounds\""));
            free_battle_output(output);
        }

        free_battle_output(std::ptr::null_mut());
    }

    #[test]
    fn the_same_seed_fights_the_same_battle_and_the_seed_zero_is_refused() {
        let mut battle = a_battle_with_a_garrison_and_a_reinforcement();
        battle.seed = Some(20260904);

        assert_eq!(as_value(&battle), as_value(&battle), "two battles fed the same seed differ");

        battle.seed = Some(0);
        let answer: serde_json::Value = serde_json::from_str(&answer_to(&serde_json::to_string(&battle).unwrap())).unwrap();
        assert!(answer["error"].as_str().unwrap().contains("seed 0"));
    }

    #[test]
    fn the_xorshift_matches_the_php_sequence() {
        // Computed by hand from the algorithm; the PHP test asserts the same three values.
        let mut draws = SeededDraws::new(1);
        assert_eq!(270369, draws.next());
        assert_eq!(67634689, draws.next());
        assert_eq!(2647435461, draws.next());
    }

    /// The digest names kind, bound and value: the same raw numbers consumed as other kinds, or in
    /// another order, give another digest. The PHP `SeededDraws` computes the same digest.
    #[test]
    fn the_journal_digest_distinguishes_kinds_and_order() {
        let mut first = SeededDraws::new(9);
        first.target_index(13);
        first.explosion_percent();
        let mut second = SeededDraws::new(9);
        second.explosion_percent();
        second.target_index(13);

        assert_eq!(first.journal().unwrap().count, second.journal().unwrap().count);
        assert_ne!(first.journal().unwrap().digest, second.journal().unwrap().digest, "two different consumptions of the same numbers have the same digest");

        // Pinned against PHP: seed 1, then target(13) = 8, explosion = 39, rapidfire = 5462, whose
        // journal `target:13:8;explosion:101:39;rapidfire:10000:5462;` has this FNV-1a digest.
        let mut pinned = SeededDraws::new(1);
        assert_eq!(8, pinned.target_index(13));
        assert_eq!(39, pinned.explosion_percent());
        assert_eq!(5462, pinned.rapidfire_centipercent());
        let journal = pinned.journal().unwrap();
        assert_eq!(3, journal.count);
        assert_eq!("3b66012af9879de4", journal.digest, "the digest differs from the one PHP computes for the same band");
    }

    #[test]
    fn the_expansion_order_is_canonical() {
        let battle = a_battle_with_a_garrison_and_a_reinforcement();
        let first: Vec<(i16, u64)> = expand_fleets(&battle.attacker_fleets).iter().map(|u| (u.unit_id, u.fleet_mission_id)).collect();
        let second: Vec<(i16, u64)> = expand_fleets(&battle.attacker_fleets).iter().map(|u| (u.unit_id, u.fleet_mission_id)).collect();

        assert_eq!(first, second);
        assert_eq!(204, first[0].0, "units are not expanded in ascending unit id order");
        assert_eq!(206, first[150].0);
    }
}
