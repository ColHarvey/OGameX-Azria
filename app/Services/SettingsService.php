<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Date;
use OGame\Factories\GameMissionFactory;
use OGame\Models\Setting;

/**
 * Class SettingsService.
 *
 * SettingsService object.
 *
 * @package OGame\Services
 */
class SettingsService
{
    /**
     * Array of setting objects.
     *
     * @var array<string, Setting>
     */
    private array $settings = [];

    /**
     * SettingsService constructor.
     */
    public function __construct()
    {
    }

    /**
     * Load all settings from database and cache locally.
     *
     * @return void
     */
    private function loadFromDatabase(): void
    {
        $settings = Setting::all();
        foreach ($settings as $setting) {
            $this->settings[$setting->key] = $setting;
        }
    }

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param string|int $default
     * @return string
     */
    public function get(string $key, string|int $default = ''): string
    {
        // When a setting is accessed, load everything from database.
        // We do it here instead of in constructor so call to database
        // is only made when something on the page actually accesses
        // a settings value.
        if (empty($this->settings)) {
            $this->loadFromDatabase();
        }

        // If it doesn't exist, return default.
        if (empty($this->settings[$key])) {
            return (string)$default;
        }

        return $this->settings[$key]->value;
    }

    /**
     * Set a setting value by key.
     *
     * @param string $key
     * @param string|int $value
     * @return void
     */
    public function set(string $key, string|int $value): void
    {
        // When a setting is accessed, load everything from database.
        // We do it here instead of in constructor so call to database
        // is only made when something on the page actually accesses
        // a settings value.
        if (empty($this->settings)) {
            $this->loadFromDatabase();
        }

        // Check if to be saved value is actually different from current one.
        $currentValue = $this->get($key, '');
        if (!empty($currentValue) && $currentValue === $value) {
            // To be saved value is same as current value, skip update to prevent unnecessary db call.
            return;
        }

        $updated_setting = Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        $this->settings[$key] = $updated_setting;
    }

    /**
     * Returns the fleet speed setting.
     *
     * @return int
     */
    public function fleetSpeed(): int
    {
        return (int)$this->get('fleet_speed', 1);
    }

    /**
     * Returns the war fleet speed setting.
     *
     * @return int
     */
    public function fleetSpeedWar(): int
    {
        return (int)$this->get('fleet_speed_war', 1);
    }

    /**
     * Returns the holding fleet speed setting.
     *
     * @return int
     */
    public function fleetSpeedHolding(): int
    {
        return (int)$this->get('fleet_speed_holding', 1);
    }

    /**
     * Returns the peaceful fleet speed setting.
     *
     * @return int
     */
    public function fleetSpeedPeaceful(): int
    {
        return (int)$this->get('fleet_speed_peaceful', 1);
    }

    /**
     * Returns the fleet speed setting.
     *
     * @return int
     */
    public function economySpeed(): int
    {
        return (int)$this->get('economy_speed', 1);
    }

    /**
     * Returns the fleet speed setting.
     *
     * @return int
     */
    public function researchSpeed(): int
    {
        return (int)$this->get('research_speed', 1);
    }

    /**
     * Returns the basic income metal setting.
     *
     * @return int
     */
    public function basicIncomeMetal(): int
    {
        return (int)$this->get('basic_income_metal', 30);
    }

    /**
     * Returns the basic income crystal setting.
     *
     * @return int
     */
    public function basicIncomeCrystal(): int
    {
        return (int)$this->get('basic_income_crystal', 15);
    }

    /**
     * Returns the basic income deuterium setting.
     *
     * @return int
     */
    public function basicIncomeDeuterium(): int
    {
        return (int)$this->get('basic_income_deuterium', 0);
    }

    /**
     * Returns the basic income energy setting.
     *
     * @return int
     */
    public function basicIncomeEnergy(): int
    {
        return (int)$this->get('basic_income_energy', 0);
    }

    /**
     * Returns the amount of planets that should be created for a new player
     * upon registration. Defaults to 1.
     *
     * @return int
     */
    public function registrationPlanetAmount(): int
    {
        return (int)$this->get('registration_planet_amount', 1);
    }

