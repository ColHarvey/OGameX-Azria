<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Bonuses\LifeformBonusSet;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformResearchService;
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
 * Le resolveur des bonus (tranche 5) : neutre a zero, batiments locaux et lineaires, technologies
 * sommees sur tout l empire, plafonnees, actives dans un emplacement ouvert seulement.
 */
final class LifeformBonusResolverTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int MAGMA_FORGE = 12106;

    private const int DISRUPTION_CHAMBER = 12107;

    private const int VOLCANIC_BATTERIES = 12201;

    private const int PSIONIC_NETWORK = 14203;

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

    public function testEveryCatalogueEffectIsServedOrDeclared(): void
    {
        $servis = LifeformBonusResolver::APPLIED;
        $ailleurs = LifeformBonusResolver::HANDLED_ELSEWHERE;
        $attendus = LifeformBonusResolver::NOT_YET_APPLIED;
        $this->assertSame([], array_intersect($servis, $ailleurs), 'Un effet ne peut pas etre servi ici et ailleurs.');
        $this->assertSame([], array_intersect($servis, $attendus));
        $this->assertSame([], array_intersect($ailleurs, $attendus));
        $connus = array_merge($servis, $ailleurs, $attendus);
        foreach (LifeformEffect::all() as $code) {
            $this->assertContains($code, $connus, "L effet « $code » n est ni servi, ni declare ailleurs, ni declare en attente.");
        }
        foreach (LifeformCatalogue::all() as $objet) {
            foreach ($objet->bonuses as $bonus) {
                $this->assertContains($bonus->code, $connus, "L objet {$objet->machineName} porte un effet inconnu du resolveur.");
            }
        }
    }

    public function testNothingWithoutSpeciesLevelsOrSwitch(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->assertTrue($resolveur->forPlanet($this->currentPlanetId)->isEmpty(), 'Sans espece, rien.');
        $this->assertTrue($resolveur->forPlayer($this->currentUserId)->isEmpty());
        $this->assertSame(0, $resolveur->buildingEnergyOf($this->currentPlanetId));
        $this->assertSame(1.0, LifeformBonusSet::none()->multiplier(LifeformEffect::METAL_PRODUCTION));
        $this->assertSame(0.0, LifeformBonusSet::none()->reduction(LifeformEffect::RESEARCH_TIME_REDUCTION, 'astrophysics'));

        $this->choose(Species::Rocktal);
        $this->assertTrue($resolveur->forPlanet($this->currentPlanetId)->isEmpty(), 'Une espece sans niveau n apporte rien.');

        $this->building($this->currentPlanetId, self::MAGMA_FORGE, 5);
        $this->assertEqualsWithDelta(0.10, $resolveur->forPlanet($this->currentPlanetId)->fraction(LifeformEffect::METAL_PRODUCTION), 1e-9, 'Forge de magma : 2 % par niveau.');
    }

    /**
     * **L interrupteur ferme bloque les ordres nouveaux, il ne confisque pas ce qui est acquis** — la regle du
     * plan approuve le 15 septembre 2026. Une premiere version rendait tout neutre ; la revue de Codex l a
     * relevee (journal §155.9).
     */
    public function testAClosedSwitchBlocksNewOrdersButKeepsWhatIsAcquired(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Rocktal);
        $this->building($this->currentPlanetId, self::MAGMA_FORGE, 5);
        $acquis = $resolveur->forPlanet($this->currentPlanetId)->fraction(LifeformEffect::METAL_PRODUCTION);
        $energie = $resolveur->buildingEnergyOf($this->currentPlanetId);
        $this->assertEqualsWithDelta(0.10, $acquis, 1e-9);
        $this->assertGreaterThan(0, $energie);

        $this->pinSettings(['lifeforms_enabled' => 0]);
        LifeformBonusCache::invalidate();

        // Ce qui est acquis compte toujours : la production, l energie, et la page des bonus.
        $this->assertEqualsWithDelta($acquis, $resolveur->forPlanet($this->currentPlanetId)->fraction(LifeformEffect::METAL_PRODUCTION), 1e-9, 'Fermer l interrupteur a retire un bonus deja construit.');
        $this->assertSame($energie, $resolveur->buildingEnergyOf($this->currentPlanetId), 'Un batiment ferme cesserait de consommer : sa production resterait, pas sa facture.');

        // Mais plus aucun ordre nouveau ne passe.
        $this->assertRefused(fn () => resolve(LifeformQueueService::class)->add($this->planetService, self::MAGMA_FORGE, (int)Date::now()->timestamp));
        $this->assertRefused(fn () => resolve(LifeformResearchService::class)->choose($this->currentPlanetId, $this->currentUserId, 1, 'local', (int)Date::now()->timestamp));
        $this->assertRefused(fn () => resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId + 500000, Species::Humans, (int)Date::now()->timestamp));
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('Un ordre a ete accepte alors que l interrupteur est ferme.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::CLOSED, $refus->reason, $refus->getMessage());
        }
    }

    public function testBuildingBonusesAreLinearCappedAndLocalToThePlanet(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Rocktal);
        $this->building($this->currentPlanetId, self::MAGMA_FORGE, 5);
        $this->building($this->currentPlanetId, self::DISRUPTION_CHAMBER, 100);

        $jeu = $resolveur->forPlanet($this->currentPlanetId);
        $this->assertEqualsWithDelta(0.10, $jeu->fraction(LifeformEffect::METAL_PRODUCTION), 1e-9);
        $this->assertEqualsWithDelta(1.50, $jeu->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9, 'Chambre de perturbation : 1,5 % par niveau, sans plafond.');
        $this->assertEqualsWithDelta(0.40, $jeu->fraction(LifeformEffect::ENERGY_CONSUMPTION_REDUCTION), 1e-9, '0,5 % par niveau, plafonne a 40 % : 100 niveaux ne font pas 50 %.');
        $this->assertSame(0.0, $jeu->fraction(LifeformEffect::CRYSTAL_PRODUCTION));

        $seconde = $this->secondPlanet();
        $this->assertTrue($resolveur->forPlanet($seconde)->isEmpty(), 'Un batiment ne compte que sur sa planete.');
        $this->assertTrue($resolveur->forPlayer($this->currentUserId)->isEmpty(), 'Les batiments n entrent pas dans les bonus du compte.');
    }

    public function testTechnologyBonusesSumAcrossThePlanetsOfTheAccountAndAreCapped(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Rocktal);
        $seconde = $this->secondPlanet();

        $this->technology($this->currentPlanetId, 1, self::VOLCANIC_BATTERIES, 4);
        $this->technology($seconde, 1, self::VOLCANIC_BATTERIES, 6);

        $attendu = (4 + 6) * 0.25 / 100;
        $this->assertEqualsWithDelta($attendu, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9, 'Les niveaux des deux planetes s additionnent.');
        $this->assertEqualsWithDelta($attendu, $resolveur->forPlanet($this->currentPlanetId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9);
        $this->assertEqualsWithDelta($attendu, $resolveur->forPlanet($seconde)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9, 'Une technologie compte sur toutes les planetes.');

        // L experience de l espece multiplie la part : niveau 1 (1 000 points) = +0,1 %.
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->where('species', Species::Rocktal->value)->update(['experience' => 1000]);
        LifeformBonusCache::invalidate();
        $this->assertEqualsWithDelta($attendu * 1.001, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9);

        // Un plafond s applique au total : Reseau psionique 0,05 % par niveau, au plus 50 %.
        $this->technology($this->currentPlanetId, 3, self::PSIONIC_NETWORK, 700);
        $this->technology($seconde, 3, self::PSIONIC_NETWORK, 700);
        $this->assertEqualsWithDelta(0.50, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::EXPEDITION_FLEET_LOSS_REDUCTION), 1e-9, '2 × 35 % plafonnes a 50 %.');
    }

    public function testATechnologyOnlyCountsWhileItsSlotIsOpenAndTheCacheFollowsTheWrites(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Rocktal);
        $this->technology($this->currentPlanetId, 1, self::VOLCANIC_BATTERIES, 4);
        $this->assertEqualsWithDelta(0.01, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9);

        // La population retombe sous le seuil : l emplacement se ferme, la technologie ne compte plus.
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 100.0]);
        $this->assertEqualsWithDelta(0.01, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9, 'La memoire tient jusqu a l invalidation ou la minute.');
        LifeformBonusCache::invalidate();
        $this->assertSame(0.0, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 'Emplacement ferme : la technologie dort.');

        // Une ecriture de niveau invalide d elle-meme.
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 2000000.0]);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::VOLCANIC_BATTERIES, 8);
        $this->assertEqualsWithDelta(0.02, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9);
    }

    public function testBuildingEnergyIsSummedFromTheLevels(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Rocktal);
        $forge = LifeformCatalogue::byId(self::MAGMA_FORGE);
        $chambre = LifeformCatalogue::byId(self::DISRUPTION_CHAMBER);
        $this->assertGreaterThan(0, $forge->energy);
        $this->building($this->currentPlanetId, self::MAGMA_FORGE, 3);
        $this->building($this->currentPlanetId, self::DISRUPTION_CHAMBER, 2);

        $attendu = LifeformFormulas::energy($forge, 3) + LifeformFormulas::energy($chambre, 2);
        $this->assertGreaterThan(0, $attendu);
        $this->assertSame($attendu, $resolveur->buildingEnergyOf($this->currentPlanetId));
        $this->assertSame(0, $resolveur->buildingEnergyOf($this->secondPlanet()), 'Une autre planete sans batiment ne consomme rien.');
    }

    private function choose(Species $species): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, $species, (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();
    }

    private function secondPlanet(): int
    {
        $planete = $this->createPlanetAtSafeCoordinate($this->currentUserId);
        resolve(LifeformInstallationService::class)->installOnExistingPlanet($planete->getPlanetId(), (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();

        return $planete->getPlanetId();
    }

    private function building(int $planetId, int $objectId, int $level): void
    {
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, $objectId, $level);
    }

    private function technology(int $planetId, int $slot, int $objectId, int $level): void
    {
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 2000000.0]);
        $this->placeLifeformSlot($planetId, $slot, $objectId, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Technology, $objectId, $level);
    }
}
