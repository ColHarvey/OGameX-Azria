<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Projection\MissileStrikeProjection;
use OGame\Combat\Support\CombatantFrozenAtEntry;
use OGame\Combat\Support\FrozenCombatCharacteristics;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameMissions\MissileMission;
use OGame\GameObjects\Models\DefenseObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Combat\LifeformCombatPhotographer;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\FleetMission;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use ReflectionMethod;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Les bonus des formes de vie en bataille, tels que l audit des bonus les a mesures** (demande de Keven, journal §164).
 *
 * « Assure-toi que tous les bonus des recherches sont appliques dans les batailles si cela affecte un vaisseau ou
 * autre. » L audit a trouve la chaine raccordee — catalogue, resolveur, photographie, les deux moteurs, rapport — et
 * trois ecarts que ce banc exige fermes : le pour cent GELE (combat durable, espace libre, garnison photographiee)
 * n etait pas arrondi comme le pour cent vivant et tirait un point de moins a certains niveaux ; la salve de missiles
 * ignorait le Renforcement des boucliers d obsidienne des defenses ; une unite a coque non multiple de dix passait par
 * une conversion implicite depreciee (erreur fatale sous PHP 9).
 */
final class LifeformBattleBonusesTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    /** Revision generale (chasseur leger), Mechas : 0,3 % par niveau. */
    private const int GENERAL_OVERHAUL_LIGHT_FIGHTER = 13205;

    /** Renforcement des boucliers d obsidienne, Rock tal : 0,5 % par niveau sur toutes les defenses. */
    private const int OBSIDIAN_SHIELD_REINFORCEMENT = 12216;

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
        $this->forgetHeldLifeformPlanets();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * Trois niveaux a 0,3 % valent 0,8999999999999999 en flottant. La lecture vivante l arrondit (journal §157) ; le
     * gel ne l arrondissait pas, et un combat durable tirait 4 035 de coque la ou l attaque instantanee et la page en
     * annoncent 4 036.
     */
    public function testAFrozenShipBonusIsTheLivePercentToTheMillionth(): void
    {
        $this->choose(Species::Mechas);
        $this->technology(self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 3);
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $brut = resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::SHIP_STATS, 'light_fighter') * 100;
        $this->assertNotSame(0.9, $brut, 'Premisse : le produit flottant n est pas exact — sinon juste et faux coincident.');

        $joueur = $this->player();
        $this->assertSame(0.9, $joueur->getLifeformUnitStatsPercent($chasseur), 'Lecture vivante : 0,9.');
        $gele = resolve(LifeformCombatPhotographer::class)->ofPlayer($joueur);
        $this->assertSame(0.9, $gele->unitStats['light_fighter'], 'La photographie ecrit 0,9, pas 0,8999999999999999.');

        // Le combattant gele tire exactement comme la page : 4 000 + 0,9 % = 4 036 de coque, 50 + 0 d attaque.
        $combattant = new CombatantFrozenAtEntry($this->currentUserId, new FrozenCombatCharacteristics(0, 0, 0, 0, $gele), null);
        $this->assertSame(4036, $chasseur->properties->structural_integrity->calculate($combattant)->totalValue, 'Le combat durable tient 4 036, comme l attaque instantanee.');

        // Une ligne ecrite avant ce correctif (non arrondie) se relit arrondie : elle tire comme la page.
        $ancienne = FrozenLifeformCombatBonuses::fromFrozenFacts(['unit_stats' => ['light_fighter' => $brut], 'protected_share' => null, 'moon_chance' => 0.0, 'debris_recovery' => 0.0, 'wreck_recovery' => 0.0]);
        $this->assertSame(0.9, $ancienne->unitStatsPercent($chasseur));
        $ancienCombattant = new CombatantFrozenAtEntry($this->currentUserId, new FrozenCombatCharacteristics(0, 0, 0, 0, $ancienne), null);
        $this->assertSame(4036, $chasseur->properties->structural_integrity->calculate($ancienCombattant)->totalValue);
    }

    /**
     * Le meme defaut sur les defenses : Obsidienne niveau 29 = 14,499999999999998 % en flottant ; le lance-missiles
     * (2 000) tient 2 290 sur la page et doit tenir 2 290 dans la garnison photographiee d un combat durable.
     */
    public function testAFrozenDefenceBonusIsTheLivePercentToTheMillionth(): void
    {
        $this->choose(Species::Rocktal);
        $this->technology(self::OBSIDIAN_SHIELD_REINFORCEMENT, 29);
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');
        $brut = resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::DEFENCE_STATS) * 100;
        $this->assertNotSame(14.5, $brut, 'Premisse : le produit flottant n est pas exact.');

        $joueur = $this->player();
        $this->assertSame(14.5, $joueur->getLifeformUnitStatsPercent($lanceur));
        $garnison = resolve(LifeformCombatPhotographer::class)->ofBody($this->planetService);
        $this->assertSame(14.5, $garnison->unitStats[FrozenLifeformCombatBonuses::DEFENCE], 'La photographie de la garnison ecrit 14,5.');
        $combattant = new CombatantFrozenAtEntry($this->currentUserId, new FrozenCombatCharacteristics(0, 0, 0, 0, $garnison), null);
        $this->assertSame(2290, $lanceur->properties->structural_integrity->calculate($combattant)->totalValue, '2 000 + 14,5 % = 2 290, pas 2 289.');
    }

    /**
     * Une coque de 4 036 donne une coque de bataille de 403 : une troncature explicite, identique au moteur Rust
     * (`floor`), sans la conversion implicite que PHP 8.5 deprecie et que PHP 9 refusera.
     */
    public function testABattleUnitTruncatesItsHullWithoutAnImplicitConversion(): void
    {
        $depreciations = [];
        set_error_handler(static function (int $niveau, string $message) use (&$depreciations): bool {
            $depreciations[] = $message;

            return true;
        }, E_DEPRECATED);
        try {
            $unite = new BattleUnit(ObjectService::getShipObjectByMachineName('light_fighter'), 4036, 10, 50, 1, 1);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(403, $unite->originalHullPlating);
        $this->assertSame(403, $unite->currentHullPlating);
        $this->assertSame([], $depreciations, 'Aucune conversion implicite depreciee.');
    }

    /**
     * La salve de missiles lit l integrite des defenses avec le bonus de formes de vie, comme la bataille et la page :
     * 1 missile, armes 10 (24 000), 100 lance-missiles, blindage 10. Sans bonus : 2 000 x 2 / 10 = 400 -> 60 detruits.
     * Obsidienne a 5 % : (4 000 + 100) / 10 = 410 -> 58. Et sans forme de vie, la formule d avant au bit pres.
     */
    public function testTheMissileSalvoReadsTheLifeformDefenceBonus(): void
    {
        $priorite = 2; // Les lance-missiles d abord.
        $zero = static fn (DefenseObject $d): float => 0.0;
        $cinq = static fn (DefenseObject $d): float => 5.0;
        $this->assertSame(['rocket_launcher' => 60], MissileStrikeProjection::destroyedOn(['rocket_launcher' => 100], 1, 10, 10, $priorite, $zero), 'Sans bonus : 60.');
        $this->assertSame(['rocket_launcher' => 58], MissileStrikeProjection::destroyedOn(['rocket_launcher' => 100], 1, 10, 10, $priorite, $cinq), 'Le Renforcement des boucliers d obsidienne sauve deux lance-missiles.');

        // Sans bonus : exactement la formule d avant, pour chaque defense, chaque blindage et quelques salves.
        foreach (ObjectService::getDefenseObjects() as $defense) {
            if (in_array($defense->machine_name, ['interplanetary_missile', 'anti_ballistic_missile'], true)) {
                continue;
            }
            foreach ([0, 3, 7, 10, 17] as $blindage) {
                foreach ([1, 3, 20] as $missiles) {
                    $avant = min((int)floor($missiles * MissileStrikeProjection::POWER_PER_MISSILE * (1 + 0.1 * 5) / ($defense->properties->structural_integrity->rawValue * (1 + 0.1 * $blindage) / 10)), 1000);
                    $this->assertSame(
                        $avant > 0 ? [$defense->machine_name => $avant] : [],
                        MissileStrikeProjection::destroyedOn([$defense->machine_name => 1000], $missiles, 5, $blindage, $this->priorityOf($defense->machine_name), $zero),
                        $defense->machine_name . ' blindage ' . $blindage . ' salve ' . $missiles
                    );
                }
            }
        }

        // La frappe vivante passe le bonus du DEFENSEUR : un defenseur gele a 5 % perd 58 lance-missiles, a 0 % 60.
        $this->planetService->removeUnit('rocket_launcher', $this->planetService->getObjectAmount('rocket_launcher'));
        $this->planetService->addUnit('rocket_launcher', 100);
        foreach (['light_laser', 'heavy_laser', 'gauss_cannon', 'ion_cannon', 'plasma_turret', 'small_shield_dome', 'large_shield_dome'] as $autre) {
            $this->planetService->removeUnit($autre, $this->planetService->getObjectAmount($autre));
        }
        $attaquant = new CombatantFrozenAtEntry($this->currentUserId, new FrozenCombatCharacteristics(10, 0, 0, 0), null);
        $mission = new FleetMission();
        $mission->target_priority = $priorite;
        $frappe = new ReflectionMethod(MissileMission::class, 'calculateDefenseDestruction');
        $missile = GameMissionFactory::getMissionById(10, []);
        foreach ([[0.0, 60], [5.0, 58]] as [$pourcent, $attendu]) {
            $defenseur = new CombatantFrozenAtEntry($this->currentUserId, new FrozenCombatCharacteristics(0, 0, 10, 0, new FrozenLifeformCombatBonuses($pourcent > 0 ? [FrozenLifeformCombatBonuses::DEFENCE => $pourcent] : [], null, 0.0, 0.0, 0.0)), null);
            $detruites = $frappe->invoke($missile, $this->planetService, $defenseur, 1, $attaquant, $mission);
            $this->assertInstanceOf(UnitCollection::class, $detruites);
            $this->assertSame($attendu, $detruites->getAmountByMachineName('rocket_launcher'), 'Bonus du defenseur ' . $pourcent . ' %.');
        }
    }

    /** Le code de priorite qui vise cette defense d abord (`MissileStrikeProjection::decodePriority()`). */
    private function priorityOf(string $machineName): int
    {
        $codes = ['rocket_launcher' => 2, 'light_laser' => 3, 'heavy_laser' => 4, 'gauss_cannon' => 5, 'ion_cannon' => 6, 'plasma_turret' => 7, 'small_shield_dome' => 8, 'large_shield_dome' => 9];

        return $codes[$machineName] ?? 0;
    }

    private function choose(Species $species): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, $species, (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();
    }

    private function technology(int $objectId, int $level): void
    {
        // Dans l emplacement de son indice, palier ouvert ; une population qui ouvre les dix-huit emplacements, posee.
        $espece = resolve(LifeformInstallationService::class)->speciesOf($this->currentUserId);
        $this->assertNotNull($espece);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 500000000.0]);
        $this->placeLifeformTechnology($this->currentPlanetId, $espece, $objectId, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, $objectId, $level);
        LifeformBonusCache::invalidate();
    }

    private function player(): PlayerService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);

        return $joueur;
    }
}
