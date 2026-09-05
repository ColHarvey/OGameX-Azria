<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Policies\NoLootV1;
use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Chaque scenario du banc de parite porte-t-il vraiment les faits qu'il annonce ?
 *
 * ## Pourquoi cet essai existe
 *
 * Le banc de parite ne s'execute que la ou la bibliotheque Rust est compilee — jamais sur le poste
 * de developpement. Un montage qui ne porterait pas ses faits — deux flottes du meme joueur la ou on
 * annonce deux proprietaires, une cible active la ou on annonce une inactive, des boucliers
 * identiques la ou on annonce des technologies differentes — rendrait le banc **vert sans rien
 * prouver**, et personne ne le verrait ici.
 *
 * Cet essai-ci n'a besoin d'aucune bibliotheque : il monte les memes scenarios et verifie leurs
 * preconditions, plus ce que le moteur PHP en fait. C'est le garde-fou du banc.
 */
class ParityScenarioFixturesTest extends UnitTestCase
{
    use BuildsParityScenarios;

    /**
     * Le taux que l'union de deux classes doit produire : 5000 + 2500 x (1/3), arrondi vers le bas.
     *
     * Ni la base, ni le maximum, ni un multiple de cent : un taux lu sur le mauvais joueur, ou une
     * classe perdue en chemin, ne le produirait pas.
     */
    public const int TAUX_UNION_PONDEREE = 5_833;

    /**
     * Le petit cargo porte cinq mille unites de fret : c'est ce qui rend le taux previsible.
     */
    private const int FRET_PAR_CARGO = 5_000;

    /**
     * Chaque flotte a son propre proprietaire, et deux flottes n'en partagent jamais un.
     */
    public function testEveryFleetHasItsOwnOwner(): void
    {
        $bataille = $this->aBattle(
            planete: ['metal' => 10_000, 'rocket_launcher' => 10],
            attaquantes: [['units' => ['light_fighter' => 10]], ['units' => ['light_fighter' => 10]]],
            renforts: [['units' => ['light_fighter' => 5]], ['units' => ['heavy_fighter' => 5]]],
        );

        $identifiants = [];

        foreach ($bataille['attaquantes'] as $flotte) {
            $identifiants[] = $flotte->player->getId();
            $this->assertSame($flotte->ownerId, $flotte->player->getId(), 'A fleet claims an owner its player service does not carry.');
        }

        foreach ($bataille['defenseurs'] as $flotte) {
            $identifiants[] = $flotte->player->getId();
            $this->assertSame($flotte->ownerId, $flotte->player->getId(), 'A defending fleet claims an owner its player service does not carry.');
        }

        $this->assertSame($identifiants, array_unique($identifiants), 'Two fleets share a player: a characteristic of one could not be told from the other.');
        $this->assertSame($bataille['cible']->getPlayer()?->getId(), $bataille['defenseurs'][0]->player->getId(), 'The garrison does not belong to the body owner.');
    }

    /**
     * L'union de deux classes : deux proprietaires, deux classes, une cible inactive, et le taux
     * exact que cela doit produire.
     */
    public function testTheWeightedUnionCarriesTwoClassesAnInactiveTargetAndItsExactRate(): void
    {
        $bataille = $this->aUnionOfTwoClassesAgainstAnInactiveTarget();
        $contexte = $bataille['contexte'];
        [$decouvreur, $autre] = $bataille['attaquantes'];

        $this->assertNotSame($decouvreur->ownerId, $autre->ownerId, 'Both attacking fleets belong to the same player.');
        $this->assertSame(CharacterClass::DISCOVERER->value, $decouvreur->player->getUser()->character_class);
        $this->assertNull($autre->player->getUser()->character_class, 'The second attacker also has a class: the mixture would not be observable.');

        $this->assertTrue($contexte->targetIsInactive, 'The target is not inactive: the weighted rate would not apply.');
        // Dix cargos et cent vingt chasseurs contre vingt cargos et quinze croiseurs : le fret
        // du Decouvreur est exactement le tiers du total, et c'est ce tiers qui fixe le taux.
        $this->assertSame(10 * self::FRET_PAR_CARGO + 120 * 50, $contexte->discovererCargo, 'The discoverer cargo is not the one the scenario describes.');
        $this->assertSame(3 * $contexte->discovererCargo, $contexte->totalCargo, 'The discoverer does not carry exactly a third of the engaged cargo.');
        $this->assertGreaterThan(0, $contexte->totalCargo - $contexte->discovererCargo, 'The non-discoverer carries no cargo: the mixture would be pure.');

        $this->assertSame(self::TAUX_UNION_PONDEREE, $contexte->rateInBasisPoints, 'The weighted rate is not the one two distinct classes must produce.');
        $this->assertGreaterThan(CargoWeightedV1::BASE_RATE, self::TAUX_UNION_PONDEREE);
        $this->assertLessThan(CargoWeightedV1::BASE_RATE + CargoWeightedV1::DISCOVERER_BONUS, self::TAUX_UNION_PONDEREE);
        $this->assertNotSame(0, self::TAUX_UNION_PONDEREE % 100, 'A rate that is a multiple of a hundred would not distinguish a mis-weighted mixture.');

        // Et le moteur applique bien ce taux-la.
        $resultat = $this->fight(PhpBattleEngine::class, $bataille);
        $this->assertSame(self::TAUX_UNION_PONDEREE, $resultat->lootRateInBasisPoints);
        $this->assertSame(CargoWeightedV1::VERSION, $resultat->lootPolicyVersion);
    }

