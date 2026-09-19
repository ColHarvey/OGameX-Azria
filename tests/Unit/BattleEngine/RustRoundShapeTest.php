<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Exceptions\RustEngineContractMismatch;
use OGame\GameMissions\BattleEngine\RustRoundShape;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * La remise en forme des cartes par flotte que rend la bibliotheque Rust (journal §166).
 *
 * Le moteur PHP pose une entree pour chaque flotte au debut de chaque round ; la bibliotheque Rust ne cree l entree
 * qu a la premiere perte, et ses cartes sont des tables de hachage dont l ordre de serialisation n est pas celui des
 * flottes. La CI du 19 septembre 2026 l a mesure : sous Rust, un renfort qui traversait le round 0 sans perte
 * disparaissait du bloc gele du rapport de combat.
 *
 * Ce temoin s execute **sans bibliotheque** : c est tout l interet de tenir la remise en forme a part, comme le
 * jugement de la reponse (`RustEngineAnswer`). Le chemin complet, lui, est tenu par le temoin de forme des deux
 * moteurs (`RoundLossesByParticipantTestAbstract`), dont la variante Rust ne tourne qu en integration continue.
 */
final class RustRoundShapeTest extends UnitTestCase
{
    public function testAFleetWithoutALossKeepsItsEntryAndTheOrderIsTheFleetsOrder(): void
    {
        $pertes = new UnitCollection();
        $pertes->addUnit(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 2);

        // Ce que rend la bibliotheque : la garnison seule, et le renfort nulle part.
        $complet = RustRoundShape::unitsOfEveryFleet([0 => $pertes], [0, 777], 'defenseur');

        $this->assertSame([0, 777], array_keys($complet), 'Le renfort manque, ou l ordre n est pas celui des flottes.');
        $this->assertSame(['rocket_launcher' => 2], $complet[0]->toArray());
        $this->assertSame([], $complet[777]->toArray(), 'Une flotte sans perte porte une collection vide, pas rien.');
    }

    public function testTheOrderOfTheAnswerDoesNotDecideTheOrderOfTheRound(): void
    {
        $chasseurs = new UnitCollection();
        $chasseurs->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);

        // Une table de hachage se serialise dans l ordre qu elle veut : ici, le renfort avant la garnison.
        $complet = RustRoundShape::unitsOfEveryFleet([777 => $chasseurs, 0 => new UnitCollection()], [0, 777], 'defenseur');

        $this->assertSame([0, 777], array_keys($complet));
        $this->assertSame(['light_fighter' => 1], $complet[777]->toArray(), 'Les pertes ont suivi leur flotte, pas leur rang.');
    }

    public function testANumberPerFleetIsZeroForAFleetThatDidNothing(): void
    {
        $complet = RustRoundShape::numbersOfEveryFleet([4242 => 7], [4242, 4243], 'attaquant');

        $this->assertSame([4242 => 7, 4243 => 0], $complet);
    }

    /**
     * Une carte qui nomme une flotte absente du combat est refusee : sa perte n appartiendrait a personne, et la
     * reconstruction silencieuse l effacerait. Le moteur partage refuserait plus loin — ici la raison est dite.
     */
    public function testAMapThatNamesAFleetWhichDoesNotFightIsRefused(): void
    {
        $pertes = new UnitCollection();
        $pertes->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);

        try {
            RustRoundShape::unitsOfEveryFleet([999 => $pertes], [0, 777], 'defenseur');
            $this->fail('Une flotte inconnue a ete acceptee dans une carte par flotte.');
        } catch (RustEngineContractMismatch $refus) {
            $this->assertStringContainsString('999', $refus->getMessage());
            $this->assertStringContainsString('defenseur', $refus->getMessage());
        }

        try {
            RustRoundShape::numbersOfEveryFleet([999 => 3], [4242], 'attaquant');
            $this->fail('Une flotte inconnue a ete acceptee dans un nombre par flotte.');
        } catch (RustEngineContractMismatch $refus) {
            $this->assertStringContainsString('attaquant', $refus->getMessage());
        }
    }
}
