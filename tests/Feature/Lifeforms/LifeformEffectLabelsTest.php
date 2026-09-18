<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformBonus;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Presentation\LifeformEffectPresenter;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Les libelles des effets disent le nom de la cible et le sens de la valeur** (audit des effets, journal §157).
 *
 * Trois ecrivains composaient le libelle d un effet cible : la fiche d un batiment, la fiche d une technologie et la
 * page des bonus. Les deux fiches cherchaient une classe de personnage dans `t_resources` (« Bonus de classe :
 * t_resources.collector.title »), la page ne remplacait jamais `:target` (« … : :target — Chasseur lourd »), et les
 * reductions s ecrivaient « +10 % » sous un libelle qui dit « Reduction ». Un seul ecrivain, `LifeformEffectPresenter`.
 */
final class LifeformEffectLabelsTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int ROCKTAL_COLLECTOR_ENHANCEMENT = 12218;

    private const int FOOD_SILO = 11107;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1, 'research_speed' => 1]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testATargetIsNamedWhetherItIsAnObjectOrAClass(): void
    {
        $collecteur = new LifeformBonus(LifeformEffect::CLASS_BONUS, 'collector', 0.2, 1.0, 0.2);
        $libelle = LifeformEffectPresenter::labelOf($collecteur);
        $this->assertStringContainsString(__('t_ingame.characterclass.collector.name'), $libelle, 'La classe est nommee.');
        $this->assertStringNotContainsString('t_resources.', $libelle);
        $this->assertStringNotContainsString(':target', $libelle);

        $chasseur = new LifeformBonus(LifeformEffect::SHIP_STATS, 'light_fighter', 0.3, 1.0, 0.3);
        $libelle = LifeformEffectPresenter::labelOf($chasseur);
        $this->assertStringContainsString(__('t_resources.light_fighter.title'), $libelle, 'Un vaisseau garde son nom.');
        $this->assertStringNotContainsString(':target', $libelle);

        $this->assertSame(__('t_lifeforms_ui.effects.metal_production', ['target' => '']), LifeformEffectPresenter::labelOf(new LifeformBonus(LifeformEffect::METAL_PRODUCTION, null, 1.0, 1.0, null)), 'Sans cible, le libelle seul.');
    }

    /**
     * Le vrai chemin : la fiche d une technologie de classe et la page des bonus.
     */
    public function testTheTechnologyPanelAndTheBonusPageNameTheClassAndTheShip(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, $maintenant);
        $planetId = $this->currentPlanetId;
        $niveaux = resolve(LifeformLevels::class);
        // L emplacement 18 exige 448 M de population de palier 3 : Forge des runes 10 (T2 520 M), Oriktorium 4 (T3 479 M).
        $niveaux->setLevel($planetId, LifeformKind::Building, 12104, 10);
        $niveaux->setLevel($planetId, LifeformKind::Building, 12105, 4);
        $niveaux->setLevel($planetId, LifeformKind::Building, 12103, 1);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 500000000.0]);
        $this->placeLifeformSlot($planetId, 18, self::ROCKTAL_COLLECTOR_ENHANCEMENT, $maintenant);
        $niveaux->setLevel($planetId, LifeformKind::Technology, self::ROCKTAL_COLLECTOR_ENHANCEMENT, 1);
        LifeformBonusCache::invalidate();

        $fiche = (string)$this->get(route('lifeforms.research.ajax', ['technology' => self::ROCKTAL_COLLECTOR_ENHANCEMENT]))->json('content.technologydetails');
        $this->assertStringContainsString(e(__('t_lifeforms_ui.effects.class_bonus', ['target' => __('t_ingame.characterclass.collector.name')])), $fiche, 'La fiche nomme le Collecteur.');
        $this->assertStringNotContainsString('t_resources.collector', $fiche);

        $page = (string)$this->get(route('lifeforms.bonuses'))->assertStatus(200)->getContent();
        $this->assertStringContainsString(e(__('t_lifeforms_ui.effects.class_bonus', ['target' => __('t_ingame.characterclass.collector.name')])), $page, 'La page des bonus aussi.');
        $this->assertStringNotContainsString(':target', $page, 'Le remplacement est fait.');
        $this->assertStringNotContainsString('t_resources.collector', $page);
    }

    public function testAReductionIsWrittenWithItsSign(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, $maintenant);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::FOOD_SILO, 10);
        $fiche = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::FOOD_SILO]))->json('content.technologydetails');
        $this->assertSame(1, preg_match('#data-effect="food_consumption_reduction">\s*<td>[^<]*</td>\s*<td[^>]*>−10 %</td>#', $fiche), 'La reduction de consommation du Silo niveau 10 : « −10 % », pas « +10 % ».');
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, 11106, 10);
        $fonderie = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => 11106]))->json('content.technologydetails');
        $this->assertSame(1, preg_match('#data-effect="metal_production">\s*<td>[^<]*</td>\s*<td[^>]*>\+[0-9.]+ %</td>#', $fonderie), 'Une hausse (Fonderie a haute energie) garde son « + ».');
        $this->assertTrue(LifeformEffect::isReduction(LifeformEffect::FOOD_CONSUMPTION_REDUCTION));
        $this->assertTrue(LifeformEffect::isReduction(LifeformEffect::EXPEDITION_FLEET_LOSS_REDUCTION));
        $this->assertFalse(LifeformEffect::isReduction(LifeformEffect::FOOD_STORAGE_PERCENT));
        $this->assertFalse(LifeformEffect::isReduction(LifeformEffect::SHIP_STATS));
    }
}