    /**
     * Le pillage interdit l'est par la politique, pas par un stock vide.
     */
    public function testTheForbiddenLootIsForbiddenByThePolicyAndNotByAnEmptyStock(): void
    {
        $bataille = $this->aBattleWhereLootingIsForbidden();

        $this->assertSame(NoLootV1::VERSION, $bataille['contexte']->policyVersion);
        $this->assertSame(NoLootReason::NpcEncounter, $bataille['contexte']->noLootBecause);
        $this->assertSame(0, $bataille['contexte']->rateInBasisPoints);
        // **Le fret se mesure sur la flotte, pas sur le contexte** : une politique sans pillage ne
        // photographie aucun fret, puisque aucun taux n'en depend.
        $this->assertGreaterThan(
            0,
            $bataille['attaquantes'][0]->units->getTotalCargoCapacity($bataille['attaquantes'][0]->player),
            'The fleet carries no free cargo: the refusal would hold trivially.'
        );
        $this->assertGreaterThan(0, (int)$bataille['cible']->metal()->get(), 'The target is empty: « forbidden » could not be told from « nothing to take ».');

        $resultat = $this->fight(PhpBattleEngine::class, $bataille);

        $this->assertSame(NoLootV1::VERSION, $resultat->lootPolicyVersion);
        $this->assertSame(0, (int)$resultat->loot->sum(), 'Loot was taken where looting is forbidden.');

        // **Le temoin qui discrimine** : la meme cible, la meme flotte, sans le refus — le butin
        // existe. Sans lui, « zero butin » pourrait venir d'ailleurs.
        $permis = $this->fight(PhpBattleEngine::class, $this->aPlunderingAttack());
        $this->assertGreaterThan(0, (int)$permis->loot->sum(), 'The same battle without the refusal takes nothing either: the refusal proves nothing.');
    }

    /**
     * Garnison et renfort partagent un type de vaisseau, avec des boucliers reellement differents.
     */
    public function testTheDefenceSharesAUnitTypeWithGenuinelyDifferentTechnologies(): void
    {
        $bataille = $this->aDefenceSharingAUnitTypeWithDifferentTechnologies();
        [$garnison, $renfort] = $bataille['defenseurs'];
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');

        $this->assertGreaterThan(0, $garnison->units->getAmountByMachineName('light_fighter'), 'The garrison fields no fighter: the shared type is missing.');
        $this->assertGreaterThan(0, $renfort->units->getAmountByMachineName('light_fighter'), 'The reinforcement fields no fighter: the shared type is missing.');

        $bouclierGarnison = $chasseur->properties->shield->calculate($garnison->player)->totalValue;
        $bouclierRenfort = $chasseur->properties->shield->calculate($renfort->player)->totalValue;

        $this->assertGreaterThan($bouclierGarnison, $bouclierRenfort, 'Both defending fleets shield their fighters the same way: a flattening would not be observable.');

        // **La survie du renfort tient au rebond, pas au bouclier seul.** Un bouclier plus grand
        // que la frappe ne suffit pas : plusieurs tirs le percent dans un meme round, et il ne se
        // regenere qu'entre les rounds. La regle qui garantit l'integrite est celle du degat
        // negligeable — sous un centieme du bouclier, le tir rebondit sans rien entamer.
        $frappe = $chasseur->properties->attack->calculate($bataille['attaquantes'][0]->player)->totalValue;
        $this->assertGreaterThan(100 * $frappe, $bouclierRenfort, 'The reinforcement shield does not bounce the incoming shot: its survival would be a matter of luck.');

        $resultat = $this->fight(PhpBattleEngine::class, $bataille);
        $pertes = [];

        foreach ($resultat->defenderFleetResults as $flotte) {
            $pertes[$flotte->fleetMissionId] = (int)$flotte->unitsLost->getAmount();
        }

        $this->assertGreaterThan(0, $pertes[0] ?? 0, 'The unshielded garrison lost nothing: the scenario proves nothing.');
        $this->assertSame(0, $pertes[2000] ?? -1, 'The shielded reinforcement lost fighters it could not lose.');
    }

