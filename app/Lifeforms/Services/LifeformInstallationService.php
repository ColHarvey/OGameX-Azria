<?php

namespace OGame\Lifeforms\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Demography\DemographicRules;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\SettingsService;

/**
 * Le choix de l espece d un compte, et le peuplement de ses planetes.
 *
 * ## Une fois, pour toutes les planetes
 *
 * Regle Azria : un compte choisit son espece **une seule fois**, parmi les quatre, et toutes ses
 * planetes la portent, presentes et futures. Le choix est une transaction qui tient la ligne du
 * compte ; la contrainte unique de `lifeform_accounts.user_id` est le verrou de derniere ligne —
 * deux choix simultanes rendent une seule ligne, et le second est refuse « deja choisi », jamais
 * silencieux, jamais double.
 *
 * ## Aucun cadeau
 *
 * Chaque planete part de la population de base de l espece, sans nourriture, sans batiment, sans
 * technologie ; la croissance commence a l instant du choix, jamais a la creation du compte. Les
 * lunes, les corps detruits et les PNJ ne sont jamais peuples.
 */
final class LifeformInstallationService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function accountOf(int $userId): LifeformAccount|null
    {
        return LifeformAccount::query()->where('user_id', $userId)->first();
    }

    public function speciesOf(int $userId): Species|null
    {
        $compte = $this->accountOf($userId);

        return $compte === null ? null : Species::from($compte->species);
    }

    /**
     * Choisit l espece du compte et peuple toutes ses planetes.
     *
     * @throws LifeformRefused
     */
    public function chooseSpecies(int $userId, Species $species, int $now): LifeformAccount
    {
        if (!$this->settings->lifeformsEnabled()) {
            throw new LifeformRefused(LifeformRefused::CLOSED);
        }

        try {
            return DB::transaction(function () use ($userId, $species, $now): LifeformAccount {
                $compte = User::query()->whereKey($userId)->lockForUpdate()->first();
                if ($compte === null) {
                    throw new LifeformRefused(LifeformRefused::NO_SPECIES, "Compte $userId inconnu.");
                }
                if (LifeformAccount::query()->where('user_id', $userId)->exists()) {
                    throw new LifeformRefused(LifeformRefused::ALREADY_CHOSEN);
                }

                $ligne = LifeformAccount::query()->create([
                    'user_id' => $userId,
                    'species' => $species->value,
                    'chosen_at' => $now,
                    'artifacts' => 0,
                    'discoveries_available' => 0,
                ]);
                LifeformSpeciesProgress::query()->firstOrCreate(
                    ['user_id' => $userId, 'species' => $species->value],
                    ['experience' => 0, 'discovered_at' => $now]
                );

                $planetes = Planet::query()
                    ->where('user_id', $userId)
                    ->where('planet_type', 1)
                    ->where(fn ($q) => $q->whereNull('destroyed')->orWhere('destroyed', 0))
                    ->orderBy('id')
                    ->get();
                foreach ($planetes as $planete) {
                    $this->populate($planete, $species, $now);
                }

                return $ligne;
            });
        } catch (QueryException $e) {
            // La contrainte unique a parle : un autre choix a gagne la course.
            if ($this->accountOf($userId) !== null) {
                throw new LifeformRefused(LifeformRefused::ALREADY_CHOSEN, 'Course perdue sur la contrainte unique.');
            }
            throw $e;
        }
    }

    /**
     * Peuple une planete qui vient d etre creee, si son compte a une espece. Sans effet sinon.
     */
    public function installOnNewPlanet(Planet $planet, int $now): void
    {
        if ((int)$planet->planet_type !== 1 || (int)$planet->user_id <= 0) {
            return;
        }
        $espece = $this->speciesOf((int)$planet->user_id);
        if ($espece === null) {
            return;
        }
        $this->populate($planet, $espece, $now);
    }

    /**
     * Peuple une planete deja existante par son identifiant, si son compte a une espece. Sans effet sinon.
     */
    public function installOnExistingPlanet(int $planetId, int $now): void
    {
        $planete = Planet::query()->whereKey($planetId)->first();
        if ($planete === null || (int)$planete->destroyed > 0) {
            return;
        }
        $this->installOnNewPlanet($planete, $now);
    }

    private function populate(Planet $planet, Species $species, int $now): void
    {
        // La memoire des bonus retient « ce corps n a pas de forme de vie » (journal §165) : l installation la vide, pour
        // qu un travailleur de longue duree ne serve pas ce neutre apres le choix d une espece.
        LifeformBonusCache::invalidate();
        $base = PlanetLifeformProfile::fromLevels($species, [], 1.0)->basePopulation;
        LifeformPlanet::query()->firstOrCreate(
            ['planet_id' => (int)$planet->id],
            [
                'species' => $species->value,
                'population' => $base,
                'food' => 0.0,
                'calculated_at' => $now,
                'installed_at' => $now,
                'rules_version' => DemographicRules::VERSION,
            ]
        );
    }
}
