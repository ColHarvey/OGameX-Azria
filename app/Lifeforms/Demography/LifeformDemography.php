<?php

namespace OGame\Lifeforms\Demography;

use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;

/**
 * L integration demographique d une planete, **partagee par l ecriture et par la relecture**.
 *
 * ## Une seule facon d avancer le temps
 *
 * `LifeformPlanetUpdater` avance l etat en livrant les travaux au passage ; ce lecteur-ci rejoue le meme
 * trajet **sans rien ecrire**, pour repondre a « quelle population la planete portait-elle a cet instant ? ».
 * Les deux coupent aux memes endroits — echeance d un travail, revision de vitesse — et prennent les memes
 * niveaux, ceux que `LifeformLevels::levelsAt()` rend pour le debut de chaque morceau. Ce n est pas une
 * promesse : `LifeformDemographyTest` rejoue un passage reel et exige **la meme population au flottant pres**.
 *
 * ## D ou part la relecture
 *
 * La colonne `previous_calculated_at` garde l etat **d ou le dernier passage est parti**. Tout instant que ce
 * passage a traverse se rejoue donc exactement. C est la fenetre qui compte : la requete qui traite une
 * arrivee est la premiere dont l horloge la depasse, donc son passage part avant elle.
 *
 * **Hors de cette fenetre**, `populationAt()` rend la population de la colonne quand celle-ci est **anterieure
 * ou egale** a l instant demande — en retard, donc jamais retroactive — et `null` quand elle lui est
 * posterieure sans instantane pour la rejouer. Un `null` veut dire « je ne sais pas », et l appelant le dit au
 * lieu de deviner (journal §155.11).
 */
final class LifeformDemography
{
    public function __construct(
        private readonly LifeformRuleRevisions $revisions,
        private readonly LifeformLevels $levels,
        private readonly DemographicClock $clock,
    ) {
    }

    /**
     * La population de la planete a cet instant, ou null si elle n est pas reconstituable.
     */
    public function populationAt(int $planetId, int $at): float|null
    {
        $ligne = LifeformPlanet::query()->where('planet_id', $planetId)->first();
        if ($ligne === null) {
            return null;
        }

        return $this->stateAt($ligne, $at)?->population;
    }

    /**
     * L etat demographique de la planete a cet instant, ou null s il n est pas reconstituable.
     */
    public function stateAt(LifeformPlanet $row, int $at): DemographicState|null
    {
        $calcule = (int)$row->calculated_at;
        if ($at >= $calcule) {
            // L horloge n a pas encore depasse l instant : la colonne est en retard, jamais en avance.
            return new DemographicState((float)$row->population, (float)$row->food, $calcule);
        }

        $depart = $row->previous_calculated_at;
        if ($depart === null || (int)$depart > $at) {
            return null;
        }

        $etat = new DemographicState((float)$row->previous_population, (float)$row->previous_food, (int)$depart);

        return $this->replay((int)$row->planet_id, Species::from((int)$row->species), $etat, $at);
    }

    /**
     * Rejoue l horloge d un etat jusqu a un instant, en coupant a chaque changement de taux deja inscrit.
     * N ecrit rien et ne livre rien.
     */
    public function replay(int $planetId, Species $species, DemographicState $from, int $until): DemographicState
    {
        $etat = $from;
        foreach ($this->cutsBetween($planetId, $from->calculatedAt, $until) as $coupe) {
            $etat = $this->advanceTo($etat, $species, $planetId, $coupe);
        }

        return $this->advanceTo($etat, $species, $planetId, $until);
    }

    /**
     * Les instants ou les taux changent entre deux bornes : echeances des travaux et revisions de vitesse.
     *
     * Une echeance anterieure au depart est ramenee au depart — un travail en retard ne fait rien avant que
     * le passage ne commence. La borne basse est incluse, la haute aussi.
     *
     * @return array<int, int> instants croissants, sans doublon
     */
    public function cutsBetween(int $planetId, int $from, int $until): array
    {
        $coupes = [];
        $travaux = LifeformQueue::query()
            ->where('planet_id', $planetId)
            ->whereIn('status', ['done', 'running'])
            ->whereNotNull('time_end')
            ->get(['time_end']);
        foreach ($travaux as $travail) {
            $echeance = max((int)$travail->time_end, $from);
            if ($echeance >= $from && $echeance <= $until) {
                $coupes[] = $echeance;
            }
        }
        foreach ($this->revisions->changesBetween($from, $until) as $instant) {
            if ($instant > $from && $instant <= $until) {
                $coupes[] = $instant;
            }
        }
        $coupes = array_values(array_unique($coupes));
        sort($coupes);

        return $coupes;
    }

    /**
     * Avance l etat jusqu a cet instant, sous les taux et les niveaux en vigueur au **debut** du morceau.
     */
    public function advanceTo(DemographicState $state, Species $species, int $planetId, int $until): DemographicState
    {
        if ($until <= $state->calculatedAt) {
            return $state;
        }
        $niveaux = $this->levels->levelsAt($planetId, LifeformKind::Building, $state->calculatedAt);
        $vitesses = $this->revisions->at($state->calculatedAt);

        return $this->clock->advance($state, PlanetLifeformProfile::fromLevels($species, $niveaux, $vitesses->demography()), $until);
    }
}
