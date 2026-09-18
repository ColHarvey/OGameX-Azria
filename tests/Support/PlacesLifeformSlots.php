<?php

namespace Tests\Support;

use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\DemographicClock;
use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Research\LifeformSlotHistory;
use OGame\Lifeforms\Research\LifeformSlotRules;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use RuntimeException;

/**
 * Monte une planete de forme de vie **qu un banc peut mesurer sans mentir** : une technologie posee avec sa
 * ligne d historique, et une population que la planete porte vraiment.
 *
 * ## Une occupation n est jamais ecrite sans son historique
 *
 * L occupation des emplacements se lit a un instant par `lifeform_slot_history`, pas par la colonne : c est ce
 * qui empeche une remise a zero faite apres une arrivee de desarmer la flotte qui arrivait (journal §155.10).
 * Un montage qui ecrit la colonne seule laisse l historique vide — et tout ce qui lit le passe repond « aucune
 * technologie » sans se plaindre. Le banc serait vert et ne prouverait rien. Meme lecon que les lignes de
 * classe (§154.11) ; `LifeformSlotHistoryGuardTest` ferme la classe.
 *
 * ## Une population figee dans le futur est une autre facon de mentir
 *
 * Les montages posaient `calculated_at = maintenant + 10 jours` pour empecher l horloge demographique de ramener
 * une population posee au-dessus de l espace de vie. Cela marchait tant que rien ne relisait le passe. Depuis que
 * le gel d un combat **rejoue** la population de l instant d admission, une horloge dans le futur rend tout
 * instant anterieur irreconstituable — et le combat se suspend, a juste titre. Pire : le gel lit **toutes** les
 * planetes du proprietaire, et le proprietaire de la planete etrangere voisine est partage par tout le
 * processus ; une seule planete laissee figee faisait suspendre le ralliement de l essai suivant.
 *
 * `sustainLifeformPopulation()` monte donc un monde que le jeu soutiendrait : le premier couple logement/ferme
 * qui **porte et nourrit** la population demandee, et la population posee **a l espace de vie**, donc
 * strictement stationnaire — l horloge peut tourner, elle ne bouge plus rien. Rien a reparer au demontage
 * (journal §155.12).
 */
trait PlacesLifeformSlots
{
    /**
     * Le couple logement/ferme stationnaire deja trouve, par espece et par minimum demande.
     *
     * @var array<string, array{0: int, 1: int, 2: float}>
     */
    private static array $mondesSoutenus = [];

    /**
     * Les planetes dont l horloge a ete arretee pour une lecture vivante, a effacer au demontage.
     *
     * @var array<int, bool>
     */
    private static array $horlogesTenues = [];

    /**
     * @param int $at instant du choix ; il date aussi la ligne d historique
     */
    protected function placeLifeformSlot(int $planetId, int $slot, int $objectId, int $at): void
    {
        // **La technologie va dans l emplacement de son indice, et nulle part ailleurs** : c est ce que le vrai chemin
        // (`LifeformResearchService::choose`, WRONG_SLOT) impose. Un banc qui posait une technologie de palier 3 dans un
        // emplacement de palier 1 mesurait un etat que le jeu ne produit jamais, avec une population qui n ouvre pas
        // cet emplacement-la (audit des effets, journal §157).
        $objet = LifeformCatalogue::byId($objectId);
        if ($objet->kind !== LifeformKind::Technology || $objet->index !== $slot) {
            throw new RuntimeException("La technologie $objet->machineName (indice $objet->index) ne va pas dans l emplacement $slot : le jeu la refuserait (WRONG_SLOT).");
        }
        LifeformSlot::query()->updateOrCreate(
            ['planet_id' => $planetId, 'slot' => $slot],
            ['object_id' => $objectId, 'chosen_via' => 'local', 'selected_at' => $at]
        );
        resolve(LifeformSlotHistory::class)->record($planetId, $slot, $objectId, $at);
    }

    /**
     * Ouvre le palier d un emplacement par les capacites de l espece : le batiment de palier 2 (et 3) au plus petit
     * niveau dont la capacite atteint l exigence de l emplacement — base × N × facteur^(N−1), la formule du profil.
     * La population, elle, reste a la charge de l essai (posee ou soutenue).
     */
    protected function openLifeformTierFor(int $planetId, Species $species, int $slot): void
    {
        $exigence = LifeformSlotRules::populationRequired($slot);
        $niveaux = resolve(LifeformLevels::class);
        foreach ([2 => LifeformEffect::TIER2_CAPACITY, 3 => LifeformEffect::TIER3_CAPACITY] as $palier => $code) {
            if (LifeformSlotRules::tierOf($slot) < $palier) {
                continue;
            }
            $batiment = LifeformCatalogue::buildingWithEffect($species, $code);
            $bonus = $batiment?->bonus($code);
            if ($batiment === null || $bonus === null) {
                throw new RuntimeException('L espece ' . $species->name . ' n a pas de batiment de palier ' . $palier . ' au catalogue.');
            }
            for ($niveau = 1; $niveau <= 60; $niveau++) {
                if (LifeformFormulas::quantityFromLevelOne($bonus, $niveau) + 1e-9 >= $exigence) {
                    $niveaux->setLevel($planetId, LifeformKind::Building, $batiment->id, max($niveau, $niveaux->levelOf($planetId, LifeformKind::Building, $batiment->id)));
                    continue 2;
                }
            }
            throw new RuntimeException("Aucun niveau de {$batiment->machineName} n ouvre l emplacement $slot.");
        }
        LifeformBonusCache::invalidate();
    }

