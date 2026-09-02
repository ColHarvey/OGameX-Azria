<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Support\CombatSnapshotEligibility;
use Tests\UnitTestCase;

/**
 * Ce qui entre dans la photographie, selon les deux barrieres.
 *
 * La regle repose sur deux instants qui ne servent pas a la meme chose : l'ouverture est la
 * barriere des **decisions**, la fermeture celle des **effets**. Les deux conditions sont
 * necessaires et aucune ne suffit — c'est ce que ces tests etablissent cas par cas.
 */
class CombatSnapshotEligibilityTest extends UnitTestCase
{
    /**
     * Rang de l'ouverture du ralliement.
     */
    private const int OUVERTURE = 1_000;

    /**
     * Rang de la fermeture, soixante crans plus loin.
     */
    private const int FERMETURE = 1_060;

    /**
     * Un engagement pris avant l'ouverture et arrivant avant la fermeture entre.
     *
     * Le transport lance la veille, qui se pose pendant le ralliement.
     */
    public function testACommitmentTakenBeforeAndArrivingInTimeEnters(): void
    {
        $this->assertTrue(
            $this->entre(self::OUVERTURE - 500, self::OUVERTURE + 20),
            'A transport committed long before the combat and landing during the rally must be in the snapshot.'
        );
    }

    /**
     * Une decision prise pendant le ralliement n'entre pas, meme si son effet arrive a temps.
     *
     * **Le defaut que la barriere des decisions empeche.** Sans elle, le defenseur disposerait de
     * la duree du ralliement pour modifier ce qui participera au combat.
     */
    public function testADecisionTakenDuringTheRallyNeverEnters(): void
    {
        $this->assertFalse(
            $this->entre(self::OUVERTURE + 5, self::OUVERTURE + 20),
            'A decision taken during the rally reached the snapshot, so the target could retouch it.'
        );
    }

    /**
     * Un engagement pris a temps mais arrivant apres la fermeture n'entre pas.
     */
    public function testACommitmentArrivingAfterTheSnapshotDoesNotEnter(): void
    {
        $this->assertFalse(
            $this->entre(self::OUVERTURE - 500, self::FERMETURE + 1),
            'An effect landing after the snapshot was taken still made it into the snapshot.'
        );
    }

    /**
     * Les deux bornes sont fermees du meme cote.
     *
     * Une seule convention a retenir dans tout le systeme : ce qui tombe **pile** sur une
     * barriere est du cote posterieur.
     */
    public function testBothBarriersAreClosedOnTheSameSide(): void
    {
        $this->assertFalse(
            $this->entre(self::OUVERTURE, self::OUVERTURE + 10),
            'A decision taken at the very instant of opening was treated as prior.'
        );

        $this->assertFalse(
            $this->entre(self::OUVERTURE - 10, self::FERMETURE),
            'An effect due exactly at closing time was included in the snapshot.'
        );

        $this->assertTrue(
            $this->entre(self::OUVERTURE - 1, self::FERMETURE - 1),
            'One rank before each barrier is still inside, so the bounds are one rank too tight.'
        );
    }

    /**
     * Le cas du transport, tel qu'il a ete arrete.
     */
    public function testTheTransportCasesBehaveAsDecided(): void
    {
        // Engage avant, arrive avant la fermeture : la cargaison est livree avant la photo.
        $this->assertTrue($this->entre(self::OUVERTURE - 100, self::FERMETURE - 1));

        // Engage avant, arrive pile a la fermeture : hors photographie.
        $this->assertFalse($this->entre(self::OUVERTURE - 100, self::FERMETURE));

        // Lance pendant le ralliement : refuse au depart, et de toute facon hors photographie.
        $this->assertFalse($this->entre(self::OUVERTURE + 1, self::FERMETURE - 1));
    }

    /**
     * Le cas de la construction, tel qu'il a ete arrete.
     */
    public function testTheBuildQueueCasesBehaveAsDecided(): void
    {
        // Commencee avant l'ouverture et terminee avant la fermeture : elle compte.
        $this->assertTrue($this->entre(self::OUVERTURE - 3_600, self::OUVERTURE + 30));

        // Commencee avant, terminee apres : les unites sont posterieures a la photographie.
        $this->assertFalse($this->entre(self::OUVERTURE - 3_600, self::FERMETURE + 10));

        // Commencee apres l'ouverture : jamais incluse, meme terminee immediatement.
        $this->assertFalse(
            $this->entre(self::OUVERTURE + 1, self::OUVERTURE + 2),
            'A build started after the barrier reached the snapshot, so the target could add units to the battle.'
        );
    }

    /**
     * Un ralliement de duree nulle n'inclut aucun effet.
     *
     * C'est le cas de l'attaquant isole : la fenetre se ferme a l'instant ou elle s'ouvre. Rien
     * ne peut alors se produire strictement avant la fermeture tout en ayant ete decide
     * strictement avant l'ouverture.
     */
    public function testAZeroLengthRallyAdmitsNoEffect(): void
    {
        $this->assertFalse(
            CombatSnapshotEligibility::entersSnapshot(
                self::OUVERTURE - 100,
                self::OUVERTURE,
                self::OUVERTURE,
                self::OUVERTURE
            ),
            'With no rally window at all, an effect still slipped into the snapshot.'
        );
    }

    /**
     * Les deux conditions sont bien independantes.
     *
     * Le balayage croise les deux cotes de chaque barriere : une seule combinaison sur quatre doit
     * entrer.
     */
    public function testBothConditionsAreNecessaryAndNeitherSuffices(): void
    {
        $entrees = 0;

        foreach ([self::OUVERTURE - 1, self::OUVERTURE + 1] as $engagement) {
            foreach ([self::FERMETURE - 1, self::FERMETURE + 1] as $effet) {
                if ($this->entre($engagement, $effet)) {
                    $entrees++;
                }
            }
        }

        $this->assertSame(1, $entrees, 'Exactly one of the four combinations may enter the snapshot.');
    }

    /**
     * @param int $engagement
     * @param int $effet
     * @return bool
     */
    private function entre(int $engagement, int $effet): bool
    {
        return CombatSnapshotEligibility::entersSnapshot($engagement, $effet, self::OUVERTURE, self::FERMETURE);
    }
}
