<?php

namespace OGame\Lifeforms\Combat;

use InvalidArgumentException;
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
 * **La regle** : quand l attaquant l emporte (la garnison est detruite et il lui reste des vaisseaux), une
 * part de la population **exposee** perit. Exposee : ce que le Bouclier planetaire ne protege pas (3 % par
 * niveau, plafond 90 %, `DemographicRules` regle 8). La part qui meurt est **le taux de morts**, un reglage
 * d administration — 25 % au depart, choix d equilibrage Azria arrete par Keven le 16 septembre 2026 sur la
 * recommandation de Codex (journal §155.20) ; zero desactive les morts, cent est la regle dure des tranches
 * precedentes. Restent toujours l abri de cent habitants, jamais plus que la population. Les paliers se
 * derivent de la population : ils tombent avec elle.
 *
 * **La part protegee et le taux viennent du contexte d application**, pas du corps vivant ni du reglage du
 * moment : un combat durable les photographie a l ouverture — changer le reglage ne touche aucune bataille
 * deja ouverte —, une attaque instantanee les lit a l arrivee. La population, elle, est celle de l instant
 * d application, avancee par l horloge demographique — les habitants nes pendant le ralliement sont la quand
 * la bataille s applique.
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
     * @param int $lossPercent la part de la population exposee qui meurt, de 0 a 100 — figee par le contexte
     * @return int les habitants perdus ; zero quand rien ne s applique
     */
    public function applyIfAttackerWon(BattleResult $result, PlanetService $planet, float|null $protectedShare, int $lossPercent, int $instant): int
    {
        // **Une porte de confiance ne ramene pas en silence** : un taux hors de 0 a 100 ne vient d aucun reglage
        // accepte ni d aucune photographie relue, et il est refuse avant tout effet.
        if ($lossPercent < 0 || $lossPercent > 100) {
            throw new InvalidArgumentException('Le taux de morts de population vaut ' . $lossPercent . ' : il tient entre 0 et 100.');
        }
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

        // **Les morts** : le taux, sur la population exposee — ce que le Bouclier ne protege pas —, jamais en
        // dessous de l abri. A cent, tout ce qui n est pas protege meurt ; a zero, personne, et rien n est ecrit.
        $population = $avant->population;
        $part = max(0.0, min(1.0, $protectedShare));
        $exposee = max(0.0, $population - $population * $part);
        $morts = floor($exposee * $lossPercent / 100);
        $survivants = min($population, max((float)DemographicRules::SHELTERED, $population - $morts));
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
                'loss_percent' => $lossPercent,
            ]);
        }

        return $pertes;
    }
}
