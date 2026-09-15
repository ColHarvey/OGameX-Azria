<?php

namespace OGame\Lifeforms\Services;

use Illuminate\Support\Facades\DB;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Research\LifeformSlotHistory;
use OGame\Lifeforms\Research\LifeformSlotRules;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Services\SettingsService;

/**
 * Les emplacements de recherche d une planete : ouverture par la population, choix d une technologie
 * (locale, tiree au sort parmi les especes decouvertes, ou choisie par artefacts), remise a zero
 * d un palier et restauration.
 *
 * ## Les regles
 *
 * - Un emplacement s ouvre quand la population de son palier atteint son exigence
 *   (`LifeformSlotRules`), reduite par le Modulateur psionique de la planete.
 * - Un emplacement ouvert et vide recoit une technologie **de sa position** : celle de l espece du
 *   compte (gratuit), celle d une espece decouverte tiree au sort (gratuit), ou celle d une espece
 *   decouverte choisie contre des artefacts (200 / 400 / 600 selon le palier). Une page reelle du jeu
 *   montre bien des technologies d autres especes a la meme position.
 * - La technologie d un emplacement se recherche niveau par niveau ; les niveaux vivent dans
 *   `lifeform_technology_levels` et **survivent** a toute remise a zero.
 * - Remettre un palier a zero vide ses six emplacements ; regle Azria : gratuit, une fois par jour et
 *   par palier, jamais pendant une recherche de ce palier ; la selection precedente se restaure
 *   pendant une heure. Aucune voie payante.
 *
 * Tout ce qui ecrit tient la ligne de la planete (et celle du compte pour les artefacts).
 */
final class LifeformResearchService
{
    public const int RESET_COOLDOWN = 86400;

