<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Exceptions\IncoherentRoundAttribution;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\UnitTestCase;

/**
 * Chaque perte d'un round porte le nom de qui l'a subie — des deux camps, dans les deux moteurs.
 *
 * ## Ce qui manquait
 *
 * Le round gele disait de quelle flotte attaquante venait chaque perte, mais pas de quelle flotte
 * defensive : un defenseur accompagne d'un renfort ne pouvait pas suivre sa propre bataille. Et le
 * chemin Rust ne remplissait meme pas la carte attaquante.
 *
 * ## Ce que l'essai etablit, et pourquoi deux temoins
 *
 * La somme des attributions d'un camp vaut ses pertes du round : c'est l'invariant que le moteur
 * partage refuse de violer. Mais une attribution qui verserait **tout** a la garnison le respecte
 * aussi. Le second temoin est donc le cumul par participant sur toute la bataille, compare aux
 * pertes que le resultat par flotte inscrit : la garnison et le renfort doivent chacun retrouver
 * exactement les leurs. La precondition exige que les deux aient perdu quelque chose — sinon
 * « tout a la garnison » et « chacun les siennes » coincident.
 */
abstract class RoundLossesByParticipantTestAbstract extends UnitTestCase
{
    private const int RENFORT = 777;

    private const int ATTAQUANTE = 4242;

    /**
     * @return class-string<BattleEngine>
     */
    abstract protected function battleEngineClass(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetUserTechModel([]);
    }

    public function testEveryLossOfEveryRoundIsAttributedToTheParticipantWhoSufferedIt(): void
    {
        $resultat = $this->aBattleWithAGarrisonAndAReinforcement();

        $this->assertGreaterThan(1, count($resultat->rounds), 'The battle lasted one round: nothing would distinguish a per-round attribution from a final one.');

        $garnison = CombatParticipantKey::forBody($this->planetService);
        $renfort = CombatParticipantKey::forFleet(self::RENFORT);
        $attaquante = CombatParticipantKey::forFleet(self::ATTAQUANTE);

        $cumul = [$garnison => [], $renfort => [], $attaquante => []];

        foreach ($resultat->rounds as $rang => $round) {
            $this->assertSame(
                [],
                array_diff(array_keys($round->lossesInRoundByParticipant), [$garnison, $renfort, $attaquante]),
                'Round ' . ($rang + 1) . ' attributes losses to someone who did not fight.'
            );

            // **Chaque camp retrouve exactement ses pertes du round dans ses attributions.**
            $this->assertSame(
                $this->flatten($round->defenderLossesInRound),
                $this->flatten($this->sumOf($round->lossesInRoundByParticipant, [$garnison, $renfort])),
                'Round ' . ($rang + 1) . ': the defending attributions do not add up to the defending losses.'
            );
            $this->assertSame(
                $this->flatten($round->attackerLossesInRound),
                $this->flatten($this->sumOf($round->lossesInRoundByParticipant, [$attaquante])),
                'Round ' . ($rang + 1) . ': the attacking attributions do not add up to the attacking losses.'
            );

            foreach ($cumul as $participant => $unites) {
                if (!isset($round->lossesInRoundByParticipant[$participant])) {
                    continue;
                }

                foreach ($round->lossesInRoundByParticipant[$participant]->units as $entree) {
                    $cumul[$participant][$entree->unitObject->machine_name] = ($cumul[$participant][$entree->unitObject->machine_name] ?? 0) + $entree->amount;
                }
            }
        }

        // **Precondition** : les deux flottes defensives ont perdu quelque chose, sinon verser tout
        // a la garnison et attribuer a chacun les siennes rendraient le meme cumul.
        $this->assertNotSame([], $this->withoutZeros($cumul[$garnison]), 'The garrison lost nothing: the attribution could not be told from "everything to the garrison".');
        $this->assertNotSame([], $this->withoutZeros($cumul[$renfort]), 'The reinforcement lost nothing: the attribution could not be told from "everything to the garrison".');

        // **Second temoin** : le cumul par participant est exactement ce que le resultat par flotte
        // inscrit comme pertes de cette flotte.
        foreach ($resultat->defenderFleetResults as $flotte) {
            $participant = $flotte->fleetMissionId === 0 ? $garnison : CombatParticipantKey::forFleet($flotte->fleetMissionId);

            $this->assertSame(
                $this->withoutZeros($flotte->unitsLost->toArray()),
                $this->withoutZeros($cumul[$participant]),
                'The losses attributed round by round to ' . $participant . ' are not the losses its fleet result records.'
            );
        }

        foreach ($resultat->attackerFleetResults as $flotte) {
            $this->assertSame(
                $this->withoutZeros($flotte->unitsLost->toArray()),
                $this->withoutZeros($cumul[CombatParticipantKey::forFleet($flotte->fleetMissionId)]),
                'The losses attributed round by round to the attacker are not the losses its fleet result records.'
            );
        }
    }

