<?php

namespace OGame\Lifeforms\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * La file des travaux de formes de vie d une planete : batiments et technologies, un travail en
 * cours par genre, cinq en attente au plus.
 *
 * ## Le patron de la file classique, sans ses tables
 *
 * Meme deroulement que `BuildingQueueService` : une demande est inscrite en attente, puis le
 * premier element en attente demarre quand rien ne court — le devis est calcule **au depart**, les
 * ressources debitees a cet instant, l echeance figee. Un element qui ne peut plus demarrer
 * (niveau incoherent, prerequis perdu, population insuffisante, ressources manquantes) est annule,
 * jamais demarre a moitie.
 *
 * ## Sous le verrou de la planete
 *
 * `add()` et `cancel()` ouvrent une transaction qui tient la ligne de la planete ; la livraison
 * (`deliverDue()`) est appelee par `LifeformPlanetUpdater` dans la transaction de
 * `PlanetService::update()`, qui tient deja ce verrou. Deux demandes simultanees se serialisent
 * donc sur la planete, et le bac MariaDB le prouve.
 */
final class LifeformQueueService
{
    public const int MAX_WAITING_PER_KIND = 5;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformRuleRevisions $revisions,
        private readonly LifeformLevels $levels,
        private readonly LifeformResearchService $research,
    ) {
    }

    /**
     * Inscrit un niveau de plus d un objet, puis tente de le demarrer.
     *
     * @throws LifeformRefused
     */
    public function add(PlanetService $planet, int $objectId, int $now): LifeformQueue
    {
        if (!$this->settings->lifeformsEnabled()) {
            throw new LifeformRefused(LifeformRefused::CLOSED);
        }
        if (!LifeformCatalogue::has($objectId)) {
            throw new LifeformRefused(LifeformRefused::UNKNOWN_OBJECT, (string)$objectId);
        }
        $objet = LifeformCatalogue::byId($objectId);

        return DB::transaction(function () use ($planet, $objet, $now): LifeformQueue {
            Planet::query()->whereKey($planet->getPlanetId())->lockForUpdate()->first();
            $etat = $this->stateOf($planet);
            if (Species::from($etat->species) !== $objet->species) {
                throw new LifeformRefused(LifeformRefused::WRONG_SPECIES, $objet->machineName);
            }

            $enFile = $this->queued($planet->getPlanetId(), $objet->kind);
            if ($enFile->where('status', 'waiting')->count() >= self::MAX_WAITING_PER_KIND) {
                throw new LifeformRefused(LifeformRefused::QUEUE_FULL);
            }

            $niveauxBatiments = $this->levels->buildingLevelsOf($planet->getPlanetId());
            $niveauCourant = $this->levels->levelOf($planet->getPlanetId(), $objet->kind, $objet->id);
            $dejaEnFile = $enFile->where('object_id', $objet->id)->count();
            $cible = $niveauCourant + $dejaEnFile + 1;

            // Les prerequis se lisent avec la file : un batiment en attente compte pour son niveau cible.
            $niveauxAvecFile = $niveauxBatiments;
            foreach ($enFile as $element) {
                if ($element->kind === LifeformKind::Building->value) {
                    $niveauxAvecFile[(int)$element->object_id] = max($niveauxAvecFile[(int)$element->object_id] ?? 0, (int)$element->target_level);
                }
            }
            $this->requireRequirements($objet, $niveauxAvecFile);
            $this->requirePopulation($objet, $cible, $etat);
            if (!$this->researchable($objet, $planet->getPlanetId(), $etat, $niveauxBatiments)) {
                throw new LifeformRefused(LifeformRefused::SLOT_LOCKED, $objet->machineName);
            }

            $ligne = LifeformQueue::query()->create([
                'planet_id' => $planet->getPlanetId(),
                'user_id' => (int)$planet->getPlayer()?->getId(),
                'kind' => $objet->kind->value,
                'object_id' => $objet->id,
                'target_level' => $cible,
                'status' => 'waiting',
                'catalogue_version' => LifeformCatalogue::VERSION,
            ]);

            $this->start($planet, $objet->kind, $now);

            return $ligne->refresh();
        });
    }

    /**
     * Demarre le premier element en attente d un genre si rien ne court. Sous le verrou de la
     * planete, tenu par l appelant.
     */
    public function start(PlanetService $planet, LifeformKind $kind, int $timeStart, float|null $populationAtStart = null): void
    {
        $planetId = $planet->getPlanetId();
        if ($this->running($planetId, $kind) !== null) {
            return;
        }
        $etat = LifeformPlanet::query()->where('planet_id', $planetId)->first();
        if ($etat === null) {
            return;
        }
        // **La population de l instant du demarrage, pas celle de la colonne.** Un rattrapage avance la
        // population en memoire et ne l ecrit qu a la fin : un travail demarre a une echeance intermediaire
        // lisait donc celle d avant l absence, et pouvait etre annule pour un seuil qui etait franchi — ou
        // accepte sur un seuil qui ne l etait plus (relance de Codex, journal §155.13).
        $population = $populationAtStart ?? (float)$etat->population;
        $vitesses = $this->revisions->at($timeStart);

        foreach ($this->queued($planetId, $kind)->where('status', 'waiting')->sortBy('id') as $element) {
            $objet = LifeformCatalogue::byId((int)$element->object_id);
            $niveauCourant = $this->levels->levelOf($planetId, $kind, $objet->id);
            $niveauxBatiments = $this->levels->buildingLevelsOf($planetId);

            $incoherent = (int)$element->target_level !== $niveauCourant + 1
                || !$this->requirementsMet($objet, $niveauxBatiments)
                || !$this->populationMetWith($objet, (int)$element->target_level, $population)
                || !$this->researchable($objet, $planetId, $etat, $niveauxBatiments, $population);
            if ($incoherent) {
                $this->markCanceled($element);
                continue;
            }

            $devis = LifeformQuote::for(
                $objet,
                (int)$element->target_level,
                $niveauxBatiments,
                $planet->getObjectLevel('robot_factory'),
                $planet->getObjectLevel('nano_factory'),
                $vitesses,
            );

            try {
                $planet->deductResources($devis->price);
            } catch (RuntimeException) {
                $this->markCanceled($element);
                continue;
            }

            $element->metal = (int)$devis->price->metal->get();
            $element->crystal = (int)$devis->price->crystal->get();
            $element->deuterium = (int)$devis->price->deuterium->get();
            $element->energy = $devis->energy;
            $element->time_start = $timeStart;
            $element->time_end = $timeStart + $devis->duration;
            $element->status = 'running';
            $element->save();

            return;
        }
    }

    /**
     * Annule un element, rembourse s il courait, puis relance la file.
     *
     * @throws LifeformRefused
     */
    public function cancel(PlanetService $planet, int $queueId, int $now): void
    {
        DB::transaction(function () use ($planet, $queueId, $now): void {
            Planet::query()->whereKey($planet->getPlanetId())->lockForUpdate()->first();
            $element = LifeformQueue::query()
                ->whereKey($queueId)
                ->where('planet_id', $planet->getPlanetId())
                ->whereIn('status', ['waiting', 'running'])
                ->lockForUpdate()
                ->first();
            if ($element === null) {
                throw new LifeformRefused(LifeformRefused::NOT_IN_QUEUE, (string)$queueId);
            }
            if ($element->status === 'running') {
                // Une addition faite en base, qui lit la ligne qu elle ecrit : le modele en memoire
                // peut etre en retard sur le debit atomique du depart.
                $planet->addResourcesAtomic(new Resources((int)$element->metal, (int)$element->crystal, (int)$element->deuterium, 0));
            }
            $this->markCanceled($element);

            // Les niveaux suivants du meme objet ne peuvent plus etre atteints dans l ordre.
            $suivants = LifeformQueue::query()
                ->where('planet_id', $planet->getPlanetId())
                ->where('object_id', $element->object_id)
                ->where('status', 'waiting')
                ->where('target_level', '>', $element->target_level)
                ->get();
            foreach ($suivants as $suivant) {
                $this->markCanceled($suivant);
            }

            $this->start($planet, LifeformKind::from($element->kind), $now);
        });
    }

    /**
     * Les travaux en cours dont l echeance est passee, par echeance croissante.
     *
     * @return Collection<int, LifeformQueue>
     */
    public function dueItems(int $planetId, int $now): Collection
    {
        return LifeformQueue::query()
            ->where('planet_id', $planetId)
            ->where('status', 'running')
            ->where('time_end', '<=', $now)
            ->orderBy('time_end')
            ->orderBy('id')
            ->get();
    }

    /**
     * Livre un travail echu : le niveau s ecrit, l element est clos, le suivant demarre a l echeance.
     */
    public function deliver(PlanetService $planet, LifeformQueue $element, float|null $populationAtDeadline = null): void
    {
        if ($element->status !== 'running') {
            return;
        }
        $genre = LifeformKind::from($element->kind);
        $this->levels->setLevel($planet->getPlanetId(), $genre, (int)$element->object_id, (int)$element->target_level);
        $element->status = 'done';
        $element->save();
        $this->start($planet, $genre, (int)$element->time_end, $populationAtDeadline);
    }

    /**
     * Les elements en attente ou en cours d un genre.
     *
     * @return Collection<int, LifeformQueue>
     */
    public function queued(int $planetId, LifeformKind $kind): Collection
    {
        return LifeformQueue::query()
            ->where('planet_id', $planetId)
            ->where('kind', $kind->value)
            ->whereIn('status', ['waiting', 'running'])
            ->orderBy('id')
            ->get();
    }

    public function running(int $planetId, LifeformKind $kind): LifeformQueue|null
    {
        return LifeformQueue::query()
            ->where('planet_id', $planetId)
            ->where('kind', $kind->value)
            ->where('status', 'running')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param array<int, int> $buildingLevels
     */
    public function requirementsMet(LifeformObject $object, array $buildingLevels): bool
    {
        if ($object->kind === LifeformKind::Technology) {
            // L arbre technologique s ouvre avec le centre de recherche de l espece (index 3).
            $centre = LifeformCatalogue::buildingWithEffect($object->species, LifeformEffect::LF_RESEARCH_TIME_REDUCTION);

            return $centre !== null && ($buildingLevels[$centre->id] ?? 0) >= 1;
        }
        foreach ($object->requirements as $exige => $niveau) {
            if (($buildingLevels[$exige] ?? 0) < $niveau) {
                return false;
            }
        }

        return true;
    }

    public function populationMet(LifeformObject $object, int $targetLevel, LifeformPlanet $state): bool
    {
        return $this->populationMetWith($object, $targetLevel, (float)$state->population);
    }

    /**
     * Le meme controle, sur une population donnee — celle de l instant ou le travail demarre.
     */
    public function populationMetWith(LifeformObject $object, int $targetLevel, float $population): bool
    {
        return $population + 1e-9 >= LifeformFormulas::populationRequired($object, $targetLevel);
    }

    /**
     * Une technologie ne se recherche que depuis un emplacement ouvert de la planete ; un batiment
     * n est pas concerne.
     *
     * @param array<int, int> $buildingLevels
     */
    public function researchable(LifeformObject $object, int $planetId, LifeformPlanet $state, array $buildingLevels, float|null $population = null): bool
    {
        if ($object->kind !== LifeformKind::Technology) {
            return true;
        }
        $espece = Species::from((int)$state->species);
        $profil = PlanetLifeformProfile::fromLevels($espece, $buildingLevels, $this->revisions->live()->demography());

        return $this->research->mayResearch($object, $planetId, $state, $profil, $espece, $buildingLevels, $population);
    }

    /**
     * @param array<int, int> $buildingLevels
     */
    private function requireRequirements(LifeformObject $object, array $buildingLevels): void
    {
        if (!$this->requirementsMet($object, $buildingLevels)) {
            throw new LifeformRefused(LifeformRefused::REQUIREMENTS_UNMET, $object->machineName);
        }
    }

    private function requirePopulation(LifeformObject $object, int $targetLevel, LifeformPlanet $state): void
    {
        if (!$this->populationMet($object, $targetLevel, $state)) {
            throw new LifeformRefused(LifeformRefused::POPULATION_UNMET, $object->machineName);
        }
    }

    private function stateOf(PlanetService $planet): LifeformPlanet
    {
        if (!$planet->isPlanet()) {
            throw new LifeformRefused(LifeformRefused::NOT_A_PLANET);
        }
        $etat = LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->first();
        if ($etat === null) {
            throw new LifeformRefused(LifeformRefused::NO_SPECIES);
        }

        return $etat;
    }

    private function markCanceled(LifeformQueue $element): void
    {
        $element->status = 'canceled';
        $element->save();
    }
}
