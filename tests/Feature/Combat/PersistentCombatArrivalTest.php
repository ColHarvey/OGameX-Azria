<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * L'aiguillage de l'arrivee : l'interrupteur decide si une attaque se resout ou commence.
 *
 * ## La seule couture visible par un joueur
 *
 * Tout le socle durable est inerte tant que `persistent_combat_enabled` vaut non : une attaque
 * arrive, se resout, le rapport part — exactement comme depuis toujours. Mis a oui, la meme
 * attaque **commence** au lieu de finir : la flotte entre dans un combat, le rapport n'arrive pas,
 * et rien n'est pris avant l'echeance.
 *
 * Ces essais prouvent les deux etats et le passage de l'un a l'autre, par la route reelle : la
 * flotte est envoyee par le formulaire de jeu et l'arrivee est declenchee par une page.
 */
class PersistentCombatArrivalTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * L'interrupteur revient a « non » apres chaque essai.
     *
     * **Les reglages vivent en base, et la base survit a l'essai** : un processus de la suite
     * enchaine des dizaines de classes sur la meme. Laisser l'interrupteur arme faisait ouvrir un
     * combat durable a la premiere attaque d'un essai suivant, qui attendait une resolution
     * immediate — `GeneralWreckFieldTest` ne trouvait plus son champ d'epaves. La fuite n'etait pas
     * dans le code du jeu : elle etait dans cet essai.
     */
    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 200);
        $this->planetAddUnit('light_fighter', 900);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);

        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('fleet_speed_holding', 1);
        $settingsService->set('fleet_speed_peaceful', 1);
        $settingsService->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * L'interrupteur ferme : une attaque se resout a l'arrivee, et rien du socle durable ne bouge.
     */
    public function testWithTheSwitchOffAnAttackStillResolvesOnArrival(): void
    {
        [$mission, $cible] = $this->anAttackArriving('0');

        $mission->refresh();
        $this->assertSame(1, (int)$mission->processed, 'The attack did not resolve on arrival.');
        $this->assertNull($mission->combat_instance_id, 'An instant attack was attached to a combat.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'No return was created.');

        $this->assertSame(0, CombatInstance::query()->count(), 'A persistent combat was opened while the switch was off.');
        $this->assertSame(0, CelestialBodyCombatBarrier::query()->count());
        $this->assertLessThan(500_000, $this->metalOf($cible), 'The target was not looted by the instant path.');
    }

    /**
     * L'interrupteur ouvert : la meme attaque commence un combat au lieu de se resoudre.
     */
    public function testWithTheSwitchOnAnAttackOpensACombatInstead(): void
    {
        [$mission, $cible] = $this->anAttackArriving('1');

        $mission->refresh();
        $this->assertSame(0, (int)$mission->processed, 'The attack resolved although the combat should have begun.');
        $this->assertNotNull($mission->combat_instance_id, 'The arriving fleet was not attached to its combat.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count(), 'A return was created before the combat ended.');

        $combat = CombatInstance::query()->whereKey($mission->combat_instance_id)->first();
        $this->assertNotNull($combat, 'The mission points at a combat that does not exist.');
        $this->assertSame(CombatState::Rallying, $combat->status);
        $this->assertSame($mission->id, $combat->mission_id, 'The combat was not opened by the arriving fleet.');

        // **L'heure d'ouverture est l'arrivee, pas l'horloge du travailleur.** La page qui declenche
        // le traitement est visitee dix secondes apres l'arrivee ; le combat commence a l'arrivee.
        $this->assertSame((int)$mission->time_arrival, $combat->started_at, 'The combat opened on the worker clock instead of the arrival.');

        $this->assertSame(500_000, $this->metalOf($cible), 'The target was looted before the combat ended.');
    }

    /**
     * Une arrivee retraitee n'ouvre pas un second combat.
     *
     * Tant que le combat dure, la mission reste non traitee : le traitement des arrivees repasse sur
     * elle a chaque tique. Sans la porte, chaque passage rouvrirait — ou rejoindrait — un combat.
     */
    public function testAReprocessedArrivalDoesNotOpenASecondCombat(): void
    {
        [$mission] = $this->anAttackArriving('1');

        $this->assertSame(1, CombatInstance::query()->count());
        $premier = (int)CombatInstance::query()->value('id');

        $this->get('/overview')->assertStatus(200);
        $this->get('/overview')->assertStatus(200);

        $this->assertSame(1, CombatInstance::query()->count(), 'Reprocessing the arrival opened another combat.');
        $this->assertSame(1, CelestialBodyCombatBarrier::query()->count());

        $mission->refresh();
        $this->assertSame($premier, $mission->combat_instance_id, 'The fleet changed combat between two passes.');
        $this->assertSame(0, (int)$mission->processed);
    }

    /**
     * Une flotte qui arrive apres la fermeture du ralliement attend, sans etre rattachee.
     *
     * ## Un trou reel, trouve par une mutation qui survivait
     *
     * Les rangs d'un combat sont figes a la fermeture : photographie prise, budgets consommes,
     * bataille calculee. Une flotte arrivee apres ne peut pas y entrer — personne ne l'admettrait,
     * et elle ne serait jamais reglee : la flotte serait perdue. Elle ne peut pas non plus attaquer
     * un corps que ce combat tient. Elle attend donc, et ouvrira **son** combat quand le corps sera
     * libre.
     *
     * **C'est une regle de jeu nouvelle**, remontee comme telle : elle decrit ce qu'un joueur voit
     * quand sa flotte arrive une seconde trop tard.
     */
    public function testAFleetArrivingAfterTheRallyClosedWaitsForTheBodyToBeFree(): void
    {
        [$premier] = $this->anAttackArriving('1');

        $combat = CombatInstance::query()->firstOrFail();
        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->firstOrFail();

        (new PersistentCombatAdvancer())->advance((int)$barriere->owned_through_effect_at);
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not close: nothing would arrive too late.');

        // Une seconde flotte, du meme joueur, vers le meme corps — elle arrive trop tard.
        $retardataire = $this->aSecondAttackAgainst($premier);

        $this->assertSame(1, CombatInstance::query()->count(), 'A second combat was opened on a body already fighting.');
        $this->assertNull($retardataire->combat_instance_id, 'A fleet that arrived too late was attached to a combat whose ranks are frozen.');
        $this->assertSame(0, (int)$retardataire->processed, 'A fleet that arrived too late resolved against a body under combat.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $retardataire->id)->count());

        // Le combat se regle ; le corps est libre ; la tique suivante ouvre le combat de la retardataire.
        (new PersistentCombatAdvancer())->advance((int)$combat->ends_at);
        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status);

        $this->get('/overview')->assertStatus(200);

        $retardataire->refresh();
        $this->assertSame(2, CombatInstance::query()->count(), 'The waiting fleet never got its own combat.');
        $this->assertNotNull($retardataire->combat_instance_id);
        $this->assertNotSame($combat->id, $retardataire->combat_instance_id, 'The waiting fleet joined the battle that was already over.');
    }

    /**
     * De l'arrivee au rapport, sans que personne n'appelle rien.
     *
     * C'est le cycle entier : la flotte arrive et le combat s'ouvre ; le passage planifie ferme le
     * ralliement et calcule la bataille ; a l'echeance, il l'applique. Le joueur n'a rien fait, et
     * aucun essai n'a appele la fermeture ni le reglement.
     */
    public function testFromArrivalToReportWithoutAnyoneCallingAnything(): void
    {
        [$mission, $cible] = $this->anAttackArriving('1');

        $combat = CombatInstance::query()->firstOrFail();
        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->firstOrFail();

        $avanceur = new PersistentCombatAdvancer();
        $avanceur->advance((int)$barriere->owned_through_effect_at);

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertNotNull($combat->ends_at);
        $this->assertSame(500_000, $this->metalOf($cible), 'The target was looted the moment the battle was computed.');

        $avanceur->advance((int)$combat->ends_at);

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status);
        $this->assertNotNull($combat->battle_report_id);

        $mission->refresh();
        $this->assertSame(1, (int)$mission->processed, 'The attacking fleet never finished its mission.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'The surviving fleet never came home.');
        $this->assertLessThan(500_000, $this->metalOf($cible), 'The target was never looted.');
    }

    /**
     * Une attaque envoyee par la route, arrivee, avec l'interrupteur dans l'etat demande.
     *
     * @return array{0: FleetMission, 1: PlanetService}
     */
    private function anAttackArriving(string $interrupteur): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 500_000,
            'crystal' => 300_000,
            'deuterium' => 100_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        // **L'interrupteur est pose juste avant l'arrivee**, pour que les deux essais partent du
        // meme monde et ne different que par lui.
        resolve(SettingsService::class)->set('persistent_combat_enabled', $interrupteur);

        // Dix secondes de retard : le traitement des arrivees n'est pas a la seconde.
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 10));
        $this->get('/overview')->assertStatus(200);

        return [$mission, $cible];
    }

    /**
     * Une seconde attaque du meme joueur vers le meme corps, arrivee.
     */
    private function aSecondAttackAgainst(FleetMission $premiere): FleetMission
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);

        $cible = Planet::query()->whereKey($premiere->planet_id_to)->firstOrFail();
        $this->dispatchFleet(
            new Coordinate((int)$cible->galaxy, (int)$cible->system, (int)$cible->planet),
            $unites,
            new Resources(0, 0, 0, 0),
            PlanetType::Planet
        );

        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->where('id', '>', $premiere->id)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('The second attack was never dispatched.');
        }

        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 10));
        $this->get('/overview')->assertStatus(200);

        $mission->refresh();

        return $mission;
    }

    private function metalOf(PlanetService $cible): int
    {
        return (int)Planet::query()->whereKey($cible->getPlanetId())->value('metal');
    }
}
