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

        $espece = Species::from((int)$ligne->species);
        $etat = new DemographicState((float)$ligne->population, (float)$ligne->food, (int)$ligne->calculated_at);

        // Les coupes : les echeances des travaux echus et les revisions de vitesse, dans l ordre.
        $echus = $this->queue->dueItems($planet->getPlanetId(), $now);
        $coupes = [];
        foreach ($echus as $element) {
            $coupes[] = max((int)$element->time_end, $etat->calculatedAt);
        }
        foreach ($this->revisions->changesBetween($etat->calculatedAt, $now) as $instant) {
            $coupes[] = $instant;
        }
        $coupes[] = $now;
        $coupes = array_values(array_unique($coupes));
        sort($coupes);

        $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
        foreach ($coupes as $instant) {
            $vitesses = $this->revisions->at($etat->calculatedAt);
            $profil = PlanetLifeformProfile::fromLevels($espece, $niveaux, $vitesses->demography());
            $etat = $this->clock->advance($etat, $profil, $instant);

            // Les travaux echus a cet instant : le niveau s ecrit, les taux changent pour la suite.
            $livre = false;
            foreach ($echus as $element) {
                if ($element->status === 'running' && max((int)$element->time_end, (int)$ligne->calculated_at) === $instant) {
                    $this->queue->deliver($planet, $element);
                    $livre = true;
                }
            }
            if ($livre) {
                $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
                // Un travail demarre a l echeance peut deja etre echu lui aussi (duree d une seconde).
                foreach ($this->queue->dueItems($planet->getPlanetId(), $now) as $nouveau) {
                    if ($echus->doesntContain('id', $nouveau->id)) {
                        $echus->push($nouveau);
                        $coupes[] = max((int)$nouveau->time_end, $instant);
                    }
                }
                $coupes = array_values(array_unique($coupes));
                sort($coupes);
            }
        }

        $ligne->population = $etat->population;
        $ligne->food = $etat->food;
        $ligne->calculated_at = $now;
        $ligne->save();
    }
}
