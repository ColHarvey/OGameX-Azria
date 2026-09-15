<?php

namespace OGame\Lifeforms\Bonuses;

use OGame\Lifeforms\Catalogue\LifeformBonus;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Services\SettingsService;

/**
 * Le resolveur des bonus de formes de vie : ce que les batiments d une planete et les technologies de
 * tout le compte apportent, par effet, plafonne, pret a etre applique.
 *
 * ## Deux portees, comme le jeu officiel
 *
 * - **Les batiments comptent sur leur planete** (raffinerie de cristal Rock'tal, forge de magma...) :
 *   bonus lineaire, `base × niveau` %, plafonne.
 * - **Les technologies comptent sur tout l empire** : chaque planete recherche les siennes, et les
 *   parts de toutes les planetes s additionnent — c est pour cela que la meme technologie se recherche
 *   sur plusieurs planetes —, puis le total est plafonne. Une technologie ne compte que dans un
 *   emplacement **ouvert** (population du palier suffisante) ; sa part est multipliee par
 *   (1 + experience de son espece) × (1 + bonus « toutes les technologies » des batiments de sa planete).
 *
 * `forPlanet()` rend les deux ; `forPlayer()` les technologies seules (flottes, phalange, expeditions :
 * rien de planetaire n y entre). Interrupteur ferme, compte sans espece, lune : un jeu vide, neutre.
 *
 * ## Ce que le resolveur sert, et ce qu il ne sert pas
 *
 * `APPLIED` nomme les effets qu il resout (lune et debris compris : le moteur les lit par la photographie de
 * combat, journal §155.6) ; `HANDLED_ELSEWHERE` ceux que la demographie, la file et les decouvertes appliquent
 * deja ; `NOT_YET_APPLIED` ceux qui attendent une decision
 * (`LifeformBonusResolverTest` exige que chaque code du catalogue soit dans une des trois listes : rien
 * ne se perd en silence). Champs de planete du Bio-modificateur : le fichier maitre dit « 200 par
 * niveau », ni un pourcentage ni un nombre de champs credible — non applique tant que ce n est pas
 * compris (absent plutot que devine).
 */
final class LifeformBonusResolver
{
    /**
     * Les vaisseaux civils du jeu officiel (l Eclaireur y est un vaisseau de combat).
     */
    public const array CIVIL_SHIPS = ['small_cargo', 'large_cargo', 'colony_ship', 'recycler', 'espionage_probe', 'solar_satellite', 'crawler'];

    public const array MINES = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'];

    public const array APPLIED = [
        LifeformEffect::METAL_PRODUCTION,
        LifeformEffect::CRYSTAL_PRODUCTION,
        LifeformEffect::DEUTERIUM_PRODUCTION,
        LifeformEffect::ALL_PRODUCTION,
        LifeformEffect::ENERGY_PRODUCTION,
        LifeformEffect::ENERGY_CONSUMPTION_REDUCTION,
        LifeformEffect::MINE_COST_REDUCTION,
        LifeformEffect::WRECK_RECOVERY,
        LifeformEffect::SHIP_BUILD_TIME_REDUCTION,
        LifeformEffect::RESEARCH_TIME_REDUCTION,
        LifeformEffect::RESEARCH_COST_REDUCTION,
        LifeformEffect::BUILDING_COST_REDUCTION,
        LifeformEffect::BUILDING_TIME_REDUCTION,
        LifeformEffect::STORAGE_CAPACITY,
        LifeformEffect::CRAWLER_ENERGY_REDUCTION,
        LifeformEffect::CRAWLER_EFFICIENCY,
        LifeformEffect::SHIP_STATS,
        LifeformEffect::DEFENCE_STATS,
        LifeformEffect::SHIP_SPEED,
        LifeformEffect::CIVIL_SHIP_SPEED,
        LifeformEffect::CIVIL_SHIP_CARGO,
        LifeformEffect::FUEL_CONSUMPTION_REDUCTION,
        LifeformEffect::PHALANX_RANGE,
        LifeformEffect::EXPEDITION_FLEET_LOSS_REDUCTION,
        LifeformEffect::EXPEDITION_SHIPS,
        LifeformEffect::EXPEDITION_RESOURCES,
        LifeformEffect::EXPEDITION_SPEED,
        LifeformEffect::EXPEDITION_DARK_MATTER,
        LifeformEffect::CLASS_BONUS,
        LifeformEffect::MOON_CHANCE,
        LifeformEffect::DEBRIS_RECOVERY,
    ];