    /**
     * Un round dont l'attribution ne recouvre pas les pertes du camp arrete le moteur partage.
     *
     * Le moteur est celui du banc ; seule la carte par flotte d'un round est amputee apres coup,
     * comme le ferait un moteur qui perd des vaisseaux sans dire de qui.
     */
    public function testARoundWhoseAttributionDoesNotCoverTheLossesIsRefused(): void
    {
        $resultat = $this->aBattleWithAGarrisonAndAReinforcement();
        $round = $resultat->rounds[0];

        $this->assertNotSame([], $this->withoutZeros($round->defenderLossesInRound->toArray()), 'The first round cost the defenders nothing: an amputated attribution would still cover it.');

        $round->defenderLossesInRoundPerFleet = [0 => new UnitCollection()];

        $moteur = new class ($resultat, $this->planetService) extends BattleEngine {
            public function __construct(private BattleResult $resultat, PlanetService $planet)
            {
                // Le seul fait dont l'attribution a besoin : le corps qui nomme la garnison.
                $this->defenderPlanet = $planet;
            }

            /**
             * @param array<BattleResultRound> $rounds
             */
            public function expose(array $rounds): void
            {
                $this->keyRoundLossesByParticipant($rounds);
            }

            protected function fightBattleRounds(BattleResult $result): array
            {
                return $this->resultat->rounds;
            }
        };

        try {
            $moteur->expose([$round]);
            $this->fail('A round whose attribution does not cover the defending losses was accepted.');
        } catch (IncoherentRoundAttribution $refus) {
            $this->assertStringContainsString('camp defenseur', $refus->getMessage());
            $this->assertStringContainsString('rocket_launcher', $refus->getMessage(), 'The refusal does not name the losses that went unattributed.');
        }
    }

    /**
     * Une garnison de lanceurs et un renfort de chasseurs, contre une attaque qui entame les deux
     * sans les aneantir avant plusieurs rounds.
     */
    private function aBattleWithAGarrisonAndAReinforcement(): BattleResult
    {
        $this->createAndSetPlanetModel([
            'metal' => 100_000,
            'crystal' => 100_000,
            'deuterium' => 10_000,
            'rocket_launcher' => 150,
        ]);

        $renfort = new DefenderFleet();
        $renfort->units = new UnitCollection();
        $renfort->units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 60);
        $renfort->player = $this->playerService;
        $renfort->fleetMissionId = self::RENFORT;
        $renfort->ownerId = 5;
        $renfort->fleetMission = null;

        $attaquante = new AttackerFleet();
        $attaquante->units = new UnitCollection();
        $attaquante->units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 150);
        $attaquante->units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);
        $attaquante->player = $this->playerService;
        $attaquante->fleetMissionId = self::ATTAQUANTE;
        $attaquante->ownerId = $this->playerService->getId();
        $attaquante->cargoResources = new Resources(0, 0, 0, 0);
        $attaquante->isInitiator = true;
        $attaquante->fleetMission = null;

        $classe = $this->battleEngineClass();
        $defenseurs = [DefenderFleet::fromPlanet($this->planetService), $renfort];

        $moteur = new $classe(
            [$attaquante],
            $this->planetService,
            $defenseurs,
            $this->settingsService,
            LiveLootContextFactory::forBattle([$attaquante], $this->planetService, FrozenLootAllocation::atOperationStart())
        );

        return $moteur->simulateBattle();
    }

    /**
     * @param array<string, UnitCollection> $parParticipant
     * @param array<int, string> $participants
     */
    private function sumOf(array $parParticipant, array $participants): UnitCollection
    {
        $somme = new UnitCollection();

        foreach ($participants as $participant) {
            if (isset($parParticipant[$participant])) {
                $somme->addCollection($parParticipant[$participant]);
            }
        }

        return $somme;
    }

    /**
     * @return array<string, int>
     */
    private function flatten(UnitCollection $unites): array
    {
        return $this->withoutZeros($unites->toArray());
    }

    /**
     * @param array<string, int> $unites
     * @return array<string, int>
     */
    private function withoutZeros(array $unites): array
    {
        $pleines = array_filter($unites, static fn (int $montant): bool => $montant > 0);
        ksort($pleines);

        return $pleines;
    }
}
