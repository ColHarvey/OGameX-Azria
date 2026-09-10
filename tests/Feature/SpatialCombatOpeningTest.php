<?php

namespace Tests\Feature;

use OGame\Combat\Enums\CombatState;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\PatrolCombatBarrier;
use OGame\Patrol\Combat\SpatialCombatOpening;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\FrozenPatrolTarget;
use Tests\AccountTestCase;

/**
 * Un combat s ouvre la ou il n y a pas de corps, et ce qu il tient est une patrouille.
 *
 * ## Les deux affirmations qui comptent
 *
 * **Ce qui est tenu est la patrouille, jamais le point.** Deux patrouilles peuvent occuper le meme
 * point sans partager leur sort : c est une decision de jeu (revues 120 et 121), et elle doit etre
 * structurelle. Un temoin qui n aurait qu une patrouille par point passerait avec un verrou pose
 * sur le point.
 *
 * **La fenetre de ralliement est nulle par construction.** Les regroupements autour d une patrouille
 * sont hors perimetre de cette version : il n y a personne a attendre, et laisser une fenetre d une
 * minute immobiliserait une patrouille pour rien.
 */
class SpatialCombatOpeningTest extends AccountTestCase
{
    /**
     * Une patrouille posee, ecrite directement : ce temoin juge l ouverture, pas le lancement.
     */
    private function patrouille(int $x, int $y, int|null $proprietaire = null): Patrol
    {
        $patrouille = new Patrol();

        $patrouille->forceFill([
            'user_id' => $proprietaire ?? $this->currentUserId,
            'home_planet_id' => null,
            'state' => PatrolState::Stationed,
            'galaxy' => 4,
            'system' => 77,
            'x' => $x,
            'y' => $y,
            'fuel_reserve' => 0.0,
            'order_version' => 0,
        ]);

        $patrouille->save();

        return $patrouille;
    }

    /**
     * La vague qui pretend ouvrir. Seuls son identifiant, son proprietaire et son arrivee comptent.
     */
    private function vague(): FleetMission
    {
        $depart = $this->planetService->getPlanetCoordinates();

        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->planet_id_from = $this->planetService->getPlanetId();
        $mission->galaxy_from = $depart->galaxy;
        $mission->system_from = $depart->system;
        $mission->position_from = $depart->position;
        $mission->type_from = PlanetType::Planet->value;
        $mission->planet_id_to = null;
        $mission->galaxy_to = 4;
        $mission->system_to = 77;
        $mission->position_to = 0;
        $mission->type_to = PlanetType::SpatialPoint->value;
        $mission->mission_type = 11;
        $mission->time_departure = time();
        $mission->time_arrival = time() + 600;
        $mission->save();

        return $mission;
    }

    /**
     * L instance dit ou elle a lieu, et la barriere dit qui elle tient.
     */
    public function testAnOpeningNamesItsPointAndHoldsItsPatrol(): void
    {
        $cible = $this->patrouille(620, -480);
        $gelee = FrozenPatrolTarget::of($cible);
        $ouverture = time();

        $combat = resolve(SpatialCombatOpening::class)->openOrJoin($this->vague(), $gelee, $ouverture);

        $this->assertSame(CombatState::Rallying, $combat->status, 'A free-space combat did not open in the rally state.');
        $this->assertNull($combat->target_planet_id, 'A free-space combat claims a celestial body.');
        $this->assertSame((int)$cible->id, (int)$combat->target_patrol_id, 'The combat does not say which patrol it targets.');
        $this->assertSame(PlanetType::SpatialPoint->value, (int)$combat->target_type, 'The combat does not declare a spatial target.');
        $this->assertSame(4, (int)$combat->galaxy);
        $this->assertSame(77, (int)$combat->system);
        $this->assertSame(
            0,
            (int)$combat->position,
            'A free-space combat claims a planetary position, so its debris would land in that planet field.'
        );
        $this->assertSame(620, (int)$combat->point_x, 'The combat lost the abscissa of the point it happens at.');
        $this->assertSame(-480, (int)$combat->point_y, 'The combat lost the ordinate of the point it happens at.');

        $barriere = PatrolCombatBarrier::query()->where('patrol_id', $cible->id)->first();

        $this->assertNotNull($barriere, 'No barrier holds the patrol, so a second wave could open a second combat on it.');
        $this->assertSame((int)$combat->id, (int)$barriere->combat_instance_id, 'The barrier holds a different combat.');
    }

