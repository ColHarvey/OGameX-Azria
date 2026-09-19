<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformKind;
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
use OGame\Services\ObjectService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **La page des reglages de production montre la ligne des formes de vie** (audit des effets, journal §157).
 *
 * `ProductionIndex` porte une ligne `lifeform` depuis la tranche 5 et le total la compte ; la page, elle, ne
 * l affichait pas : les lignes visibles ne faisaient plus le total, et le joueur ne voyait nulle part ce que sa
 * Forge de magma lui rapporte. Une ligne sur le modele de la classe de personnage, portrait de l espece, grisee
 * sans espece.
 */
final class LifeformResourceSettingsTest extends AccountTestCase
{
    use PinsSettings;

    private const int MAGMA_FORGE = 12106;

    private const int DISRUPTION_CHAMBER = 12107;

    /** Centre de recherche minerale : un gros consommateur d energie (120 × 1,3^niveau). */
    private const int MINERAL_RESEARCH_CENTRE = 12111;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1]);
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

    public function testTheLifeformRowShowsWhatTheBuildingsBringAndTheRowsMakeTheTotal(): void
    {
        $planete = $this->planetService;
        $this->planetSetObjectLevel('metal_mine', 10);
        $this->planetSetObjectLevel('solar_plant', 40);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::MAGMA_FORGE, 10);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::DISRUPTION_CHAMBER, 10);
        LifeformBonusCache::invalidate();
        $planete->updateResourceProductionStats(true);

        $mine = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('metal_mine'));
        $solaire = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('solar_plant'));
        $metalAttendu = (int)$mine->lifeform->metal->get();
        $energieAttendue = (int)($mine->lifeform->energy->get() + $solaire->lifeform->energy->get());
        $this->assertGreaterThan(0, $metalAttendu, 'Forge de magma niveau 10 : la ligne a quelque chose a montrer.');
        $this->assertGreaterThan(0, $energieAttendue, 'Chambre de perturbation niveau 10 : la mine consomme moins et la centrale rend plus.');

        $page = (string)$this->get('/resources/settings')->assertStatus(200)->getContent();
        $this->assertStringContainsString(__('t_ingame.resource_settings.lifeform'), $page, 'La page des reglages montre la ligne des formes de vie.');
        $ligne = $this->ligne($page, __('t_ingame.resource_settings.lifeform'));
        $this->assertNotSame('', $ligne);
        $this->assertStringContainsString('lifeform-item-icon small lifeform' . Species::Rocktal->value, $ligne, 'Le portrait de l espece du compte.');
        $this->assertStringContainsString(e(__('t_lifeforms.species.rocktal')), $ligne, 'L infobulle nomme l espece.');
        $this->assertStringNotContainsString('grayscale', $ligne, 'Avec une espece, la ligne n est pas grisee.');
        $this->assertSame([$metalAttendu, 0, 0, $energieAttendue], $this->valeurs($ligne), 'Les quatre colonnes de la ligne : le metal de la Forge, rien en cristal ni en deuterium, l energie de la Chambre.');

        // Le total annonce est la somme des lignes visibles : revenu de base, batiments (sans classe), foreuses, plasma,
        // articles, officiers, classes, et desormais les formes de vie.
        $total = $this->valeurs($this->ligne($page, __('t_ingame.resource_settings.total_per_hour')));
        $this->assertSame((int)$planete->getMetalProductionPerHour(), $total[0]);
        $sansFormesDeVie = (int)$planete->getMetalProductionPerHour() - $metalAttendu;
        $this->assertGreaterThan($sansFormesDeVie, $total[0], 'Le total compte la ligne ; sans elle, les lignes visibles ne le faisaient pas.');
    }

    /**
     * **L energie que consomment les batiments de formes de vie a sa ligne, et le total d energie est celui du bandeau**
     * (audit des bonus, journal §164). Le bilan de la planete retirait cette energie (bandeau, facteur de production) ; la
     * page n avait aucune ligne pour elle et son total ne la comptait pas, pas plus que celle des foreuses : le joueur lisait
     * une energie positive la ou le bandeau disait la penurie.
     */
    public function testTheLifeformBuildingsEnergyHasItsRowAndTheEnergyTotalIsTheBanners(): void
    {
        $planete = $this->planetService;
        $this->planetSetObjectLevel('metal_mine', 10);
        $this->planetSetObjectLevel('solar_plant', 40);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::MAGMA_FORGE, 10);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::MINERAL_RESEARCH_CENTRE, 10);
        LifeformBonusCache::invalidate();
        $planete->updateResourceProductionStats(true);
        $consommation = resolve(LifeformBonusResolver::class)->buildingEnergyOf($this->currentPlanetId);
        $this->assertGreaterThan(0, $consommation, 'Premisse : les batiments de formes de vie consomment de l energie.');

        $page = (string)$this->get('/resources/settings')->assertStatus(200)->getContent();
        $ligne = $this->ligne($page, __('t_ingame.resource_settings.lifeform_buildings_energy'));
        $this->assertNotSame('', $ligne, 'La page a une ligne pour l energie des batiments de formes de vie.');
        $this->assertSame([0, 0, 0, -$consommation], $this->valeurs($ligne), 'Rien en ressources, et l energie consommee.');

        $bandeau = Planet::query()->whereKey($this->currentPlanetId)->firstOrFail();
        $total = $this->valeurs($this->ligne($page, __('t_ingame.resource_settings.total_per_hour')));
        $this->assertSame((int)$bandeau->energy_max - (int)$bandeau->energy_used, $total[3], 'Le total d energie de la page est celui du bandeau.');
    }

    public function testWithoutASpeciesTheRowIsGreyedAndEmpty(): void
    {
        $this->planetSetObjectLevel('metal_mine', 10);
        $page = (string)$this->get('/resources/settings')->assertStatus(200)->getContent();
        $ligne = $this->ligne($page, __('t_ingame.resource_settings.lifeform'));
        $this->assertNotSame('', $ligne, 'La ligne est la meme sans espece, comme celle de la classe.');
        $this->assertStringContainsString('grayscale', $ligne);
        $this->assertStringContainsString(e(__('t_lifeforms_ui.banner.no_species')), $ligne);
        $this->assertSame([0, 0, 0, 0], $this->valeurs($ligne));
        // La ligne d energie des batiments, elle, n existe pas sans espece : rien a montrer (journal §165).
        $this->assertSame('', $this->ligne($page, __('t_ingame.resource_settings.lifeform_buildings_energy')), 'Sans espece, aucune ligne d energie des batiments de formes de vie.');
    }

    /**
     * La ligne du tableau dont la cellule de libelle porte ce texte — « Formes de vie » figure aussi dans le menu.
     */
    private function ligne(string $page, string $libelle): string
    {
        foreach (explode('<tr', $page) as $bloc) {
            if (preg_match('#class="label">\s*(<em>)?' . preg_quote($libelle, '#') . '\s*(</em>)?\s*</td>#', $bloc) === 1) {
                return $bloc;
            }
        }

        return '';
    }

    /**
     * Les quatre valeurs d une ligne (metal, cristal, deuterium, energie), lues dans le `title` complet des infobulles.
     *
     * @return array<int, int>
     */
    private function valeurs(string $ligne): array
    {
        preg_match_all('/<span class="tooltipCustom[^"]*"\s+title="(-?[\d.,]+)"/', $ligne, $m);

        return array_map(static fn (string $v): int => (int)str_replace(['.', ',', ' '], '', $v), $m[1]);
    }
}
