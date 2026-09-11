<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\ReturnDestinationKind;
use OGame\Combat\Support\ReturnPlan;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Patrol\Geometry\SpatialPoint;
use ReflectionMethod;
use RuntimeException;
use Tests\UnitTestCase;

/**
 * Le retour d une flotte **vers un point de l espace**, ou sa patrouille l attend.
 *
 * ## La regle de jeu, et pourquoi elle coute
 *
 * Decision de Keven, 11 septembre 2026 : une patrouille est un **poste**. Elle frappe une planete et
 * revient a son guet ; elle n est pas un tremplin a usage unique. Tout le systeme de combat, lui,
 * avait ete construit sur une certitude : **une flotte rentre sur un corps celeste**. La fabrique de
 * la destination l exigeait par cinq invariants, et refusait tout repli — parce qu une premiere
 * version comblait les trous par une planete par defaut, des coordonnees `0:0:0` et le joueur zero.
 *
 * ## Ce que ces temoins gardent
 *
 * **Aucune de ces cinq gardes n a ete relachee.** La forme « point » n est pas un trou dans le
 * controle : c est une seconde forme, avec ses propres invariants, exiges aussi durement. Ces
 * temoins epinglent les deux moities :
 *
 * 1. la forme « point » porte ce qu elle doit porter, et le refus tombe des qu il manque ;
 * 2. les deux formes **s excluent** — un objet qui porterait un corps *et* un point laisserait chaque
 *    lecteur choisir lequel compte, et deux lecteurs choisiraient differemment ;
 * 3. la garde partagee la plus importante tient toujours : **une flotte ne rentre jamais chez un
 *    autre joueur**, point ou corps.
 */
class ReturnToASpatialPointTest extends UnitTestCase
{
    private const int JOUEUR = 7;

    private function coordonnees(): Coordinate
    {
        return new Coordinate(1, 5, 0);
    }

    /**
     * Une mission d aller, reduite a ce que la fabrique lit : son identifiant et son proprietaire.
     */
    private function mission(int $proprietaire = self::JOUEUR): FleetMission
    {
        $mission = new FleetMission();
        $mission->id = 4242;
        $mission->user_id = $proprietaire;

        return $mission;
    }

    public function testUnPlanVersUnPointPorteSaPatrouilleEtSonPointEtAucunCorps(): void
    {
        $plan = ReturnPlan::toPatrolPoint(9, $this->coordonnees(), new SpatialPoint(-660, 580), self::JOUEUR);

        $this->assertTrue($plan->isPossible(), 'Un retour vers un point est une destination : il doit etre possible.');
        $this->assertTrue($plan->landsOnAPoint(), 'Le plan ne se dit pas pose sur un point.');
        $this->assertSame(ReturnDestinationKind::PatrolPoint, $plan->kind);
        $this->assertSame(9, $plan->patrolId);
        $point = $plan->point;
        $this->assertNotNull($point, 'Un retour vers un point ne porte aucun point.');
        $this->assertSame(-660, $point->x);
        $this->assertSame(580, $point->y);
        $this->assertSame(self::JOUEUR, $plan->ownerId);

        // **Et aucun corps** : c est la moitie qui distingue cette forme de toutes les autres.
        $this->assertNull($plan->planetId, 'Un retour vers un point designe un corps celeste.');
        $this->assertNull($plan->bodyType, 'Un retour vers un point porte un genre de corps.');
    }

    /**
     * **Les trois autres genres ne se disent pas poses sur un point.**
     *
     * Sans cette moitie, `landsOnAPoint()` pourrait rendre `true` partout et les deux temoins
     * precedents passeraient quand meme : la valeur juste et la fausse coincideraient.
     */
    public function testUnPlanVersUnCorpsNeSeDitPasPoseSurUnPoint(): void
    {
        $vers = ReturnPlan::toOriginalBody(3, $this->coordonnees(), PlanetType::Planet, self::JOUEUR);
        $repli = ReturnPlan::toAssociatedPlanet(4, $this->coordonnees(), self::JOUEUR);
        $mere = ReturnPlan::toHomeworld(5, $this->coordonnees(), self::JOUEUR);
        $aucun = ReturnPlan::cannotReturn(CombatReasonCode::RallyClosed);

        foreach ([$vers, $repli, $mere, $aucun] as $plan) {
            $this->assertFalse(
                $plan->landsOnAPoint(),
                'Le plan ' . $plan->kind->value . ' se dit pose sur un point de l espace.'
            );
            $this->assertNull($plan->patrolId, 'Le plan ' . $plan->kind->value . ' porte une patrouille.');
            $this->assertNull($plan->point, 'Le plan ' . $plan->kind->value . ' porte un point.');
        }
    }

