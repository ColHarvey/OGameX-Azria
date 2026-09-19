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
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformDiscovery;
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

    private const int PLASMA_DRIVE = 13202;

    /** Production de puces en masse, Mechas : +0,4 % a toutes les technologies de la planete par niveau. */
    private const int CHIP_MASS_PRODUCTION = 13111;

    private const int PSIONIC_MODULATOR = 14110;

    /** Recuperation de chaleur, Kaelesh : la technologie de l emplacement 1. */
    private const int HEAT_RECOVERY = 14201;

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

    /**
     * **Le bonus « toutes les technologies » d un batiment ne multiplie que les technologies de SA planete** (audit des
     * bonus, journal §164) : Propulsion a plasma 10 sur deux planetes (+2 % chacune), Production de puces en masse 10 sur
     * la premiere seulement (+4 %) : 2 % × 1,04 + 2 % = 4,08 % — ni 4,16 % (le batiment partout), ni 4 % (nulle part).
     */
    public function testTheAllTechnologiesBonusOfABuildingOnlyMultipliesTheTechnologiesOfItsPlanet(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Mechas);
        $seconde = $this->secondPlanet();
        $this->technology($this->currentPlanetId, 2, self::PLASMA_DRIVE, 10);
        $this->technology($seconde, 2, self::PLASMA_DRIVE, 10);
        $this->assertEqualsWithDelta(0.04, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::SHIP_SPEED), 1e-9, 'Premisse : 2 % + 2 %.');

        $this->building($this->currentPlanetId, self::CHIP_MASS_PRODUCTION, 10);
        LifeformBonusCache::invalidate();
        $this->assertEqualsWithDelta(0.0408, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::SHIP_SPEED), 1e-9, '2 % × 1,04 + 2 %.');
        $parts = [];
        foreach ($resolveur->contributionsOf($this->currentUserId) as $part) {
            if ($part->code === LifeformEffect::SHIP_SPEED) {
                $parts[$part->planetId] = $part->fraction;
            }
        }
        $this->assertCount(2, $parts, 'Une contribution par planete.');
        $this->assertEqualsWithDelta(0.0208, $parts[$this->currentPlanetId] ?? 0.0, 1e-9, 'La planete du batiment : × 1,04.');
        $this->assertEqualsWithDelta(0.02, $parts[$seconde] ?? 0.0, 1e-9, 'L autre planete : rien du batiment.');
    }

    /**
     * **Le Modulateur psionique abaisse la population qu exige un emplacement** — prouve par le chemin de l ordre
     * (`mayResearch()`), pas par la seule formule (audit des bonus, journal §164) : niveau 15 = 30 %, l emplacement 1
     * s ouvre a 140 000 habitants au lieu de 200 000.
     */
    public function testThePsionicModulatorLowersThePopulationASlotNeeds(): void
    {
        $this->choose(Species::Kaelesh);
        $this->placeLifeformSlot($this->currentPlanetId, 1, self::HEAT_RECOVERY, (int)Date::now()->timestamp);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 150000.0]);
        $etat = LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->firstOrFail();
        $service = resolve(LifeformResearchService::class);
        $objet = LifeformCatalogue::byId(self::HEAT_RECOVERY);
        $profil = PlanetLifeformProfile::fromLevels(Species::Kaelesh, [], 1.0);

        $this->assertFalse($service->mayResearch($objet, $this->currentPlanetId, $etat, $profil, Species::Kaelesh, []), 'Sans Modulateur : 150 000 < 200 000.');
        $this->assertEqualsWithDelta(0.30, $service->requirementReduction(Species::Kaelesh, [self::PSIONIC_MODULATOR => 15]), 1e-9, '2 % par niveau, plafonne a 30 %.');
        $this->assertTrue($service->mayResearch($objet, $this->currentPlanetId, $etat, $profil, Species::Kaelesh, [self::PSIONIC_MODULATOR => 15]), 'Modulateur 15 : 150 000 ≥ 140 000.');
        $this->assertFalse($service->mayResearch($objet, $this->currentPlanetId, $etat, $profil, Species::Kaelesh, [self::PSIONIC_MODULATOR => 1]), 'Modulateur 1 : 150 000 < 196 000.');
    }

    public function testATechnologyOnlyCountsWhileItsSlotIsOpenAndTheCacheFollowsTheWrites(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Rocktal);
        $this->technology($this->currentPlanetId, 1, self::VOLCANIC_BATTERIES, 4);
        $this->assertEqualsWithDelta(0.01, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9);

        // La population retombe sous le seuil : l emplacement se ferme, la technologie ne compte plus. Ecrite ici
        // hors des ecrivains du jeu (comme le ferait un autre processus), la memoire ne peut pas le savoir avant
        // l invalidation ou la minute ; les ecrivains du jeu, eux, invalident (temoins ci-dessous).
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 100.0]);
        $this->assertEqualsWithDelta(0.01, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9, 'Une ecriture hors du jeu : la memoire tient jusqu a l invalidation ou la minute.');
        LifeformBonusCache::invalidate();
        $this->assertSame(0.0, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 'Emplacement ferme : la technologie dort.');

        // Une ecriture de niveau invalide d elle-meme.
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 2000000.0]);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::VOLCANIC_BATTERIES, 8);
        $this->assertEqualsWithDelta(0.02, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9);
    }

    /**
     * **La croissance ecrite par le passage ouvre l emplacement sans que personne ne vide la memoire**
     * (relance de Codex, journal §155.18). La population decide quelles technologies sont actives ; une
     * memoire qui survivait a son ecriture gardait une technologie endormie apres le franchissement d un
     * seuil — jusqu a une minute dans un processus persistant.
     */
    public function testGrowthWrittenByTheUpdaterOpensTheSlotWithoutClearingTheMemoryByHand(): void
    {
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->choose(Species::Rocktal);
        $planetId = $this->currentPlanetId;
        $maintenant = (int)Date::now()->timestamp;

        // Un monde qui porte au moins 300 000 habitants, et une population encore sous le seuil de l emplacement 1.
        $espace = $this->sustainLifeformPopulation($planetId, Species::Rocktal, 300000.0, $maintenant);
        $this->assertGreaterThanOrEqual(300000.0, $espace);
        $this->placeLifeformSlot($planetId, 1, self::VOLCANIC_BATTERIES, $maintenant);
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Technology, self::VOLCANIC_BATTERIES, 4);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 199500.0, 'previous_population' => 199500.0]);
        LifeformBonusCache::invalidate();
        $this->assertSame(0.0, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 'Sous le seuil de 200 000 : l emplacement est ferme, et la memoire garde ce zero.');

        // Une heure de croissance, ecrite par l entree reelle : la population franchit le seuil.
        $this->travelTo(Date::createFromTimestamp($maintenant + 3600));
        $this->planetService->update();
        $population = (float)LifeformPlanet::query()->where('planet_id', $planetId)->value('population');
        $this->assertGreaterThan(200000.0, $population, 'La croissance a franchi le seuil (espace ÷ 80 par heure).');
        $this->assertEqualsWithDelta(0.01, $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9, 'Sans rien vider a la main : la memoire suit l ecriture de la population.');
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

    /**
     * **Un emplacement ouvert par la population APRES l arrivee n arme pas la flotte** (relance de Codex).
     *
     * Le bonus d une technologie ne compte que si son emplacement est ouvert, et c est la population qui
     * l ouvre. Si le gel juge l ouverture sur la population **courante**, une planete qui franchit le seuil
     * entre l arrivee et le traitement arme retroactivement une flotte deja partie.
     */
    public function testASlotOpenedByPopulationAfterTheInstantDoesNotArmTheFleet(): void
    {
        $planetId = $this->currentPlanetId;
        $this->choose(Species::Rocktal);
        $instant = (int)Date::now()->timestamp;

        // L emplacement 1 exige 200 000 habitants. La planete n en a que la moitie a l instant.
        $this->placeLifeformSlot($planetId, 1, self::VOLCANIC_BATTERIES, $instant - 1000);
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Technology, self::VOLCANIC_BATTERIES, 10);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 100000.0, 'calculated_at' => $instant]);
        LifeformBonusCache::invalidate();
        $ferme = resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $instant)->fraction(LifeformEffect::ENERGY_PRODUCTION);
        $this->assertSame(0.0, $ferme, 'Premisse : sous le seuil, l emplacement se tait.');

        // **La population franchit le seuil apres l instant**, et le passage l a deja integree jusqu a
        // maintenant. Il a garde l etat d ou il est parti : c est ce qui rend l instant rejouable.
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 300000.0,
            'calculated_at' => $instant + 600,
            'previous_population' => 100000.0,
            'previous_food' => 0.0,
            'previous_calculated_at' => $instant - 600,
        ]);
        LifeformBonusCache::invalidate();
        $this->assertGreaterThan(0.0, resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 'Premisse : au present, l emplacement compte.');

        $this->assertSame(
            0.0,
            resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $instant)->fraction(LifeformEffect::ENERGY_PRODUCTION),
            'Une population franchie apres l instant ouvre retroactivement l emplacement et arme la flotte.'
        );
    }

    /**
     * **Une decouverte reglee APRES l arrivee n augmente pas le bonus de cette flotte** (relance de Codex).
     *
     * La part d une technologie est multipliee par (1 + experience de son espece). L experience monte par
     * les decouvertes, dont le reglement est un effet **date** : `PlayerService::update()` les credite avant
     * meme que les flottes ne soient traitees, dans la meme requete.
     */
    public function testADiscoverySettledAfterTheInstantDoesNotRaiseTheFrozenBonus(): void
    {
        $planetId = $this->currentPlanetId;
        $this->choose(Species::Rocktal);
        $instant = (int)Date::now()->timestamp;
        $this->technology($planetId, 1, self::VOLCANIC_BATTERIES, 10);
        LifeformBonusCache::invalidate();

        $avant = resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $instant)->fraction(LifeformEffect::ENERGY_PRODUCTION);
        $this->assertGreaterThan(0.0, $avant, 'Premisse : la technologie compte deja.');

        // **Un vol de decouverte se regle dix secondes apres l instant** et credite 9 000 points : niveau 4.
        LifeformDiscovery::query()->create([
            'user_id' => $this->currentUserId,
            'planet_id' => $planetId,
            'galaxy' => 1, 'system' => 1, 'position' => 1,
            'started_at' => $instant - 3600,
            'ends_at' => $instant + 10,
            'outcome' => (new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::EXPERIENCE, Species::Rocktal, 0, 9000))->toStorage(),
            'status' => 'settled',
            'settled_at' => $instant + 10,
            'rules_version' => LifeformDiscoveryRules::VERSION,
        ]);
        LifeformSpeciesProgress::query()->updateOrCreate(
            ['user_id' => $this->currentUserId, 'species' => Species::Rocktal->value],
            ['experience' => 9000, 'discovered_at' => $instant - 100000]
        );
        LifeformBonusCache::invalidate();

        $maintenant = resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION);
        $this->assertGreaterThan($avant, $maintenant, 'Premisse : au present, l experience a fait monter la part.');
        $this->assertSame(4, LifeformExperience::levelOf(9000), 'Premisse : 9 000 points valent le niveau 4, soit +0,4 %.');

        $this->assertEqualsWithDelta(
            $avant,
            resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $instant)->fraction(LifeformEffect::ENERGY_PRODUCTION),
            1e-12,
            'Une decouverte reglee apres l instant augmente retroactivement le bonus de la flotte.'
        );

        // **Un vol encore en vol n a rien credite** : son issue est scellee, mais elle n a pas encore ete
        // versee. La retirer du total reviendrait a desarmer la flotte avec des points jamais recus.
        LifeformDiscovery::query()->create([
            'user_id' => $this->currentUserId,
            'planet_id' => $planetId,
            'galaxy' => 1, 'system' => 1, 'position' => 2,
            'started_at' => $instant - 50,
            'ends_at' => $instant + 100000,
            'outcome' => (new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::EXPERIENCE, Species::Rocktal, 0, 9000))->toStorage(),
            'status' => 'running',
            'settled_at' => null,
            'rules_version' => LifeformDiscoveryRules::VERSION,
        ]);
        LifeformBonusCache::invalidate();
        $this->assertEqualsWithDelta(
            $avant,
            resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $instant)->fraction(LifeformEffect::ENERGY_PRODUCTION),
            1e-12,
            'Un vol encore en cours a ete compte comme credite, et la flotte y perd des points qu elle avait.'
        );
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
