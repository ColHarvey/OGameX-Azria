<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\AdmissionCeiling;
use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Admission\DefensiveAdmissionSelector;
use OGame\Combat\Admission\DefensiveRallyCandidate;
use OGame\Combat\Admission\FrozenAllianceMembership;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\EngagedFleetCheck;
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
            DefensiveRallyCandidate::ofAll(array_values($lues)),
            AdmissionCeiling::whilePlanningTheWindow(self::OPENING)
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

        // **Elle vole encore : rien ne la retient.** La retenue ne vise que ce qui est pose sur le
        // corps ; immobiliser une flotte en vol l empecherait d etre rappelee alors que son
        // proprietaire en a encore le droit.
        $this->assertNull($renfort->refresh()->combat_instance_id, 'A reinforcement still in flight was held before it landed.');

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
     * Une Defense ACS deja posee sur le corps est retenue des l'ouverture.
     *
     * Elle fait partie de l'etat du corps : ni un rappel, ni la fin de son stationnement ne doit la
     * faire partir avant que l'admission ait prononce son verdict. Le lien est celui que l'arrivee
     * d'une attaque pose deja, et `EngagedFleetCheck` le lit.
     */
    public function testAReinforcementAlreadyPresentIsHeldFromTheOpening(): void
    {
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $presente = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300, 3600);
        // **Deux vagues du meme joueur** : la seconde est admissible, donc elle prolonge la fenetre
        // et le ralliement reste ouvert — sans quoi la retenue ne serait pas observable.
        $attaquant = $this->aPlayer();
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $attaquant);
        $this->anAttackAt($corps, self::OPENING + 20, $attaquant);

        $combat = (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertSame(CombatState::Rallying, $combat->refresh()->status, 'The rally closed at once: the hold would not be observable.');
        $this->assertSame($combat->id, (int)$presente->refresh()->combat_instance_id, 'A reinforcement already on the body was not held.');
        $this->assertTrue((new EngagedFleetCheck())->isEngaged($presente), 'The held reinforcement is not seen as engaged: recall and hold expiry would still let it leave.');
    }

    /**
     * Une flotte rappelee avant l'ouverture n'est pas retenue : elle est deja repartie.
     */
    public function testARecalledReinforcementIsNotHeld(): void
    {
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $rappelee = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300, 3600);
        $rappelee->canceled = 1;
        $rappelee->save();
        $attaquant = $this->aPlayer();
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $attaquant);
        $this->anAttackAt($corps, self::OPENING + 20, $attaquant);

        $combat = (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertSame(CombatState::Rallying, $combat->refresh()->status);
        $this->assertNull($rappelee->refresh()->combat_instance_id, 'A fleet recalled before the opening was held by the combat.');
    }

    /**
     * La fermeture garde les admises et libere les refusees.
     *
     * Cinq joueurs defenseurs au plus, proprietaire compris : le cinquieme renfort exterieur est
     * refuse pour la limite de joueurs. Il a ete retenu le temps du verdict, il ne l'est plus apres.
     */
    public function testTheClosureKeepsTheAdmittedAndReleasesTheRefused(): void
    {
        $defenseur = $this->aPlayer();
        $corps = $this->aPlanetOwnedBy($defenseur)->id;

        $renforts = [];
        for ($i = 0; $i < 5; $i++) {
            $renforts[] = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300 - $i, 3600);
        }

        $attaquant = $this->aPlayer();
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $attaquant);
        $this->anAttackAt($corps, self::OPENING + 20, $attaquant);

        $combat = (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->assertSame(CombatState::Rallying, $combat->refresh()->status);

        // L attaquante porte le lien que son arrivee lui pose en jeu : la fermeture ne doit jamais
        // le lui reprendre, quel que soit le sort des renforts.
        $ouvreur->combat_instance_id = $combat->id;
        $ouvreur->save();

        foreach ($renforts as $renfort) {
            $this->assertSame($combat->id, (int)$renfort->refresh()->combat_instance_id, 'A present reinforcement was not held before the verdict.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, self::OPENING + 21)->closed, 'The rally did not close.');

        $retenues = 0;
        $liberees = 0;

        foreach ($renforts as $renfort) {
            if ($this->isADefendingParticipant($combat, $renfort)) {
                $this->assertSame($combat->id, (int)$renfort->refresh()->combat_instance_id, 'An admitted reinforcement lost its hold.');
                $retenues++;

                continue;
            }

            $this->assertNull($renfort->refresh()->combat_instance_id, 'A refused reinforcement is still held: it would stand outside the photograph.');
            $liberees++;
        }

        $this->assertSame($combat->id, (int)$ouvreur->refresh()->combat_instance_id, 'The closure released an attacking fleet: its link comes from its own arrival.');
        $this->assertSame(4, $retenues, 'The defensive player budget did not admit exactly four outsiders.');
        $this->assertSame(1, $liberees, 'No reinforcement was refused: the test would prove nothing about release.');
    }

    /**
     * Une flotte deja rattachee a un combat n est jamais reprise par un autre.
     *
     * Le lien dit a quelle photographie la flotte appartient. Le lui reprendre la ferait disparaitre
     * de celle ou elle est deja inscrite — et apparaitre dans une ou personne ne l a jugee.
     */
    public function testAFleetAlreadyBoundToACombatIsNeverTakenByAnother(): void
    {
        $ailleurs = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $premier = (new CombatOpeningService())->openOrJoin(
            $this->anAttackAt($ailleurs, self::OPENING, $this->aPlayer()),
            $ailleurs,
            self::OPENING
        );

        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $presente = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING - 300, 3600);
        $presente->combat_instance_id = $premier->id;
        $presente->save();

        $attaquant = $this->aPlayer();
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $attaquant);
        $this->anAttackAt($corps, self::OPENING + 20, $attaquant);

        $second = (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertNotSame($premier->id, $second->id, 'Both attacks opened the same combat: the test would prove nothing.');
        $this->assertSame($premier->id, (int)$presente->refresh()->combat_instance_id, 'A second combat took a fleet that already belonged to another.');
    }

    /**
     * Une place liberee par un rappel ne fait pas entrer une candidate qui arrive apres l'echeance.
     *
     * ## Le defaut que cet essai ferme
     *
     * Le selecteur jugeait contre le plafond de la fenetre — soixante secondes — aux deux passages.
     * A l'ouverture c'est juste : on cherche qui pourrait fixer l'echeance. A la fermeture c'est
     * faux : l'echeance est deja fixee, et la photographie se prend a cet instant-la.
     *
     * Le scenario : quatre renforts en vol occupent les quatre places exterieures et fixent
     * l'echeance ; un cinquieme, prevu bien plus tard, est refuse a l'ouverture pour la limite de
     * joueurs — il ne prolonge donc rien. Un rappel libere ensuite sa place. Juge contre le plafond,
     * il entrait dans une photographie prise quarante-quatre secondes avant son arrivee.
     */
    public function testAPlaceFreedByARecallDoesNotAdmitACandidateArrivingAfterTheDeadline(): void
    {
        $defenseur = $this->aPlayer();
        $corps = $this->aPlanetOwnedBy($defenseur)->id;

        // Quatre renforts en vol, quatre joueurs : les quatre places exterieures sont prises, et
        // l'echeance se fixe une seconde apres leur arrivee.
        $enVol = [];
        for ($i = 0; $i < 4; $i++) {
            $enVol[] = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING + 5, 3600);
        }

        // Un cinquieme joueur, prevu bien plus tard : refuse a l'ouverture pour la limite de joueurs,
        // donc il ne prolonge pas la fenetre.
        $tardif = $this->anAcsDefence($this->aPlayer(), $corps, self::OPENING + 50, 3600);

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $this->aPlayer());
        $combat = (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);

        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($barriere);
        $this->assertSame(self::OPENING + 6, (int)$barriere->owned_through_effect_at, 'The deadline is not one tick after the four reinforcements in flight.');

        // Un rappel libere une place avant la fermeture.
        $enVol[0]->canceled = 1;
        $enVol[0]->save();

        $this->assertTrue((new RallyClosureService())->close($combat->id, self::OPENING + 6)->closed, 'The rally did not close.');

        $this->assertFalse(
            $this->isADefendingParticipant($combat, $tardif),
            'A candidate arriving forty-four seconds after the photograph was taken entered it, because a place had been freed.'
        );
        $this->assertFalse($this->isADefendingParticipant($combat, $enVol[0]), 'The recalled reinforcement was admitted.');
        $this->assertSame(3, $this->defendingParticipants($combat), 'The three remaining reinforcements were not the ones admitted.');
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

    /**
     * Le nombre de renforts inscrits du cote defenseur.
     */
    private function defendingParticipants(CombatInstance $combat): int
    {
        return CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('side', CombatParticipant::SIDE_DEFENDER)
            ->whereNotNull('fleet_mission_id')
            ->count();
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
