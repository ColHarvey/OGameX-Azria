<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Support\CombatSnapshotEligibility;
use OGame\Combat\Support\EffectOrderKey;
use Tests\UnitTestCase;

/**
 * Ce qui entre dans la photographie, selon les deux barrieres.
 *
 * La regle repose sur deux instants qui ne servent pas a la meme chose : l'ouverture est la
 * barriere des **decisions**, la fermeture celle des **effets**. Les deux conditions sont
 * necessaires et aucune ne suffit.
 *
 * L'initiateur du combat echappe aux deux : il ne les franchit pas, il les pose.
 */
class CombatSnapshotEligibilityTest extends UnitTestCase
{
    /**
     * Rang de l'ouverture du ralliement, dans l'ordre des decisions.
     */
    private const int OUVERTURE = 1_000;

    /**
     * Heure de la fermeture, soixante secondes plus loin.
     */
    private const int FERMETURE = 1_060;

    /**
     * Un engagement pris avant l'ouverture et arrivant avant la fermeture entre.
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
     * Une seule convention a retenir dans tout le systeme : ce qui tombe **pile** sur une barriere
     * est du cote posterieur.
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
        $this->assertTrue($this->entre(self::OUVERTURE - 3_600, self::OUVERTURE + 30, CombatEventType::QueueCompletion));

        // Commencee avant, terminee apres : les unites sont posterieures a la photographie.
        $this->assertFalse($this->entre(self::OUVERTURE - 3_600, self::FERMETURE + 10, CombatEventType::QueueCompletion));

        // Commencee apres l'ouverture : jamais incluse, meme terminee immediatement.
        $this->assertFalse(
            $this->entre(self::OUVERTURE + 1, self::OUVERTURE + 2, CombatEventType::QueueCompletion),
            'A build started after the barrier reached the snapshot, so the target could add units to the battle.'
        );
    }

    /**
     * Un ralliement de duree nulle n'admet aucun effet **supplementaire**, mais garde l'initiateur.
     *
     * **La nuance est essentielle.** C'est le cas de l'attaquant isole : la fenetre se ferme a
     * l'instant ou elle s'ouvre. Aucun effet secondaire ne peut franchir les barrieres — mais il
     * serait absurde d'en conclure que l'attaquant lui-meme n'est pas dans la bataille. Il est la
     * donnee fondatrice : sans lui, il n'y a pas de combat du tout.
     */
    public function testZeroLengthRallyAdmitsNoAdditionalEffectsButKeepsTheOpener(): void
    {
        $ouverture = EffectOrderKey::barrierAt(self::OUVERTURE);

        $this->assertFalse(
            CombatSnapshotEligibility::entersSnapshot(
                false,
                self::OUVERTURE - 100,
                EffectOrderKey::forEvent(self::OUVERTURE, CombatEventType::FleetArrival, 4),
                self::OUVERTURE,
                $ouverture
            ),
            'With no rally window at all, a secondary effect still slipped into the snapshot.'
        );

        $this->assertTrue(
            CombatSnapshotEligibility::entersSnapshot(
                true,
                self::OUVERTURE,
                EffectOrderKey::forEvent(self::OUVERTURE, CombatEventType::FleetArrival, 1),
                self::OUVERTURE,
                $ouverture
            ),
            'The fleet that opened the combat was left out of its own battle.'
        );
    }

    /**
     * L'initiateur entre quelles que soient les barrieres.
     *
     * Il ne les franchit pas : il les pose. Aucune combinaison ne doit pouvoir l'exclure.
     */
    public function testTheOpenerEntersWhateverTheBarriers(): void
    {
        foreach ([self::OUVERTURE - 10, self::OUVERTURE, self::OUVERTURE + 10] as $decision) {
            foreach ([self::OUVERTURE, self::FERMETURE, self::FERMETURE + 10] as $effet) {
                $this->assertTrue(
                    CombatSnapshotEligibility::entersSnapshot(
                        true,
                        $decision,
                        EffectOrderKey::forEvent($effet, CombatEventType::FleetArrival, 1),
                        self::OUVERTURE,
                        EffectOrderKey::barrierAt(self::FERMETURE)
                    ),
                    'A combination of barriers excluded the combat opener from its own battle.'
                );
            }
        }
    }

    /**
     * Une mission creee tot mais prevue tard est classee sur son heure prevue.
     *
     * Les deux ordres sont bien distincts : un transport lent lance a midi arrive apres un
     * transport rapide lance a treize heures. Classer par rang de creation inverserait leur ordre
     * reel.
     */
    public function testAMissionCommittedEarlyButDueLateIsJudgedOnItsDueTime(): void
    {
        $this->assertFalse(
            $this->entre(1, self::FERMETURE + 1),
            'A mission committed very early was let in although its effect lands after the snapshot.'
        );
    }

    /**
     * Les deux conditions sont bien independantes.
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
     * @param int $engagement Rang de la decision.
     * @param int $effet Heure planifiee de l'effet.
     * @param CombatEventType $type
     * @return bool
     */
    private function entre(int $engagement, int $effet, CombatEventType $type = CombatEventType::FleetArrival): bool
    {
        return CombatSnapshotEligibility::entersSnapshot(
            false,
            $engagement,
            EffectOrderKey::forEvent($effet, $type, 12),
            self::OUVERTURE,
            EffectOrderKey::barrierAt(self::FERMETURE)
        );
    }
}
