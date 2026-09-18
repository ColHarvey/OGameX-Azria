<?php

namespace OGame\Lifeforms\Catalogue;

use ReflectionClass;

/**
 * Les codes d effet du catalogue des formes de vie.
 *
 * Un code nomme ce qu un bonus change ; sa cible eventuelle (unite, recherche, batiment, classe) vit
 * dans `LifeformBonus::$target`. La liste est fermee : `LifeformCatalogueTest` refuse un code qui n y
 * figure pas, pour qu une faute de frappe dans les donnees ne devienne pas un effet silencieusement
 * ignore.
 *
 * Les effets sont **decrits ici, appliques ailleurs** : chaque code trouvera son point d application
 * dans les tranches suivantes (production, proprietes d unites, expeditions, phalange, epaves...).
 */
final class LifeformEffect
{
    // Demographie (batiments)
    public const string LIVING_SPACE = 'living_space';
    public const string LIVING_SPACE_PERCENT = 'living_space_percent';
    public const string GROWTH_RATE = 'growth_rate';
    public const string GROWTH_RATE_PERCENT = 'growth_rate_percent';
    public const string FOOD_PRODUCTION = 'food_production';
    public const string FOOD_PRODUCTION_PERCENT = 'food_production_percent';
    public const string FOOD_STORAGE = 'food_storage';
    public const string FOOD_STORAGE_PERCENT = 'food_storage_percent';
    public const string FOOD_CONSUMPTION_REDUCTION = 'food_consumption_reduction';
    public const string TIER2_CAPACITY = 'tier2_capacity';
    public const string TIER3_CAPACITY = 'tier3_capacity';
    public const string POPULATION_PROTECTION = 'population_protection';

    // Economie de la planete (batiments)
    public const string METAL_PRODUCTION = 'metal_production';
    public const string CRYSTAL_PRODUCTION = 'crystal_production';
    public const string DEUTERIUM_PRODUCTION = 'deuterium_production';
    public const string ALL_PRODUCTION = 'all_production';
    public const string ENERGY_PRODUCTION = 'energy_production';
    public const string ENERGY_CONSUMPTION_REDUCTION = 'energy_consumption_reduction';
    public const string MINE_COST_REDUCTION = 'mine_cost_reduction';
    public const string PLANET_FIELDS = 'planet_fields';
    public const string MOON_CHANCE = 'moon_chance';
    public const string DEBRIS_RECOVERY = 'debris_recovery';
    public const string WRECK_RECOVERY = 'wreck_recovery';
    public const string SHIP_BUILD_TIME_REDUCTION = 'ship_build_time_reduction';

    // Les formes de vie elles-memes
    public const string LF_RESEARCH_COST_REDUCTION = 'lf_research_cost_reduction';
    public const string LF_RESEARCH_TIME_REDUCTION = 'lf_research_time_reduction';
    public const string LF_BUILDING_COST_REDUCTION = 'lf_building_cost_reduction';
    public const string LF_BUILDING_TIME_REDUCTION = 'lf_building_time_reduction';
    public const string LF_TECH_BONUS = 'lf_tech_bonus';
    public const string SLOT_REQUIREMENT_REDUCTION = 'slot_requirement_reduction';
    public const string DISCOVERY_DURATION_REDUCTION = 'discovery_duration_reduction';

    // Recherches et batiments classiques (technologies)
    public const string RESEARCH_TIME_REDUCTION = 'research_time_reduction';
    public const string RESEARCH_COST_REDUCTION = 'research_cost_reduction';
    public const string BUILDING_COST_REDUCTION = 'building_cost_reduction';
    public const string BUILDING_TIME_REDUCTION = 'building_time_reduction';
    public const string STORAGE_CAPACITY = 'storage_capacity';
    public const string CRAWLER_ENERGY_REDUCTION = 'crawler_energy_reduction';
    public const string CRAWLER_EFFICIENCY = 'crawler_efficiency';

    // Flottes
    public const string SHIP_STATS = 'ship_stats';
    public const string DEFENCE_STATS = 'defence_stats';
    public const string SHIP_SPEED = 'ship_speed';
    public const string CIVIL_SHIP_SPEED = 'civil_ship_speed';
    public const string CIVIL_SHIP_CARGO = 'civil_ship_cargo';
    public const string FUEL_CONSUMPTION_REDUCTION = 'fuel_consumption_reduction';
    public const string RECALL_FUEL_REFUND = 'recall_fuel_refund';
    public const string PHALANX_RANGE = 'phalanx_range';

    // Expeditions
    public const string EXPEDITION_FLEET_LOSS_REDUCTION = 'expedition_fleet_loss_reduction';
    public const string EXPEDITION_SHIPS = 'expedition_ships';
    public const string EXPEDITION_RESOURCES = 'expedition_resources';
    public const string EXPEDITION_SPEED = 'expedition_speed';
    public const string EXPEDITION_DARK_MATTER = 'expedition_dark_matter';

    // Classes
    public const string CLASS_BONUS = 'class_bonus';

    // Un nombre du fichier maitre sans signification etablie.
    public const string UNASSIGNED = 'unassigned';

    /**
     * Un effet dont la valeur est une BAISSE (cout, duree, consommation, exigence) : les fiches l ecrivent avec « − ».
     */
    public static function isReduction(string $code): bool
    {
        return str_ends_with($code, '_reduction') || $code === self::EXPEDITION_FLEET_LOSS_REDUCTION;
    }

    /**
     * Tous les codes connus.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $reflexion = new ReflectionClass(self::class);

        return array_values(array_filter($reflexion->getConstants(), fn ($valeur) => is_string($valeur)));
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::all(), true);
    }
}
