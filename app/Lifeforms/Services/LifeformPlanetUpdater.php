<?php

namespace OGame\Lifeforms\Services;

use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\LifeformDemography;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
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
        private readonly LifeformDemography $demography,
        /**
         * La couture qui rend la borne temoignable.
         *
         * En jeu elle vaut null et la borne se derive des donnees (`boundOf()`), ou elle est **hors
         * d atteinte par construction**. Un banc la baisse pour eprouver ce qui se passe quand elle est
         * atteinte : le passage s arrete la, l horloge ne depasse pas ce qu il a traite, et le passage
         * suivant reprend. Une garde qu on ne peut pas voir tomber n est pas une garde.
         */
        private readonly int|null $tourLimit = null,
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

        // **L etat d ou ce passage part est garde** : il rend tout instant qu il traverse rejouable, et
        // c est ce qui permet a un combat de savoir quelle population la planete portait a l arrivee d une
        // flotte (journal §155.11). Ecrit avant la premiere avance, jamais apres.
        $ligne->previous_population = $etat->population;
        $ligne->previous_food = $etat->food;
        $ligne->previous_calculated_at = $depart;

        // La file est **relue a chaque tour** : livrer un travail en demarre un autre, qui peut etre
        // echu a son tour dans le meme passage. Une liste de coupes calculee une fois pour toutes ne
        // verrait jamais ces echeances-la, et une absence laisserait des travaux en retard derriere elle.
        $tours = $this->boundOf($planetId, $revisions);
        for ($tour = 0; $tour < $tours; $tour++) {
            $coupe = $this->nextCut($planetId, $depart, $now, $etat->calculatedAt, $revisions);
            if ($coupe === null) {
                break;
            }
            $etat = $this->demography->advanceTo($etat, $espece, $planetId, $coupe);

            foreach ($this->queue->dueItems($planetId, $now) as $element) {
                if (max((int)$element->time_end, $depart) === $coupe) {
                    // **La population de l echeance**, pas celle de la colonne : l etat n est ecrit qu a la
                    // fin du passage, et le travail suivant demarre ici (journal §155.13).
                    $this->queue->deliver($planet, $element, $etat->population);
                }
            }
        }

        // **L horloge ne depasse jamais ce que le passage a reellement traite.** Si une coupe reste — ce que
        // la borne ci-dessus rend impossible en pratique, et que rien ne garantit pour autant —, l etat
        // s arrete la : le passage suivant reprend exactement ou celui-ci s est arrete, sans perdre ni
        // croissance ni livraison. Avancer jusqu a maintenant en laissant un travail echu derriere aurait
        // fait disparaitre les deux.
        $reste = $this->nextCut($planetId, $depart, $now, $etat->calculatedAt, $revisions) !== null;
        if (!$reste) {
            $etat = $this->demography->advanceTo($etat, $espece, $planetId, $now);
        }

        $ligne->population = $etat->population;
        $ligne->food = $etat->food;
        $ligne->calculated_at = $etat->calculatedAt;
        $ligne->save();
    }

    /**
     * Le nombre de tours qu un passage peut avoir a faire, **derive de ce qu il a devant lui**.
     *
     * Chaque tour livre un travail ou consomme une revision de vitesse : les travaux de la planete et les
     * revisions de la periode bornent donc la boucle, et la borne est une preuve, pas un chiffre choisi. Les
     * deux tours de marge couvrent la coupe finale et un arrondi. Elle **ne leve rien** — une exception ici
     * condamnerait toutes les pages du compte (journal §120) — et l horloge s arrete d elle-meme si elle
     * etait atteinte.
     *
     * @param array<int, int> $revisions
     */
    private function boundOf(int $planetId, array $revisions): int
    {
        if ($this->tourLimit !== null) {
            return $this->tourLimit;
        }

        return LifeformQueue::query()->where('planet_id', $planetId)->count() + count($revisions) + 2;
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
}
