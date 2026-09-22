<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Empire\EmpireProjection;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Le groupe des formes de vie : le meme instant que le reste, et trois etats qui ne s excluent pas.**
 *
 * Sans espece installee, ce groupe n existe pas et rien de tout cela n est eprouve — c est exactement ce qui a laissé
 * survivre une mutation : retirer l instant explicite du calcul demographique ne faisait rougir aucun essai. Cette
 * classe pose donc une espece, une population et des emplacements, et tient les deux proprietes.
 */
class EmpireLifeformProjectionTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8, 'research_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    /**
     * @param array<string, mixed> $charge
     * @return array<int|string, mixed> une colonne : ses clefs d objets sont numeriques, PHP les rend entieres
     */
    private function colonneDe(array $charge, int $planetId): array
    {
        foreach ($charge['planets'] as $colonne) {
            if ($colonne['id'] === $planetId) {
                return $colonne;
            }
        }

        $this->fail('Le corps ' . $planetId . ' n a pas de colonne.');
    }

    /**
     * **La population suit l instant de la photographie, elle aussi.**
     *
     * Sans cela le groupe des formes de vie parlerait d un autre moment que le reste de la page, et le total
     * melangerait deux instants. Le temoin exige une **croissance observable** entre deux instants : une population
     * figee passerait aussi bien avec l horloge qu avec le parametre, et ne prouverait rien.
     */
    public function testThePopulationFollowsTheSnapshotInstantLikeEverythingElse(): void
    {
        $planete = $this->planetService;
        $instant = (int)Date::now()->timestamp;

        // Un secteur residentiel : sans lui, l espace de vie est nul et la population ne peut pas croitre.
        resolve(LifeformLevels::class)->setLevel($planete->getPlanetId(), LifeformKind::Building, LifeformCatalogue::buildingsOf(Species::Kaelesh)[0]->id, 8);

        $etat = LifeformPlanet::query()->where('planet_id', $planete->getPlanetId())->first();
        $this->assertNotNull($etat, 'La planete doit porter un etat de forme de vie.');
        // Une population **tres au-dessous** de son espace de vie, et de quoi la nourrir : sans cela elle decroitrait
        // vers sa capacite, et le temoin lirait une baisse en croyant lire une hausse.
        $etat->population = 1.0;
        $etat->food = 1_000_000.0;
        $etat->calculated_at = $instant;
        $etat->save();

        $projection = resolve(EmpireProjection::class);
        $maintenant = $this->colonneDe($projection->of($this->player(), false, $instant), $planete->getPlanetId());
        $plusTard = $this->colonneDe($projection->of($this->player(), false, $instant + 7200), $planete->getPlanetId());

        $this->assertArrayHasKey('lf_population', $maintenant, 'Le groupe des formes de vie doit exister.');
        $this->assertGreaterThan(
            $maintenant['lf_population'],
            $plusTard['lf_population'],
            'Deux heures plus tard la population a cru : sans instant explicite, le groupe lirait sa propre horloge.',
        );
    }

    /**
     * **Trois etats, et ils ne s excluent pas.**
     *
     * Une technologie peut etre **active** (emplacement ouvert, elle contribue), **posee sans effet** (emplacement
     * occupe, palier ferme), ou **developpee sans emplacement** (un niveau survit a une remise a zero de palier). Et
     * une recherche en cours peut porter sur une technologie **deja active** : la fleche s ajoute a l etat, elle ne
     * le remplace pas. La forme de la cellule le dit sans sa couleur.
     */
    public function testATechnologyStateIsReadableWithoutItsColourAndResearchIsNotExclusive(): void
    {
        $planete = $this->planetService;
        $technologie = LifeformCatalogue::technologiesOf(Species::Kaelesh)[0];

        // Un niveau, mais aucun emplacement : developpee, sans effet.
        resolve(LifeformLevels::class)->setLevel($planete->getPlanetId(), LifeformKind::Technology, $technologie->id, 4);

        $charge = resolve(EmpireProjection::class)->of($this->player(), false, (int)Date::now()->timestamp);
        $colonne = $this->colonneDe($charge, $planete->getPlanetId());
        $cellule = $colonne[$technologie->id . '_html'];

        // La forme, pas la couleur : ce sont les crochets qui disent « sans emplacement », dans toutes les langues.
        $this->assertStringContainsString('[4]', $cellule, 'Un niveau sans emplacement se lit entre crochets.');
        $this->assertStringContainsString(
            e((string)__('t_ingame.empire.tech_developed')),
            $cellule,
            'La cellule dit en toutes lettres qu elle n a aucun effet.',
        );
        $this->assertSame(0, $colonne[(string)$technologie->id], 'Elle ne compte pas comme active.');

        // Et la legende explique les formes, sans parler de couleur.
        $legende = (string)__('t_ingame.empire.tech_legend');
        $this->assertStringContainsString('[12]', $legende);
        $this->assertStringContainsString('(12)', $legende);
        $this->assertStringContainsString('13', $legende, 'La legende explique aussi la fleche d une recherche en cours.');
    }

    /**
     * **Les effets du compte figurent une seule fois**, jamais multiplies par le nombre de colonnes.
     */
    public function testAccountWideEffectsAppearOnceInTheSummaryAndNeverPerColumn(): void
    {
        $charge = resolve(EmpireProjection::class)->of($this->player(), false, (int)Date::now()->timestamp);

        $this->assertArrayHasKey('lf_effects', $charge['summary']);

        foreach ($charge['planets'] as $colonne) {
            $this->assertSame(0, $colonne['lf_effects'], 'Une colonne ne porte aucune valeur d effet : elle renverrait au total.');
            $this->assertStringContainsString('—', $colonne['lf_effects_html']);
        }

        $nombreDeColonnes = count($charge['planets']);
        $this->assertGreaterThan(1, $nombreDeColonnes);

        // Une technologie ne s additionne pas : le total dit sur combien de corps elle contribue.
        $technologie = LifeformCatalogue::technologiesOf(Species::Kaelesh)[0];
        $this->assertStringContainsString(
            '/ ' . $nombreDeColonnes,
            $charge['summary'][(string)$technologie->id]['html'] ?? '',
            'Le total d une technologie doit nommer le nombre de corps, pas sommer des valeurs.',
        );

        // Et une moyenne de niveau porte son libelle, avec ce meme nombre.
        $batiment = LifeformCatalogue::buildingsOf(Species::Kaelesh)[0];
        $this->assertStringStartsWith('ø ', $charge['summary'][(string)$batiment->id]['html'] ?? '');
        $this->assertStringContainsString(
            (string)$nombreDeColonnes,
            $charge['summary'][(string)$batiment->id]['title'] ?? '',
            'Une moyenne sans son libelle est un nombre qui ment.',
        );
    }
}
