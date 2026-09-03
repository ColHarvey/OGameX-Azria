<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Enums\OperationKind;
use OGame\Combat\Support\OperationKey;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use Tests\UnitTestCase;

/**
 * L'identite d'une operation : d'ou elle vient, et ce qu'elle refuse.
 *
 * ## Ce que la fabrique vivante garantit
 *
 * Le genre et l'identifiant sortent du **meme modele**. Une fabrique qui les recevrait separement
 * accepterait une paire incoherente — la mission de recyclage numero 12 scellee comme une attaque
 * immediate — et la protection ne serait plus qu'une convention.
 *
 * ## Le drapeau `exists`
 *
 * C'est ce que `save()` positionne. Le manipuler directement ici revient a decrire les deux etats
 * d'un modele sans passer par la base : le controle porte sur ce drapeau et sur l'identifiant, et
 * c'est exactement ce qu'un enregistrement reel produit.
 */
class OperationKeyTest extends UnitTestCase
{
    /**
     * Les quatre types de mission qui scellent une operation.
     *
     * **L'attaque groupee en fait partie.** Une premiere version de la correspondance l'avait
     * oubliee : chaque resolution d'attaque ACS aurait alors leve une exception au moment de sceller
     * ses diagnostics.
     */
    public function testTheFourMissionTypesThatOpenAnOperation(): void
    {
        $attendus = [
            'attaque simple' => [1, OperationKind::ImmediateAttack],
            'attaque groupee' => [2, OperationKind::ImmediateAttack],
            'recyclage' => [8, OperationKind::Recycling],
            'destruction de lune' => [9, OperationKind::MoonDestruction],
        ];

        foreach ($attendus as $quoi => [$type, $genre]) {
            $cle = OperationKey::forFleetMission($this->mission($type));

            $this->assertSame($genre, $cle->kind, "The mission type {$type} (« {$quoi} ») was classified wrongly.");
            $this->assertSame(42, $cle->identifier);
        }
    }

    /**
     * Les autres types de mission sont refuses.
     *
     * Un transport ou une colonisation ne produit pas de diagnostics de conversion : leur donner une
     * cle laisserait croire qu'elles en scellent.
     */
    public function testOtherMissionTypesAreRefused(): void
    {
        foreach ([3, 4, 5, 6, 7, 10, 15] as $type) {
            try {
                OperationKey::forFleetMission($this->mission($type));
                $this->fail("The mission type {$type} was accepted although it seals no diagnostics.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Un retour n'ouvre pas d'operation.
     *
     * **Il conserve le `mission_type` de son aller.** Sans ce controle, une flotte qui rentre serait
     * scellee comme une attaque nouvelle, et ses diagnostics se melangeraient a ceux de l'aller sous
     * une cle qui n'est pas la sienne.
     */
    public function testAReturnLegOpensNoOperation(): void
    {
        foreach ([1, 2, 8, 9] as $type) {
            $retour = $this->mission($type);
            $retour->parent_id = 7;

            try {
                OperationKey::forFleetMission($retour);
                $this->fail("A return leg of type {$type} was sealed as a new operation.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Un modele jamais enregistre est refuse.
     *
     * Son identifiant changerait au premier enregistrement : la cle designerait alors une autre
     * operation que celle qu'elle scelle.
     */
    public function testAnUnsavedModelIsRefused(): void
    {
        $mission = new FleetMission();
        $mission->mission_type = 1;

        $this->expectException(InvalidArgumentException::class);

        OperationKey::forFleetMission($mission);
    }

    /**
     * Une instance de combat impose son genre.
     */
    public function testACombatInstanceImposesItsKind(): void
    {
        $instance = new CombatInstance();
        $instance->exists = true;
        $instance->id = 42;

        $cle = OperationKey::forCombatInstance($instance);

        $this->assertSame(OperationKind::PersistentCombat, $cle->kind);
        $this->assertSame(42, $cle->identifier);
    }

    /**
     * Le meme nombre sous deux genres donne deux cles distinctes.
     *
     * La mission de flotte 42 et l'instance de combat 42 viennent de tables differentes.
     */
    public function testTheSameNumberUnderTwoKindsGivesTwoKeys(): void
    {
        $instance = new CombatInstance();
        $instance->exists = true;
        $instance->id = 42;

        $mission = OperationKey::forFleetMission($this->mission(1));
        $combat = OperationKey::forCombatInstance($instance);

        $this->assertFalse($mission->equals($combat));
        $this->assertNotSame($mission->asString(), $combat->asString());
    }

    /**
     * La relecture ne consulte aucun modele.
     *
     * Un combat doit rester lisible longtemps apres que sa mission a disparu de la base : exiger le
     * modele vivant rendrait l'audit dependant de donnees qui, par nature, finissent par etre
     * purgees.
     */
    public function testRehydrationReadsNoLivingModel(): void
    {
        $relue = OperationKey::rehydrate(OperationKind::ImmediateAttack, 42);

        $this->assertSame(OperationKind::ImmediateAttack, $relue->kind);
        $this->assertSame(42, $relue->identifier);
        $this->assertTrue($relue->equals(OperationKey::forFleetMission($this->mission(1))));

        // Et un identifiant absent reste refuse, meme en relecture.
        $this->expectException(InvalidArgumentException::class);

        OperationKey::rehydrate(OperationKind::ImmediateAttack, 0);
    }

    /**
     * Un identifiant relu sous forme de chaine numerique ou de flottant est refuse.
     *
     * ## Le defaut que cet essai ferme
     *
     * `rehydrate(OperationKind $kind, int $identifier)` acceptait « 42 » et 42.0 : sans
     * `strict_types` au site d'appel, PHP les convertit. Une cle d'idempotence relue depuis une
     * colonne texte aurait ete reconstruite sans que personne ne sache d'ou venait le nombre.
     */
    public function testANumericStringIdentifierIsRefusedAtRehydration(): void
    {
        foreach (['42', 42.0, true] as $valeur) {
            try {
                OperationKey::rehydrate(OperationKind::ImmediateAttack, $valeur);

                $this->fail('A ' . get_debug_type($valeur) . ' was accepted as a rehydrated identifier.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Une mission enregistree, d'un type donne.
     *
     * @param int $type
     * @return FleetMission
     */
    private function mission(int $type): FleetMission
    {
        $mission = new FleetMission();
        $mission->exists = true;
        $mission->id = 42;
        $mission->mission_type = $type;
        $mission->parent_id = null;

        return $mission;
    }
}
