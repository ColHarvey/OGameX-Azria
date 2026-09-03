<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\FrozenAllianceMembership;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\CombatRallyWindow;
use OGame\Combat\Support\CombatRuleVersionSet;
use OGame\Models\AllianceMember;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\TestCase;

/**
 * L'ouverture durable : ce qui se fige, et qui gagne la course.
 *
 * ## Ce que ces essais protegent
 *
 * L'ouverture est l'instant ou un combat choisit les regles sous lesquelles il vivra deux heures.
 * Une seule des quatre versions relue plus tard, et la bataille serait photographiee sous une regle
 * puis reglee sous une autre.
 *
 * Ils protegent aussi la garantie que le code seul ne pouvait pas donner : **un corps celeste ne
 * porte qu'un combat**. Deux flottes arrivant a la meme seconde lisaient toutes deux « libre ».
 * C'est desormais la contrainte d'unicite qui tranche, et le perdant rejoint au lieu d'ouvrir.
 */
class CombatOpeningServiceTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    private CombatOpeningService $service;

    /**
     * Le nombre de corps deja crees, pour en donner un different a chacun.
     */
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CombatOpeningService();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Une ouverture pose l'instance et sa barriere.
     */
    public function testAnOpeningLaysDownTheCombatAndItsBarrier(): void
    {
        [$mission, $corps] = $this->anArrivingAttack();

        $combat = $this->service->openOrJoin($mission, $corps, self::OPENING);

        $this->assertSame(CombatState::Rallying, $combat->status);
        $this->assertSame($corps, $combat->target_planet_id);

        $barriere = CelestialBodyCombatBarrier::where('target_body_id', $corps)->first();

        $this->assertNotNull($barriere, 'The opening left no barrier: the body is not held by anything.');
        $this->assertSame($combat->id, $barriere->combat_instance_id);
        $this->assertSame(self::OPENING, $barriere->opened_at);
    }

    /**
     * Les quatre versions de regle sont ecrites avec le combat.
     *
     * **C'est la garantie qui tient les deux heures.** Sans elles, une mise a jour de regle
     * changerait l'issue de toutes les batailles en cours.
     */
    public function testTheFourRuleVersionsAreWrittenWithTheCombat(): void
    {
        [$mission, $corps] = $this->anArrivingAttack();

        $combat = $this->service->openOrJoin($mission, $corps, self::OPENING);
        $attendues = CombatRuleVersionSet::chosenAtOpening()->toStorage();

        $this->assertSame($attendues['causal_order'], $combat->causal_order_version);
        $this->assertSame($attendues['loot_allocator'], $combat->loot_allocator_version);
        $this->assertSame($attendues['loot_policy'], $combat->loot_policy_version);
        $this->assertSame($attendues['moon_destruction'], $combat->moon_destruction_rule_version);

        $this->assertNotNull(
            $combat->frozen_facts_fingerprint,
            'The frozen facts carry no fingerprint, so nothing would notice if one of them moved.'
        );
    }

    /**
     * L'ouvreur, son alliance et l'heure qui fait foi sont figes.
     */
    public function testTheOpenerAndItsAllianceAreFrozen(): void
    {
        [$mission, $corps, $joueur] = $this->anArrivingAttack();

        $combat = $this->service->openOrJoin($mission, $corps, self::OPENING);

        $this->assertSame(CombatParticipantKey::forFleet($mission->id), $combat->opener_identity);
        $this->assertSame($joueur->id, $combat->founding_creator_id);
        $this->assertSame($mission->time_arrival, $combat->authoritative_arrival_at);
    }

    /**
     * L appartenance a l alliance est photographiee a l ouverture.
     *
     * ## Ce que cet essai ferme
     *
     * `FrozenAllianceMembership` existait et le lecteur savait la relire, mais personne ne la
     * prenait : la fermeture attendait un fait que l ouverture n ecrivait pas.
     *
     * Lire `alliance_members` est exact **ici, et seulement ici** : l ouverture est l instant
     * present. Deux heures plus tard, une sortie aura supprime la ligne sans laisser de trace.
     */
    public function testTheAllianceMembershipIsPhotographedAtTheOpening(): void
    {
        $createur = $this->aPlayer();
        $allie = $this->aPlayer();
        $etranger = $this->aPlayer();

        $alliance = app(AllianceService::class)->createAlliance($createur->id, 'PHO', 'Photographie');

        // `createAlliance()` inscrit deja son fondateur et lui pose son `alliance_id` : le refaire
        // ici doublerait le travail du service et masquerait un jour un changement de sa part.

        AllianceMember::create([
            'alliance_id' => $alliance->id,
            'user_id' => $allie->id,
            'rank_id' => null,
            'joined_at' => now(),
        ]);

        $corps = $this->aBodyId();
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $createur);

        // Les deux autres visent le meme corps dans la fenetre.
        $this->anAttackAt($corps, self::OPENING + 10, $allie);
        $this->anAttackAt($corps, self::OPENING + 20, $etranger);

        $combat = $this->service->openOrJoin($ouvreur, $corps, self::OPENING);

        $photographie = FrozenAllianceMembership::fromStorage($combat->frozen_alliance_membership);

        $this->assertSame($alliance->id, $photographie->allianceId);

        $this->assertSame(
            $alliance->id,
            $photographie->allianceFor($allie->id),
            'A member of the governing alliance was not photographed at the opening.'
        );

        $this->assertNull(
            $photographie->allianceFor($etranger->id),
            'A player outside the alliance was photographed as one of its members.'
        );
    }

    /**
     * Sans union, les plafonds sont ceux du jeu.
     */
    public function testWithoutAUnionTheBudgetsAreTheCanonicalOnes(): void
    {
        [$mission, $corps] = $this->anArrivingAttack();

        $combat = $this->service->openOrJoin($mission, $corps, self::OPENING);

        $this->assertSame(16, $combat->max_fleets);
        $this->assertSame(5, $combat->max_players);
    }

    /**
     * La barriere possede les effets jusqu'au plafond, faute d'echeance calculee.
     *
     * Ce n'est pas l'echeance finale : elle se raccourcit des que la derniere candidate attendue est
     * arrivee. Le plafond est le choix sur — il ne laisse echapper aucun evenement qui appartenait a
     * ce combat.
     */
    public function testTheBarrierOwnsEffectsUpToTheCeilingForNow(): void
    {
        [$mission, $corps] = $this->anArrivingAttack();

        $this->service->openOrJoin($mission, $corps, self::OPENING);

        $barriere = CelestialBodyCombatBarrier::where('target_body_id', $corps)->firstOrFail();

        $this->assertSame(
            self::OPENING + CombatRallyWindow::WINDOW_SECONDS,
            $barriere->owned_through_effect_at
        );

        // La borne est fermee du meme cote que partout ailleurs.
        $this->assertTrue($barriere->ownsEffectAt($barriere->owned_through_effect_at - 1));
        $this->assertFalse($barriere->ownsEffectAt($barriere->owned_through_effect_at));
    }

    /**
     * Une seconde arrivee sur le meme corps rejoint au lieu d'ouvrir.
     *
     * **La garantie centrale.** Deux combats sur un meme corps signifieraient deux photographies,
     * dont la seconde effacerait la premiere.
     */
    public function testASecondArrivalJoinsInsteadOfOpening(): void
    {
        [$premiere, $corps] = $this->anArrivingAttack();

        $ouvert = $this->service->openOrJoin($premiere, $corps, self::OPENING);

        $seconde = $this->anAttackAt($corps, self::OPENING + 5);
        $rejoint = $this->service->openOrJoin($seconde, $corps, self::OPENING + 5);

        $this->assertSame(
            $ouvert->id,
            $rejoint->id,
            'A second arrival opened its own combat on a body that was already held.'
        );

        $this->assertSame(
            1,
            CombatInstance::where('target_planet_id', $corps)->count(),
            'Two combats exist on the same celestial body.'
        );
    }

    /**
     * Deux corps aux memes coordonnees restent deux corps.
     *
     * Une planete et sa lune partagent leurs coordonnees : viser l'une n'est pas viser l'autre, et
     * un combat sur l'une ne verrouille pas l'autre.
     */
    public function testAPlanetAndItsMoonAreTwoDifferentBodies(): void
    {
        [$surLaPlanete, $planete] = $this->anArrivingAttack();

        $lune = $this->aBodyId();
        $surLaLune = $this->anAttackAt($lune, self::OPENING);

        $premier = $this->service->openOrJoin($surLaPlanete, $planete, self::OPENING);
        $second = $this->service->openOrJoin($surLaLune, $lune, self::OPENING);

        $this->assertNotSame(
            $premier->id,
            $second->id,
            'A combat on one body prevented a combat on another that merely shares its coordinates.'
        );
    }

    /**
     * Une attaque qui arrive, sur un corps a elle.
     *
     * @return array{0: FleetMission, 1: int, 2: User}
     */
    private function anArrivingAttack(): array
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        return [$this->anAttackAt($corps, self::OPENING, $joueur), $corps, $joueur];
    }

    /**
     * Une attaque en vol vers ce corps.
     */
    private function anAttackAt(int $targetBodyId, int $arrivesAt, User|null $owner = null): FleetMission
    {
        $proprietaire = $owner ?? $this->aPlayer();

        return FleetMission::forceCreate([
            'user_id' => $proprietaire->id,
            'planet_id_to' => $targetBodyId,
            'mission_type' => 1,
            'time_departure' => self::OPENING - 600,
            'time_arrival' => $arrivesAt,
            'galaxy_to' => 5,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);
    }

    /**
     * Un joueur, avec une planete.
     */
    private function aPlayer(): User
    {
        $utilisateur = User::factory()->create();

        $this->aPlanetOwnedBy($utilisateur);

        return $utilisateur;
    }

    /**
     * Un corps celeste reel : `planet_id_to` porte une cle etrangere.
     */
    private function aBodyId(): int
    {
        return $this->aPlanetOwnedBy(User::factory()->create())->id;
    }

    /**
     * Une planete a des coordonnees libres, deterministes.
     */
    private function aPlanetOwnedBy(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 5,
            'system' => 400 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
