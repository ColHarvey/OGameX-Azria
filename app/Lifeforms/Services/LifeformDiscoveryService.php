<?php

namespace OGame\Lifeforms\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use OGame\GameMessages\LifeformDiscoveryReport;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformDiscovery;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet\Coordinate;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Les vols de decouverte : quota qui s accumule, lancement paye et scelle, reglement credite une fois.
 *
 * ## Le quota s accumule depuis le choix de l espece
 *
 * Regle Azria pour satisfaire « aucun rattrapage » : l accumulation commence a l instant du choix de
 * l espece (50 vols ce jour-la, 50 de plus par jour revolu), jamais a la naissance du compte, et cet
 * instant ne bouge plus. `discoveries_credited_until` avance par jours entiers ; la fraction en cours
 * n est pas perdue, elle attend le jour suivant.
 *
 * ## Le lancement tient le compte, le reglement tient la ligne
 *
 * Lancer verrouille la ligne du compte (quota, artefacts) et debite la planete de depart de facon
 * atomique ; l issue est tiree et ecrite dans la meme transaction. Regler verrouille la ligne du vol,
 * la relit `running`, credite, envoie le rapport et pose `settled_at` : un second passage ne trouve
 * plus rien a faire.
 */
final class LifeformDiscoveryService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformLevels $levels,
        private readonly LifeformResearchService $research,
        private readonly LifeformRuleRevisions $revisions,
        private readonly MessageService $messages,
    ) {
    }

    /**
     * Fait avancer le quota du compte jusqu a maintenant et le rend. Sans espece, null.
     */
    public function accrueQuota(int $userId, int $now): LifeformAccount|null
    {
        return DB::transaction(function () use ($userId, $now): LifeformAccount|null {
            $compte = LifeformAccount::query()->where('user_id', $userId)->lockForUpdate()->first();
            if ($compte === null) {
                return null;
            }
            $this->accrue($compte, $now);

            return $compte;
        });
    }

    /**
     * Lance un vol depuis une planete du compte vers des coordonnees.
     *
     * @throws LifeformRefused
     */
    public function launch(PlanetService $planet, Coordinate $target, int $now): LifeformDiscovery
    {
        if (!$this->settings->lifeformsEnabled()) {
            throw new LifeformRefused(LifeformRefused::CLOSED);
        }
        $joueur = $planet->getPlayer();
        if ($joueur === null || !$planet->isPlanet()) {
            throw new LifeformRefused(LifeformRefused::NOT_A_PLANET);
        }
        $this->requireCoordinates($target);

        return DB::transaction(function () use ($planet, $joueur, $target, $now): LifeformDiscovery {
            $compte = LifeformAccount::query()->where('user_id', $joueur->getId())->lockForUpdate()->first();
            if ($compte === null) {
                throw new LifeformRefused(LifeformRefused::NO_SPECIES);
            }
            $espece = Species::from((int)$compte->species);
            $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
            $centre = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::LF_RESEARCH_TIME_REDUCTION);
            if ($centre === null || ($niveaux[$centre->id] ?? 0) < 1) {
                throw new LifeformRefused(LifeformRefused::DISCOVERY_LOCKED);
            }
            $this->accrue($compte, $now);
            if ((int)$compte->discoveries_available < 1) {
                throw new LifeformRefused(LifeformRefused::QUOTA_EXHAUSTED);
            }
            $recent = LifeformDiscovery::query()
                ->where('user_id', $joueur->getId())
                ->where('galaxy', $target->galaxy)->where('system', $target->system)->where('position', $target->position)
                ->where('started_at', '>', $now - LifeformDiscoveryRules::REEXPLORATION_DELAY)
                ->exists();
            if ($recent) {
                throw new LifeformRefused(LifeformRefused::RECENTLY_EXPLORED, $target->asString());
            }
            if (!$planet->deductResourcesAtomic(LifeformDiscoveryRules::cost())) {
                throw new LifeformRefused(LifeformRefused::INSUFFICIENT_RESOURCES);
            }

            $compte->discoveries_available = (int)$compte->discoveries_available - 1;
            $compte->save();

            $issue = LifeformDiscoveryRules::draw($this->research->discoveredSpeciesOf($joueur->getId()));
            $duree = LifeformDiscoveryRules::duration(
                LifeformDiscoveryRules::distance($planet->getPlanetCoordinates(), $target),
                $this->envoysReduction($joueur->getId(), $planet->getPlanetId()),
                $this->revisions->live()->discovery(),
            );

            return LifeformDiscovery::query()->create([
                'user_id' => $joueur->getId(),
                'planet_id' => $planet->getPlanetId(),
                'galaxy' => $target->galaxy,
                'system' => $target->system,
                'position' => $target->position,
                'started_at' => $now,
                'ends_at' => $now + $duree,
                'outcome' => $issue->toStorage(),
                'status' => 'running',
                'rules_version' => LifeformDiscoveryRules::VERSION,
            ]);
        });
    }

    /**
     * Regle les vols echus du compte : credite une fois, envoie le rapport.
     */
    public function settleDue(PlayerService $player, int $now): int
    {
        $regles = 0;
        $echus = LifeformDiscovery::query()->where('user_id', $player->getId())->where('status', 'running')->where('ends_at', '<=', $now)->oldest('ends_at')->pluck('id');
        foreach ($echus as $id) {
            $fait = DB::transaction(function () use ($id, $player, $now): bool {
                $vol = LifeformDiscovery::query()->whereKey($id)->lockForUpdate()->first();
                if ($vol === null || $vol->status !== 'running') {
                    return false;
                }
                $issue = LifeformDiscoveryOutcome::fromStorage($vol->outcome);
                $compte = LifeformAccount::query()->where('user_id', $player->getId())->lockForUpdate()->first();
                $credite = $issue;
                if ($compte !== null) {
                    $credite = $this->credit($compte, $issue, $now);
                }
                $vol->status = 'settled';
                $vol->settled_at = $now;
                $vol->outcome = $credite->toStorage();
                $vol->save();

                $this->messages->sendSystemMessageToPlayer($player, LifeformDiscoveryReport::class, [
                    'coordinates' => (new Coordinate((int)$vol->galaxy, (int)$vol->system, (int)$vol->position))->asString(),
                    'outcome_code' => $credite->kind,
                    'artifacts' => $credite->artifacts,
                    'experience' => $credite->experience,
                    'species_code' => $credite->species?->machineName() ?? '',
                ]);

                return true;
            });
            if ($fait) {
                $regles++;
            }
        }

        return $regles;
    }

    /**
     * @return Collection<int, LifeformDiscovery>
     */
    public function runningOf(int $userId): Collection
    {
        return LifeformDiscovery::query()->where('user_id', $userId)->where('status', 'running')->oldest('ends_at')->get();
    }

    /**
     * @return Collection<int, LifeformDiscovery>
     */
    public function historyOf(int $userId, int $limit = 20): Collection
    {
        return LifeformDiscovery::query()->where('user_id', $userId)->where('status', 'settled')->latest('settled_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * La reduction de duree des Emissaires intergalactiques (1 % par niveau, actifs sur la planete).
     */
    public function envoysReduction(int $userId, int $planetId): float
    {
        $emissaires = LifeformCatalogue::byMachineName('intergalactic_envoys');
        $bonus = $emissaires->bonus(LifeformEffect::DISCOVERY_DURATION_REDUCTION);
        if ($bonus === null) {
            return 0.0;
        }
        $niveau = $this->levels->levelOf($planetId, $emissaires->kind, $emissaires->id);
        if ($niveau <= 0 || $this->research->slotHolding($planetId, $emissaires->id) === null) {
            return 0.0;
        }

        return min(0.99, LifeformFormulas::technologyBonusPercent($bonus, $niveau) / 100);
    }

    private function accrue(LifeformAccount $compte, int $now): void
    {
        $depuis = (int)($compte->discoveries_credited_until ?? 0);
        if ($depuis <= 0) {
            // Premiere lecture : l accumulation part du choix de l espece, avec le quota du jour.
            $compte->discoveries_started_at = (int)$compte->chosen_at;
            $compte->discoveries_credited_until = (int)$compte->chosen_at;
            $compte->discoveries_available = (int)$compte->discoveries_available + LifeformDiscoveryRules::QUOTA_PER_DAY;
            $depuis = (int)$compte->chosen_at;
        }
        $jours = intdiv(max(0, $now - $depuis), 86400);
        if ($jours > 0) {
            $compte->discoveries_available = (int)$compte->discoveries_available + $jours * LifeformDiscoveryRules::QUOTA_PER_DAY;
            $compte->discoveries_credited_until = $depuis + $jours * 86400;
        }
        if ($compte->isDirty()) {
            $compte->save();
        }
    }

    /**
     * Credite une issue au compte et rend ce qui a vraiment ete credite (la reserve peut refuser).
     */
    private function credit(LifeformAccount $compte, LifeformDiscoveryOutcome $issue, int $now): LifeformDiscoveryOutcome
    {
        switch ($issue->kind) {
            case LifeformDiscoveryOutcome::ARTIFACTS:
                if ((int)$compte->artifacts >= LifeformDiscoveryRules::ARTIFACT_CAP) {
                    return new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::ARTIFACTS, null, 0, 0);
                }
                $compte->artifacts = (int)$compte->artifacts + $issue->artifacts;
                $compte->save();

                return $issue;
            case LifeformDiscoveryOutcome::EXPERIENCE:
            case LifeformDiscoveryOutcome::SPECIES:
                $espece = $issue->species;
                if ($espece === null) {
                    return $issue;
                }
                $progres = LifeformSpeciesProgress::query()->firstOrCreate(
                    ['user_id' => (int)$compte->user_id, 'species' => $espece->value],
                    ['experience' => 0, 'discovered_at' => null]
                );
                if ($progres->discovered_at === null) {
                    $progres->discovered_at = $now;
                }
                $progres->experience = (int)$progres->experience + $issue->experience;
                $progres->save();
                LifeformBonusCache::invalidate();

                return $issue;
            default:
                return $issue;
        }
    }

    private function requireCoordinates(Coordinate $target): void
    {
        if ($target->galaxy < 1 || $target->galaxy > $this->settings->numberOfGalaxies() || $target->system < 1 || $target->system > 499 || $target->position < 1 || $target->position > 15) {
            throw new LifeformRefused(LifeformRefused::BAD_COORDINATES, $target->asString());
        }
    }
}
