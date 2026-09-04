<?php

namespace Tests\Feature\Combat;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Une flotte engagee dans un combat durable ne se rappelle plus.
 *
 * ## Pourquoi la regle amont ne suffit pas
 *
 * Le rappel amont refuse une flotte « arrivee » en lisant `time_arrival < now` : a la seconde meme
 * de l'arrivee, il passe. Et il autorise expressement le rappel d'une Defense ACS pendant tout son
 * stationnement. Dans le chemin instantane, ni l'un ni l'autre ne coute rien : la bataille est deja
 * appliquee. Dans le chemin durable, la bataille est calculee a la fermeture et appliquee a
 * l'echeance — une flotte rappelee entre les deux combattrait **et** rentrerait.
 *
 * ## Ce que ces essais prouvent
 *
 * Le refus tient dans `FleetMissionService::cancelMission()`, le seul chemin de tout rappel, pour les
 * deux facons d'etre engagee ; il cesse avec le combat ; et il ne touche pas une vague qui n'est
 * pas encore arrivee.
 */
class EngagedFleetRecallTest extends FleetDispatchTestCase
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
     * La flotte arrivee a un combat ne se rappelle pas, meme a la seconde de son arrivee.
     *
     * A cette seconde, la regle amont (« pas encore arrivee » se lit `time_arrival < now`) laisserait
     * passer le rappel. La flotte est pourtant deja au combat, inscrite par son arrivee.
     */
    public function testTheFleetThatArrivedCannotBeRecalledEvenAtTheSecondOfItsArrival(): void
    {
        [$combat, $ouvreuse] = $this->aRallyWithASecondWaveExpected();

        resolve(FleetMissionService::class)->cancelMission($ouvreuse);

        $ouvreuse->refresh();
        $this->assertSame(0, (int)$ouvreuse->canceled, 'The fleet that opened the combat was recalled: it will fight and come home at once.');
        $this->assertSame(0, (int)$ouvreuse->processed);
        $this->assertSame($combat->id, (int)$ouvreuse->combat_instance_id);
        $this->assertSame(0, FleetMission::query()->where('parent_id', $ouvreuse->id)->count(), 'A return was created for an engaged fleet.');
    }

    /**
     * Un renfort defensif inscrit ne se rappelle pas pendant son stationnement, tant que le combat dure.
     *
     * Le renfort n'a pas de `combat_instance_id` : seule la ligne de participant le lie au combat. La
     * regle amont autorise le rappel d'une Defense ACS pendant tout son maintien ; ici, non.
     */
    public function testADefendingReinforcementCannotBeRecalledWhileTheCombatLasts(): void
    {
        [$combat, $renfort, $ouverture] = $this->aClosedCombatWithADefendingReinforcement();

        $this->assertNull($renfort->refresh()->combat_instance_id, 'The reinforcement carries a combat id: the participant row is no longer its only link.');

        $this->travelTo(Date::createFromTimestamp($ouverture + 10));
        resolve(FleetMissionService::class)->cancelMission($renfort);

        $renfort->refresh();
        $this->assertSame(0, (int)$renfort->canceled, 'An engaged reinforcement was recalled: it will fight and come home at once.');
        $this->assertSame(0, (int)$renfort->processed);
        $this->assertSame($ouverture + 15, (int)$renfort->time_arrival);
        $this->assertSame(0, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'A return was created for an engaged reinforcement.');
    }

    /**
     * Le combat annule, le renfort inscrit est deja rentre par l'annulation ; un rappel n'y ajoute rien.
     *
     * ## Ce que la regle devient quand le combat finit
     *
     * Une premiere version de cet essai rappelait le renfort apres l'annulation et attendait qu'il
     * parte. L'annulation rend maintenant l'effectif des deux camps : le renfort inscrit rentre par
     * elle, avec sa cause, comme les attaquantes. La regle « une engagee ne se rappelle pas » ne
     * survit pas au combat — mais il n'y a plus rien a rappeler, et un rappel apres coup ne doit
     * surtout pas creer un second retour.
     */
    public function testOnceTheCombatIsCancelledTheReinforcementIsAlreadyHomeBoundAndARecallAddsNothing(): void
    {
        [$combat, $renfort, $ouverture] = $this->aClosedCombatWithADefendingReinforcement();

        $issue = resolve(AttackMission::class)->cancelPersistentCombat($combat->id, CombatCancellationCause::AdministrativeDecision, 'essai', $ouverture + 30);
        $this->assertTrue($issue->cancelled, 'The combat could not be cancelled: ' . $issue->reason);
        $this->assertSame(CombatState::Cancelled, $combat->refresh()->status);
        $this->assertSame(1, $issue->defendersSentHome, 'The cancellation did not send the enrolled reinforcement home.');

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'The enrolled reinforcement stayed on the body after the cancellation.');
        $this->assertSame(0, (int)$renfort->canceled, 'The cancellation was recorded as a player recall.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'The reinforcement is not coming home.');
        $this->assertSame($ouverture + 30, (int)FleetMission::query()->where('parent_id', $renfort->id)->value('time_departure'), 'The return does not leave at the cancellation instant.');

        // Un rappel apres coup : rien de plus, surtout pas un second retour.
        $this->travelTo(Date::createFromTimestamp($ouverture + 40));
        resolve(FleetMissionService::class)->cancelMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(0, (int)$renfort->canceled, 'A recall after the cancellation was accepted on a fleet already home-bound.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'A recall after the cancellation doubled the return.');
    }

    /**
     * Une vague encore en vol se rappelle, et la fermeture ne l'inscrit pas.
     *
     * Elle n'est ni arrivee, ni inscrite : rien ne l'engage. C'est la frontiere de la regle, et elle
     * est deja arretee — une candidate rappelee est retiree, elle ne rejoint pas la photographie.
     */
    public function testAWaveStillInFlightCanBeRecalledAndLeavesTheRally(): void
    {
        [$combat, , $vague, , $ouverture] = $this->aRallyWithASecondWaveExpected();

        $this->travelTo(Date::createFromTimestamp($ouverture + 5));
        resolve(FleetMissionService::class)->cancelMission($vague);

        $vague->refresh();
        $this->assertSame(1, (int)$vague->canceled, 'A wave still in flight could not be recalled: the rule reached a fleet that is not engaged.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $vague->id)->count());

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');
        $this->assertFalse(
            CombatParticipant::query()->where('combat_instance_id', $combat->id)->where('fleet_mission_id', $vague->id)->exists(),
            'A recalled wave was registered as a participant.'
        );
    }

    /**
     * Un combat ferme, avec un renfort defensif inscrit — lance avant l'arrivee de l'attaque, puisque
     * la liste des candidates defensives est figee a l'ouverture.
     *
     * @return array{0: CombatInstance, 1: FleetMission, 2: int}
     */
    private function aClosedCombatWithADefendingReinforcement(): array
    {
        $renfort = null;

        [$combat, , , , $ouverture] = $this->aRallyWithASecondWaveExpected(function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcementArriving($cible, $ouverture + 15, 10);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');
        $this->assertTrue(
            $this->isADefendingParticipant($combat, $renfort),
            'The reinforcement was not admitted (' . $this->refusalOf($combat, $renfort) . '): nothing would be engaged.'
        );

        return [$combat, $renfort, $ouverture];
    }

    /**
     * Un combat en ralliement : l'ouvreuse arrivee et traitee par la route reelle, une seconde vague
     * du meme joueur attendue dix-huit secondes plus tard.
     *
     * L'horloge est laissee a la seconde de l'ouverture : c'est la que la regle amont laisse passer.
     *
     * @param Closure(PlanetService, int): void|null $avantOuverture Ce qui doit exister avant l'arrivee.
     * @return array{0: CombatInstance, 1: FleetMission, 2: FleetMission, 3: PlanetService, 4: int}
     */
    private function aRallyWithASecondWaveExpected(Closure|null $avantOuverture = null): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));
        $ouvreuse = $this->lastAttackDispatched();
        $ouverture = (int)$ouvreuse->time_arrival;

        // Deux flottes parties ensemble arrivent ensemble : l'arrivee de la vague est repoussee a la
        // main. Elle reste partie avant l'ouverture, donc en vol a l'ouverture.
        $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
        DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 18]);
        $vague->refresh();

        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 500_000,
            'crystal' => 300_000,
            'deuterium' => 100_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        if ($avantOuverture !== null) {
            $avantOuverture($cible, $ouverture);
        }

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp($ouverture));
        $this->get('/overview')->assertStatus(200);

        $ouvreuse->refresh();
        $combat = CombatInstance::query()->find((int)($ouvreuse->combat_instance_id ?? 0));

        if ($combat === null) {
            $this->fail('The arrival did not open a combat: nothing is engaged.');
        }

        $this->assertSame(CombatState::Rallying, $combat->status, 'The rally closed at once: the second wave was not expected.');

        return [$combat, $ouvreuse, $vague, $cible, $ouverture];
    }

    private function aSecondAttackAgainst(PlanetService $cible, FleetMission $premiere): FleetMission
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);

        $coordonnees = $cible->getPlanetCoordinates();
        $this->dispatchFleet(
            new Coordinate($coordonnees->galaxy, $coordonnees->system, $coordonnees->position),
            $unites,
            new Resources(0, 0, 0, 0),
            PlanetType::Planet
        );

        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->where('planet_id_to', $cible->getPlanetId())
            ->where('id', '>', $premiere->id)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('The second attack was never dispatched against the same body.');
        }

        return $mission;
    }

    /**
     * Un renfort defensif d'un tiers : en vol a l'ouverture, arrive dans la fenetre, en stationnement.
     *
     * Le lecteur de candidates lit `time_arrival` tel quel, donc le renfort doit y tenir dans la
     * fenetre. Ce que `time_arrival` veut dire pour une Defense ACS en stationnement est un point
     * ouvert, note au rapport ; il ne change rien a ce que ces essais prouvent.
     */
    private function aDefensiveReinforcementArriving(PlanetService $cible, int $arrivesAt, int $holding): FleetMission
    {
        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        // Un vrai joueur : la base garde des comptes systeme et des PNJ, et un camp PNJ ne se renforce pas.
        $allie = User::query()
            ->where('is_npc', false)
            ->where('vacation_mode', false)
            ->where('username', '!=', User::SYSTEM_ACCOUNT_USERNAME)
            ->whereNotIn('id', [$this->currentUserId, $proprietaire])
            ->orderByDesc('id')
            ->first();

        if ($allie === null) {
            $this->fail('No third player exists to send a reinforcement.');
        }

        $origine = Planet::query()->where('user_id', $allie->id)->orderBy('id')->first();

        if ($origine === null) {
            $this->fail('The ally owns no planet: the reinforcement could leave from nowhere.');
        }

        $coordonnees = $cible->getPlanetCoordinates();

        return FleetMission::forceCreate([
            'user_id' => $allie->id,
            'planet_id_from' => $origine->id,
            'type_from' => 1,
            'galaxy_from' => $origine->galaxy,
            'system_from' => $origine->system,
            'position_from' => $origine->planet,
            'planet_id_to' => $cible->getPlanetId(),
            'type_to' => 1,
            'galaxy_to' => $coordonnees->galaxy,
            'system_to' => $coordonnees->system,
            'position_to' => $coordonnees->position,
            'mission_type' => 5,
            'time_departure' => $arrivesAt - 600,
            'time_arrival' => $arrivesAt,
            'time_holding' => $holding,
            // Les messages d'arrivee sont deja partis : ce n'est pas ce que l'on eprouve.
            'processed_hold' => 1,
            'light_fighter' => 5,
            // Le retour relit le fret : une colonne nulle n'est pas un fret vide.
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    private function isADefendingParticipant(CombatInstance $combat, FleetMission $mission): bool
    {
        return CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $mission->id)
            ->where('side', CombatParticipant::SIDE_DEFENDER)
            ->exists();
    }

    /**
     * Pourquoi la fermeture a renvoye cette flotte, si elle l'a dit.
     */
    private function refusalOf(CombatInstance $combat, FleetMission $mission): string
    {
        $avis = CombatOutboxMessage::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($mission->id))
            ->first();

        if ($avis === null) {
            return 'no notice at all';
        }

        $raison = $avis->payload['reason'] ?? null;

        return is_string($raison) ? $raison : 'a notice without a reason';
    }

    private function lastAttackDispatched(): FleetMission
    {
        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        return $mission;
    }
}
