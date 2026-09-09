<?php

namespace Tests\Unit\Patrol;

use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\PatrolTargetVerdict;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\FrozenPatrolTarget;
use OGame\Patrol\PatrolTargetLock;
use Tests\UnitTestCase;

/**
 * Ce qu une attaque trouve a l emplacement qu elle avait gele.
 *
 * Le verdict est pur : il prend le fait gele et la ligne vivante. Ces temoins ne montent donc
 * aucune base — ils eprouvent la regle elle-meme, y compris les combinaisons qu un montage
 * complet produirait difficilement (proprietaire different, identifiant different).
 */
class PatrolTargetLockTest extends UnitTestCase
{
    private function gelee(int $id = 7, int $proprietaire = 3): FrozenPatrolTarget
    {
        return new FrozenPatrolTarget($id, $proprietaire, 1, 1, 620, 480);
    }

    /**
     * Une patrouille vivante, telle que la base la rendrait.
     */
    private function vivante(array $champs = []): Patrol
    {
        $patrouille = new Patrol();

        $patrouille->forceFill(array_merge([
            'id' => 7,
            'user_id' => 3,
            'state' => PatrolState::Stationed,
            'galaxy' => 1,
            'system' => 1,
            'x' => 620,
            'y' => 480,
        ], $champs));

        return $patrouille;
    }

    public function testLaMemePatrouilleAuMemePointOuvreLeCombat(): void
    {
        $this->assertSame(
            PatrolTargetVerdict::Present,
            PatrolTargetLock::decide($this->gelee(), $this->vivante())
        );
    }

    public function testUneLigneDisparueNOuvreRien(): void
    {
        $this->assertSame(
            PatrolTargetVerdict::Gone,
            PatrolTargetLock::decide($this->gelee(), null)
        );
    }

    /**
     * **Jamais une autre patrouille.** Meme point, meme etat, meme proprietaire : seul
     * l identifiant differe, et cela suffit a refuser.
     */
    public function testUneAutrePatrouilleAuMemePointNEstPasLaCible(): void
    {
        $this->assertSame(
            PatrolTargetVerdict::Gone,
            PatrolTargetLock::decide($this->gelee(), $this->vivante(['id' => 8]))
        );
    }

    public function testUnProprietaireDifferentNEstPasLaCible(): void
    {
        $this->assertSame(
            PatrolTargetVerdict::Gone,
            PatrolTargetLock::decide($this->gelee(), $this->vivante(['user_id' => 4]))
        );
    }

    /**
     * Les quatre etats ou la patrouille n a rien a son point — dont `Attacking`, ou ses unites
     * sont parties frapper ailleurs alors que le point reste le sien.
     */
    public function testUnePatrouilleQuiNEstPasPoseeNOffreRienACombattre(): void
    {
        foreach ([PatrolState::EnRoute, PatrolState::Returning, PatrolState::Attacking, PatrolState::Finished] as $etat) {
            $this->assertSame(
                PatrolTargetVerdict::Gone,
                PatrolTargetLock::decide($this->gelee(), $this->vivante(['state' => $etat])),
                'etat ' . $etat->value
            );
        }
    }

    /**
     * Immobilisee, elle est bien la : `PatrolState` le dit — « reste posee et attaquable ».
     */
    public function testUnePatrouilleImmobiliseeResteUneCible(): void
    {
        $this->assertSame(
            PatrolTargetVerdict::Present,
            PatrolTargetLock::decide($this->gelee(), $this->vivante(['state' => PatrolState::Immobilised]))
        );
    }

    /**
     * Chaque coordonnee est comparee pour elle-meme : un temoin qui n en bougerait qu une seule
     * laisserait passer une comparaison qui oublie les trois autres.
     */
    public function testUnePatrouillePoseeAilleursNEstPasPoursuivie(): void
    {
        foreach ([['x' => 630], ['y' => 490], ['system' => 2], ['galaxy' => 2]] as $ailleurs) {
            $this->assertSame(
                PatrolTargetVerdict::Moved,
                PatrolTargetLock::decide($this->gelee(), $this->vivante($ailleurs)),
                implode(',', array_keys($ailleurs))
            );
        }
    }

    /**
     * Une patrouille posee au point voisin de la grille est **ailleurs**, pas « a peu pres la ».
     */
    public function testUnPasDeGrilleSuffitAEtreAilleurs(): void
    {
        $this->assertSame(
            PatrolTargetVerdict::Moved,
            PatrolTargetLock::decide($this->gelee(), $this->vivante(['x' => 621]))
        );
    }

    public function testOnNeGelePasUneCibleEnVolQuiNAPlusDePoint(): void
    {
        $this->expectException(PatrolOrderRefused::class);

        FrozenPatrolTarget::of($this->vivante(['state' => PatrolState::EnRoute, 'x' => null, 'y' => null]));
    }

    /**
     * **Le point ne suffit pas ; l etat compte aussi.** Une patrouille dont les unites sont parties
     * attaquer garde son point (`PatrolState::Attacking` le dit), et n a pourtant rien a ce point.
     * Une mutation qui retirait la lecture de l etat survivait au temoin precedent : celui-la
     * gelait une patrouille sans coordonnees, que les deux versions refusaient pour la meme raison.
     * Ici la valeur juste et la valeur fausse cessent de coincider.
     */
    public function testOnNeGelePasUneCibleDontLesUnitesSontPartiesAttaquer(): void
    {
        $this->expectException(PatrolOrderRefused::class);

        FrozenPatrolTarget::of($this->vivante(['state' => PatrolState::Attacking]));
    }

    public function testLeGelReprendLIdentiteEtLEmplacement(): void
    {
        $gelee = FrozenPatrolTarget::of($this->vivante());

        $this->assertSame(7, $gelee->patrolId);
        $this->assertSame(3, $gelee->ownerId);
        $this->assertSame(1, $gelee->galaxy);
        $this->assertSame(1, $gelee->system);
        $this->assertSame(620, $gelee->point()->x);
        $this->assertSame(480, $gelee->point()->y);
    }
}
