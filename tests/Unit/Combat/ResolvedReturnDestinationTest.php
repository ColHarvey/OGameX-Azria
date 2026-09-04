<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Support\ReturnPlan;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use ReflectionClass;
use RuntimeException;
use Tests\UnitTestCase;

/**
 * La destination d'un retour se deduit d'un plan verifie, ou ne se deduit pas du tout.
 *
 * ## Ce que les replis fabriquaient
 *
 * La conversion comblait les trous d'un plan incomplet : planete par defaut, coordonnees « 0:0:0 »,
 * proprietaire converti depuis `null`. Aucune de ces valeurs n'existe dans le jeu. Elles auraient
 * fabrique une destination **plausible** a partir d'un plan corrompu — et la flotte serait partie
 * pour un endroit inexistant, sans que rien ne s'arrete.
 *
 * Chaque invariant a donc son essai, et une mutation qui reintroduit son repli le fait tomber. Le
 * dernier — le proprietaire doit etre celui de la flotte — n'est pas une precaution technique : une
 * flotte de repli ne se pose jamais chez quelqu'un d'autre.
 */
class ResolvedReturnDestinationTest extends UnitTestCase
{
    /**
     * Un plan sans destination ne se convertit pas : il n'y a rien a ecrire.
     */
    public function testAnImpossiblePlanCannotBecomeADestination(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ne designe aucune destination/');

        ResolvedReturnDestination::from(ReturnPlan::cannotReturn(CombatReasonCode::NoReturnDestination), $this->aMission());
    }

    /**
     * Un plan sans coordonnees ne se comble pas par « 0:0:0 ».
     *
     * ## Ce que le repli fabriquait
     *
     * La conversion utilisait `?? new Coordinate(0, 0, 0)`. Ces coordonnees n'existent nulle part
     * dans le jeu : un plan incomplet devenait une destination **plausible**, et la flotte partait
     * pour un endroit qui n'existe pas au lieu que la transaction s'arrete.
     */
    public function testAPlanWithoutCoordinatesIsNotFilledWithZeros(): void
    {
        $plan = ReturnPlan::toOriginalBody(7, new Coordinate(1, 2, 3), PlanetType::Planet, 42);
        $sansCoordonnees = $this->aPlanWithout($plan, 'coordinate');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/coordonnees/');

        ResolvedReturnDestination::from($sansCoordonnees, $this->aMission(42));
    }

    /**
     * Un plan sans genre de corps ne devient pas une planete par defaut.
     */
    public function testAPlanWithoutABodyTypeIsNotAssumedToBeAPlanet(): void
    {
        $plan = ReturnPlan::toOriginalBody(7, new Coordinate(1, 2, 3), PlanetType::Moon, 42);
        $sansGenre = $this->aPlanWithout($plan, 'bodyType');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/genre de corps/');

        ResolvedReturnDestination::from($sansGenre, $this->aMission(42));
    }

    /**
     * Un plan sans proprietaire ne devient pas le joueur zero.
     */
    public function testAPlanWithoutAnOwnerIsNotConvertedToZero(): void
    {
        $plan = ReturnPlan::toOriginalBody(7, new Coordinate(1, 2, 3), PlanetType::Planet, 42);
        $sansProprietaire = $this->aPlanWithout($plan, 'ownerId');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/proprietaire/');

        ResolvedReturnDestination::from($sansProprietaire, $this->aMission(42));
    }

    /**
     * Une destination qui appartient a quelqu'un d'autre est refusee.
     *
     * Une flotte de repli ne se pose jamais chez autrui, meme si le corps est exactement la ou elle
     * allait. Le planificateur le garantit deja ; la conversion le verifie une seconde fois, parce
     * que c'est ici que la valeur devient une ecriture.
     */
    public function testADestinationOwnedBySomeoneElseIsRefused(): void
    {
        $plan = ReturnPlan::toHomeworld(7, new Coordinate(1, 2, 3), 41);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/appartient au joueur/');

        ResolvedReturnDestination::from($plan, $this->aMission(42));
    }

    /**
     * Un plan complet et coherent se convertit, et porte exactement ce qu'il disait.
     */
    public function testACompletePlanBecomesTheDestinationItDescribes(): void
    {
        $plan = ReturnPlan::toAssociatedPlanet(7, new Coordinate(4, 5, 6), 42);

        $destination = ResolvedReturnDestination::from($plan, $this->aMission(42));

        $this->assertSame(7, $destination->bodyId);
        $this->assertSame(PlanetType::Planet, $destination->type);
        $this->assertSame(4, $destination->coordinate->galaxy);
        $this->assertSame(5, $destination->coordinate->system);
        $this->assertSame(6, $destination->coordinate->position);
        $this->assertSame(42, $destination->ownerId);
    }

    /**
     * Un plan ampute d'un de ses champs, pour eprouver un invariant a la fois.
     *
     * `ReturnPlan` est immuable et ses fabriques sont coherentes : c'est precisement pourquoi une
     * regression y serait invisible sans cette manipulation. La reflexion reproduit l'etat qu'un
     * defaut futur produirait.
     */
    private function aPlanWithout(ReturnPlan $plan, string $champ): ReturnPlan
    {
        $reflexion = new ReflectionClass($plan);
        $copie = $reflexion->newInstanceWithoutConstructor();

        foreach ($reflexion->getProperties() as $propriete) {
            $propriete->setValue($copie, $propriete->getName() === $champ ? null : $propriete->getValue($plan));
        }

        return $copie;
    }

    private function aMission(int $owner = 42): FleetMission
    {
        $mission = new FleetMission();
        $mission->id = 1_234;
        $mission->user_id = $owner;

        return $mission;
    }
}
