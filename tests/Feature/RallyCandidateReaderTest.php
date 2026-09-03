<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Services\RallyCandidateReader;
use OGame\Models\AllianceMember;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\TestCase;

/**
 * Ce que la fermeture relit dans la base, et ce qu'elle refuse d'y decider.
 *
 * ## Ce que ces essais protegent
 *
 * Le lecteur ne filtre que sur ce qui **ne peut pas etre juge plus tard** : le corps exact, et la
 * fenetre temporelle. Tout le reste — rappel, alliance, sens de vol, genre d'acteur — est **rendu
 * comme fait**, pour que les selecteurs refusent en le disant.
 *
 * La tentation inverse est forte et couteuse : ecarter les candidates rappelees ou etrangeres des la
 * lecture ferait disparaitre des flottes sans qu'aucun joueur ne sache pourquoi. C'est exactement ce
 * que `CombatArrivalOutcome::RecalledToOrigin` faisait — sept refus differents sous un seul mot.
 *
 * ## L'alliance a l'ouverture
 *
 * `users.alliance_id` porte l'alliance d'aujourd'hui, pas celle de l'ouverture.
 * `alliance_members.joined_at` repond exactement a la question posee, et l'essai le fige : un allie
 * inscrit apres l'ouverture n'est pas un allie de ce combat.
 */
class RallyCandidateReaderTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    private RallyCandidateReader $lecteur;

    /**
     * Le nombre de corps deja crees, pour en donner un different a chacun.
     */
    private int $bodies = 0;

    /**
     * Le proprietaire des corps vises, partage par tous les essais d'une meme methode.
     */
    private User|null $victim = null;

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
     * Seules les missions visant le corps exact sont relues.
     *
     * **Une planete et sa lune partagent leurs coordonnees.** Viser l'une n'est pas viser l'autre,
     * et confondre les deux ferait entrer dans une bataille des flottes qui n'y allaient pas.
     */
    public function testOnlyMissionsAimedAtTheExactBodyAreRead(): void
    {
        $cible = $this->aBodyId();
        $voisin = $this->aBodyId();

        $joueur = $this->aPlayer();

        $attendue = $this->aMission($joueur, $cible, self::OPENING + 10);
        $this->aMission($joueur, $voisin, self::OPENING + 10);

        $candidates = $this->read($cible);

        $this->assertSame(
            [$attendue->id],
            array_map(static fn (CandidateMission $c): int => $c->missionId, $candidates),
            'A fleet aimed at another body of the same coordinates was pulled into the battle.'
        );
    }

    /**
     * La mission qui a ouvert n'est pas une candidate.
     */
    public function testTheOpenerIsNotOneOfItsOwnCandidates(): void
    {
        $cible = $this->aBodyId();
        $joueur = $this->aPlayer();

        $ouvreur = $this->aMission($joueur, $cible, self::OPENING);
        $autre = $this->aMission($joueur, $cible, self::OPENING + 5);

        $candidates = $this->read($cible, openerMissionId: $ouvreur->id);

        $this->assertSame(
            [$autre->id],
            array_map(static fn (CandidateMission $c): int => $c->missionId, $candidates),
            'The founding mission was read as a candidate to its own rally.'
        );
    }

    /**
     * La fenetre est bornee des deux cotes, et fermee du meme cote que partout ailleurs.
     */
    public function testTheWindowIsClosedOnBothSidesTheSameWay(): void
    {
        $cible = $this->aBodyId();
        $joueur = $this->aPlayer();

        $avant = $this->aMission($joueur, $cible, self::OPENING - 1);
        $pile = $this->aMission($joueur, $cible, self::OPENING);
        $dedans = $this->aMission($joueur, $cible, self::OPENING + 59);
        $plafond = $this->aMission($joueur, $cible, self::OPENING + 60);

        $lues = array_map(static fn (CandidateMission $c): int => $c->missionId, $this->read($cible));

        $this->assertNotContains($avant->id, $lues, 'A fleet due before the opening was read as a candidate.');
        $this->assertContains($pile->id, $lues, 'A fleet due exactly at the opening was left out.');
        $this->assertContains($dedans->id, $lues, 'A fleet due one second before the ceiling was left out.');
        $this->assertNotContains($plafond->id, $lues, 'A fleet due exactly at the ceiling was read as a candidate.');
    }

    /**
     * Une candidate rappelee est rendue, avec son fait, et non passee sous silence.
     *
     * C'est ce qui permet au selecteur de refuser en disant `CandidateRecalled` plutot que de laisser
     * une flotte disparaitre sans raison lisible.
     */
    public function testARecalledCandidateIsReportedRatherThanHidden(): void
    {
        $cible = $this->aBodyId();
        $joueur = $this->aPlayer();

        $mission = $this->aMission($joueur, $cible, self::OPENING + 10);
        $mission->canceled = 1;
        $mission->processed = 1;
        $mission->save();

        $candidates = $this->read($cible);

        $this->assertCount(1, $candidates, 'A recalled fleet vanished from the reading instead of being refused with a reason.');
        $this->assertTrue($candidates[0]->recalled);
    }

    /**
     * « Deja en vol a l'ouverture » se compte strictement avant.
     */
    public function testAlreadyInFlightMeansStrictlyBeforeTheOpening(): void
    {
        $cible = $this->aBodyId();
        $joueur = $this->aPlayer();

        $partieAvant = $this->aMission($joueur, $cible, self::OPENING + 10, departsAt: self::OPENING - 1);
        $partiePile = $this->aMission($joueur, $cible, self::OPENING + 11, departsAt: self::OPENING);

        $parIdentifiant = [];

        foreach ($this->read($cible) as $candidate) {
            $parIdentifiant[$candidate->missionId] = $candidate->inFlightAtOpening;
        }

        $this->assertTrue($parIdentifiant[$partieAvant->id], 'A fleet launched before the opening was not counted as already flying.');
        $this->assertFalse(
            $parIdentifiant[$partiePile->id],
            'A fleet launched at the very instant of the opening was counted as already flying: equality must count as after.'
        );
    }

    /**
     * Le sens de vol vient du lien vers la mission qu'elle prolonge.
     */
    public function testTheFlightLegComesFromTheParentMission(): void
    {
        $cible = $this->aBodyId();
        $joueur = $this->aPlayer();

        $aller = $this->aMission($joueur, $cible, self::OPENING + 10);

        $retour = $this->aMission($joueur, $cible, self::OPENING + 20);
        $retour->parent_id = $aller->id;
        $retour->save();

        $parIdentifiant = [];

        foreach ($this->read($cible) as $candidate) {
            $parIdentifiant[$candidate->missionId] = $candidate->leg;
        }

        $this->assertSame(FlightLeg::Outbound, $parIdentifiant[$aller->id]);
        $this->assertSame(FlightLeg::Return, $parIdentifiant[$retour->id]);
    }

    /**
     * L'alliance retenue est celle de l'ouverture, pas celle d'aujourd'hui.
     *
     * **C'est le fait que `users.alliance_id` ne sait pas donner.** Un allie inscrit apres
     * l'ouverture n'est pas un allie de ce combat, meme s'il l'est au moment ou on lit.
     */
    public function testTheAllianceIsTheOneHeldAtTheOpening(): void
    {
        $cible = $this->aBodyId();

        $alliance = $this->anAlliance();

        $ancien = $this->aPlayer();
        $nouveau = $this->aPlayer();
        $etranger = $this->aPlayer();

        $this->joinAlliance($ancien, $alliance, self::OPENING - 3_600);
        $this->joinAlliance($nouveau, $alliance, self::OPENING + 10);

        $missionAncien = $this->aMission($ancien, $cible, self::OPENING + 10);
        $missionNouveau = $this->aMission($nouveau, $cible, self::OPENING + 11);
        $missionEtranger = $this->aMission($etranger, $cible, self::OPENING + 12);

        $parIdentifiant = [];

        foreach ($this->read($cible, governingAllianceId: $alliance) as $candidate) {
            $parIdentifiant[$candidate->missionId] = $candidate->allianceIdAtOpening;
        }

        $this->assertSame(
            $alliance,
            $parIdentifiant[$missionAncien->id],
            'A member who joined before the opening was not recognised as an ally of this combat.'
        );

        $this->assertNull(
            $parIdentifiant[$missionNouveau->id],
            'A player who joined the alliance after the opening was treated as an ally of this combat.'
        );

        $this->assertNull($parIdentifiant[$missionEtranger->id]);
    }

    /**
     * Sans alliance qui gouverne, aucune candidate n'en porte une.
     */
    public function testWithNoGoverningAllianceNobodyCarriesOne(): void
    {
        $cible = $this->aBodyId();

        $alliance = $this->anAlliance();
        $joueur = $this->aPlayer();
        $this->joinAlliance($joueur, $alliance, self::OPENING - 3_600);

        $this->aMission($joueur, $cible, self::OPENING + 10);

        $candidates = $this->read($cible, governingAllianceId: null);

        $this->assertCount(1, $candidates);
        $this->assertNull(
            $candidates[0]->allianceIdAtOpening,
            'A candidate carried an alliance although no alliance governs this combat.'
        );
    }

    /**
     * L'ordre est deterministe : arrivee planifiee, puis identifiant.
     */
    public function testTheOrderIsDeterministic(): void
    {
        $cible = $this->aBodyId();
        $joueur = $this->aPlayer();

        $tardive = $this->aMission($joueur, $cible, self::OPENING + 30);
        $premiere = $this->aMission($joueur, $cible, self::OPENING + 5);
        $exAequo = $this->aMission($joueur, $cible, self::OPENING + 5);

        $lues = array_map(static fn (CandidateMission $c): int => $c->missionId, $this->read($cible));

        $exAequoTriees = [$premiere->id, $exAequo->id];
        sort($exAequoTriees);

        $this->assertSame(
            [...$exAequoTriees, $tardive->id],
            $lues,
            'The reading order depended on something other than the scheduled arrival and the mission id.'
        );
    }

    /**
     * Le genre d'acteur est celui du proprietaire.
     */
    public function testTheActorKindIsTheOwnersOwn(): void
    {
        $cible = $this->aBodyId();

        $pirate = $this->aPlayer();
        $pirate->is_npc = true;
        $pirate->save();

        $this->aMission($pirate, $cible, self::OPENING + 10);

        $candidates = $this->read($cible);

        $this->assertSame(ActorKind::Npc, $candidates[0]->actor);
    }

    /**
     * Les candidates lues pour ce corps.
     *
     * @return array<int, CandidateMission>
     */
    private function read(
        int $targetBodyId,
        int|null $governingAllianceId = null,
        int $openerMissionId = 0,
    ): array {
        return $this->lecteur->read($targetBodyId, self::OPENING, $governingAllianceId, $openerMissionId);
    }

    /**
     * Un joueur, avec une planete d'origine.
     */
    private function aPlayer(): User
    {
        $utilisateur = User::factory()->create();

        $this->aPlanetOwnedBy($utilisateur);

        return $utilisateur;
    }

    /**
     * Une planete a des coordonnees libres, deterministes.
     */
    private function aPlanetOwnedBy(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 4,
            'system' => 100 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }

    /**
     * Inscrit un joueur dans une alliance a une date donnee.
     */
    private function joinAlliance(User $user, int $allianceId, int $joinedAt): void
    {
        AllianceMember::create([
            'alliance_id' => $allianceId,
            'user_id' => $user->id,
            'rank_id' => null,
            'joined_at' => date('Y-m-d H:i:s', $joinedAt),
        ]);
    }

    /**
     * Une alliance, creee par un fondateur qui ne participe a rien.
     *
     * `createAlliance()` inscrit son fondateur avec un `joined_at` a maintenant ; les membres dont
     * la date compte sont donc inscrits a la main, avec la leur.
     */
    private function anAlliance(): int
    {
        $fondateur = $this->aPlayer();

        return app(AllianceService::class)->createAlliance($fondateur->id, "RCR", "Lecteur")->id;
    }

    /**
     * Une attaque en vol vers ce corps.
     */
    private function aMission(
        User $owner,
        int $targetBodyId,
        int $arrivesAt,
        int|null $departsAt = null,
        int $missionType = 1,
    ): FleetMission {
        return FleetMission::forceCreate([
            'user_id' => $owner->id,
            'planet_id_to' => $targetBodyId,
            'mission_type' => $missionType,
            'time_departure' => $departsAt ?? (self::OPENING - 600),
            'time_arrival' => $arrivesAt,
            'galaxy_to' => 4,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);
    }

    /**
     * Un corps celeste reel, vise par les missions de l'essai.
     *
     * **Une planete, pas un identifiant invente.** `fleet_missions.planet_id_to` porte une cle
     * etrangere : un identifiant fabrique est refuse par la base. Le rappel vaut d'etre garde — une
     * fixture qui invente des identifiants ne prouve rien de ce qu'elle croit prouver.
     */
    private function aBodyId(): int
    {
        return $this->aPlanetOwnedBy($this->aVictim())->id;
    }

    /**
     * Le proprietaire des corps vises, cree une seule fois.
     */
    private function aVictim(): User
    {
        return $this->victim ??= User::factory()->create();
    }
}
