<?php

namespace Tests\Feature\Combat;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatReasonCode;
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
 * Une Defense ACS ne stationne jamais hors photographie, et une engagee ne part pas avant le combat.
 *
 * ## Les deux moities de la regle
 *
 * Refusee a la fermeture, ou arrivee apres elle, une Defense ACS repart des que le travailleur la
 * touche — avec la raison de la fermeture si elle existe, `RallyClosed` sinon. Inscrite, elle reste
 * jusqu'a ce que le combat soit final, meme si son stationnement expire entre-temps : la bataille
 * est calculee avec elle et appliquee a l'echeance, la renvoyer entre les deux la ferait combattre
 * et rentrer.
 *
 * Le travailleur est appele directement (`FleetMissionService::updateMission()`) : en jeu, c'est la
 * page de l'allie ou celle du defenseur qui le fait passer sur cette mission.
 */
class AcsDefenceUnderCombatTest extends FleetDispatchTestCase
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
     * Un renfort refuse a la fermeture repart a son arrivee physique, avec la raison de la fermeture.
     *
     * Parti a l'ouverture, il n'etait pas en vol : la fermeture le refuse (`NotAlreadyInFlight`).
     * Le travailleur le renvoie des qu'il est la, et la raison lue est celle du jugement — pas un
     * « ralliement ferme » ecrit par-dessus.
     */
    public function testARefusedReinforcementGoesHomeAtItsArrivalWithTheClosureReason(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 15, 30, $ouverture);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');
        $this->assertFalse($this->isADefendingParticipant($combat, $renfort), 'A reinforcement launched at the opening was admitted: nothing would be refused.');
        $this->assertSame(CombatReasonCode::NotAlreadyInFlight->value, $this->reasonToldTo($combat, $renfort));

        $this->travelTo(Date::createFromTimestamp($ouverture + 25));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'A refused reinforcement is still holding outside the photograph.');
        $this->assertSame(0, (int)$renfort->canceled, 'The server turned the fleet back as if the player had recalled it.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'The refused reinforcement is not coming home.');
        $this->assertSame(CombatReasonCode::NotAlreadyInFlight->value, $this->reasonToldTo($combat, $renfort), 'The closure reason was overwritten.');
    }

    /**
     * Un renfort arrive apres la fermeture repart avec « ralliement ferme ».
     *
     * Son arrivee physique tombe au-dela du plafond : il n'a jamais ete candidat, personne ne l'a
     * juge. Il apprend pourquoi par le meme canal que les refus.
     */
    public function testAReinforcementArrivingAfterTheClosureGoesHomeWithRallyClosed(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(false, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 90, 600, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertSame(CombatState::Active, $combat->refresh()->status, 'A reinforcement beyond the ceiling kept the rally open.');
        $this->assertNull($this->reasonToldTo($combat, $renfort), 'A fleet that was never a candidate received a verdict.');

        $this->travelTo(Date::createFromTimestamp($ouverture + 95));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'A late reinforcement is holding outside the photograph.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count());
        $this->assertSame(CombatReasonCode::RallyClosed->value, $this->reasonToldTo($combat, $renfort), 'The late reinforcement went home without being told why.');

        // **La retenue ne vaut que pendant le ralliement.** Retenir une flotte arrivee apres la
        // fermeture la ferait passer pour engagee alors qu elle rentre : `EngagedFleetCheck` la
        // verrait, et son propre retour serait bloque.
        $this->assertNull($renfort->combat_instance_id, 'A reinforcement that landed after the closure was held by the combat.');
    }

    /**
     * Le stationnement d'un renfort inscrit ne s'acheve pas avant le combat ; le combat fini, il rentre.
     */
    public function testTheHoldOfAnEngagedReinforcementDoesNotEndBeforeTheCombat(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 5, 30, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');
        $this->assertTrue($this->isADefendingParticipant($combat, $renfort), 'The reinforcement was not admitted (' . ($this->reasonToldTo($combat, $renfort) ?? 'no notice') . ').');

        // Son stationnement expire pendant le combat.
        $this->travelTo(Date::createFromTimestamp($ouverture + 40));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(0, (int)$renfort->processed, 'An engaged reinforcement went home before the battle was applied: it fights and comes home at once.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $renfort->id)->count());

        // Le combat fini, son stationnement expire est traite.
        $issue = resolve(AttackMission::class)->cancelPersistentCombat($combat->id, CombatCancellationCause::AdministrativeDecision, $ouverture + 41);
        $this->assertTrue($issue->cancelled);

        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'The hold of a reinforcement outlived the combat.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count());
    }

    /**
     * Une vague attaquante refusee garde la raison de la fermeture quand son arrivee est traitee.
     *
     * L'avis de la fermeture est construit a la main : il est exactement ce qu'elle ecrit pour une
     * vague refusee, et c'est le traitement de l'arrivee qui est eprouve, pas la fermeture.
     */
    public function testARefusedWaveKeepsTheClosureReasonWhenItsArrivalIsProcessed(): void
    {
        [$combat, $ouvreuse, , $ouverture, $cible] = $this->anOpenedCombat(false);
        $this->assertSame(CombatState::Active, $combat->refresh()->status);

        $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
        DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 30]);

        CombatOutboxMessage::query()->create([
            'combat_instance_id' => $combat->id,
            'participant_key' => CombatParticipantKey::forFleet($vague->id),
            'kind' => CombatOutboxKind::RallyRefused->value,
            'payload' => ['reason' => CombatReasonCode::FleetLimitReached->value, 'group_fleets' => 1],
            'available_at' => $ouverture,
        ]);

        $this->travelTo(Date::createFromTimestamp($ouverture + 40));
        $this->get('/overview')->assertStatus(200);

        $vague->refresh();
        $this->assertSame(1, (int)$vague->processed, 'The refused wave is still waiting.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $vague->id)->count());
        $this->assertSame(CombatReasonCode::FleetLimitReached->value, $this->reasonToldTo($combat, $vague), 'The closure reason was overwritten by « rally closed ».');
    }

    /**
     * Un renfort qui se pose pendant le ralliement est retenu, et son stationnement n'expire pas.
     *
     * C'est la moitie que la fermeture ne couvrait pas : entre son arrivee physique et le verdict,
     * la flotte est sur le corps sans etre encore inscrite. Rien ne la retenait.
     */
    public function testAReinforcementLandingDuringTheRallyIsHeldAtOnce(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            // Arrivee physique cinq secondes apres l'ouverture, stationnement de dix secondes : il se
            // pose pendant le ralliement, et sa fin de stationnement tombe avant la fermeture.
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 5, 10, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertSame(CombatState::Rallying, $combat->refresh()->status, 'The rally closed at once.');
        $this->assertNull($renfort->refresh()->combat_instance_id, 'The reinforcement was held before it landed.');

        // Il se pose : le travailleur le retient.
        $this->travelTo(Date::createFromTimestamp($ouverture + 6));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $this->assertSame($combat->id, (int)$renfort->refresh()->combat_instance_id, 'A reinforcement landing during the rally was not held.');

        // Son stationnement expire avant la fermeture : il ne part pas pour autant.
        $this->travelTo(Date::createFromTimestamp($ouverture + 16));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(0, (int)$renfort->processed, 'The hold expired and the fleet left before the verdict.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $renfort->id)->count());
    }

    /**
     * Un combat ouvert par une vraie attaque, traitee par la route reelle a la seconde de son arrivee.
     *
     * @param bool $secondeVague Une seconde vague du meme joueur, attendue dix-huit secondes plus tard.
     * @param Closure(PlanetService, int): void|null $avantOuverture Ce qui doit exister avant l'arrivee.
     * @return array{0: CombatInstance, 1: FleetMission, 2: FleetMission|null, 3: int, 4: PlanetService}
     */
    private function anOpenedCombat(bool $secondeVague, Closure|null $avantOuverture = null): array
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

        $vague = null;

        if ($secondeVague) {
            $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
            DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 18]);
            $vague->refresh();
        }

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
            $this->fail('The arrival did not open a combat.');
        }

        return [$combat, $ouvreuse, $vague, $ouverture, $cible];
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
     * Un renfort defensif d'un vrai joueur tiers : `time_arrival` porte la fin du stationnement.
     */
    private function aDefensiveReinforcement(PlanetService $cible, int $physicalArrival, int $holding, int $departsAt): FleetMission
    {
        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
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
            $this->fail('The ally owns no planet.');
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
            'time_departure' => $departsAt,
            'time_arrival' => $physicalArrival + $holding,
            'time_holding' => $holding,
            'processed_hold' => 1,
            'light_fighter' => 5,
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

    private function reasonToldTo(CombatInstance $combat, FleetMission $mission): string|null
    {
        $avis = CombatOutboxMessage::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($mission->id))
            ->first();

        if ($avis === null) {
            return null;
        }

        $raison = $avis->payload['reason'] ?? null;

        return is_string($raison) ? $raison : null;
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