    /**
     * Returns the amount of planet fields bonus given upon planet creation.
     *
     * @return int
     */
    public function planetFieldsBonus(): int
    {
        return (int)$this->get('planet_fields_bonus', 0);
    }

    /**
     * Returns the amount of dark matter given for a new player.
     *
     * @return int
     */
    public function darkMatterBonus(): int
    {
        return (int)$this->get('dark_matter_bonus', 8000);
    }

    /**
     * Returns the status of the Alliance Combat System.
     *
     * @return int
     */
    public function allianceCombatSystemOn(): int
    {
        return (int)$this->get('alliance_combat_system_on', 1);
    }

    /**
     * Returns the alliance cooldown period in days.
     * This is the number of days a player must wait after leaving an alliance
     * before they can create or join another alliance.
     *
     * @return int
     */
    public function allianceCooldownDays(): int
    {
        return (int)$this->get('alliance_cooldown_days', 3);
    }

    /**
     * Returns the percentage of debris field generated from destroyed ships.
     *
     * @return int
     */
    public function debrisFieldFromShips(): int
    {
        return (int)$this->get('debris_field_from_ships', 30);
    }

    /**
     * Returns the percentage of debris field generated from destroyed defensive structures.
     *
     * @return int
     */
    public function debrisFieldFromDefense(): int
    {
        return (int)$this->get('debris_field_from_defense', 0);
    }

    /**
     * Returns the minimum resource loss required for wreck field formation.
     *
     * @return int
     */
    public function wreckFieldMinResourcesLoss(): int
    {
        return (int)$this->get('wreck_field_min_resources_loss', 150000);
    }

    /**
     * Returns the minimum fleet percentage that must be destroyed for wreck field formation.
     *
     * @return int
     */
    public function wreckFieldMinFleetPercentage(): int
    {
        return (int)$this->get('wreck_field_min_fleet_percentage', 5);
    }

    /**
     * Returns the lifetime of wreck fields in hours.
     *
     * @return int
     */
    public function wreckFieldLifetimeHours(): int
    {
        return (int)$this->get('wreck_field_lifetime_hours', 72);
    }

    /**
     * Returns the maximum repair time in hours for wreck fields.
     *
     * @return int
     */
    public function wreckFieldRepairMaxHours(): int
    {
        return (int)$this->get('wreck_field_repair_max_hours', 12);
    }

    /**
     * Returns the minimum repair time in minutes for wreck fields.
     *
     * @return int
     */
    public function wreckFieldRepairMinMinutes(): int
    {
        return (int)$this->get('wreck_field_repair_min_minutes', 30);
    }

    /**
     * Returns the status of Deuterium in debris fields.
     *
     * @return int
     */
    public function debrisFieldDeuteriumOn(): int
    {
        return (int)$this->get('debris_field_deuterium_on', 0);
    }

    /**
     * Returns the maximum percentage chance of a moon forming after battle.
     *
     * @return int
     */
    public function maximumMoonChance(): int
    {
        return (int)$this->get('maximum_moon_chance', 20);
    }

    /**
     * Returns the status of ignoring empty systems.
     *
     * @return int
     */
    public function ignoreEmptySystemsOn(): int
    {
        return (int)$this->get('ignore_empty_systems_on', 0);
    }

    /**
     * Returns the status of ignoring inactive systems.
     *
     * @return int
     */
    public function ignoreInactiveSystemsOn(): int
    {
        return (int)$this->get('ignore_inactive_systems_on', 0);
    }

    /**
     * Returns the number of galaxies in the universe.
     *
     * @return int
     */
    public function numberOfGalaxies(): int
    {
        return (int)$this->get('number_of_galaxies', 9);
    }

    /**
     * Returns the name of the universe.
     *
     * @return string
     */
    public function universeName(): string
    {
        return $this->get('universe_name', "Universe");
    }

    /**
     * Returns the battle engine setting.
     *
     * @return string
     */
    public function battleEngine(): string
    {
        return $this->get('battle_engine', 'rust');
    }

