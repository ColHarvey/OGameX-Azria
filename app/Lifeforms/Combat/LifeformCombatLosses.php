<?php

namespace OGame\Lifeforms\Combat;

use OGame\Combat\Exceptions\UnknownAdmissionHistory;
use OGame\GameMessages\LifeformPopulationLossReport;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Lifeforms\Demography\DemographicRules;
use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\LifeformDemography;
use OGame\Lifeforms\Services\LifeformPlanetUpdater;
use OGame\Lifeforms\Species;
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
        private readonly LifeformDemography $demography,
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

        // Le monde est amene a l instant de la bataille s il est en retard : les travaux echus y sont livres, et
        // l etat ecrit est celui de cet instant. S il est deja en avance, il n est pas touche.
        $this->updater->update($planet, $instant);
        $ligne = LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->lockForUpdate()->first();
        if ($ligne === null) {
            return 0;
        }
        $horloge = (int)$ligne->calculated_at;
        if ($horloge < $instant) {
            // Le passage s est arrete avant l instant (borne atteinte) : le monde a cet instant n est pas etabli.
            throw new UnknownAdmissionHistory(
                'Les pertes de population de la planete ' . $planet->getPlanetId() . ' ne peuvent pas etre appliquees a l instant '
                . $instant . ' : son horloge s est arretee a ' . $horloge . '.'
            );
        }

        // **Les pertes se prennent sur la population de l instant de la bataille**, jamais sur celle de l horloge.
        // Un combat durable se regle a son echeance, que la planete a pu depasser entre-temps par une page
        // chargee : la population d alors est rejouee depuis l ancre. Si elle n est pas reconstituable, on ne
        // devine pas — on suspend, comme pour tout historique d admission manquant (relance de Codex, §155.14).
        $espece = Species::from((int)$ligne->species);
        $avant = $this->demography->stateAt($ligne, $instant);
        if ($avant === null) {
            throw new UnknownAdmissionHistory(
                'Les pertes de population de la planete ' . $planet->getPlanetId() . ' ne peuvent pas etre appliquees a l instant '
                . $instant . ' : la population de cet instant n est pas reconstituable (horloge a ' . $horloge
                . ', etat garde depuis ' . var_export($ligne->previous_calculated_at, true) . ').'
            );
        }

        $population = $avant->population;
        $part = max(0.0, min(1.0, $protectedShare));
        $survivants = min($population, max((float)DemographicRules::SHELTERED, $population * $part));
        $pertes = (int)floor($population - $survivants);
        if ($pertes <= 0) {
            return 0;
        }

        // **Puis les survivants recroissent jusqu a l horloge**, par la meme integration que tout le reste : une
        // bataille reglee dix minutes en retard donne exactement la planete d une bataille reglee a l heure suivie
        // de dix minutes de croissance — population, nourriture et bonus compris. L ancre est datee de la
        // bataille, avec l etat d apres : tout instant de la fenetre se rejoue depuis des survivants, tout instant
        // anterieur devient irreconstituable, donc refuse. Une mort n est pas un evenement que l horloge sait
        // rejouer ; c est l ancre qui la porte.
        $apres = new DemographicState($survivants, $avant->food, $instant);
        $regru = $horloge > $instant ? $this->demography->replay($planet->getPlanetId(), $espece, $apres, $horloge) : $apres;

        $ligne->population = $regru->population;
        $ligne->food = $regru->food;
        $ligne->previous_population = $apres->population;
        $ligne->previous_food = $apres->food;
        $ligne->previous_calculated_at = $instant;
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