    /**
     * Pose une technologie **dans l emplacement de son indice**, palier ouvert par ses capacites, et rend cet emplacement.
     */
    protected function placeLifeformTechnology(int $planetId, Species $species, int $objectId, int $at): int
    {
        $objet = LifeformCatalogue::byId($objectId);
        $this->openLifeformTierFor($planetId, $species, $objet->index);
        $this->placeLifeformSlot($planetId, $objet->index, $objectId, $at);

        return $objet->index;
    }

    /**
     * Pose sur la planete une population **stationnaire** d au moins `$atLeast` habitants, et la rend.
     *
     * La population posee vaut l espace de vie du couple choisi : l horloge la plafonne la, donc elle ne bouge
     * plus, et un essai peut mesurer des pertes sans qu une croissance vienne brouiller le compte. L instant
     * `$at` date l horloge **et** l etat garde, si bien que tout instant ulterieur se relit.
     *
     * @return float la population reellement posee
     */
    protected function sustainLifeformPopulation(int $planetId, Species $species, float $atLeast, int $at): float
    {
        [$niveauLogement, $niveauFerme, $population] = $this->aSustainableWorld($species, $atLeast);

        $logement = LifeformCatalogue::buildingWithEffect($species, LifeformEffect::LIVING_SPACE);
        $ferme = LifeformCatalogue::buildingWithEffect($species, LifeformEffect::FOOD_PRODUCTION);
        if ($logement === null || $ferme === null) {
            throw new RuntimeException('L espece ' . $species->name . ' n a ni logement ni ferme au catalogue.');
        }

        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, $logement->id, $niveauLogement);
        $niveaux->setLevel($planetId, LifeformKind::Building, $ferme->id, $niveauFerme);

        $profil = PlanetLifeformProfile::fromLevels($species, $niveaux->buildingLevelsOf($planetId), resolve(LifeformRuleRevisions::class)->live()->demography());
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => $population,
            'food' => $profil->foodStorage,
            'calculated_at' => $at,
            'previous_population' => $population,
            'previous_food' => $profil->foodStorage,
            'previous_calculated_at' => $at,
        ]);
        LifeformBonusCache::invalidate();

        return $population;
    }

    /**
     * Fige la population d une planete en arretant son horloge, **pour une lecture vivante seulement**.
     *
     * Certains essais mesurent un nombre exact affiche — un rapport d espionnage, une page de bonus — et lisent
     * la colonne, jamais un instant passe. Une horloge posee dans le futur leur convient, mais elle ne doit
     * **jamais survivre a l essai** : le gel d un combat lit toutes les planetes d un proprietaire, et le
     * proprietaire de la planete etrangere voisine est partage par tout le processus. Une horloge oubliee sur
     * une de ses planetes faisait suspendre le ralliement d une classe voisine, loin de la (journal §155.13).
     *
     * `forgetHeldLifeformPlanets()`, appele au demontage, efface donc la ligne posee ici : la planete redevient
     * ce qu elle etait, sans forme de vie. `LifeformBenchClockGuardTest` refuse tout autre ecrivain.
     */
    protected function holdLifeformPopulationForLiveRead(int $planetId, float $population, int $now): void
    {
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => $population,
            'calculated_at' => $now + 10 * 86400,
        ]);
        self::$horlogesTenues[$planetId] = true;
        LifeformBonusCache::invalidate();
    }

    /**
     * Efface les lignes posees par `holdLifeformPopulationForLiveRead()`. **A appeler au demontage.**
     */
    protected function forgetHeldLifeformPlanets(): void
    {
        if (self::$horlogesTenues !== []) {
            LifeformPlanet::query()->whereIn('planet_id', array_keys(self::$horlogesTenues))->delete();
            self::$horlogesTenues = [];
            LifeformBonusCache::invalidate();
        }
    }

    /**
     * Le premier couple logement/ferme dont l espace de vie atteint le minimum **et se tient une semaine sans
     * bouger d un habitant**. Cherche une fois par espece et par minimum ; l echec est dit, pas contourne.
     *
     * @return array{0: int, 1: int, 2: float}
     */
    private function aSustainableWorld(Species $species, float $atLeast): array
    {
        $clef = $species->value . ':' . $atLeast;
        if (isset(self::$mondesSoutenus[$clef])) {
            return self::$mondesSoutenus[$clef];
        }

        $logement = LifeformCatalogue::buildingWithEffect($species, LifeformEffect::LIVING_SPACE);
        $ferme = LifeformCatalogue::buildingWithEffect($species, LifeformEffect::FOOD_PRODUCTION);
        if ($logement === null || $ferme === null) {
            throw new RuntimeException('L espece ' . $species->name . ' n a ni logement ni ferme au catalogue.');
        }
        $vitesse = resolve(LifeformRuleRevisions::class)->live()->demography();
        $horloge = new DemographicClock();

        for ($niveauLogement = 1; $niveauLogement <= 45; $niveauLogement++) {
            for ($niveauFerme = $niveauLogement; $niveauFerme <= $niveauLogement + 25; $niveauFerme++) {
                $profil = PlanetLifeformProfile::fromLevels($species, [$logement->id => $niveauLogement, $ferme->id => $niveauFerme], $vitesse);
                if ($profil->livingSpace < $atLeast) {
                    continue 2;
                }
                $population = (float)$profil->livingSpace;
                $apres = $horloge->advance(new DemographicState($population, $profil->foodStorage, 0), $profil, 7 * 86400);
                if (abs($apres->population - $population) < 1e-6) {
                    return self::$mondesSoutenus[$clef] = [$niveauLogement, $niveauFerme, $population];
                }
            }
        }

        throw new RuntimeException('Aucun couple logement/ferme ne soutient ' . $atLeast . ' habitants pour ' . $species->name . '.');
    }
}