    /**
     * Returns the configured attack block end timestamp.
     *
     * @return int
     */
    public function attackBlockUntil(): int
    {
        return (int)$this->get('attack_block_until', 0);
    }

    /**
     * Returns whether the server-wide attack block is currently active.
     *
     * @return bool
     */
    public function attackBlockActive(): bool
    {
        $until = $this->attackBlockUntil();

        return $until > Date::now()->timestamp;
    }

    /**
     * Returns whether the given mission type is blocked by attack block.
     *
     * @param int $missionType
     * @return bool
     */
    public function missionBlockedByAttackBlock(int $missionType): bool
    {
        return $this->attackBlockActive() && GameMissionFactory::getMissionById($missionType, [])->isBlockedByServerAttackBlock();
    }

    /**
     * Returns if expedition failed outcome is enabled.
     *
     * @return bool
     */
    public function expeditionFailedEnabled(): bool
    {
        return (bool)$this->get('expedition_failed', 1);
    }

    /**
     * Returns if expedition failed and delay outcome is enabled.
     *
     * @return bool
     */
    public function expeditionFailedAndDelayEnabled(): bool
    {
        return (bool)$this->get('expedition_failed_and_delay', 1);
    }

    /**
     * Returns if expedition failed and speedup outcome is enabled.
     *
     * @return bool
     */
    public function expeditionFailedAndSpeedupEnabled(): bool
    {
        return (bool)$this->get('expedition_failed_and_speedup', 1);
    }

    /**
     * Returns if expedition gain ships outcome is enabled.
     *
     * @return bool
     */
    public function expeditionGainShipsEnabled(): bool
    {
        return (bool)$this->get('expedition_gain_ships', 1);
    }

    /**
     * Returns if expedition gain dark matter outcome is enabled.
     *
     * @return bool
     */
    public function expeditionGainDarkMatterEnabled(): bool
    {
        return (bool)$this->get('expedition_gain_dark_matter', 1);
    }

    /**
     * Returns if expedition gain resources outcome is enabled.
     *
     * @return bool
     */
    public function expeditionGainResourcesEnabled(): bool
    {
        return (bool)$this->get('expedition_gain_resources', 1);
    }

    /**
     * Returns if expedition gain merchant trade outcome is enabled.
     *
     * @return bool
     */
    public function expeditionGainMerchantTradeEnabled(): bool
    {
        return (bool)$this->get('expedition_gain_merchant_trade', 1);
    }

    /**
     * Returns if expedition gain item outcome is enabled.
     *
     * @return bool
     */
    public function expeditionGainItemEnabled(): bool
    {
        return (bool)$this->get('expedition_gain_item', 1);
    }

    /**
     * Returns if expedition loss of fleet outcome is enabled.
     *
     * @return bool
     */
    public function expeditionLossOfFleetEnabled(): bool
    {
        return (bool)$this->get('expedition_loss_of_fleet', 1);
    }

    /**
     * Returns if expedition battle outcome is enabled.
     *
     * @return bool
     */
    public function expeditionBattleEnabled(): bool
    {
        return (bool)$this->get('expedition_battle', 1);
    }

    /**
     * Returns the defense repair rate percentage (0-100).
     * After a battle, destroyed defenses have this percentage chance of being repaired.
     * Default is 70% as per official game rules.
     *
     * @return int
     */
    public function defenseRepairRate(): int
    {
        return (int)$this->get('defense_repair_rate', 70);
    }

    /**
     * Returns the bonus expedition slots setting.
     *
     * @return int
     */
    public function bonusExpeditionSlots(): int
    {
        return (int)$this->get('bonus_expedition_slots', 0);
    }

    /**
     * Returns the expedition rewards multiplier.
     *
     * @return float
     */
    public function expeditionRewardsMultiplier(): float
    {
        return (float)$this->get('expedition_rewards_multiplier', '1.0');
    }

    /**
     * Returns the expedition resource rewards multiplier.
     *
     * @return float
     */
    public function expeditionRewardMultiplierResources(): float
    {
        return (float)$this->get('expedition_reward_multiplier_resources', '1.0');
    }

