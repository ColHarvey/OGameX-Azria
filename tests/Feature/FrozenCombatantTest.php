<?php

namespace Tests\Feature;

use OGame\Combat\Replay\BattleFieldStateCodec;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Patrol\Combat\FrozenCombatant;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * Un attaquant gele garde ses niveaux, quoi que le monde fasse ensuite.
 *
 * ## Ce que ce temoin etablit, et pourquoi une garde de source n y aurait pas suffi
 *
 * Une garde de source dirait qu aucun chemin n ecrit une resolution de joueur vivant. Elle ne
 * dirait pas que le moteur **utilise** les valeurs gelees. La preuve est donc d effet : les niveaux
 * vivants changent apres le gel, et les nombres derives ne bougent pas.
 *
 * Et il ne suffit pas de constater que rien ne bouge : encore faut-il que quelque chose **aurait
 * pu** bouger. Chaque essai mesure donc aussi ce que le joueur vivant rend au meme instant — si les
 * deux coincidaient, l essai ne prouverait rien.
 *
 * La seconde moitie est l aller-retour : les unites passent par le codec de l etat de champ, et
 * leurs nombres sont relus depuis le document. C est ce qui etablit que le gel survit a la
 * persistance, et non seulement a une variable PHP.
 */
class FrozenCombatantTest extends AccountTestCase
{
    private const string CHASSEUR = 'light_fighter';

    /**
     * La puissance d attaque d un chasseur, telle que le moteur la calcule pour ce combattant.
     */
    private function puissance(PlayerService $combattant): int
    {
        return ObjectService::getUnitObjectByMachineName(self::CHASSEUR)
            ->properties->attack->calculate($combattant)->totalValue;
    }

    /**
     * **Le temoin essentiel : la recherche monte, les nombres geles ne bougent pas.**
     */
    public function testTheFrozenLevelsSurviveEverythingTheWorldDoesNext(): void
    {
        $this->playerSetResearchLevel('weapon_technology', 5);

        $vivant = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $gele = new FrozenCombatant($this->currentUserId, 5, 2, 1, 0);

        $avant = $this->puissance($gele);
        $this->assertSame($this->puissance($vivant), $avant, 'The freeze did not start from what the world said.');

        // --- Le monde bouge ---
        $this->playerSetResearchLevel('weapon_technology', 20);
        $vivantApres = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);

        // **Le controle qui rend l essai probant** : le monde a vraiment change. Sans lui, « rien
        // n a bouge » pourrait vouloir dire « rien ne pouvait bouger ».
        $this->assertNotSame(
            $avant,
            $this->puissance($vivantApres),
            'The living player gives the same attack power after the research: this witness could not see a drift.'
        );

        $this->assertSame(
            $avant,
            $this->puissance($gele),
            'The frozen combatant followed the living research: a battle already engaged would grow stronger.'
        );
    }

    /**
     * **L aller-retour** : les nombres geles traversent le codec de l etat de champ.
     */
    public function testTheFrozenNumbersSurviveTheFieldStateRoundTrip(): void
    {
        $this->playerSetResearchLevel('weapon_technology', 5);
        $this->playerSetResearchLevel('shielding_technology', 3);
        $this->playerSetResearchLevel('armor_technology', 2);

        $gele = new FrozenCombatant($this->currentUserId, 5, 3, 2, 0);
        $objet = ObjectService::getUnitObjectByMachineName(self::CHASSEUR);

        $unite = new BattleUnit(
            $objet,
            $objet->properties->structural_integrity->calculate($gele)->totalValue,
            $objet->properties->shield->calculate($gele)->totalValue,
            $objet->properties->attack->calculate($gele)->totalValue,
            77,
            $this->currentUserId,
        );

        $champ = new BattleFieldState(
            [$unite],
            [clone $unite],
            new SeededDraws(12345),
            new SeededDraws(12345),
            0,
            new UnitCollection(),
            new UnitCollection(),
            new UnitCollection(),
            new UnitCollection(),
            [],
            [],
        );

        $document = BattleFieldStateCodec::toStorage($champ);

        // --- Le monde bouge apres l ecriture ---
        $this->playerSetResearchLevel('weapon_technology', 20);

        $relu = BattleFieldStateCodec::fromStorage($document);

        $this->assertCount(1, $relu->attackerUnits);
        $this->assertSame(
            $unite->attackPower,
            $relu->attackerUnits[0]->attackPower,
            'The attack power written into the field state changed between the write and the read.'
        );
        $this->assertSame($unite->originalShieldPoints, $relu->attackerUnits[0]->originalShieldPoints);
        $this->assertSame($unite->originalHullPlating, $relu->attackerUnits[0]->originalHullPlating);
        $this->assertSame($this->currentUserId, $relu->attackerUnits[0]->ownerId, 'The unit lost the owner it must be returned to.');
        $this->assertSame(77, $relu->attackerUnits[0]->fleetMissionId, 'The unit lost the fleet it belongs to.');
    }

    /**
     * L identite du proprietaire est conservee : c est a lui que les survivants reviennent.
     */
    public function testAFrozenCombatantKeepsTheIdentityOfItsOwner(): void
    {
        $gele = new FrozenCombatant(4242, 1, 1, 1, 2);

        $this->assertSame(4242, $gele->getId(), 'A frozen combatant lost the identity of its owner.');
        $this->assertSame(2, $gele->classCombatBonus(), 'A frozen combatant lost the class bonus it carries.');
    }

    /**
     * **Une quatrieme recherche est refusee**, jamais rendue a zero.
     *
     * Un zero se glisserait dans un calcul sans que personne ne le voie ; le refus dit que le gel
     * ne couvre pas cette lecture, et le dit a l endroit exact ou elle se fait.
     */
    public function testAResearchThatWasNotFrozenIsRefused(): void
    {
        $gele = new FrozenCombatant($this->currentUserId, 5, 3, 2, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not carry/');

        $gele->getResearchLevel('energy_technology');
    }

    /**
     * Une photographie decrit un instant passe : elle ne se modifie pas.
     */
    public function testAFrozenCombatantRefusesToBeChanged(): void
    {
        $gele = new FrozenCombatant($this->currentUserId, 5, 3, 2, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already happened/');

        $gele->setResearchLevel('weapon_technology', 9, false);
    }

    /**
     * Les trois niveaux geles sont ceux que le moteur lit, un par un.
     *
     * Les mesurer ensemble laisserait passer une inversion : un bouclier lu a la place d un
     * blindage donne une bataille differente et aucun essai global ne le verrait.
     */
    public function testEachFrozenLevelIsTheOneTheEngineReads(): void
    {
        $gele = new FrozenCombatant($this->currentUserId, 7, 5, 3, 0);

        $this->assertSame(7, $gele->getResearchLevel('weapon_technology'));
        $this->assertSame(5, $gele->getResearchLevel('shielding_technology'));
        $this->assertSame(3, $gele->getResearchLevel('armor_technology'));
    }
}
