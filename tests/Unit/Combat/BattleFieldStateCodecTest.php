<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Replay\BattleFieldStateCodec;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * L etat d un champ ne se relit jamais par conversion, et son format se refuse a deviner.
 *
 * ## Pourquoi cette porte compte
 *
 * Aucun fichier du depot ne declare `strict_types` : une signature `int` accepte « 42 » et 42.0 en
 * les convertissant, et un cast accepte n importe quoi. Un etat de bataille relu par conversion
 * reprendrait une bataille legerement fausse — une coque de 42,7 devenue 42, un mot de generateur
 * lu depuis une chaine — et **le combat continuerait comme si de rien n etait**.
 *
 * `PersistedRehydrationGuardTest` inventorie ces portes ; celle-ci y est inscrite avec cet essai.
 *
 * ## L aller-retour est verifie ici aussi
 *
 * Un refus bien pose sur une porte qui perdrait des faits ne vaudrait rien. Le premier temoin
 * etablit donc qu un etat ecrit puis relu redonne le meme etat, unite par unite, avant que les
 * suivants n eprouvent ses refus.
 */
class BattleFieldStateCodecTest extends UnitTestCase
{
    public function testAWrittenStateIsReadBackUnitByUnit(): void
    {
        $etat = $this->aState();
        $relu = BattleFieldStateCodec::fromStorage(BattleFieldStateCodec::toStorage($etat));

        $this->assertSame(3, count($relu->attackerUnits), 'The attacking units did not come back.');
        $this->assertSame(1, count($relu->defenderUnits), 'The defending unit did not come back.');
        $this->assertSame(2, $relu->roundsPlayed);

        // **Les coques entamees, une a une et dans l ordre** : c est ce que l encodage doit porter.
        $this->assertSame(
            array_map(static fn (BattleUnit $u): int => $u->currentHullPlating, $etat->attackerUnits),
            array_map(static fn (BattleUnit $u): int => $u->currentHullPlating, $relu->attackerUnits),
            'The hulls came back in another shape.'
        );

        $this->assertSame(
            array_map(static fn (BattleUnit $u): int => $u->unitObject->id, $etat->attackerUnits),
            array_map(static fn (BattleUnit $u): int => $u->unitObject->id, $relu->attackerUnits),
            'The units came back in another order: a target is drawn by position.'
        );
    }

    public function testANumericStringIsRefused(): void
    {
        $ecrit = BattleFieldStateCodec::toStorage($this->aState());
        $ecrit['rounds_played'] = '2';

        $this->expectException(InvalidArgumentException::class);
        BattleFieldStateCodec::fromStorage($ecrit);
    }

    public function testAFloatHullIsRefused(): void
    {
        $ecrit = BattleFieldStateCodec::toStorage($this->aState());
        $ecrit['attacker_units'][0]['current_hull'] = 42.0;

        $this->expectException(InvalidArgumentException::class);
        BattleFieldStateCodec::fromStorage($ecrit);
    }

    public function testAnotherSchemaIsRefusedInsteadOfGuessed(): void
    {
        $ecrit = BattleFieldStateCodec::toStorage($this->aState());
        $ecrit['schema'] = BattleFieldStateCodec::SCHEMA + 1;

        $this->expectException(InvalidArgumentException::class);
        BattleFieldStateCodec::fromStorage($ecrit);
    }

    /**
     * **Un generateur a l etat zero y resterait pour toujours** : un tel mot ne vient d aucune
     * suite reelle, et le lire serait lire une donnee corrompue.
     */
    public function testAZeroedGeneratorStateIsRefused(): void
    {
        $ecrit = BattleFieldStateCodec::toStorage($this->aState());
        $ecrit['draws']['state'] = 0;

        $this->expectException(InvalidArgumentException::class);
        BattleFieldStateCodec::fromStorage($ecrit);
    }

    /**
     * Une bande que l on ne peut pas rejouer ne peut pas etre ecrite — et le refus le dit.
     */
    public function testABandThatCannotBeReplayedIsRefusedAtWriting(): void
    {
        $etat = $this->aState();
        $etat->battleDraws = new \OGame\GameMissions\BattleEngine\Draws\SystemDraws();

        $this->expectException(InvalidArgumentException::class);
        BattleFieldStateCodec::toStorage($etat);
    }

    /**
     * Un champ tenu a la main : trois attaquantes dont deux entamees differemment, une defenseuse.
     */
    private function aState(): BattleFieldState
    {
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $croiseur = ObjectService::getUnitObjectByMachineName('cruiser');
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');

        $premier = new BattleUnit($chasseur, 400, 10, 50, 1000, 7);
        $second = new BattleUnit($chasseur, 400, 10, 50, 1000, 7);
        $second->currentHullPlating = 12;
        $troisieme = new BattleUnit($croiseur, 2700, 50, 400, 1000, 7);
        $defenseur = new BattleUnit($lanceur, 2000, 20, 80, 0, 9);

        $restantes = new UnitCollection();
        $restantes->addUnit($chasseur, 2);
        $restantes->addUnit($croiseur, 1);

        $defense = new UnitCollection();
        $defense->addUnit($lanceur, 1);

        $pertes = new UnitCollection();
        $pertes->addUnit($chasseur, 3);

        return new BattleFieldState(
            [$premier, $second, $troisieme],
            [$defenseur],
            new SeededDraws(20260909),
            new SeededDraws(20260909),
            2,
            $restantes,
            $defense,
            $pertes,
            new UnitCollection(),
            [1000 => $pertes],
            [1000 => $restantes],
        );
    }
}
