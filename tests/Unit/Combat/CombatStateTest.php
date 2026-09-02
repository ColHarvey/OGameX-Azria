<?php

namespace Tests\Unit\Combat;

use Illuminate\Support\Facades\Route;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Support\CombatLockedActions;
use Tests\UnitTestCase;

/**
 * Les etats d'un combat et le verrou qu'ils posent tiennent-ils avant qu'une table existe ?
 *
 * Conception pure : ces tests ne touchent ni base ni controleur. Ils verifient que la
 * description elle-meme est coherente — c'est ce qui permet de dicter le schema plutot que de
 * le subir.
 */
class CombatStateTest extends UnitTestCase
{
    /**
     * Assert that the nominal cycle can be walked from end to end.
     */
    public function testTheNominalCycleCanBeWalkedFromEndToEnd(): void
    {
        $cycle = [CombatState::Pending, CombatState::Active, CombatState::Resolving, CombatState::Resolved];

        foreach ($cycle as $rang => $etat) {
            $suivant = $cycle[$rang + 1] ?? null;

            if ($suivant === null) {
                continue;
            }

            $this->assertTrue(
                $etat->canTransitionTo($suivant),
                "The nominal cycle is broken: {$etat->value} cannot move to {$suivant->value}."
            );
        }
    }

    /**
     * Assert that a combat already under way can no longer be cancelled.
     *
     * C'est la contrepartie de la regle « un combat engage ne se rappelle pas ». Si `Active`
     * pouvait revenir a `Cancelled`, un attaquant pourrait effacer une bataille dont il connait
     * deja l'issue defavorable — le resultat etant fige des l'arrivee.
     */
    public function testACombatUnderWayCanNoLongerBeCancelled(): void
    {
        $this->assertTrue(CombatState::Pending->canTransitionTo(CombatState::Cancelled), 'A combat that has not started cannot be cancelled.');

        foreach ([CombatState::Active, CombatState::Resolving, CombatState::Resolved] as $etat) {
            $this->assertFalse(
                $etat->canTransitionTo(CombatState::Cancelled),
                "A combat in state {$etat->value} can still be cancelled, so its outcome could be erased once known."
            );
        }
    }

    /**
     * Assert that no cancellation cause is ever within a player's reach.
     *
     * La fenetre `Pending` — entre l'arrivee de la flotte et le premier round — est courte,
     * mais le resultat y est deja calcule. Un joueur qui pourrait s'y engouffrer effacerait une
     * bataille perdue d'avance. L'annulation exige donc une cause, et aucune n'est declenchable
     * par un rappel.
     */
    public function testNoCancellationCauseIsEverWithinAPlayerReach(): void
    {
        $this->assertNotEmpty(CombatCancellationCause::cases(), 'There is no cancellation cause left, so a combat could never be cancelled at all.');

        foreach (CombatCancellationCause::cases() as $cause) {
            $this->assertFalse(
                $cause->isPlayerInitiated(),
                "The cancellation cause « {$cause->value} » is within a player's reach: they could erase a battle whose outcome is already computed."
            );

            $this->assertTrue(
                CombatState::Pending->canBeCancelledFor($cause),
                "A pending combat cannot be cancelled for « {$cause->value} », so a system cancellation has no way through."
            );

            foreach ([CombatState::Active, CombatState::Resolving, CombatState::Resolved] as $etat) {
                $this->assertFalse(
                    $etat->canBeCancelledFor($cause),
                    "A combat in state {$etat->value} can still be cancelled for « {$cause->value} »."
                );
            }
        }
    }

    /**
     * Assert that no state ever allows a recall.
     *
     * Le rappel appartient a la periode **avant** l'arrivee, quand aucun combat n'existe. Des
     * qu'il en existe un, la flotte y est engagee.
     */
    public function testNoStateEverAllowsARecall(): void
    {
        foreach (CombatState::cases() as $etat) {
            $this->assertFalse(
                $etat->allowsRecall(),
                "The state {$etat->value} allows a recall, so a fleet could leave a battle it is already counted in."
            );
        }
    }

    /**
     * Assert that the final states let nothing out.
     *
     * C'est ce qui rend la resolution idempotente : un job relance retrouve un combat deja
     * `Resolved` et n'a rien a faire, au lieu de rejouer rapports, butin et retours.
     */
    public function testTheFinalStatesLetNothingOut(): void
    {
        foreach ([CombatState::Resolved, CombatState::Cancelled] as $etat) {
            $this->assertTrue($etat->isFinal(), "The state {$etat->value} is not final.");
            $this->assertSame([], $etat->allowedTransitions(), "The state {$etat->value} still allows a transition, so a replayed job could apply a result twice.");
        }
    }