    /**
     * Returns the expedition ship rewards multiplier.
     *
     * @return float
     */
    public function expeditionRewardMultiplierShips(): float
    {
        return (float)$this->get('expedition_reward_multiplier_ships', '1.0');
    }

    /**
     * Returns the expedition dark matter rewards multiplier.
     *
     * @return float
     */
    public function expeditionRewardMultiplierDarkMatter(): float
    {
        return (float)$this->get('expedition_reward_multiplier_dark_matter', '1.0');
    }

    /**
     * Returns the expedition item rewards multiplier.
     *
     * @return float
     */
    public function expeditionRewardMultiplierItems(): float
    {
        return (float)$this->get('expedition_reward_multiplier_items', '1.0');
    }

    /**
     * Returns the expedition outcome weight for ships (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightShips(): float
    {
        return (float)$this->get('expedition_weight_ships', '17');
    }

    /**
     * Returns the expedition outcome weight for resources (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightResources(): float
    {
        return (float)$this->get('expedition_weight_resources', '35');
    }

    /**
     * Returns the expedition outcome weight for delay (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightDelay(): float
    {
        return (float)$this->get('expedition_weight_delay', '7.5');
    }

    /**
     * Returns the expedition outcome weight for speedup (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightSpeedup(): float
    {
        return (float)$this->get('expedition_weight_speedup', '2.75');
    }

    /**
     * Returns the expedition outcome weight for nothing/failed (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightNothing(): float
    {
        return (float)$this->get('expedition_weight_nothing', '25');
    }

    /**
     * Returns the expedition outcome weight for black hole/fleet loss (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightBlackHole(): float
    {
        return (float)$this->get('expedition_weight_black_hole', '0.2');
    }

    /**
     * Returns the expedition outcome weight for pirates (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightPirates(): float
    {
        return (float)$this->get('expedition_weight_pirates', '3.0');
    }

    /**
     * Returns the expedition outcome weight for aliens (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightAliens(): float
    {
        return (float)$this->get('expedition_weight_aliens', '1.5');
    }

    /**
     * Returns the expedition outcome weight for dark matter (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightDarkMatter(): float
    {
        return (float)$this->get('expedition_weight_dark_matter', '7.5');
    }

    /**
     * Returns the expedition outcome weight for merchant (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightMerchant(): float
    {
        return (float)$this->get('expedition_weight_merchant', '0.4');
    }

    /**
     * Returns the expedition outcome weight for items (0-100 scale, relative).
     *
     * @return float
     */
    public function expeditionWeightItems(): float
    {
        return (float)$this->get('expedition_weight_items', '0');
    }

    /**
     * Get the Hamill Manoeuvre probability (Light Fighter destroying Deathstar).
     * This is the probability denominator (1 in X chance).
     * Default is 1000 (0.1% chance = 1 in 1000).
     * Setting this to 10 means 10% chance (1 in 10), useful for testing.
     *
     * @return int
     */
    public function hamillManoeuvreChance(): int
    {
        return (int)$this->get('hamill_manoeuvre_chance', 1000);
    }

    /**
     * Returns whether admin users should be visible in highscores.
     * When disabled (default), admins are excluded from highscore rankings entirely.
     * When enabled, admins appear in highscores with orange-highlighted names.
     *
     * @return bool
     */
    public function highscoreAdminVisible(): bool
    {
        return (bool)$this->get('highscore_admin_visible', 0);
    }

    /**
     * Returns the server rules content (BBCode).
     *
     * @return string
     */
    public function rulesContent(): string
    {
        return $this->get('rules_content', '');
    }

    /**
     * Returns the legal content (BBCode).
     *
     * @return string
     */
    public function legalContent(): string
    {
        return $this->get('legal_content', '');
    }

    /**
     * Returns the privacy policy content (BBCode).
     *
     * @return string
     */
    public function privacyPolicyContent(): string
    {
        return $this->get('privacy_policy_content', '');
    }

    /**
     * Returns the terms and conditions content (BBCode).
     *
     * @return string
     */
    public function termsContent(): string
    {
        return $this->get('terms_content', '');
    }

