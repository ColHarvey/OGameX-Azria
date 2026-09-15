<?php

namespace OGame\Lifeforms\Services;

use OGame\Lifeforms\Demography\DemographicClock;
use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Services\PlanetService;

/**
 * La mise a jour d une planete peuplee : l horloge demographique avance jusqu a maintenant en
 * coupant a chaque evenement qui change les taux, et les travaux echus sont livres a leur echeance.
 *
 * ## Appele dans la transaction de la planete
 *
 * `PlanetService::update()` l appelle juste apres la file classique, sous le verrou de la ligne de
 * la planete : deux requetes simultanees ne livrent pas deux fois, et l etat ecrit est celui d une
 * seule avance. Une planete sans etat de forme de vie coute une lecture et rien d autre.
 *
 * ## Les coupes
 *
 * Trois choses changent les taux : la fin d un travail (un niveau de plus), une revision de
 * vitesse, et les vacances du joueur — pendant lesquelles le temps ne compte pas. Chaque coupe est
 * un instant ; l horloge avance jusque la avec les taux d avant, l evenement est applique, et on
 * repart. Rien n est jamais applique retroactivement a toute une absence.
 */
final class LifeformPlanetUpdater
{
    /**
     * Le nombre de coupes qu un passage traite au plus.
     *
     * Chaque tour livre un travail ou consomme une revision de vitesse, et les deux sont bornes : la file
     * porte cinq elements en attente par genre, les revisions d une absence se comptent. Ce plafond n est
     * donc pas une regle de jeu mais un garde-fou contre une boucle sur une page du joueur. Il **ne leve
     * rien** : une exception ici condamnerait toutes les pages du compte (journal §120).
     */
    private const int MAX_CUTS = 512;

    public function __construct(
        private readonly LifeformQueueService $queue,
        private readonly LifeformRuleRevisions $revisions,
        private readonly LifeformLevels $levels,
        private readonly DemographicClock $clock,
    ) {
    }

    public function update(PlanetService $planet, int $now): void
    {
        $ligne = LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->first();
        if ($ligne === null) {
            return;
        }
        if ($now <= (int)$ligne->calculated_at) {
            return;
        }

        $joueur = $planet->getPlayer();
        if ($joueur !== null && $joueur->isInVacationMode()) {
            // Le temps ne compte pas : ni croissance, ni consommation, ni livraison.
            $ligne->calculated_at = $now;
            $ligne->save();

            return;
        }

        $planetId = $planet->getPlanetId();
        $espece = Species::from((int)$ligne->species);
        $etat = new DemographicState((float)$ligne->population, (float)$ligne->food, (int)$ligne->calculated_at);
        $depart = $etat->calculatedAt;
        $revisions = $this->revisions->changesBetween($depart, $now);
        $niveaux = $this->levels->buildingLevelsOf($planetId);

        // La file est **relue a chaque tour** : livrer un travail en demarre un autre, qui peut etre
        // echu a son tour dans le meme passage. Une liste de coupes calculee une fois pour toutes ne
        // verrait jamais ces echeances-la, et une absence laisserait des travaux en retard derriere elle.
        for ($tour = 0; $tour < self::MAX_CUTS; $tour++) {
            $coupe = $this->nextCut($planetId, $depart, $now, $etat->calculatedAt, $revisions);
            if ($coupe === null) {
                break;
            }
            $etat = $this->advanceTo($etat, $espece, $niveaux, $coupe);

            $livre = false;
            foreach ($this->queue->dueItems($planetId, $now) as $element) {
                if (max((int)$element->time_end, $depart) === $coupe) {
                    $this->queue->deliver($planet, $element);
                    $livre = true;
                }
            }
            if ($livre) {
                $niveaux = $this->levels->buildingLevelsOf($planetId);
            }
        }

        $etat = $this->advanceTo($etat, $espece, $niveaux, $now);

        $ligne->population = $etat->population;
        $ligne->food = $etat->food;
        $ligne->calculated_at = $now;
        $ligne->save();
    }

    /**
     * La prochaine coupe strictement apres l instant deja integre, ou null s il n en reste aucune avant
     * la fin du passage.
     *
     * Deux sortes : l echeance d un travail **en cours** que la file porte encore — relue a chaque tour,
     * donc les enchainements comptent —, et une revision de vitesse. Une echeance anterieure au depart du
     * passage est ramenee au depart : le travail a beau etre en retard, il ne fait rien avant.
     *
     * @param array<int, int> $revisions instants ou la vitesse du serveur a change
     */
    private function nextCut(int $planetId, int $depart, int $now, int $integre, array $revisions): int|null
    {
        $prochaine = null;
        foreach ($this->queue->dueItems($planetId, $now) as $element) {
            $echeance = max((int)$element->time_end, $depart);
            if ($echeance >= $integre && ($prochaine === null || $echeance < $prochaine)) {
                $prochaine = $echeance;
            }
        }
        foreach ($revisions as $instant) {
            if ($instant > $integre && $instant <= $now && ($prochaine === null || $instant < $prochaine)) {
                $prochaine = $instant;
            }
        }

        return $prochaine === null || $prochaine > $now ? null : $prochaine;
    }

    /**
     * Avance l etat jusqu a cet instant sous les taux en vigueur au **debut** du morceau.
     *
     * @param array<int, int> $levels
     */
    private function advanceTo(DemographicState $state, Species $species, array $levels, int $until): DemographicState
    {
        if ($until <= $state->calculatedAt) {
            return $state;
        }
        $vitesses = $this->revisions->at($state->calculatedAt);

        return $this->clock->advance($state, PlanetLifeformProfile::fromLevels($species, $levels, $vitesses->demography()), $until);
    }
}