    public const array HANDLED_ELSEWHERE = [
        LifeformEffect::LIVING_SPACE,
        LifeformEffect::LIVING_SPACE_PERCENT,
        LifeformEffect::GROWTH_RATE,
        LifeformEffect::GROWTH_RATE_PERCENT,
        LifeformEffect::FOOD_PRODUCTION,
        LifeformEffect::FOOD_PRODUCTION_PERCENT,
        LifeformEffect::FOOD_STORAGE,
        LifeformEffect::FOOD_STORAGE_PERCENT,
        LifeformEffect::FOOD_CONSUMPTION_REDUCTION,
        LifeformEffect::TIER2_CAPACITY,
        LifeformEffect::TIER3_CAPACITY,
        LifeformEffect::POPULATION_PROTECTION,
        LifeformEffect::LF_RESEARCH_COST_REDUCTION,
        LifeformEffect::LF_RESEARCH_TIME_REDUCTION,
        LifeformEffect::LF_BUILDING_COST_REDUCTION,
        LifeformEffect::LF_BUILDING_TIME_REDUCTION,
        LifeformEffect::LF_TECH_BONUS,
        LifeformEffect::SLOT_REQUIREMENT_REDUCTION,
        LifeformEffect::DISCOVERY_DURATION_REDUCTION,
        LifeformEffect::UNASSIGNED,
    ];

    /**
     * Attendent une decision (champs de planete, remboursement au rappel) — journal §155.5.
     */
    public const array NOT_YET_APPLIED = [
        LifeformEffect::PLANET_FIELDS,
        LifeformEffect::RECALL_FUEL_REFUND,
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformLevels $levels,
        private readonly LifeformResearchService $research,
        private readonly LifeformRuleRevisions $revisions,
    ) {
    }

    /**
     * Les bonus qui s appliquent sur cette planete : ses batiments, plus les technologies du compte.
     */
    public function forPlanet(int $planetId): LifeformBonusSet
    {
        if (!$this->settings->lifeformsEnabled()) {
            return LifeformBonusSet::none();
        }
        $jeu = LifeformBonusCache::remember('lf-planete:' . $planetId, time(), function () use ($planetId): LifeformBonusSet|null {
            $etat = LifeformPlanet::query()->where('planet_id', $planetId)->first();
            if ($etat === null) {
                return null;
            }
            $proprietaire = Planet::query()->whereKey($planetId)->value('user_id');
            $espece = Species::from((int)$etat->species);
            $batiments = $this->buildingBonuses($espece, $this->levels->buildingLevelsOf($planetId));

            return $batiments->merge($this->forPlayer((int)$proprietaire));
        });

        return $jeu instanceof LifeformBonusSet ? $jeu : LifeformBonusSet::none();
    }

    /**
     * Les bonus des technologies du compte, sommes sur toutes ses planetes et plafonnes.
     */
    public function forPlayer(int $userId): LifeformBonusSet
    {
        if (!$this->settings->lifeformsEnabled()) {
            return LifeformBonusSet::none();
        }
        $jeu = LifeformBonusCache::remember('lf-compte:' . $userId, time(), fn (): LifeformBonusSet|null => $this->technologyBonuses($userId));

        return $jeu instanceof LifeformBonusSet ? $jeu : LifeformBonusSet::none();
    }

    /**
     * L energie que les batiments de formes de vie de la planete consomment.
     */
    public function buildingEnergyOf(int $planetId): int
    {
        if (!$this->settings->lifeformsEnabled()) {
            return 0;
        }
        $energie = LifeformBonusCache::remember('lf-energie:' . $planetId, time(), function () use ($planetId): int|null {
            $etat = LifeformPlanet::query()->where('planet_id', $planetId)->first();
            if ($etat === null) {
                return null;
            }
            $niveaux = $this->levels->buildingLevelsOf($planetId);
            $total = 0;
            foreach (LifeformCatalogue::buildingsOf(Species::from((int)$etat->species)) as $batiment) {
                $niveau = $niveaux[$batiment->id] ?? 0;
                if ($niveau > 0 && $batiment->energy > 0) {
                    $total += LifeformFormulas::energy($batiment, $niveau);
                }
            }

            return $total;
        });

        return is_int($energie) ? $energie : 0;
    }

    public static function isCivilShip(string $machineName): bool
    {
        return in_array($machineName, self::CIVIL_SHIPS, true);
    }

