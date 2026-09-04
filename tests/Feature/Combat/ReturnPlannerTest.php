<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\ReturnDestinationKind;
use OGame\Combat\Services\ReturnPlanner;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Ou une flotte renvoyee se pose quand son point de depart n'est plus la.
 *
 * ## Les recours sont ordonnes, et le premier n'est pas le seul
 *
 * Une premiere version demandait « cette flotte peut-elle rentrer ? ». La question est mal posee :
 * l'absence du corps d'origine ne signifie pas que la flotte est perdue. Le jeu prevoit une lune
 * detruite qui ramene sur sa planete, et une planete mere qui reste quand tout le reste a disparu.
 *
 * Conclure trop vite ferait disparaitre des vaisseaux de joueurs ; conclure trop tard ferait poser
 * une flotte chez quelqu'un d'autre. Ces essais fixent les quatre issues et leur ordre.
 */
class ReturnPlannerTest extends TestCase
{
    /**
     * L'horodatage d'une destruction reelle.
     *
     * **Pas `1`.** La colonne porte l'instant de la destruction, et un essai qui y ecrit `1`
     * laisserait passer un planificateur qui compare a `1` — c'est exactement le defaut que ces
     * essais existent pour attraper.
     */
    private const int DESTROYED_AT = 1_700_000_500;

    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Le corps d'origine, quand il est toujours la et toujours au meme proprietaire.
     */
    public function testAFleetReturnsToItsOriginalBody(): void
    {
        $joueur = $this->aPlayer();
        $origine = $this->aBodyOf($joueur, PlanetType::Planet);

        $plan = (new ReturnPlanner())->planFor($this->aMissionFrom($joueur, $origine));

        $this->assertSame(ReturnDestinationKind::OriginalBody, $plan->kind);
        $this->assertSame($origine->id, $plan->planetId);
        $this->assertSame($joueur->id, $plan->ownerId);
        $this->assertTrue($plan->isPossible());
    }

    /**
     * Une lune detruite ramene sur la planete qui occupe ses coordonnees.
     */
    public function testAFleetFromADestroyedMoonReturnsToTheAssociatedPlanet(): void
    {
        $joueur = $this->aPlayer();
        $planete = $this->aBodyOf($joueur, PlanetType::Planet);
        $lune = $this->aMoonOver($planete);

        $mission = $this->aMissionFrom($joueur, $lune);
        DB::table('planets')->where('id', $lune->id)->update(['destroyed' => self::DESTROYED_AT]);

        $plan = (new ReturnPlanner())->planFor($mission);

        $this->assertSame(ReturnDestinationKind::AssociatedPlanet, $plan->kind);
        $this->assertSame($planete->id, $plan->planetId, 'The fleet did not fall back to the planet under its moon.');
    }

    /**
     * Sans planete associee, la flotte revient sur la planete mere du compte.
     */
    public function testWithoutAnAssociatedPlanetTheFleetReturnsToTheHomeworld(): void
    {
        $joueur = $this->aPlayer();
        $mere = $this->aBodyOf($joueur, PlanetType::Planet);
        $ailleurs = $this->aBodyOf($joueur, PlanetType::Planet);
        $lune = $this->aMoonOver($ailleurs);

        $mission = $this->aMissionFrom($joueur, $lune);

        // La lune et sa planete disparaissent toutes deux : il ne reste que la mere.
        DB::table('planets')->where('id', $lune->id)->update(['destroyed' => self::DESTROYED_AT]);
        DB::table('planets')->where('id', $ailleurs->id)->update(['destroyed' => self::DESTROYED_AT]);

        $plan = (new ReturnPlanner())->planFor($mission);

        $this->assertSame(ReturnDestinationKind::Homeworld, $plan->kind);
        $this->assertSame($mere->id, $plan->planetId, 'The fleet did not fall back to the first planet of the account.');
    }

    /**
     * Un corps qui a change de mains n'accueille pas la flotte : ce n'est plus le meme foyer.
     */
    public function testABodyThatChangedHandsIsNotADestination(): void
    {
        $joueur = $this->aPlayer();
        $mere = $this->aBodyOf($joueur, PlanetType::Planet);
        $vendue = $this->aBodyOf($joueur, PlanetType::Planet);

        $mission = $this->aMissionFrom($joueur, $vendue);
        DB::table('planets')->where('id', $vendue->id)->update(['user_id' => $this->aPlayer()->id]);

        $plan = (new ReturnPlanner())->planFor($mission);

        $this->assertSame(ReturnDestinationKind::Homeworld, $plan->kind, 'The fleet landed on a body that now belongs to someone else.');
        $this->assertSame($mere->id, $plan->planetId);
    }

    /**
     * Tous les recours epuises : la flotte n'a plus de destination, et le plan le dit.
     */
    public function testWithNoBodyLeftTheFleetHasNoDestination(): void
    {
        $joueur = $this->aPlayer();
        $origine = $this->aBodyOf($joueur, PlanetType::Planet);

        $mission = $this->aMissionFrom($joueur, $origine);
        DB::table('planets')->where('user_id', $joueur->id)->update(['destroyed' => self::DESTROYED_AT]);

        $plan = (new ReturnPlanner())->planFor($mission);

        $this->assertSame(ReturnDestinationKind::None, $plan->kind);
        $this->assertFalse($plan->isPossible());
        $this->assertNull($plan->planetId);
    }

