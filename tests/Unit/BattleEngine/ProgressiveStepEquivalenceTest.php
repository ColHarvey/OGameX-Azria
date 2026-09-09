<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le moteur joue une etape de six rounds, ou six etapes d un round : c est la meme bataille.
 *
 * ## Ce que ce temoin etablit, et pourquoi il est la premiere piece du moteur progressif
 *
 * La conception (`moteur-progressif-conception.html`, section 5) demande de commencer par la
 * **couture d etat**, et de ne rien livrer d autre que l equivalence : « joue k rounds depuis cet
 * etat » doit reproduire exactement ce que le moteur produit aujourd hui. Tant que decouper la
 * bataille la change, rien de progressif ne peut etre construit dessus.
 *
 * Deux egalites, et la seconde est celle qui compte :
 *
 * 1. **Une etape de six rounds = le comportement actuel.** La suite entiere en est le temoin :
 *    `fightBattleRounds()` passe desormais par la couture, et aucun essai du depot n a bouge.
 * 2. **Six etapes d un round = une etape de six.** C est ce que cet essai prouve, et il n est pas
 *    acquis d avance : entre deux etapes, tout ce que le moteur garde en memoire doit survivre.
 *
 * ## Ce qui se casserait sans l etat, et que ce temoin verrait
 *
 * Les **coques entamees**. Les boucliers se regenerent a la fin de chaque round, la coque non : un
 * vaisseau touche traverse le round avec sa coque entamee. Une etape qui repartirait d un simple
 * compte d unites **soignerait la flotte**, et une flotte soignee survit a une bataille qu elle
 * aurait du perdre.
 *
 * L **etat des tirages** ensuite : le generateur est un xorshift 32 bits, et sa suite doit etre
 * independante du decoupage. Une graine reprise a chaque etape rejouerait les memes nombres.
 *
 * ## Comment il compare
 *
 * Par la projection canonique du depot — celle que le banc de parite PHP/Rust emploie — et par
 * l empreinte du journal des tirages. La comparaison porte donc sur la **sortie du jeu**, pas sur
 * les entrailles de la couture.
 */
class ProgressiveStepEquivalenceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetUserTechModel([]);
    }

    public function testSixStepsOfOneRoundFightTheSameBattleAsOneStepOfSix(): void
    {
        $enUneFois = $this->fight(20260909, pas: 6);
        $enSixFois = $this->fight(20260909, pas: 1);

        $entiere = CanonicalProjection::of($enUneFois);
        $decoupee = CanonicalProjection::of($enSixFois);

        // **La premisse**, sans laquelle l egalite ne prouverait rien : la bataille dure vraiment
        // plusieurs rounds. Sur un seul round, decouper ou non revient au meme par construction.
        $this->assertGreaterThan(
            1,
            count($entiere['rounds']),
            'The battle lasted a single round: splitting it could not be told from not splitting it.'
        );

        $divergence = CanonicalProjection::firstDivergence($entiere, $decoupee);
        $this->assertNull($divergence, 'Splitting the battle into six steps changed it at ' . ($divergence ?? ''));

        // La bande de tirages a ete consommee a l identique : meme nombre, meme empreinte.
        $this->assertNotNull($enUneFois->drawsConsumed, 'A seeded battle kept no journal of its draws.');
        $this->assertSame(
            $enUneFois->drawsConsumed,
            $enSixFois->drawsConsumed,
            'The split battle did not consume the same draws: the generator state does not survive a step.'
        );
    }

    /**
     * **Le decoupage ne rend aucun vaisseau a personne.**
     *
     * L egalite ci-dessus le couvre deja, mais elle le couvre en bloc : un temoin qui compare tout
     * ne dit pas ce qui a change. Celui-ci nomme la chose — les pertes cumulees des deux camps —
     * pour qu une mutation qui soignerait les coques entre deux etapes tombe sur une phrase lisible.
     */
    public function testNoShipIsHandedBackBetweenTwoSteps(): void
    {
        $enUneFois = $this->fight(4242, pas: 6);
        $enDeuxFois = $this->fight(4242, pas: 3);

        $this->assertGreaterThan(
            0,
            $enUneFois->attackerUnitsLost->getAmount() + $enUneFois->defenderUnitsLost->getAmount(),
            'Nobody lost a ship: healing between steps would be invisible.'
        );

        $this->assertSame(
            $enUneFois->attackerUnitsLost->getAmount(),
            $enDeuxFois->attackerUnitsLost->getAmount(),
            'The attacker lost a different number of ships once the battle was split in two steps.'
        );

        $this->assertSame(
            $enUneFois->defenderUnitsLost->getAmount(),
            $enDeuxFois->defenderUnitsLost->getAmount(),
            'The defender lost a different number of ships once the battle was split in two steps.'
        );
    }

    /**
     * Une bataille jouee par pas de `$pas` rounds, jusqu au plafond du jeu.
     */
    private function fight(int $graine, int $pas): BattleResult
    {
        $this->createAndSetPlanetModel(['metal' => 50_000, 'crystal' => 50_000, 'deuterium' => 0, 'rocket_launcher' => 100]);

        $flottes = [];
        foreach ([['light_fighter' => 150, 'cruiser' => 20], ['heavy_fighter' => 30]] as $rang => $composition) {
            $flotte = new AttackerFleet();
            $flotte->units = $this->units($composition);
            $flotte->player = $this->playerService;
            $flotte->fleetMissionId = 1000 + $rang;
            $flotte->ownerId = $this->playerService->getId();
            $flotte->cargoResources = new Resources(0, 0, 0, 0);
            $flotte->isInitiator = $rang === 0;
            $flotte->fleetMission = null;
            $flottes[] = $flotte;
        }

        $defenseurs = [DefenderFleet::fromPlanet($this->planetService)];
        foreach ([['light_fighter' => 60], ['heavy_fighter' => 25]] as $rang => $composition) {
            $renfort = new DefenderFleet();
            $renfort->units = $this->units($composition);
            $renfort->player = $this->playerService;
            $renfort->fleetMissionId = 2000 + $rang;
            $renfort->ownerId = 5;
            $renfort->fleetMission = null;
            $defenseurs[] = $renfort;
        }

        $moteur = new EngineFightingInSteps(
            $flottes,
            $this->planetService,
            $defenseurs,
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart())
        );

        $moteur->roundsPerStep = $pas;

        return $moteur->withDraws(new SeededDraws($graine))->simulateBattle();
    }

    /**
     * @param array<string, int> $composition
     */
    private function units(array $composition): UnitCollection
    {
        $unites = new UnitCollection();

        foreach ($composition as $nom => $montant) {
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $montant);
        }

        return $unites;
    }
}

/**
 * Le moteur du jeu, joue par pas — la couture, et rien d autre.
 *
 * Il ne redefinit ni les regles ni les tirages : il enchaine les memes trois temps que
 * `PhpBattleEngine::fightBattleRounds()`, en s arretant plus souvent. Un essai qui ecrirait sa
 * propre boucle de rounds ne prouverait rien du moteur.
 */
class EngineFightingInSteps extends PhpBattleEngine
{
    public int $roundsPerStep = 6;

    /**
     * @return array<BattleResultRound>
     */
    protected function fightBattleRounds(BattleResult $result): array
    {
        $etat = $this->openTheField($result);
        $rounds = [];

        $pas = max(1, $this->roundsPerStep);

        while ($etat->roundsPlayed < self::MAX_ROUNDS && $etat->bothSidesStillStand()) {
            foreach ($this->playRounds($etat, $pas) as $round) {
                $rounds[] = $round;
            }
        }

        $this->closeTheField($result, $etat);

        return $rounds;
    }
}
