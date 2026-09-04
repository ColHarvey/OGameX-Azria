<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Admission\DefensiveAdmissionSelector;
use OGame\Combat\Admission\DefensiveRallyCandidate;
use OGame\Combat\Admission\FrozenAllianceMembership;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RallyCandidateReader;
use OGame\Combat\Services\RallyClosureService;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Une Defense ACS est lue a son arrivee physique, et une flotte deja presente compose la photographie.
 *
 * ## Ce qui etait faux
 *
 * Une Defense ACS a l'aller porte la fin de son stationnement dans `time_arrival`. Le lecteur de
 * candidates la lisait telle quelle : une flotte deja stationnee a l'ouverture n'etait jamais
 * candidate, un renfort en vol n'etait jamais lu, et la fenetre ne comptait qu'un camp. Le chemin
 * instantane, lui, lit les Defenses ACS presentes (`collectDefendingFleets()`).
 *
 * ## Les regles arretees que ces essais rendent vraies
 *
 * « Les forces presentes sont deja celles qui composeront la photo » ; « fermeture = derniere
 * arrivee admise des deux camps + 1 » ; une flotte deja partie n'est plus une candidate, une flotte
 * rappelee reste lue pour que son refus se raconte.
 */
class AcsDefencePresenceTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    private RallyCandidateReader $lecteur;

    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecteur = new RallyCandidateReader();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * L'arrivee planifiee d'une Defense ACS est celle du corps, pas la fin de son stationnement.
     */
    public function testAnAcsDefenceIsReadAtItsPhysicalArrivalNotAtTheEndOfItsHold(): void
    {
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $renfort = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING + 20, 3600);
        $attaque = $this->anAttackAt($corps, self::OPENING + 20, $this->aPlayer());

        $lues = $this->readById($corps);

        $this->assertArrayHasKey($renfort->id, $lues, 'A reinforcement arriving in the window was not read: its hold ends long after it.');
        $this->assertSame(CombatMissionKind::AcsDefend, $lues[$renfort->id]->mission);
        $this->assertSame(self::OPENING + 20, $lues[$renfort->id]->scheduledArrivalAt, 'The reinforcement is dated at the end of its hold, not at its arrival.');
        $this->assertSame(self::OPENING + 3620, $lues[$renfort->id]->holdsUntil);
        $this->assertTrue($lues[$renfort->id]->isStillHoldingAt(self::OPENING + 3619));
        $this->assertFalse($lues[$renfort->id]->isStillHoldingAt(self::OPENING + 3620), 'A hold that ends at the instant still counts as holding: equality must mean « after ».');

        $this->assertArrayHasKey($attaque->id, $lues);
        $this->assertSame(self::OPENING + 20, $lues[$attaque->id]->scheduledArrivalAt);
        $this->assertNull($lues[$attaque->id]->holdsUntil, 'An attack has no hold.');
    }

    /**
     * Une Defense ACS deja stationnee a l'ouverture est presente ; partie avant, elle n'est rien.
     */
    public function testAnAcsDefenceAlreadyHoldingAtTheOpeningIsPresent(): void
    {
        $defenseur = $this->aPlayer();
        $corps = $this->aPlanetOwnedBy($defenseur)->id;
        $presente = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300, 3600);
        $partie = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300, 299);
        $auSeuil = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300, 300);

        $lues = $this->readById($corps);

        $this->assertArrayHasKey($presente->id, $lues, 'A fleet holding at the opening was not read: it would not fight, though it is there.');
        $this->assertSame(self::OPENING - 300, $lues[$presente->id]->scheduledArrivalAt);
        $this->assertArrayNotHasKey($partie->id, $lues, 'A fleet whose hold ended before the opening was read.');
        $this->assertArrayNotHasKey($auSeuil->id, $lues, 'A fleet whose hold ends at the opening itself was read: equality must mean « after ».');

        $verdict = (new DefensiveAdmissionSelector())->select(
            $defenseur->id,
            $corps,
            self::OPENING,
            DefensiveRallyCandidate::ofAll(array_values($lues))
        );

        $admises = array_map(static fn ($groupe): int => $groupe->missions[0]->missionId, $verdict->admitted());
        $this->assertSame([$presente->id], $admises, 'The present fleet was refused: ' . implode(', ', array_map(static fn ($a): string => (string)$a->refusal?->value, $verdict->refused())));
    }

    /**
     * Une flotte deja partie n'est plus une candidate ; une flotte rappelee reste lue, et dite rappelee.
     */
    public function testAFleetThatAlreadyLeftIsNoCandidateButARecalledOneIsStillTold(): void
    {
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $partie = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING + 10, 3600);
        $partie->processed = 1;
        $partie->save();
        $rappelee = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING + 12, 3600);
        $rappelee->canceled = 1;
        $rappelee->processed = 1;
        $rappelee->save();

        $lues = $this->readById($corps);

        $this->assertArrayNotHasKey($partie->id, $lues, 'A fleet that already went home was read as a candidate: it would fight from its origin.');
        $this->assertArrayHasKey($rappelee->id, $lues, 'A recalled fleet was hidden: its refusal could not be told.');
        $this->assertTrue($lues[$rappelee->id]->recalled);
    }

    /**
     * Un renfort defensif en vol prolonge la fenetre : « derniere arrivee admise des deux camps + 1 ».
     */
    public function testADefensiveReinforcementInFlightExtendsTheWindow(): void
    {
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $this->aPlayer());
        $renfort = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING + 20, 3600);

        $combat = (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertSame(CombatState::Rallying, $combat->status, 'The rally closed at once: the reinforcement in flight was not awaited.');
        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($barriere);
        $this->assertSame(self::OPENING + 21, (int)$barriere->owned_through_effect_at, 'The window does not close one tick after the last admitted arrival of both sides.');

        $this->assertTrue((new RallyClosureService())->close($combat->id, self::OPENING + 21)->closed);
        $this->assertTrue($this->isADefendingParticipant($combat, $renfort), 'The awaited reinforcement was not registered as a defender.');
    }

    /**
     * Une flotte presente n'a rien a prolonger : la fenetre reste nulle, et elle est dans la photographie.
     */
    public function testAPresentFleetExtendsNothingAndIsInThePhotograph(): void
    {
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $presente = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300, 3600);
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $this->aPlayer());

        $combat = (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);
        $combat->refresh();

        $this->assertSame(CombatState::Active, $combat->status, 'A present fleet kept the rally open: it has nothing to await.');
        $this->assertTrue($this->isADefendingParticipant($combat, $presente), 'A fleet holding at the opening is absent from the photograph.');
    }

    /**
     * @return array<int, CandidateMission> par identifiant de mission
     */
    private function readById(int $targetBodyId): array
    {
        $parId = [];

        foreach ($this->lecteur->read($targetBodyId, self::OPENING, FrozenAllianceMembership::none(), 0) as $candidate) {
            $parId[$candidate->missionId] = $candidate;
        }

        return $parId;
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
     * Une Defense ACS a l'aller : `time_arrival` porte la fin du stationnement, comme dans le jeu.
     */
    private function anAcsDefence(User $owner, int $targetBodyId, int $physicalArrival, int $holding): FleetMission
    {
        return FleetMission::forceCreate([
            'user_id' => $owner->id,
            'planet_id_from' => $this->aPlanetIdOf($owner),
            'type_from' => 1,
            'planet_id_to' => $targetBodyId,
            'mission_type' => 5,
            'time_departure' => $physicalArrival - 600,
            'time_arrival' => $physicalArrival + $holding,
            'time_holding' => $holding,
            'galaxy_to' => 7,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 5,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    private function anAttackAt(int $targetBodyId, int $arrivesAt, User $owner): FleetMission
    {
        return FleetMission::forceCreate([
            'user_id' => $owner->id,
            'planet_id_from' => $this->aPlanetIdOf($owner),
            'type_from' => 1,
            'planet_id_to' => $targetBodyId,
            'mission_type' => 1,
            'time_departure' => self::OPENING - 600,
            'time_arrival' => $arrivesAt,
            'galaxy_to' => 7,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    private function aPlanetIdOf(User $owner): int
    {
        $id = Planet::query()->where('user_id', $owner->id)->value('id');

        if (!is_int($id)) {
            $this->fail('The player ' . $owner->id . ' owns no planet.');
        }

        return $id;
    }

    private function aPlayer(): User
    {
        $utilisateur = User::factory()->create();

        $this->aPlanetOwnedBy($utilisateur);

        return $utilisateur;
    }

    private function aPlanetOwnedBy(User $owner): Planet
    {
        $this->bodies++;

        // Loin des coordonnees que les autres fixtures occupent : une planete et sa position sont uniques.
        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 7,
            'system' => 500 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