    /**
     * Returns the contact content (BBCode).
     *
     * @return string
     */
    public function contactContent(): string
    {
        return $this->get('contact_content', '');
    }

    /**
     * Returns whether the mission event is switched on by the administrator.
     *
     * L'interrupteur ne suffit pas a rendre l'evenement actif : les bornes de dates sont
     * verifiees separement par EventMissionService::isRunning().
     *
     * @return bool
     */
    public function eventMissionsEnabled(): bool
    {
        return $this->get('event_missions_enabled', '0') === '1';
    }

    /**
     * Returns the mission event start date, formatted Y-m-d, or an empty string.
     *
     * @return string
     */
    public function eventMissionsStart(): string
    {
        return $this->get('event_missions_start', '');
    }

    /**
     * Returns the mission event end date, formatted Y-m-d, or an empty string.
     *
     * @return string
     */
    public function eventMissionsEnd(): string
    {
        return $this->get('event_missions_end', '');
    }

    /**
     * Returns how many missions are drawn for each player each day.
     *
     * @return int
     */
    public function eventMissionsPerDay(): int
    {
        return (int)$this->get('event_missions_per_day', '5');
    }

    /**
     * Returns the tritium step between two event ranks.
     *
     * Les sept rangs sont espaces regulierement : rang N demande pas x N. Avec le pas
     * par defaut on retrouve l echelle du jeu officiel, 1 000 a 7 000.
     *
     * @return int
     */
    public function eventRankStep(): int
    {
        return max(1, (int)$this->get('event_rank_step', '1000'));
    }

    /**
     * Returns the unix timestamp at which the event was switched on, 0 when unknown.
     *
     * @return int
     */
    public function eventMissionsOpenedAt(): int
    {
        return (int)$this->get('event_missions_opened_at', 0);
    }

    // ------------------------------------------------------------------
    // Factions hostiles. Une seule existe a ce jour : les pirates.
    //
    // Regle appliquee sans exception : aucune valeur d'equilibrage qui depend de la
    // progression du serveur n'est ecrite dans le code. Le code porte les regles, les
    // donnees du serveur portent l'echelle, ces reglages portent les commandes.
    // ------------------------------------------------------------------

    /**
     * Returns whether attacks open a combat that lasts instead of resolving on arrival.
     *
     * **Un seul interrupteur, et il vaut non par defaut.** Le deploiement des combats durables se
     * fait en une fois, par decision explicite : ni beta, ni activation progressive, ni canari. Tant
     * qu'il vaut non, une attaque se resout a l'arrivee exactement comme avant, et rien du socle
     * durable ne s'execute — pas une table lue, pas une ligne ecrite.
     */
    public function persistentCombatEnabled(): bool
    {
        return $this->get('persistent_combat_enabled', '0') === '1';
    }

    /**
     * Returns whether the hostile faction system is switched on at all.
     */
    public function npcEnabled(): bool
    {
        return $this->get('npc_enabled', '0') === '1';
    }

    /**
     * Returns whether raids are only decided and logged, never actually sent.
     *
     * La simulation ne gele que l'envoi des flottes : les bases continuent de grandir,
     * sans quoi la semaine d'observation porterait sur un monde fige et ne dirait rien
     * de ce qui se passera reellement.
     */
    public function npcSimulation(): bool
    {
        return $this->get('npc_simulation', '1') === '1';
    }

    /**
     * Returns the active human population below which the fixed threshold is used.
     *
     * En dessous de cet effectif la mediane saute par a-coups des que deux joueurs se
     * croisent : ce n'est pas de la volatilite mais un probleme d'echantillon, et
     * l'amortir ne ferait que retarder le moment ou on s'en apercoit.
     */
    public function npcMinActivePlayers(): int
    {
        return max(1, (int)$this->get('npc_min_active_players', '8'));
    }

    /**
     * Returns the eligibility threshold used while the server is too small for a median.
     */
    public function npcMinScoreFixed(): int
    {
        return max(0, (int)$this->get('npc_min_score_fixed', '25'));
    }

