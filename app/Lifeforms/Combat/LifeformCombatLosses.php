<?php

namespace OGame\Lifeforms\Combat;

use OGame\GameMessages\LifeformPopulationLossReport;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Lifeforms\Demography\DemographicRules;
use OGame\Lifeforms\Services\LifeformPlanetUpdater;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;

/**
 * Les morts de la population quand une attaque reussit — decision de Keven, 15 septembre 2026 (journal §155.6).
 *
 * **La regle** : quand l attaquant l emporte (la garnison est detruite et il lui reste des vaisseaux), la
 * population non protegee perit. Restent : la part que le Bouclier planetaire protege (3 % par niveau,
 * plafond 90 %, `DemographicRules` regle 8), jamais moins que l abri de cent habitants, jamais plus que la
 * population. Les paliers se derivent de la population : ils tombent avec elle.
 *
 * **La part protegee vient du contexte d application**, pas du corps vivant : un combat durable la
 * photographie a l ouverture, une attaque instantanee la lit a l arrivee. La population, elle, est celle de
 * l instant d application, avancee par l horloge demographique — les habitants nes pendant le ralliement
 * sont la quand la bataille s applique.
 */
final class LifeformCombatLosses
{
    public function __construct(
        private readonly LifeformPlanetUpdater $updater,
        private readonly MessageService $messages,
    ) {
    }

    /**
     * @return int les habitants perdus ; zero quand rien ne s applique
     */
    public function applyIfAttackerWon(BattleResult $result, PlanetService $planet, float|null $protectedShare, int $instant): int
    {
        if ($protectedShare === null || !$planet->isPlanet()) {
            return 0;
        }
        if ($result->defenderUnitsResult->getAmount() > 0 || $result->attackerUnitsResult->getAmount() === 0) {
            return 0;
        }
        if (!LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->exists()) {
            return 0;
        }

        $this->updater->update($planet, $instant);
        $ligne = LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->lockForUpdate()->first();
        if ($ligne === null) {
            return 0;
        }

        $population = (float)$ligne->population;
        $part = max(0.0, min(1.0, $protectedShare));
        $survivants = min($population, max((float)DemographicRules::SHELTERED, $population * $part));
        $pertes = (int)floor($population - $survivants);
        if ($pertes <= 0) {
            return 0;
        }

        $ligne->population = $survivants;
        $ligne->save();

        $proprietaire = $planet->getPlayer();
        if ($proprietaire !== null) {
            $this->messages->sendSystemMessageToPlayer($proprietaire, LifeformPopulationLossReport::class, [
                'coordinates' => '[coordinates]' . $planet->getPlanetCoordinates()->asString() . '[/coordinates]',
                'lost' => $pertes,
                'survivors' => (int)floor($survivants),
                'protected_percent' => (int)round($part * 100),
            ]);
        }

        return $pertes;
    }
}
