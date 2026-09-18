<?php

namespace OGame\Lifeforms\Presentation;

use Illuminate\Support\Facades\Date;
use OGame\Facades\AppUtil;
use OGame\Lifeforms\Combat\LifeformCombatHold;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformWelcome;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Ce que le bandeau et la vue generale disent des formes de vie : l espece du compte, la population
 * et la nourriture de la planete courante, et si l invitation d accueil doit se montrer.
 *
 * Lecture seule. L etat de la planete a ete avance par `PlanetService::update()` au chargement de
 * la page ; ici on le presente, on ne le calcule pas.
 *
 * @phpstan-type Suspension array{since: int, since_formatted: string}
 * @phpstan-type Chiffres array{population: float, population_formatted: string, living_space: int, living_space_formatted: string, tier2: float, tier2_formatted: string, tier3: float, tier3_formatted: string, satisfied: float, satisfied_formatted: string, hungry: float, hungry_formatted: string, growth_hour: float, growth_hour_formatted: string, sheltered: float, sheltered_formatted: string, food: float, food_formatted: string, food_storage: float, food_storage_formatted: string, food_production_hour: float, food_production_hour_formatted: string, food_consumption_hour: float, food_consumption_hour_formatted: string, food_balance_hour: float, food_balance_hour_formatted: string, food_runs_out_in: int|null, food_runs_out_formatted: string, full: bool}
 */
final class LifeformBanner
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformInstallationService $installation,
        private readonly LifeformLevels $levels,
        private readonly LifeformRuleRevisions $revisions,
        private readonly LifeformCombatHold $hold,
    ) {
    }

    /**
     * @return array{enabled: bool, species: Species|null, species_name: string|null, planet: Chiffres|null, welcome: bool, held: Suspension|null}
     */
    public function for(PlayerService $player, PlanetService|null $planet): array
    {
        $ouvert = $this->settings->lifeformsEnabled();
        $espece = $this->speciesFor($player);
        $chiffres = null;
        if ($espece !== null && $planet !== null && $planet->isPlanet()) {
            $chiffres = $this->planetFigures($planet, $espece);
        }

        $nom = $espece === null ? null : __('t_lifeforms.species.' . $espece->machineName());

        return [
            'enabled' => $ouvert,
            'species' => $espece,
            'species_name' => is_string($nom) ? $nom : null,
            'planet' => $chiffres,
            'welcome' => $ouvert && $espece === null && !$this->welcomeDismissed($player->getId()),
            'held' => $espece !== null && $planet !== null && $planet->isPlanet() ? $this->heldOn($planet) : null,
        ];
    }

    /**
     * Les chiffres de la planete pour ce compte, sans le reste du bandeau.
     *
     * Le bandeau des ressources se resynchronise toutes les trente secondes (`/ajax/resourcebox`) : il lui
     * faut la population et la nourriture, pas l invitation d accueil ni la suspension de la planete, qui
     * coutent chacune une lecture de plus.
     *
     * @return Chiffres|null
     */
    public function figuresOf(PlayerService $player, PlanetService|null $planet): array|null
    {
        $espece = $this->speciesFor($player);

        return $espece !== null && $planet !== null && $planet->isPlanet() ? $this->planetFigures($planet, $espece) : null;
    }

    /**
     * L espece du compte : celle qu il porte, meme quand les formes de vie sont refermees — un compte deja
     * engage garde son espece, et son bandeau garde ses chiffres.
     */
    private function speciesFor(PlayerService $player): Species|null
    {
        return $this->settings->lifeformsEnabled() || $this->installation->accountOf($player->getId()) !== null
            ? $this->installation->speciesOf($player->getId())
            : null;
    }

    /**
     * La bataille qui tient la planete, si une bataille non reglee la tient — ce que le joueur doit lire
     * plutot qu une page qui semble defectueuse (journal §155.16). Meme lecture que `LifeformPlanetUpdater`.
     *
     * @return Suspension|null
     */
    public function heldOn(PlanetService $planet): array|null
    {
        $depuis = $this->hold->until($planet->getPlanetId(), (int)Date::now()->timestamp);
        if ($depuis === null) {
            return null;
        }

        return ['since' => $depuis, 'since_formatted' => date('d.m.Y H:i', $depuis)];
    }

    public function welcomeDismissed(int $userId): bool
    {
        return LifeformWelcome::query()->where('user_id', $userId)->where('dismissed_version', '>=', LifeformWelcome::VERSION)->exists();
    }

    /**
     * @return Chiffres|null
     */
    public function planetFigures(PlanetService $planet, Species $species): array|null
    {
        $etat = LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->first();
        if ($etat === null) {
            return null;
        }
        $profil = PlanetLifeformProfile::fromLevels($species, $this->levels->buildingLevelsOf($planet->getPlanetId()), $this->revisions->live()->demography());
        $population = (float)$etat->population;
        $nourris = $profil->inhabitantsFed();
        $satisfaits = min($population, $nourris);
        $affames = max(0.0, $population - $satisfaits);
        $consommation = $population * $profil->foodPerInhabitantPerHour;
        $bilan = $profil->foodProductionPerHour - $consommation;
        $nourriture = (float)$etat->food;
        $epuisement = null;
        if ($bilan < 0.0 && $nourriture > 0.0) {
            $epuisement = (int)ceil($nourriture / -$bilan * 3600);
        }
        $croissance = $population < $profil->livingSpace && ($nourriture > 0.0 || $bilan > 0.0) ? $profil->growthPerHour : 0.0;

        return [
            'population' => $population,
            'population_formatted' => AppUtil::formatNumber((int)floor($population)),
            'living_space' => $profil->livingSpace,
            'living_space_formatted' => AppUtil::formatNumber($profil->livingSpace),
            'tier2' => $profil->tier2Of($population),
            'tier2_formatted' => AppUtil::formatNumber((int)floor($profil->tier2Of($population))),
            'tier3' => $profil->tier3Of($population),
            'tier3_formatted' => AppUtil::formatNumber((int)floor($profil->tier3Of($population))),
            'satisfied' => $satisfaits,
            'satisfied_formatted' => AppUtil::formatNumber((int)floor($satisfaits)),
            'hungry' => $affames,
            'hungry_formatted' => AppUtil::formatNumber((int)ceil($affames)),
            'growth_hour' => $croissance,
            'growth_hour_formatted' => '+' . AppUtil::formatNumber((int)floor($croissance)),
            'sheltered' => $profil->shelteredOf($population),
            'sheltered_formatted' => AppUtil::formatNumber((int)floor($profil->shelteredOf($population))),
            'food' => $nourriture,
            'food_formatted' => AppUtil::formatNumber((int)floor($nourriture)),
            'food_storage' => $profil->foodStorage,
            'food_storage_formatted' => AppUtil::formatNumber((int)floor($profil->foodStorage)),
            'food_production_hour' => $profil->foodProductionPerHour,
            'food_production_hour_formatted' => '+' . AppUtil::formatNumber((int)floor($profil->foodProductionPerHour)),
            'food_consumption_hour' => $consommation,
            'food_consumption_hour_formatted' => '-' . AppUtil::formatNumber((int)ceil($consommation)),
            'food_balance_hour' => $bilan,
            'food_balance_hour_formatted' => ($bilan >= 0 ? '+' : '-') . AppUtil::formatNumber((int)floor(abs($bilan))),
            'food_runs_out_in' => $epuisement,
            'food_runs_out_formatted' => $epuisement === null ? '~' : AppUtil::formatTimeDuration($epuisement),
            'full' => $population >= $profil->livingSpace,
        ];
    }
}