    /**
     * Returns the share of the active human median that forms the eligibility threshold.
     */
    public function npcMedianRatio(): float
    {
        return max(0.01, (float)$this->get('npc_median_ratio', '0.80'));
    }

    /**
     * Returns how many days a freshly registered account is untouchable.
     */
    public function npcNewPlayerDays(): int
    {
        return max(0, (int)$this->get('npc_new_player_days', '14'));
    }

    /**
     * Returns how many days a newly eligible player is only spied on, never raided.
     */
    public function npcSpottedDays(): int
    {
        return max(0, (int)$this->get('npc_spotted_days', '7'));
    }

    /**
     * Returns the floor on the number of pirate bases.
     */
    public function npcBaseCountMin(): int
    {
        return max(0, (int)$this->get('npc_base_count_min', '5'));
    }

    /**
     * Returns the ceiling on the number of pirate bases, swarming included.
     */
    public function npcBaseCountMax(): int
    {
        return max(0, (int)$this->get('npc_base_count_max', '20'));
    }

    /**
     * Returns how many active human players justify one more base.
     */
    public function npcPlayersPerBase(): int
    {
        return max(1, (int)$this->get('npc_players_per_base', '5'));
    }

    /**
     * Returns whether bases develop themselves over time.
     */
    public function npcGrowthEnabled(): bool
    {
        return $this->get('npc_growth_enabled', '1') === '1';
    }

    /**
     * Returns the maturity ceiling, expressed in multiples of the active human median.
     *
     * Sans plafond une base laissee tranquille six mois devient intouchable et le contenu
     * se transforme en decor. Le plafond suit la progression du serveur, donc les bases
     * restent eternellement a portee sans jamais devenir triviales.
     */
    public function npcMaturityRatio(): float
    {
        return max(0.1, (float)$this->get('npc_maturity_ratio', '1.30'));
    }

    /**
     * Returns whether a long-matured base may found a second one.
     */
    public function npcSwarmEnabled(): bool
    {
        return $this->get('npc_swarm_enabled', '0') === '1';
    }

    /**
     * Returns how many days a base must sit at its ceiling before it may swarm.
     */
    public function npcSwarmDelayDays(): int
    {
        return max(1, (int)$this->get('npc_swarm_delay_days', '7'));
    }

    /**
     * Returns the shortest delay before a destroyed base is replaced elsewhere.
     */
    public function npcRespawnMinHours(): int
    {
        return max(0, (int)$this->get('npc_respawn_min_hours', '24'));
    }

    /**
     * Returns the longest delay before a destroyed base is replaced elsewhere.
     */
    public function npcRespawnMaxHours(): int
    {
        return max($this->npcRespawnMinHours(), (int)$this->get('npc_respawn_max_hours', '72'));
    }

    /**
     * Returns the minimum distance in systems between a new base and any human planet.
     */
    public function npcSeedMinDistance(): int
    {
        return max(0, (int)$this->get('npc_seed_min_distance', '15'));
    }

    /**
     * Returns the distance in systems beyond which a base would serve nobody.
     */
    public function npcSeedMaxDistance(): int
    {
        return max($this->npcSeedMinDistance() + 1, (int)$this->get('npc_seed_max_distance', '120'));
    }

    /**
     * Returns the absolute threat ceiling.
     */
    public function npcThreatMax(): int
    {
        return max(1, (int)$this->get('npc_threat_max', '100'));
    }

    /**
     * Returns how many hours pass before one threat point is forgotten.
     */
    public function npcThreatDecayHours(): int
    {
        return max(1, (int)$this->get('npc_threat_decay_hours', '3'));
    }

    /**
     * Returns the threat multiplier applied when the base sits in the player's system.
     */
    public function npcProximitySystem(): float
    {
        return max(1.0, (float)$this->get('npc_proximity_system', '2.0'));
    }

    /**
     * Returns the threat multiplier applied when the base sits in the player's galaxy.
     */
    public function npcProximityGalaxy(): float
    {
        return max(1.0, (float)$this->get('npc_proximity_galaxy', '1.5'));
    }

