<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\AllianceService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * La protection d alliance decide **sous la porte**, et couvre l ouverture comme l admission.
 *
 * ## Le defaut que ces temoins ferment
 *
 * Le controle vivait dans `AttackMission::processArrival()`, avant l aiguillage du combat durable :
 * hors transaction, hors du rendez-vous des joueurs. La course du bac
 * (`AllianceVersusCombatOpeningRaceTest`) l a prise en faute — l arrivee lisait « pas allies »,
 * l adhesion prenait sa barriere, ecrivait, commitait, et l arrivee ouvrait le combat sur sa lecture
 * d avant. **La barriere protegeait l action ; la decision, elle, etait restee dehors.**
 *
 * La preuve de la course appartient a MariaDB — deux processus, de vrais verrous. Ce qui se prouve
 * ici est l autre moitie, celle que la course ne dit pas : **ou** la decision est prise, et **ce
 * qu elle couvre**. Une decision juste au mauvais endroit reste fausse ; une decision au bon endroit
 * qui ne fermerait que la creation laisserait passer la vague suivante.
 *
 * ## Les deux branches d une meme porte
 *
 * `openOrJoin()` ouvre **ou** rejoint. Le premier temoin prend la branche « ouvrir » : personne ne
 * tient le corps, et rien ne doit s ouvrir. Les deux suivants prennent la branche « rejoindre », en
 * paire : un combat tient deja le corps, une flotte alliee arrive — elle doit repartir ; **la meme
 * flotte, sans l alliance, entre.** Sans ce second temoin, le premier passerait aussi bien si
 * l admission etait refusee pour n importe quelle autre raison.
 *
 * ## Pourquoi un troisieme joueur pour l admission
 *
 * Le meme joueur ne peut pas produire ce cas, et c est voulu : des que sa premiere vague a ouvert
 * le combat, il est adversaire du defenseur, et `AllianceMembershipChangeGuard` refuse son adhesion.
 * Les deux moities de la regle se tiennent. L admission ne s obtient donc que par un tiers : un
 * joueur qui rejoint l alliance du defenseur pendant qu un **autre** l attaque, puis dont la flotte
 * arrive dans ce combat-la.
 */
class AllianceUnderTheCombatGateTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private int|null $alliance = null;

    protected function setUp(): void
    {
        parent::setUp();

        // La base d un processus enchaine des dizaines de classes : les combats des voisins
        // fausseraient chaque comptage de cette classe-ci.
        DB::table('fleet_missions')->whereNotNull('combat_instance_id')->update(['combat_instance_id' => null]);

        foreach ([
            'combat_snapshot_inclusions',
            'combat_outbox',
            'combat_participants',
            'combat_effect_receipts',
            'combat_loot_reservations',
            'celestial_body_combat_barriers',
            'combat_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    protected function tearDown(): void
    {
        if ($this->alliance !== null) {
            // Le lien vit sur `users` autant que dans `alliance_members` : les deux partent, sinon un
            // essai voisin heriterait d une appartenance que plus rien ne porte.
            DB::table('users')->where('alliance_id', $this->alliance)->update(['alliance_id' => null, 'alliance_left_at' => null]);
            AllianceMember::query()->where('alliance_id', $this->alliance)->delete();
            Alliance::query()->whereKey($this->alliance)->delete();
            $this->alliance = null;
        }

        $reglages = resolve(SettingsService::class);
        $reglages->set('alliance_offensive_protection_enabled', 0);
        $reglages->set('persistent_combat_enabled', '0');

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * **Ouvrir : rien ne s ouvre.** L attaque part avant l alliance et arrive apres.
     *
     * Le demi-tour se decide desormais dans la section gardee, et il s y ecrit : aucune instance,
     * aucune barriere de corps, un seul retour, et l avis qui dit pourquoi. Une flotte gouvernee par
     * le combat n a qu un ecrivain de son mouvement, et c est la porte.
     */
    public function testUnderTheSwitchAnAttackThatBecameIntraAllianceOpensNothingAndComesHome(): void
    {
        [$mission, $defenseur] = $this->uneAttaqueEnVol();

        // L alliance se forme pendant le vol : le lancement avait dit oui.
        $this->armer();
        $this->uneAllianceAvec($defenseur);

        $avisAvant = Message::query()->where('user_id', $this->currentUserId)->count();

        $this->laFlotteArrive($mission);

        $mission->refresh();

        $this->assertSame(0, CombatInstance::query()->count(), 'A durable combat was opened against a member of the same alliance.');
        $this->assertSame(0, CelestialBodyCombatBarrier::query()->count(), 'The target body was held although nothing should have opened.');
        $this->assertNull($mission->combat_instance_id, 'The fleet was attached to a combat it should never have entered.');

        $this->assertSame(1, (int)$mission->processed, 'The arrival was not processed at all.');

        $retours = FleetMission::query()->where('parent_id', $mission->id)->get();
        $this->assertCount(1, $retours, 'The turnaround did not create exactly one return.');

        $retour = $retours->first();
        $this->assertNotNull($retour);
        $this->assertSame(350, (int)$retour->light_fighter, 'The returning fleet is not the one that left.');
        $this->assertSame(50, (int)$retour->small_cargo, 'The returning fleet is not the one that left.');

        $this->assertSame(
            $avisAvant + 1,
            Message::query()->where('user_id', $this->currentUserId)->count(),
            'The player was not told why the fleet turned back.'
        );
        $this->assertSame(
            'attack_cancelled_by_alliance_protection',
            (string)Message::query()->where('user_id', $this->currentUserId)->orderByDesc('id')->value('key'),
            'The notice is not the one that explains the alliance turnaround.'
        );
    }

    /**
     * **Rejoindre : l admission est refusee elle aussi.**
     *
     * Un tiers tient le corps par un ralliement ouvert. Un joueur entre dans l alliance du defenseur
     * — permis, il n est adversaire de personne — puis sa flotte arrive dans ce combat. Elle ne le
     * rejoint pas, elle rentre : rejoindre serait une offensive contre un allie tout autant
     * qu ouvrir.
     */
    public function testAFleetOfANewAllyIsNotAdmittedIntoACombatAlreadyOpen(): void
    {
        [$combat, $corps, $ouverture] = $this->anOpenRally();

        $this->assertSame(CombatState::Rallying, $combat->status, 'The rally is closed: the admission branch would never be reached.');

        $defenseur = (int)DB::table('planets')->where('id', $corps)->value('user_id');
        [$allie, $arrivante] = $this->aFleetOfAThirdPlayerArrivingInto($corps, $ouverture + 10);

        // L alliance se noue **avant** l arrivee, et elle est permise : ce joueur n est encore
        // partie a aucun combat.
        $this->armer();
        $this->uneAllianceAvec($defenseur);

        $avisAvant = Message::query()->where('user_id', $allie)->count();

        $this->travelTo(Date::createFromTimestamp($ouverture + 10));
        resolve(PlayerServiceFactory::class)->make($allie, true)->updateFleetMissions();

        $arrivante->refresh();

        $this->assertNull($arrivante->combat_instance_id, 'The fleet of a new ally was admitted into the combat against that ally.');
        $this->assertSame(1, (int)$arrivante->processed, 'The arrival was not processed at all.');
        $this->assertCount(
            1,
            FleetMission::query()->where('parent_id', $arrivante->id)->get(),
            'The refused fleet did not come home exactly once.'
        );
        $this->assertSame(
            'attack_cancelled_by_alliance_protection',
            (string)Message::query()->where('user_id', $allie)->orderByDesc('id')->value('key'),
            'The notice is not the one that explains the alliance turnaround.'
        );
        $this->assertSame(
            $avisAvant + 1,
            Message::query()->where('user_id', $allie)->count(),
            'The player was not told why the fleet turned back.'
        );

        // La bataille des autres n est pas touchee : la regle refuse une entree, elle n annule rien.
        $this->assertSame(CombatState::Rallying, $combat->refresh()->status, 'The third party combat was disturbed by a refused admission.');
    }

    /**
     * **La meme flotte, sans l alliance, entre.** Sans ce temoin, le precedent se satisferait d un
     * refus venu de n importe ou.
     */
    public function testWithoutTheAllianceTheSameFleetIsAdmitted(): void
    {
        [$combat, $corps, $ouverture] = $this->anOpenRally();

        [$etranger, $arrivante] = $this->aFleetOfAThirdPlayerArrivingInto($corps, $ouverture + 10);

        // La protection est armee, et pourtant rien ne s y oppose : ces deux-la ne sont pas allies.
        $this->armer();

        $this->travelTo(Date::createFromTimestamp($ouverture + 10));
        resolve(PlayerServiceFactory::class)->make($etranger, true)->updateFleetMissions();

        $arrivante->refresh();

        $this->assertSame(
            (int)$combat->id,
            (int)$arrivante->combat_instance_id,
            'The fleet of a stranger was refused: the previous witness would prove nothing.'
        );
        $this->assertCount(
            0,
            FleetMission::query()->where('parent_id', $arrivante->id)->get(),
            'The admitted fleet was sent home as well.'
        );
    }

    /**
     * **Sans l alliance, la meme attaque ouvre le combat.** Sans ce temoin, le precedent se
     * satisferait d un monde ou rien ne s ouvre jamais — c est exactement ce qui est arrive : une
     * premiere version oubliait de poser l interrupteur du combat durable, jouait le chemin
     * instantane, et passait au vert en ne prouvant rien. La mutation l a dit.
     */
    public function testWithoutTheAllianceTheSameAttackOpensACombat(): void
    {
        [$mission] = $this->uneAttaqueEnVol();

        $this->armer();

        $this->laFlotteArrive($mission);

        $mission->refresh();

        $this->assertSame(1, CombatInstance::query()->count(), 'No durable combat was opened: the sibling witness would prove nothing.');
        $this->assertNotNull($mission->combat_instance_id, 'The attack did not enter the combat it opened.');
        $this->assertCount(0, FleetMission::query()->where('parent_id', $mission->id)->get(), 'The fleet came home although it opened a combat.');
    }

    /**
     * Une attaque en vol vers un corps propre, l interrupteur du combat durable arme.
     *
     * @return array{0: FleetMission, 1: int} La mission, et le proprietaire du corps vise.
     */
    private function uneAttaqueEnVol(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $cible = $this->sendMissionToOtherPlayerCleanPlanet($this->uneVague(350), new Resources(0, 0, 0, 0));
        $mission = $this->lastMissionDispatched();

        $defenseur = $cible->getPlayer();
        $this->assertNotNull($defenseur);

        // **L interrupteur du combat durable, sans quoi tout ceci prouverait le chemin instantane.**
        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');

        return [$mission, $defenseur->getId()];
    }

    private function laFlotteArrive(FleetMission $mission): void
    {
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 10));
        $this->get('/overview')->assertStatus(200);
    }

    private function armer(): void
    {
        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);
    }

    private function uneVague(int $chasseurs): UnitCollection
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), $chasseurs);

        return $unites;
    }

    /**
     * Le joueur courant et cet autre dans une meme alliance, par le vrai chemin.
     *
     * Un temoin qui ecrirait lui-meme la ligne ne protegerait pas le constructeur reel : l
     * appartenance vit sur `users` **et** dans `alliance_members`, et c est precisement l accord des
     * deux que la decision sous verrou suppose.
     */
    private function uneAllianceAvec(int $autre): void
    {
        $service = resolve(AllianceService::class);

        $alliance = $service->createAlliance(
            $this->currentUserId,
            'P' . substr((string)$this->currentUserId, -3) . substr((string)$autre, -3),
            'Porte ' . $this->currentUserId . '-' . $autre
        );

        $this->alliance = (int)$alliance->id;

        $candidature = $service->applyToAlliance($autre, $this->alliance);
        $service->acceptApplication((int)$candidature->id, $this->currentUserId);

        $this->assertTrue(
            $service->arePlayersInSameAlliance($this->currentUserId, $autre),
            'The two players are not in the same alliance: nothing would be proved.'
        );
    }

    /**
     * Un joueur neuf, et son attaque vers ce corps, arrivant a cet instant.
     *
     * La mission est posee directement : son instant d arrivee doit tomber **dans** la fenetre du
     * ralliement, ce qu un envoi par le formulaire ne permet pas de choisir. Ce que l essai observe
     * n est pas le lancement — il a ses propres temoins — mais ce que l arrivee decide.
     *
     * @return array{0: int, 1: FleetMission} Le joueur, et sa flotte en vol.
     */
    private function aFleetOfAThirdPlayerArrivingInto(int $corps, int $arrivee): array
    {
        $this->createAndLoginUser();

        $joueur = $this->currentUserId;
        $origine = $this->planetService;
        $depart = $origine->getPlanetCoordinates();

        $vise = Planet::query()->whereKey($corps)->firstOrFail();

        $mission = FleetMission::forceCreate([
            'user_id' => $joueur,
            'planet_id_from' => $origine->getPlanetId(),
            'type_from' => 1,
            'galaxy_from' => $depart->galaxy,
            'system_from' => $depart->system,
            'position_from' => $depart->position,
            'planet_id_to' => $corps,
            'type_to' => 1,
            'galaxy_to' => (int)$vise->galaxy,
            'system_to' => (int)$vise->system,
            'position_to' => (int)$vise->planet,
            'mission_type' => 1,
            'time_departure' => $arrivee - 600,
            'time_arrival' => $arrivee,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        return [$joueur, $mission];
    }
}