    /**
     * La ligne de la lune a ete purgee, mais la mission garde ses coordonnees de depart.
     *
     * La suppression definitive d'un corps detruit efface sa ligne. Dependre d'elle ferait sauter la
     * planete associee — pourtant toujours la — pour aller droit a la planete mere.
     */
    public function testAPurgedMoonStillFallsBackToItsAssociatedPlanet(): void
    {
        $joueur = $this->aPlayer();
        $mere = $this->aBodyOf($joueur, PlanetType::Planet);
        $planete = $this->aBodyOf($joueur, PlanetType::Planet);
        $lune = $this->aMoonOver($planete);

        $mission = $this->aMissionFrom($joueur, $lune);

        // La lune est supprimee pour de bon : la mission perd son corps de depart — c est ce que
        // fait la purge en production — et il ne lui reste que ses coordonnees.
        DB::table('fleet_missions')->where('id', $mission->id)->update([
            'planet_id_from' => null,
            'planet_id_to' => $planete->id,
        ]);
        DB::table('planets')->where('id', $lune->id)->delete();
        $mission->refresh();

        $plan = (new ReturnPlanner())->planFor($mission);

        $this->assertSame(ReturnDestinationKind::AssociatedPlanet, $plan->kind, 'A purged moon jumped straight to the homeworld.');
        $this->assertSame($planete->id, $plan->planetId);
        $this->assertNotSame($mere->id, $plan->planetId);
    }

    /**
     * La planete associee decide du recours meme quand elle appartient a quelqu'un d'autre.
     *
     * ## Le fait que l'ensemble oubliait
     *
     * Les corps qui decident etaient l'origine et les planetes du proprietaire. La planete sous une
     * lune rasee n'y figurait que si elle lui appartenait deja. Or la propriete est justement ce
     * qui decide : a un autre joueur a la premiere passe, transferee entre les deux, elle devient
     * le recours — sans qu'aucune ligne tenue n'ait bouge. Elle est donc tenue quel que soit son
     * proprietaire ; le plan, lui, continue d'exiger le bon.
     */
    public function testTheAssociatedPlanetIsAFactThatDecidesEvenWhenSomebodyElseOwnsIt(): void
    {
        $joueur = $this->aPlayer();
        $this->aBodyOf($joueur, PlanetType::Planet);

        $autre = $this->aPlayer();
        $planete = $this->aBodyOf($autre, PlanetType::Planet);

        $lune = Planet::factory()->create([
            'user_id' => $joueur->id,
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
            'planet' => $planete->planet,
            'planet_type' => PlanetType::Moon->value,
        ]);

        $mission = $this->aMissionFrom($joueur, $lune);
        DB::table('planets')->where('id', $lune->id)->update(['destroyed' => self::DESTROYED_AT]);

        $planificateur = new ReturnPlanner();
        $this->assertContains($planete->id, $planificateur->bodiesThatDecideFor($mission), 'The planet under the moon is not held because somebody else owns it.');
        $this->assertNotSame(ReturnDestinationKind::AssociatedPlanet, $planificateur->planFor($mission)->kind, 'The plan sent the fleet to somebody else.');

        // La lune purgee : il ne reste que les coordonnees que la mission porte.
        DB::table('fleet_missions')->where('id', $mission->id)->update(['planet_id_from' => null, 'planet_id_to' => $planete->id]);
        DB::table('planets')->where('id', $lune->id)->delete();
        $mission->refresh();

        $this->assertContains($planete->id, $planificateur->bodiesThatDecideFor($mission), 'Once the moon is purged, the planet under it is no longer held.');
    }

    private function aMissionFrom(User $owner, Planet $origine): FleetMission
    {
        return FleetMission::forceCreate([
            'user_id' => $owner->id,
            'planet_id_from' => $origine->id,
            'type_from' => $origine->planet_type,
            'galaxy_from' => $origine->galaxy,
            'system_from' => $origine->system,
            'position_from' => $origine->planet,
            'planet_id_to' => $origine->id,
            'type_to' => 1,
            'galaxy_to' => $origine->galaxy,
            'system_to' => $origine->system,
            'position_to' => $origine->planet,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    private function aPlayer(): User
    {
        return User::factory()->create();
    }

    private function aBodyOf(User $owner, PlanetType $genre): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 8,
            'system' => 400 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
            'planet_type' => $genre->value,
        ]);
    }

    /**
     * Une lune aux coordonnees exactes de sa planete.
     */
    private function aMoonOver(Planet $planete): Planet
    {
        return Planet::factory()->create([
            'user_id' => $planete->user_id,
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
            'planet' => $planete->planet,
            'planet_type' => PlanetType::Moon->value,
        ]);
    }
}