    /**
     * Returns the minimum delay in hours between two raids against the same player.
     */
    public function npcRaidCooldownHours(): int
    {
        return max(1, (int)$this->get('npc_raid_cooldown_hours', '12'));
    }

    /**
     * Returns how many raids a single player may suffer within a day.
     */
    public function npcMaxRaids24h(): int
    {
        return max(1, (int)$this->get('npc_max_raids_24h', '2'));
    }

    /**
     * Returns the exponent that makes raid power grow slower than the player.
     *
     * La relation doit rester sous-lineaire, sinon doubler sa flotte double le raid et
     * grossir ne protege jamais : le joueur comprend vite que sa flotte ne lui sert a
     * rien contre les pirates, et l'incitation devient de rester faible.
     */
    public function npcPowerExponent(): float
    {
        return min(1.0, max(0.1, (float)$this->get('npc_power_exponent', '0.70')));
    }

    /**
     * Returns the largest multiple of the player's own military power a raid may reach.
     */
    public function npcPowerCeiling(): float
    {
        return max(0.1, (float)$this->get('npc_power_ceiling', '1.20'));
    }

    /**
     * Returns whether a battle against a NPC may create a moon for that NPC.
     */
    public function npcMoonEnabled(): bool
    {
        return $this->get('npc_moon_enabled', '1') === '1';
    }

    /**
     * Returns whether faction rows are shown in the player highscore.
     */
    public function npcHighscoreRows(): bool
    {
        return $this->get('npc_highscore_rows', '1') === '1';
    }

    /**
     * Returns whether a faction without a single base is hidden from the highscore.
     */
    public function npcHighscoreHideEmpty(): bool
    {
        return $this->get('npc_highscore_hide_empty', '1') === '1';
    }

    /**
     * Returns the unix timestamp of the last base creation, 0 when none.
     *
     * Sert de minuterie de reapparition sans table supplementaire : le tick ne recree une
     * base que si le delai tire au sort depuis cette date est ecoule.
     */
    public function npcLastSpawnAt(): int
    {
        return (int)$this->get('npc_last_spawn_at', 0);
    }

    /**
     * Returns whether combats last in time instead of resolving on arrival.
     *
     * **Desactive par defaut, et c'est deliberé.** Tant que ce reglage vaut 0, le jeu garde
     * exactement son comportement actuel : combat instantane a l'arrivee, rapport immediat,
     * retour immediat. Les tables peuvent donc exister en production sans qu'aucun combat ne
     * les utilise, et l'activation est un geste separe du deploiement.
     *
     * @return bool
     */
    public function combatDurationEnabled(): bool
    {
        return (bool)(int)$this->get('combat_duration_enabled', 0);
    }

    /**
     * Returns the pace coefficient: how much combat work is consumed per second.
     *
     * Choisi sur le tableau de calibrage, pas au jugé. Il n'a de sens qu'avec l'amortissement
     * ci-dessous : les deux forment un modele.
     *
     * Ce reglage n'est lu **qu'au demarrage** d'un combat, et la valeur employee est ecrite avec
     * lui. L'ajuster ne touche donc jamais une bataille deja commencee.
     *
     * @return float
     */
    public function combatDurationRate(): float
    {
        return (float)$this->get('combat_duration_rate', 2083);
    }

    /**
     * Returns the root applied to the combat work before converting it to seconds.
     *
     * La regle multiplie quatre grandeurs qui croissent chacune avec la taille des flottes :
     * sans amortissement, le travail s'etale sur onze ordres de grandeur et aucun rythme unique
     * ne convient. La racine cubique comprime cet ecart sans changer l'ordre des batailles.
     *
     * @return float
     */
    public function combatDurationDamping(): float
    {
        return (float)$this->get('combat_duration_damping', 3);
    }

    /**
     * Returns the floor duration of a battle that actually took place.
     *
     * Ne s'applique pas a une bataille sans round — une retraite tactique se resout
     * immediatement.
     *
     * @return int
     */
    public function combatDurationMinimumSeconds(): int
    {
        return (int)$this->get('combat_duration_minimum_seconds', 5);
    }
}