    public const int RESTORE_WINDOW = 3600;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformLevels $levels,
        private readonly LifeformRuleRevisions $revisions,
        private readonly LifeformSlotHistory $history,
    ) {
    }

    /**
     * Les dix-huit emplacements, par numero ; ceux qui n ont jamais ete touches sont rendus vides.
     *
     * @return array<int, LifeformSlot>
     */
    public function slotsOf(int $planetId): array
    {
        $lignes = LifeformSlot::query()->where('planet_id', $planetId)->get()->keyBy('slot');
        $resultat = [];
        for ($slot = 1; $slot <= LifeformSlotRules::SLOTS; $slot++) {
            $ligne = $lignes->get($slot);
            if ($ligne === null) {
                $ligne = new LifeformSlot(['planet_id' => $planetId, 'slot' => $slot, 'object_id' => null]);
            }
            $resultat[$slot] = $ligne;
        }

        return $resultat;
    }

    /**
     * La reduction des exigences que la planete accorde (Modulateur psionique), en fraction.
     *
     * @param array<int, int> $buildingLevels
     */
    public function requirementReduction(Species $species, array $buildingLevels): float
    {
        $reduction = 0.0;
        foreach (LifeformCatalogue::buildingsOf($species) as $batiment) {
            $bonus = $batiment->bonus(LifeformEffect::SLOT_REQUIREMENT_REDUCTION);
            if ($bonus !== null) {
                $reduction += LifeformFormulas::buildingBonusPercent($bonus, $buildingLevels[$batiment->id] ?? 0) / 100;
            }
        }

        return min(0.99, $reduction);
    }

    /**
     * La population du palier de cet emplacement que la planete porte.
     */
    public function tierPopulationOf(int $slot, LifeformPlanet $state, PlanetLifeformProfile $profile): float
    {
        $population = (float)$state->population;

        return match (LifeformSlotRules::tierOf($slot)) {
            1 => $population,
            2 => $profile->tier2Of($population),
            default => $profile->tier3Of($population),
        };
    }

    public function isUnlocked(int $slot, LifeformPlanet $state, PlanetLifeformProfile $profile, float $reduction): bool
    {
        return $this->tierPopulationOf($slot, $state, $profile) + 1e-9 >= LifeformSlotRules::populationRequired($slot, $reduction);
    }

    /**
     * L emplacement qui porte cet objet sur cette planete, ou null.
     */
    public function slotHolding(int $planetId, int $objectId): LifeformSlot|null
    {
        return LifeformSlot::query()->where('planet_id', $planetId)->where('object_id', $objectId)->first();
    }

    /**
     * Une technologie peut-elle etre recherchee ici : presente dans un emplacement ouvert ?
     */
    public function mayResearch(LifeformObject $object, int $planetId, LifeformPlanet $state, PlanetLifeformProfile $profile, Species $species, array $buildingLevels): bool
    {
        if ($object->kind !== LifeformKind::Technology) {
            return false;
        }
        $slot = $this->slotHolding($planetId, $object->id);
        if ($slot === null) {
            return false;
        }

        return $this->isUnlocked((int)$slot->slot, $state, $profile, $this->requirementReduction($species, $buildingLevels));
    }

    /**
     * Les especes decouvertes par le compte (la sienne comprise).
     *
     * @return array<int, Species>
     */
    public function discoveredSpeciesOf(int $userId): array
    {
        $resultat = [];
        foreach (LifeformSpeciesProgress::query()->where('user_id', $userId)->whereNotNull('discovered_at')->orderBy('species')->get() as $ligne) {
            $resultat[] = Species::from((int)$ligne->species);
        }

        return $resultat;
    }

    /**
     * Le multiplicateur des bonus de technologies de la planete : (1 + experience de l espece) × (1 +
     * bonus des batiments « ameliore toutes les technologies »).
     *
     * @param array<int, int> $buildingLevels
     */
    public function technologyBonusMultiplier(int $userId, Species $species, array $buildingLevels): float
    {
        $progres = LifeformSpeciesProgress::query()->where('user_id', $userId)->where('species', $species->value)->first();
        $experience = LifeformExperience::bonusFraction(LifeformExperience::levelOf($progres === null ? 0 : (int)$progres->experience));
        $batiments = 0.0;
        foreach (LifeformCatalogue::buildingsOf($species) as $batiment) {
            $bonus = $batiment->bonus(LifeformEffect::LF_TECH_BONUS);
            if ($bonus !== null) {
                $batiments += LifeformFormulas::buildingBonusPercent($bonus, $buildingLevels[$batiment->id] ?? 0) / 100;
            }
        }

        return (1 + $experience) * (1 + $batiments);
    }

    /**
     * Choisit la technologie d un emplacement ouvert et vide.
     *
     * @param string $choice 'local', 'random', ou l identifiant d une technologie a payer en artefacts
     *
     * @throws LifeformRefused
     */
    public function choose(int $planetId, int $userId, int $slot, string $choice, int $now): LifeformSlot
    {
        if (!$this->settings->lifeformsEnabled()) {
            throw new LifeformRefused(LifeformRefused::CLOSED);
        }
        if ($slot < 1 || $slot > LifeformSlotRules::SLOTS) {
            throw new LifeformRefused(LifeformRefused::UNKNOWN_OBJECT, "emplacement $slot");
        }

        return DB::transaction(function () use ($planetId, $userId, $slot, $choice, $now): LifeformSlot {
            Planet::query()->whereKey($planetId)->lockForUpdate()->first();
            $etat = LifeformPlanet::query()->where('planet_id', $planetId)->first();
            if ($etat === null) {
                throw new LifeformRefused(LifeformRefused::NO_SPECIES);
            }
            $espece = Species::from((int)$etat->species);
            $niveaux = $this->levels->buildingLevelsOf($planetId);
            $profil = PlanetLifeformProfile::fromLevels($espece, $niveaux, $this->revisions->live()->demography());
            if (!$this->isUnlocked($slot, $etat, $profil, $this->requirementReduction($espece, $niveaux))) {
                throw new LifeformRefused(LifeformRefused::SLOT_LOCKED, "emplacement $slot");
            }
            $ligne = LifeformSlot::query()->firstOrCreate(['planet_id' => $planetId, 'slot' => $slot], ['object_id' => null]);
            if ($ligne->object_id !== null) {
                throw new LifeformRefused(LifeformRefused::SLOT_TAKEN, "emplacement $slot");
            }

            $position = LifeformSlotRules::positionOf($slot);
            $palier = LifeformSlotRules::tierOf($slot);
            $decouvertes = $this->discoveredSpeciesOf($userId);
            $via = $choice;
            if ($choice === 'local') {
                $objet = self::technologyAt($espece, $palier, $position);
            } elseif ($choice === 'random') {
                $autres = array_values(array_filter($decouvertes, fn (Species $s) => $s !== $espece));
                if ($autres === []) {
                    throw new LifeformRefused(LifeformRefused::NO_DISCOVERED_SPECIES);
                }
                $objet = self::technologyAt($autres[random_int(0, count($autres) - 1)], $palier, $position);
            } else {
                if (!ctype_digit($choice) || !LifeformCatalogue::has((int)$choice)) {
                    throw new LifeformRefused(LifeformRefused::UNKNOWN_OBJECT, $choice);
                }
                $objet = LifeformCatalogue::byId((int)$choice);
                if ($objet->kind !== LifeformKind::Technology || $objet->tier() !== $palier || $objet->index !== ($palier - 1) * LifeformSlotRules::PER_TIER + $position) {
                    throw new LifeformRefused(LifeformRefused::WRONG_SLOT, $objet->machineName);
                }
                if (!in_array($objet->species, $decouvertes, true)) {
                    throw new LifeformRefused(LifeformRefused::NO_DISCOVERED_SPECIES, $objet->machineName);
                }
                $via = 'artifacts';
                if ($objet->species !== $espece) {
                    $this->payArtifacts($userId, LifeformSlotRules::ARTIFACT_COST[$palier]);
                } else {
                    $via = 'local';
                }
            }

            // La meme technologie ne peut pas occuper deux emplacements de la planete.
            if ($this->slotHolding($planetId, $objet->id) !== null) {
                throw new LifeformRefused(LifeformRefused::SLOT_TAKEN, $objet->machineName);
            }

            $ligne->object_id = $objet->id;
            $ligne->selected_at = $now;
            $ligne->chosen_via = $via;
            $ligne->save();
            $this->history->record($planetId, $slot, $objet->id, $now);

            LifeformBonusCache::invalidate();

            return $ligne;
        });
    }

    /**
     * Vide les six emplacements d un palier ; les niveaux restent, la selection est gardee une heure.
     *
     * @throws LifeformRefused
     */
    public function resetTier(int $planetId, int $tier, int $now): void
    {
        if (!$this->settings->lifeformsEnabled()) {
            throw new LifeformRefused(LifeformRefused::CLOSED);
        }
        if ($tier < 1 || $tier > 3) {
            throw new LifeformRefused(LifeformRefused::UNKNOWN_OBJECT, "palier $tier");
        }
        DB::transaction(function () use ($planetId, $tier, $now): void {
            Planet::query()->whereKey($planetId)->lockForUpdate()->first();
            $emplacements = LifeformSlot::query()->where('planet_id', $planetId)->whereIn('slot', LifeformSlotRules::slotsOfTier($tier))->lockForUpdate()->get();
            $derniere = (int)$emplacements->max('reset_at');
            if ($derniere > 0 && $now - $derniere < self::RESET_COOLDOWN) {
                throw new LifeformRefused(LifeformRefused::RESET_TOO_SOON, (string)($derniere + self::RESET_COOLDOWN));
            }
            $objets = $emplacements->whereNotNull('object_id')->pluck('object_id')->map(fn ($v) => (int)$v)->all();
            if ($objets === []) {
                throw new LifeformRefused(LifeformRefused::NOTHING_TO_RESET);
            }
            $enCours = LifeformQueue::query()->where('planet_id', $planetId)->where('kind', LifeformKind::Technology->value)
                ->whereIn('status', ['waiting', 'running'])->whereIn('object_id', $objets)->exists();
            if ($enCours) {
                throw new LifeformRefused(LifeformRefused::RESEARCH_IN_PROGRESS);
            }
            foreach ($emplacements as $emplacement) {
                $emplacement->previous_object_id = $emplacement->object_id;
                $emplacement->object_id = null;
                $emplacement->reset_at = $now;
                $emplacement->save();
                $this->history->record($planetId, (int)$emplacement->slot, null, $now);
            }
        });
        LifeformBonusCache::invalidate();
    }

    /**
     * Remet la selection precedente d un palier, pendant l heure qui suit sa remise a zero.
     *
     * @throws LifeformRefused
     */
    public function restoreTier(int $planetId, int $tier, int $now): void
    {
        if (!$this->settings->lifeformsEnabled()) {
            throw new LifeformRefused(LifeformRefused::CLOSED);
        }
        DB::transaction(function () use ($planetId, $tier, $now): void {
            Planet::query()->whereKey($planetId)->lockForUpdate()->first();
            $emplacements = LifeformSlot::query()->where('planet_id', $planetId)->whereIn('slot', LifeformSlotRules::slotsOfTier($tier))->lockForUpdate()->get();
            $derniere = (int)$emplacements->max('reset_at');
            if ($derniere <= 0 || $now - $derniere > self::RESTORE_WINDOW) {
                throw new LifeformRefused(LifeformRefused::RESTORE_EXPIRED);
            }
            if ($emplacements->whereNotNull('object_id')->isNotEmpty()) {
                throw new LifeformRefused(LifeformRefused::SLOT_TAKEN, 'restauration sur un palier deja rechoisi');
            }
            foreach ($emplacements as $emplacement) {
                if ($emplacement->previous_object_id === null) {
                    continue;
                }
                $emplacement->object_id = $emplacement->previous_object_id;
                $emplacement->previous_object_id = null;
                $emplacement->save();
                $this->history->record($planetId, (int)$emplacement->slot, (int)$emplacement->object_id, $now);
            }
        });
        LifeformBonusCache::invalidate();
    }

    /**
     * Les niveaux des technologies dont l emplacement est ouvert — ceux dont le bonus compte.
     *
     * @return array<int, int> niveau par identifiant de technologie
     */
    public function activeTechnologyLevels(int $planetId, LifeformPlanet $state, PlanetLifeformProfile $profile, Species $species, array $buildingLevels): array
    {
        return $this->activeAmong($this->occupancyOf($planetId), $this->levels->technologyLevelsOf($planetId), $state, $profile, $species, $buildingLevels);
    }

    /**
     * L occupation des emplacements de la planete : au present, ou **telle qu elle etait** a un instant.
     *
     * C est la lecture unique — le resolveur s en sert pour nommer l emplacement de chaque contribution,
     * et les technologies actives en sortent. Deux lectures separees auraient pu diverger.
     *
     * @return array<int, int> identifiant de technologie par numero d emplacement, les vides omis
     */
    public function occupancyOf(int $planetId, int|null $at = null): array
    {
        if ($at !== null) {
            return $this->history->occupantsAt($planetId, $at);
        }
        $occupation = [];
        foreach ($this->slotsOf($planetId) as $slot => $ligne) {
            if ($ligne->object_id !== null) {
                $occupation[$slot] = (int)$ligne->object_id;
            }
        }

        return $occupation;
    }

    /**
     * Les memes, **telles qu elles etaient a un instant** : l occupation vient de l historique des
     * emplacements, les niveaux de la file des travaux.
     *
     * L ouverture d un emplacement se juge en revanche sur la population **courante** : l horloge
     * demographique ne se remonte pas, et l inventer serait pire que l avouer (journal §155.10).
     *
     * @param array<int, int> $buildingLevels niveaux de batiments **a cet instant**
     * @return array<int, int> niveau par identifiant de technologie
     */
    public function activeTechnologyLevelsAt(int $planetId, LifeformPlanet $state, PlanetLifeformProfile $profile, Species $species, array $buildingLevels, int $at): array
    {
        return $this->activeAmong(
            $this->occupancyOf($planetId, $at),
            $this->levels->levelsAt($planetId, LifeformKind::Technology, $at),
            $state,
            $profile,
            $species,
            $buildingLevels
        );
    }

    /**
     * @param array<int, int> $occupation identifiant de technologie par emplacement
     * @param array<int, int> $technologyLevels
     * @param array<int, int> $buildingLevels
     * @return array<int, int>
     */
    private function activeAmong(array $occupation, array $technologyLevels, LifeformPlanet $state, PlanetLifeformProfile $profile, Species $species, array $buildingLevels): array
    {
        $reduction = $this->requirementReduction($species, $buildingLevels);
        $actifs = [];
        foreach ($occupation as $slot => $objectId) {
            $niveau = $technologyLevels[$objectId] ?? 0;
            if ($niveau > 0 && $this->isUnlocked($slot, $state, $profile, $reduction)) {
                $actifs[$objectId] = $niveau;
            }
        }

        return $actifs;
    }

    public static function technologyAt(Species $species, int $tier, int $position): LifeformObject
    {
        $index = ($tier - 1) * LifeformSlotRules::PER_TIER + $position;
        foreach (LifeformCatalogue::technologiesOf($species) as $technologie) {
            if ($technologie->index === $index) {
                return $technologie;
            }
        }
        throw new LifeformRefused(LifeformRefused::UNKNOWN_OBJECT, "technologie $index de {$species->name}");
    }

    private function payArtifacts(int $userId, int $cost): void
    {
        $compte = LifeformAccount::query()->where('user_id', $userId)->lockForUpdate()->first();
        if ($compte === null) {
            throw new LifeformRefused(LifeformRefused::NO_SPECIES);
        }
        if ((int)$compte->artifacts < $cost) {
            throw new LifeformRefused(LifeformRefused::NOT_ENOUGH_ARTIFACTS, (string)$cost);
        }
        $compte->artifacts = (int)$compte->artifacts - $cost;
        $compte->save();
    }
}
