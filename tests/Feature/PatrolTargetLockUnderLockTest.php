<?php

namespace Tests\Feature;

use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\PatrolTargetVerdict;
use OGame\Patrol\FrozenPatrolTarget;
use OGame\Patrol\PatrolTargetLock;
use Tests\AccountTestCase;

/**
 * La lecture sous verrou vise l identite gelee, jamais l occupant du point.
 *
 * ## Pourquoi ce temoin passe par la base
 *
 * `PatrolTargetLock::decide()` est pure et s eprouve sans base ; ce qu elle ne peut pas etablir,
 * c est **par quoi la ligne vivante a ete trouvee**. Une lecture « la patrouille a ce point »
 * rendrait exactement les memes verdicts tant qu il n y a qu une patrouille au point, et
 * substituerait une cible des qu il y en a deux. Ce temoin pose les deux.
 */
class PatrolTargetLockUnderLockTest extends AccountTestCase
{
    /**
     * Une patrouille posee, ecrite directement : ce temoin juge la lecture, pas le lancement.
     */
    private function patrouille(int $proprietaire, PatrolState $etat, int|null $x, int|null $y): Patrol
    {
        $patrouille = new Patrol();

        $patrouille->forceFill([
            'user_id' => $proprietaire,
            'home_planet_id' => null,
            'state' => $etat,
            'galaxy' => 1,
            'system' => 1,
            'x' => $x,
            'y' => $y,
            'fuel_reserve' => 0.0,
            'order_version' => 0,
        ]);

        $patrouille->save();

        return $patrouille;
    }

    public function testLaMemePatrouilleEncorePoseeOuvreLeCombat(): void
    {
        $cible = $this->patrouille($this->currentUserId, PatrolState::Stationed, 620, 480);
        $gelee = FrozenPatrolTarget::of($cible);

        $this->assertSame(PatrolTargetVerdict::Present, resolve(PatrolTargetLock::class)->underLock($gelee));
    }

    /**
     * **Le coeur du temoin.** La cible s en va ; une autre patrouille prend exactement sa place,
     * jusqu au proprietaire. Une lecture par le point rendrait « presente » et l attaque
     * frapperait quelqu un qu elle n avait jamais vise.
     */
    public function testUneAutrePatrouilleQuiPrendLaPlaceNEstPasLaCible(): void
    {
        $cible = $this->patrouille($this->currentUserId, PatrolState::Stationed, 620, 480);
        $gelee = FrozenPatrolTarget::of($cible);

        $cible->state = PatrolState::EnRoute;
        $cible->x = null;
        $cible->y = null;
        $cible->save();

        $remplacante = $this->patrouille($this->currentUserId, PatrolState::Stationed, 620, 480);

        $this->assertNotSame((int)$cible->id, (int)$remplacante->id);
        $this->assertSame(PatrolTargetVerdict::Gone, resolve(PatrolTargetLock::class)->underLock($gelee));
    }

    /**
     * La cible existe toujours, posee, mais ailleurs : elle n est pas poursuivie.
     */
    public function testUneCibleQuiSEstDeplaceeRendUnVerdictDeDeplacement(): void
    {
        $cible = $this->patrouille($this->currentUserId, PatrolState::Stationed, 620, 480);
        $gelee = FrozenPatrolTarget::of($cible);

        $cible->x = 700;
        $cible->save();

        $this->assertSame(PatrolTargetVerdict::Moved, resolve(PatrolTargetLock::class)->underLock($gelee));
    }

    /**
     * La ligne a disparu : rien a combattre, et surtout aucune exception.
     */
    public function testUneCibleEffaceeNOuvreRien(): void
    {
        $cible = $this->patrouille($this->currentUserId, PatrolState::Stationed, 620, 480);
        $gelee = FrozenPatrolTarget::of($cible);

        $cible->delete();

        $this->assertSame(PatrolTargetVerdict::Gone, resolve(PatrolTargetLock::class)->underLock($gelee));
    }
}
