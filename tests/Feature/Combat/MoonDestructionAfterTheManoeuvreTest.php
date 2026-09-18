<?php

namespace Tests\Feature\Combat;

use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\MoonDestructionMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;
use Tests\RecordsClassHistory;

/**
 * **La destruction de lune apres une manoeuvre de Hamill, par son propre chemin.**
 *
 * ## Ce que cet essai eprouve
 *
 * La tentative de destruction a ses conditions a elle, et cet essai n en contourne aucune : la mission part
 * reellement, la bataille est jouee par le moteur du jeu, la victoire se decide comme d habitude — des
 * survivants attaquants et **plus aucun defenseur** —, et la chance vient de la formule (diametre 1, deux
 * Etoiles attaquantes : 99 x racine de 2, plafonne a cent pour cent).
 *
 * ## Pourquoi la manoeuvre change quelque chose ici
 *
 * Une lune dont la seule defense est une Etoile de la mort se retrouve **sans defenseur avant le premier
 * round** quand la manoeuvre reussit. Sous les regles precedentes, l Etoile detruite comptait encore parmi
 * les survivants : la bataille etait tenue pour perdue et **aucune tentative n avait lieu**. La regle
 * courante la retire du decompte, et la tentative se joue.
 *
 * ## Le temoin contraire
 *
 * Une lune qui garde une defense apres la manoeuvre n est pas conquise pour autant : la manoeuvre retire une
 * Etoile, elle ne donne pas la victoire. Sans ce second essai, le premier passerait aussi bien si la
 * tentative se jouait sans condition.
 */
final class MoonDestructionAfterTheManoeuvreTest extends FleetDispatchTestCase
{
    use RecordsClassHistory;

    protected int $missionType = 9;

    protected string $missionName = 'Moon Destruction';

    /**
     * Deux Etoiles suffisent a garantir la destruction d une lune de diametre 1, et laissent la flotte
     * survivre a une defense qui tient : dix l auraient balayee.
     */
    private const int ETOILES = 2;

    private const int CHASSEURS = 50;

    private int $chance = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $reglages = resolve(SettingsService::class);
        $this->chance = $reglages->hamillManoeuvreChance();
        $reglages->set('hamill_manoeuvre_chance', 1);
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('hamill_manoeuvre_chance', $this->chance);

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        // Des Etoiles pour la tentative, des chasseurs legers pour la manoeuvre : sans eux elle ne se joue
        // pas du tout, et l essai ne mesurerait rien.
        $this->planetAddUnit('deathstar', self::ETOILES);
        $this->planetAddUnit('light_fighter', self::CHASSEURS);
        $this->playerSetResearchLevel('computer_technology', 5);

        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 1);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));

        // **Le General est celui qui attaque** : la manoeuvre lit sa classe au moment de la bataille. Colonne et
        // ligne d historique ensemble : ecrite seule, la classe restait sur ce compte, dont la lune devenait la
        // cible « etrangere la plus proche » de `PersistentMoonDestructionTest` quatre classes plus loin — et sa
        // fermeture se suspendait (« did not close at once »).
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
    }

    /**
     * **Une lune dont la seule defense est une Etoile tombe** : la manoeuvre la prend, la bataille est gagnee,
     * la tentative se joue.
     */
    public function testAMoonWhoseOnlyDefenceIsADeathstarFallsAfterTheManoeuvre(): void
    {
        [$lune, $mission] = $this->uneLuneAttaquee(['deathstar' => 1]);

        $this->assertFalse(
            Planet::query()->whereKey($lune)->exists(),
            'La lune a survecu : la tentative de destruction n a pas eu lieu apres une manoeuvre qui avait vide sa defense.'
        );

        // **La preuve qu aucun round ne s est joue** : la flotte rentre entiere. Si l Etoile de la lune avait
        // combattu, les chasseurs legers l auraient paye.
        $retour = FleetMission::query()->where('parent_id', $mission)->first();
        $this->assertNotNull($retour, 'La flotte victorieuse n a pas de retour.');
        $this->assertSame(self::CHASSEURS, (int)$retour->light_fighter, 'Des chasseurs legers ont ete perdus : l Etoile de la lune a tire.');
        $this->assertSame(self::ETOILES, (int)$retour->deathstar, 'Des Etoiles attaquantes ont ete perdues alors qu aucun round ne s est joue.');
    }

    /**
     * **La manoeuvre ne donne pas la victoire** : une lune qui garde une defense n est pas detruite.
     *
     * Les lance-missiles survivent au tir rapide de deux Etoiles, et leurs tirs n entament pas assez les
     * Etoiles pour les detruire : la bataille se joue, la defense tient, et la tentative n a pas lieu.
     */
    public function testAMoonThatKeepsADefenceIsNotDestroyed(): void
    {
        [$lune, $mission] = $this->uneLuneAttaquee(['deathstar' => 1, 'rocket_launcher' => 3_000]);

        $this->assertTrue(
            Planet::query()->whereKey($lune)->exists(),
            'La lune est tombee alors que sa defense tenait encore : la victoire ne se lit plus sur les survivants.'
        );

        // La premisse : la defense a bien survecu. Sans elle, la lune pourrait avoir survecu pour une autre
        // raison — une flotte attaquante aneantie, par exemple.
        $this->assertGreaterThan(
            0,
            (int)Planet::query()->whereKey($lune)->value('rocket_launcher'),
            'La defense a ete balayee : ce n est pas le cas que cet essai veut mesurer.'
        );
        $this->assertNotNull(FleetMission::query()->where('parent_id', $mission)->first(), 'La flotte attaquante n a pas de retour : elle a ete aneantie, et l essai ne mesure plus la condition.');
    }

    /**
     * Envoie une destruction de lune sur une lune etrangere qui ne porte que ce qu on lui donne, et laisse le
     * jeu traiter l arrivee.
     *
     * @param array<string, int> $defense
     * @return array{0: int, 1: int} L identifiant de la lune, celui de la mission.
     */
    private function uneLuneAttaquee(array $defense): array
    {
        $this->basicSetup();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('deathstar'), self::ETOILES);
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), self::CHASSEURS);

        $lune = $this->sendMissionToOtherPlayerMoon($flotte, new Resources(0, 0, 0, 0));

        // La lune ne porte que la defense demandee : le montage partage en laisse d autres.
        $lune->removeUnits($lune->getShipUnits(), false);
        $lune->removeUnits($lune->getDefenseUnits(), false);
        $lune->save();
        $lune->reloadPlanet();

        Planet::query()->whereKey($lune->getPlanetId())->update(['diameter' => 1] + $defense);

        $mission = (int)FleetMission::query()
            ->where('user_id', $this->currentUserId)
            ->where('mission_type', 9)
            ->orderByDesc('id')
            ->value('id');

        $duree = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()])
            ->calculateFleetMissionDuration(
                $this->planetService,
                $lune->getPlanetCoordinates(),
                $flotte,
                resolve(MoonDestructionMission::class)
            );

        $this->travel($duree + 1)->seconds();
        $this->reloadApplication();
        // Une bataille rejouable : trois mille lance-missiles contre deux Etoiles se jouent au tirage, et un tirage sur
        // quelques dizaines balayait la defense — la lune tombait et le temoin rougissait sans defaut (18 septembre 2026).
        // La liaison se pose apres le rafraichissement de l application, que le dernier envoi a provoque.
        $this->app->bind(BattleDraws::class, static fn (): SeededDraws => new SeededDraws(4242));
        $this->get('/overview')->assertStatus(200);

        return [$lune->getPlanetId(), $mission];
    }
}