    /**
     * Assert that no state can move to itself.
     *
     * Une transition vers soi-meme masquerait une double application : passer `Resolving` a
     * `Resolving` ressemblerait a un succes alors que deux processus travaillent.
     */
    public function testNoStateCanMoveToItself(): void
    {
        foreach (CombatState::cases() as $etat) {
            $this->assertFalse(
                $etat->canTransitionTo($etat),
                "The state {$etat->value} can move to itself, which would hide a double application."
            );
        }
    }

    /**
     * Assert that the lock covers the whole window during which the result is frozen.
     *
     * `Pending` est la fenetre entre l'arrivee et le premier round : le resultat y est deja
     * calcule. Laisser partir une flotte a ce moment-la la ferait echapper a une bataille qui
     * la compte deja parmi les defenseurs.
     */
    public function testTheLockCoversTheWholeWindowDuringWhichTheResultIsFrozen(): void
    {
        foreach ([CombatState::Pending, CombatState::Active, CombatState::Resolving] as $etat) {
            $this->assertTrue($etat->locksTargetBody(), "The state {$etat->value} does not lock the target body, so a fleet could escape a battle it is already counted in.");
        }

        foreach ([CombatState::Resolved, CombatState::Cancelled] as $etat) {
            $this->assertFalse($etat->locksTargetBody(), "The state {$etat->value} still locks the target body, so the planet would never be released.");
        }
    }

    /**
     * Assert that every route the lock refuses actually exists.
     *
     * Une liste de refus qui nomme une route disparue protege le vide. C'est le genre de
     * decalage qui ne se voit qu'au moment ou quelqu'un contourne le verrou.
     */
    public function testEveryRefusedRouteActuallyExists(): void
    {
        // getRoutesByName() rend un tableau nom => route : plus direct, et reellement
        // iterable pour l analyse statique.
        $connues = array_keys(Route::getRoutes()->getRoutesByName());

        foreach (array_keys(CombatLockedActions::refusedRoutes()) as $nom) {
            $this->assertContains($nom, $connues, "The lock refuses the route « {$nom} », which no longer exists: the list protects nothing.");
        }

        foreach (array_keys(CombatLockedActions::allowedRoutes()) as $nom) {
            $this->assertContains($nom, $connues, "The lock explicitly allows the route « {$nom} », which no longer exists.");
        }
    }

    /**
     * Assert that no dispatching route was forgotten by the lock.
     *
     * Le test le plus utile du fichier, et le seul qui puisse trouver quelque chose que
     * personne n'a pense a chercher : toute route qui fait partir quelque chose doit etre
     * **soit refusee, soit explicitement permise**. Le silence n'est pas une reponse.
     */
    public function testNoDispatchingRouteWasForgotten(): void
    {
        $refusees = array_keys(CombatLockedActions::refusedRoutes());
        $permises = array_keys(CombatLockedActions::allowedRoutes());
        $classees = array_merge($refusees, $permises);

        // Les routes qui font partir quelque chose se reconnaissent a leur verbe et a leur nom.
        $suspectes = [];

        foreach (Route::getRoutes()->getRoutesByName() as $nom => $route) {
            if (!in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (!preg_match('/(dispatch|missile|jumpgate\.execute|recall)/', $nom)) {
                continue;
            }

            if (in_array($nom, $classees, true)) {
                continue;
            }

            $suspectes[] = $nom;
        }

        $this->assertSame(
            [],
            $suspectes,
            "These routes dispatch something but the combat lock neither refuses nor allows them. Silence is not an answer:\n  - "
            . implode("\n  - ", $suspectes)
        );
    }

    /**
     * Assert that the undecided case is still marked as undecided.
     *
     * Les vagues d'attaques successives ne doivent pas recevoir un comportement par defaut que
     * personne n'aurait choisi. Tant que la decision n'est pas prise, elle reste ecrite ici.
     */
    public function testTheUndecidedCaseIsStillMarkedAsUndecided(): void
    {
        $this->assertNotEmpty(
            CombatLockedActions::undecidedCases(),
            'The waves case is no longer listed as undecided. If it was decided, say so explicitly; if it was implemented silently, that is the problem this test exists for.'
        );
    }
}