    /**
     * Le symetrique chez deux attaquants du meme type.
     */
    public function testTheAttackSharesAUnitTypeWithGenuinelyDifferentTechnologies(): void
    {
        $bataille = $this->anAttackSharingAUnitTypeWithDifferentTechnologies();
        [$nu, $blinde] = $bataille['attaquantes'];
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');

        $this->assertNotSame($nu->ownerId, $blinde->ownerId);
        $this->assertGreaterThan(
            $chasseur->properties->shield->calculate($nu->player)->totalValue,
            $chasseur->properties->shield->calculate($blinde->player)->totalValue,
            'Both attacking fleets shield their fighters the same way.'
        );

        // Le rebond, ici encore : le lanceur de la garnison frappe plus fort qu'un chasseur.
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');
        $frappe = $lanceur->properties->attack->calculate($bataille['defenseurs'][0]->player)->totalValue;
        $this->assertGreaterThan(100 * $frappe, $chasseur->properties->shield->calculate($blinde->player)->totalValue, 'The shielded attacker does not bounce the incoming shot.');

        $resultat = $this->fight(PhpBattleEngine::class, $bataille);
        $pertes = [];

        foreach ($resultat->attackerFleetResults as $flotte) {
            $pertes[$flotte->fleetMissionId] = (int)$flotte->unitsLost->getAmount();
        }

        $this->assertGreaterThan(0, $pertes[1000] ?? 0, 'The unshielded attacker lost nothing: the scenario proves nothing.');
        $this->assertSame(0, $pertes[1001] ?? -1, 'The shielded attacker lost fighters it could not lose.');
    }

    /**
     * Le fret plafonne vraiment le butin, et le partage ne tombe pas juste.
     */
    public function testTheLimitingCargoCapsTheLootAndTheRemaindersAreInPlay(): void
    {
        $bataille = $this->aLimitingCargoWithIndivisibleRemainders();
        $resultat = $this->fight(PhpBattleEngine::class, $bataille);

        $stock = 1_000_003 + 500_001 + 250_007;
        $this->assertLessThan($stock, (int)$resultat->loot->sum(), 'The whole stock fit in the holds: the cap was never reached.');
        $this->assertSame(
            $resultat->attackerSurvivingCargoCapacity,
            (int)$resultat->loot->sum(),
            'The loot did not fill the surviving holds exactly: the cap decided nothing.'
        );

        $parts = [];

        foreach ($resultat->attackerFleetResults as $flotte) {
            $parts[$flotte->fleetMissionId] = (int)$flotte->lootShare->sum();
        }

        $this->assertCount(2, $parts);
        $this->assertNotSame($parts[1000], $parts[1001], 'Both fleets took the same share: the remainders were never in play.');
        $this->assertSame((int)$resultat->loot->sum(), array_sum($parts), 'The shares do not add up to the loot.');
    }

    /**
     * Le duel a stock nul est bien un duel a stock nul — et non un pillage interdit deguise.
     */
    public function testTheDuelHasNothingToTakeRatherThanAForbiddenLoot(): void
    {
        $bataille = $this->aDuelWithNothingToTake();

        $this->assertNull($bataille['contexte']->noLootBecause, 'The duel refuses looting: it would not distinguish an empty stock.');
        $this->assertSame(CargoWeightedV1::VERSION, $bataille['contexte']->policyVersion);
        $this->assertSame(0, (int)$bataille['cible']->metal()->get() + (int)$bataille['cible']->crystal()->get(), 'The target is not empty.');

        $resultat = $this->fight(PhpBattleEngine::class, $bataille);
        $this->assertSame(0, (int)$resultat->loot->sum());
    }

    /**
     * La permutation des flottes ne change pas la bataille dans le moteur PHP — la moitie de la
     * preuve que le banc complete du cote Rust.
     */
    public function testAPermutationChangesNothingInThePhpEngine(): void
    {
        $droit = $this->fight(PhpBattleEngine::class, $this->aDefenceSharingAUnitTypeWithDifferentTechnologies());
        $permute = $this->fight(PhpBattleEngine::class, $this->aDefenceSharingAUnitTypeWithDifferentTechnologies(permute: true));

        $this->assertSame($droit->drawsConsumed, $permute->drawsConsumed, 'The permuted battle consumed a different band.');
        $this->assertSame(
            \OGame\GameMissions\BattleEngine\Parity\CanonicalProjection::of($droit),
            \OGame\GameMissions\BattleEngine\Parity\CanonicalProjection::of($permute),
            'The order the fleets were listed in changed the battle.'
        );
    }
}