    public static function isMine(string $machineName): bool
    {
        return in_array($machineName, self::MINES, true);
    }

    /**
     * @param array<int, int> $levels
     */
    private function buildingBonuses(Species $species, array $levels): LifeformBonusSet
    {
        $sommes = [];
        $plafonds = [];
        foreach (LifeformCatalogue::buildingsOf($species) as $batiment) {
            $niveau = $levels[$batiment->id] ?? 0;
            if ($niveau <= 0) {
                continue;
            }
            foreach ($batiment->bonuses as $bonus) {
                if (!self::applies($bonus) || $bonus->factor !== 1.0) {
                    continue;
                }
                self::accumulate($sommes, $plafonds, $bonus, LifeformFormulas::buildingBonusPercent($bonus, $niveau) / 100);
            }
        }

        return self::capped($sommes, $plafonds);
    }

    private function technologyBonuses(int $userId): LifeformBonusSet|null
    {
        $planetes = Planet::query()->where('user_id', $userId)->where('destroyed', 0)->pluck('id');
        $etats = LifeformPlanet::query()->whereIn('planet_id', $planetes)->get();
        if ($etats->isEmpty()) {
            return null;
        }
        $vitesse = $this->revisions->live()->demography();
        $experiences = [];
        $sommes = [];
        $plafonds = [];
        foreach ($etats as $etat) {
            $planetId = (int)$etat->planet_id;
            $espece = Species::from((int)$etat->species);
            $niveaux = $this->levels->buildingLevelsOf($planetId);
            $profil = PlanetLifeformProfile::fromLevels($espece, $niveaux, $vitesse);
            $actifs = $this->research->activeTechnologyLevels($planetId, $etat, $profil, $espece, $niveaux);
            if ($actifs === []) {
                continue;
            }
            $batiments = 0.0;
            foreach (LifeformCatalogue::buildingsOf($espece) as $batiment) {
                $bonus = $batiment->bonus(LifeformEffect::LF_TECH_BONUS);
                if ($bonus !== null) {
                    $batiments += LifeformFormulas::buildingBonusPercent($bonus, $niveaux[$batiment->id] ?? 0) / 100;
                }
            }
            foreach ($actifs as $objectId => $niveau) {
                $technologie = LifeformCatalogue::byId($objectId);
                $especeTech = $technologie->species->value;
                if (!isset($experiences[$especeTech])) {
                    $progres = LifeformSpeciesProgress::query()->where('user_id', $userId)->where('species', $especeTech)->first();
                    $experiences[$especeTech] = LifeformExperience::bonusFraction(LifeformExperience::levelOf($progres === null ? 0 : (int)$progres->experience));
                }
                $multiplicateur = (1 + $experiences[$especeTech]) * (1 + $batiments);
                foreach ($technologie->bonuses as $bonus) {
                    if (!self::applies($bonus)) {
                        continue;
                    }
                    $brut = $bonus->base * $niveau * $bonus->factor ** ($niveau - 1) / 100;
                    self::accumulate($sommes, $plafonds, $bonus, $brut * $multiplicateur);
                }
            }
        }

        return self::capped($sommes, $plafonds);
    }

    private static function applies(LifeformBonus $bonus): bool
    {
        return in_array($bonus->code, self::APPLIED, true);
    }

    /**
     * @param array<string, float> $sommes
     * @param array<string, float|null> $plafonds
     */
    private static function accumulate(array &$sommes, array &$plafonds, LifeformBonus $bonus, float $part): void
    {
        $clef = LifeformBonusSet::key($bonus->code, $bonus->target);
        $sommes[$clef] = ($sommes[$clef] ?? 0.0) + $part;
        if ($bonus->max !== null) {
            $plafonds[$clef] = isset($plafonds[$clef]) ? min($plafonds[$clef], $bonus->max) : $bonus->max;
        } elseif (!array_key_exists($clef, $plafonds)) {
            $plafonds[$clef] = null;
        }
    }

    /**
     * @param array<string, float> $sommes
     * @param array<string, float|null> $plafonds
     */
    private static function capped(array $sommes, array $plafonds): LifeformBonusSet
    {
        $resultat = [];
        foreach ($sommes as $clef => $part) {
            if ($part <= 0.0) {
                continue;
            }
            $plafond = $plafonds[$clef] ?? null;
            $resultat[$clef] = $plafond === null ? $part : min($part, $plafond);
        }

        return new LifeformBonusSet($resultat);
    }
}