    /**
     * **Un point sans patrouille n est pas une destination.** La patrouille est ce qui rend ce point
     * legitime : sans elle, ce ne sont que deux nombres.
     */
    public function testUnPointSansPatrouilleEstRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReturnPlan::toPatrolPoint(0, $this->coordonnees(), new SpatialPoint(-660, 580), self::JOUEUR);
    }

    /**
     * **Les deux formes s excluent, et la garde est forcee ici volontairement.**
     *
     * Aucune fabrique publique ne peut produire un plan mixte — c est bien le but. Cet essai passe
     * par le constructeur prive parce que c est exactement le chemin que la garde doit fermer, et il
     * existe pour que personne ne l ouvre demain en ajoutant une fabrique commode.
     */
    public function testUnPlanNePeutPasPorterUnCorpsEtUnPointALaFois(): void
    {
        $constructeur = new ReflectionMethod(ReturnPlan::class, '__construct');
        $plan = $constructeur->getDeclaringClass()->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);

        $constructeur->invoke(
            $plan,
            ReturnDestinationKind::PatrolPoint,
            3,
            $this->coordonnees(),
            PlanetType::Planet,
            self::JOUEUR,
            null,
            9,
            new SpatialPoint(-660, 580)
        );
    }

    /**
     * **Et l inverse** : un retour vers un corps ne porte ni patrouille ni point.
     */
    public function testUnRetourVersUnCorpsNePeutPasPorterUnPoint(): void
    {
        $constructeur = new ReflectionMethod(ReturnPlan::class, '__construct');
        $plan = $constructeur->getDeclaringClass()->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);

        $constructeur->invoke(
            $plan,
            ReturnDestinationKind::OriginalBody,
            3,
            $this->coordonnees(),
            PlanetType::Planet,
            self::JOUEUR,
            null,
            9,
            new SpatialPoint(-660, 580)
        );
    }

    public function testLaDestinationResolueGardeLePointEtRefuseLeCorps(): void
    {
        $plan = ReturnPlan::toPatrolPoint(9, $this->coordonnees(), new SpatialPoint(-660, 580), self::JOUEUR);
        $ou = ResolvedReturnDestination::from($plan, $this->mission());

        $this->assertTrue($ou->landsOnAPoint(), 'La destination resolue ne se dit pas posee sur un point.');
        $this->assertSame(9, $ou->patrolId);
        $this->assertSame(-660, $ou->pointOrFail()->x);
        $this->assertSame(580, $ou->pointOrFail()->y);
        $this->assertNull($ou->bodyId, 'La destination resolue vers un point designe un corps.');

        // **Demander le corps d un point est une faute, pas un `null` silencieux.**
        $this->expectException(RuntimeException::class);
        $ou->bodyIdOrFail();
    }

    /**
     * **Et symetriquement** : demander le point d un corps leve.
     *
     * Sans cette moitie, un ecrivain qui se tromperait de forme obtiendrait `null` et ecrirait une
     * ligne a moitie vide — une flotte en vol vers nulle part, sans une ligne de journal.
     */
    public function testDemanderLePointDUnCorpsLeve(): void
    {
        $plan = ReturnPlan::toOriginalBody(3, $this->coordonnees(), PlanetType::Planet, self::JOUEUR);
        $ou = ResolvedReturnDestination::from($plan, $this->mission());

        $this->assertFalse($ou->landsOnAPoint());
        $this->assertSame(3, $ou->bodyIdOrFail());
        $this->assertSame(PlanetType::Planet, $ou->bodyTypeOrFail());

        $this->expectException(RuntimeException::class);
        $ou->pointOrFail();
    }

    /**
     * **La garde partagee tient pour les deux formes : une flotte ne rentre jamais chez un autre.**
     *
     * C est l invariant que la fabrique protege depuis le debut, et l ajout d une seconde forme
     * aurait pu lui ouvrir un passage. Il s applique au point exactement comme au corps.
     */
    public function testUnPointDUnAutreJoueurEstRefuse(): void
    {
        $plan = ReturnPlan::toPatrolPoint(9, $this->coordonnees(), new SpatialPoint(-660, 580), self::JOUEUR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/appartient au joueur|se pose chez le joueur/');

        ResolvedReturnDestination::from($plan, $this->mission(self::JOUEUR + 1));
    }
}