    /**
     * **La fenetre est nulle**, et l egalite compte pour « apres », comme partout dans ce socle.
     */
    public function testTheRallyWindowOfAFreeSpaceCombatIsEmpty(): void
    {
        $cible = $this->patrouille(300, 300);
        $ouverture = time();

        $combat = resolve(SpatialCombatOpening::class)->openOrJoin($this->vague(), FrozenPatrolTarget::of($cible), $ouverture);

        $barriere = PatrolCombatBarrier::query()->where('combat_instance_id', $combat->id)->firstOrFail();

        $this->assertSame($ouverture, (int)$barriere->opened_at);
        $this->assertSame(
            $ouverture,
            (int)$barriere->owned_through_effect_at,
            'A free-space combat kept a rally window although nobody can join it: the patrol would be held for nothing.'
        );
        $this->assertFalse(
            $barriere->ownsEffectAt($ouverture),
            'The opening instant still belongs to this combat, so the boundary is not closed on the same side as everywhere else.'
        );
    }

    /**
     * Une seconde vague sur la meme patrouille **rejoint**, elle n ouvre pas.
     */
    public function testASecondWaveJoinsInsteadOfOpening(): void
    {
        $cible = $this->patrouille(150, 900);
        $gelee = FrozenPatrolTarget::of($cible);
        $service = resolve(SpatialCombatOpening::class);

        $premier = $service->openOrJoin($this->vague(), $gelee, time());
        $second = $service->openOrJoin($this->vague(), $gelee, time() + 5);

        $this->assertSame((int)$premier->id, (int)$second->id, 'A second wave opened a second combat on the same patrol.');
        $this->assertSame(
            1,
            PatrolCombatBarrier::query()->where('patrol_id', $cible->id)->count(),
            'Two barriers hold the same patrol.'
        );
    }

    /**
     * **Le coeur du temoin : deux patrouilles au meme point ne partagent pas leur sort.**
     *
     * Un verrou pose sur le point rendrait ici un seul combat, et la seconde attaque frapperait
     * une patrouille qu elle n avait jamais visee — peut-etre alliee. Le temoin les place aux
     * memes coordonnees exactement, sans quoi il ne prouverait rien.
     */
    public function testTwoPatrolsAtTheSamePointGetTwoCombats(): void
    {
        $premiere = $this->patrouille(880, 120);
        $seconde = $this->patrouille(880, 120);

        $this->assertSame((int)$premiere->x, (int)$seconde->x, 'The two patrols are not at the same point.');
        $this->assertSame((int)$premiere->y, (int)$seconde->y, 'The two patrols are not at the same point.');

        $service = resolve(SpatialCombatOpening::class);

        $combatA = $service->openOrJoin($this->vague(), FrozenPatrolTarget::of($premiere), time());
        $combatB = $service->openOrJoin($this->vague(), FrozenPatrolTarget::of($seconde), time());

        $this->assertNotSame(
            (int)$combatA->id,
            (int)$combatB->id,
            'Two patrols sharing a point shared their combat: what is held is the point, not the patrol.'
        );
        $this->assertSame((int)$premiere->id, (int)$combatA->target_patrol_id);
        $this->assertSame((int)$seconde->id, (int)$combatB->target_patrol_id);
    }

    /**
     * Une patrouille que rien ne tient n est tenue par rien : la lecture doit le dire.
     */
    public function testAPatrolOutsideAnyCombatIsHeldByNothing(): void
    {
        $libre = $this->patrouille(50, 50);

        $this->assertNull(
            resolve(SpatialCombatOpening::class)->combatHolding((int)$libre->id),
            'A patrol nobody attacked is reported as being in combat.'
        );
    }

    /**
     * L instance ouverte est bien retrouvee par la lecture, et c est la meme ligne.
     */
    public function testTheHoldingCombatIsTheOneThatWasOpened(): void
    {
        $cible = $this->patrouille(700, -700);
        $service = resolve(SpatialCombatOpening::class);

        $ouvert = $service->openOrJoin($this->vague(), FrozenPatrolTarget::of($cible), time());
        $tenu = $service->combatHolding((int)$cible->id);

        $this->assertNotNull($tenu);
        $this->assertSame((int)$ouvert->id, (int)$tenu->id);
        $this->assertSame(1, CombatInstance::query()->where('target_patrol_id', $cible->id)->count());
    }
}
