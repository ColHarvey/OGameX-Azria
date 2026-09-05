<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * La conservation comptable du butin, ressource par ressource.
 *
 * ## Les trois niveaux, et lesquels sont demontrables aujourd'hui
 *
 * **1. L'allocateur pur** — le butin total egale la somme des parts, la somme ne depasse jamais le
 * fret, et chaque part est une unite entiere. Verifie ici.
 *
 * **2. Le moteur** — le butin du resultat de bataille **est** la somme des parts attribuees, sur
 * chacune des trois ressources separement. C'est ce montant-la que `CombatResolutionService`
 * preleve sur la cible : la deduction et les parts ne peuvent donc pas diverger. Verifie ici.
 *
 * **3. Le chemin persistant** — rechargement depuis la colonne JSON, application differee,
 * idempotence d'un travail rejoue, arrivee effective des retours, absence de double prelevement.
 * **Pas encore demontrable** : ni le stockage ni le worker n'existent. Ce qui manque est nomme
 * plutot que suppose.
 */
class LootAccountingTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetPlanetModel([
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
        $this->createAndSetUserTechModel([]);
    }

    /**
     * L'allocateur rend exactement ce qu'on lui donne, en unites entieres.
     *
     * Le balayage couvre les montants qui ne se divisent pas, les flottes de poids tres inegaux, et
     * les cas ou la place manque.
     */
    public function testTheAllocatorGivesBackExactlyWhatItWasGiven(): void
    {
        $regle = new ExactLootAllocationV1();
        $verifies = 0;

        $montages = [
            'poids egaux, montant indivisible' => [[101 => 100, 102 => 100, 103 => 100], [101 => 999, 102 => 999, 103 => 999]],
            'poids tres inegaux' => [[101 => 1, 102 => 999_999], [101 => 999_999, 102 => 999_999]],
            'place limitee sur une flotte' => [[101 => 500, 102 => 500], [101 => 3, 102 => 999_999]],
            'place limitee partout' => [[101 => 500, 102 => 500], [101 => 5, 102 => 5]],
            'une seule flotte' => [[101 => 42], [101 => 999_999]],
        ];

        foreach ($montages as $quoi => [$poids, $place]) {
            foreach ([1, 2, 7, 100, 1_001, 999_983] as $montant) {
                $parts = $regle->shareBetweenFleets($montant, $poids, $place, 101);
                $somme = array_sum($parts);

                $this->assertLessThanOrEqual($montant, $somme, "« {$quoi} » handed out more than it was given.");

                foreach ($parts as $flotte => $part) {
                    $this->assertGreaterThanOrEqual(0, $part, "« {$quoi} » gave a negative share.");
                    $this->assertLessThanOrEqual(
                        $place[$flotte],
                        $part,
                        "« {$quoi} » exceeded the room left on fleet {$flotte}."
                    );
                }

                // Tant que de la place reste, rien ne doit rester sur la cible.
                $placeTotale = array_sum($place);

                if ($montant <= $placeTotale) {
                    $this->assertSame($montant, $somme, "« {$quoi} » left units behind although there was room.");
                } else {
                    $this->assertSame($placeTotale, $somme, "« {$quoi} » did not fill the available room.");
                }

                $verifies++;
            }
        }

        $this->assertSame(30, $verifies, 'The sweep no longer covers what it claims to cover.');
    }

    /**
     * Le butin du resultat **est** la somme des parts, sur chaque ressource separement.
     *
     * **C'est le maillon qui relie l'attribution au prelevement.** `CombatResolutionService` deduit
     * `$battleResult->loot` de la cible et ajoute `$fleetResult->lootShare` a chaque retour : si les
     * deux divergeaient, une unite serait prise a la cible sans arriver chez personne, ou l'inverse.
     */
    public function testTheBattleLootIsExactlyTheSumOfTheAssignedShares(): void
    {
        foreach ([
            'montant indivisible' => new Resources(1_001, 1_001, 1_001, 0),
            'plus grand que le fret' => new Resources(500_000, 500_000, 500_000, 0),
            'tres petit' => new Resources(1, 2, 0, 0),
            'nul' => new Resources(0, 0, 0, 0),
        ] as $quoi => $butin) {
            $resultat = $this->distribute($butin, [101 => 2, 102 => 3, 103 => 6]);

            foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
                $sommeDesParts = 0;

                foreach ($resultat->attackerFleetResults as $part) {
                    $sommeDesParts += (int)$part->lootShare->{$ressource}->get();
                }

                $this->assertSame(
                    $sommeDesParts,
                    (int)$resultat->loot->{$ressource}->get(),
                    "For « {$quoi} », the {$ressource} deducted from the target is not what the fleets received."
                );
            }
        }
    }

    /**
     * Le cas non divisible, de bout en bout : les deux unites supplementaires vont bien quelque part.
     *
     * Elles apparaissent dans les parts, une seule fois, et le total prelevable sur la cible les
     * comprend. Aucun depassement du fret survivant.
     */
    public function testTheTwoExtraUnitsOfTheNonDivisibleCaseAreAccountedFor(): void
    {
        // Trois flottes de fret egal, 100 000 de fret survivant au total, et un butin plafonne a
        // 53 384 : le tiers entier vaut 17 794 et il reste deux unites a placer.
        $resultat = $this->distribute(new Resources(53_384, 0, 0, 0), [101 => 4, 102 => 4, 103 => 4]);

        $parts = [];

        foreach ($resultat->attackerFleetResults as $part) {
            $parts[$part->fleetMissionId] = (int)$part->lootShare->metal->get();
        }

        ksort($parts);

        $this->assertSame(53_384, array_sum($parts), 'The two extra units vanished between the cap and the shares.');
        $this->assertSame(53_384, (int)$resultat->loot->metal->get(), 'The target would be charged a different amount.');

        // Chaque flotte reste dans sa place : quatre petits transporteurs portent vingt mille.
        foreach ($parts as $flotte => $part) {
            $this->assertLessThanOrEqual(20_000, $part, "Fleet {$flotte} was given more than it can carry.");
        }
    }

    /**
     * Le service de resolution applique les parts, il ne les recalcule pas.
     *
     * ## Une garde architecturale, pas une preuve d'execution
     *
     * Le chemin persistant n'existe pas encore : il n'y a pas de travail differe a espionner. Ce que
     * ce controle garantit, c'est que le service **ne contient pas** de quoi repartir a nouveau — ni
     * l'allocateur, ni la fabrique de contexte, ni la politique de pillage.
     *
     * La preuve par espions viendra avec le stockage et le worker ; elle n'est pas remplacee ici,
     * elle est preparee.
     */
    public function testTheResolutionServiceAppliesTheSharesWithoutRecomputingThem(): void
    {
        $service = (string)file_get_contents(base_path('app/Combat/Services/CombatResolutionService.php'));

        foreach ([
            'shareBetweenFleets' => 'it would allocate loot a second time',
            'LiveLootContextFactory' => 'it would observe living data at settlement time',
            'LootPolicySelector' => 'it would choose a loot rule of its own',
            'LootPolicyRegistry' => 'it would resolve a loot rule of its own',
            'maximumRateInBasisPoints' => 'it would recompute a loot rate',
        ] as $interdit => $pourquoi) {
            $this->assertStringNotContainsString(
                $interdit,
                $service,
                "CombatResolutionService references « {$interdit} »: {$pourquoi}."
            );
        }

        // Ce qu'il fait, en revanche : lire les parts deja calculees.
        $this->assertStringContainsString('lootShare', $service, 'The service no longer reads the shares it must apply.');
        $this->assertStringContainsString('deductResources($battleResult->loot)', $service, 'The service no longer charges the target.');
    }

    /**
     * Les deux plafonds defensifs ne repartissent rien : ils plafonnent.
     *
     * ## Ce qui est verifiable par la forme, et ce qui l est par la mesure
     *
     * `capByCargo()` ne connait pas les flottes : sa signature ne recoit qu un butin et une
     * capacite. **Aucune part ne peut y changer de proprietaire**, et ce n est pas une propriete a
     * tester, c est une consequence du type.
     *
     * Ce qui merite d etre mesure, c est qu elle ne cree jamais d unite et ne depasse jamais le
     * fret — y compris quand elle est appelee sur une cargaison deja calculee, comme aux deux sites
     * defensifs de `CombatResolutionService`.
     */
    public function testTheDefensiveCapsOnlyCapAndNeverReallocate(): void
    {
        $regle = new ExactLootAllocationV1();
        $verifies = 0;

        foreach ([
            'deja dans les clous' => [new Resources(10, 20, 30, 0), 1_000],
            'juste a la limite' => [new Resources(10, 20, 30, 0), 60],
            'au-dela du fret' => [new Resources(10_000, 20_000, 30_000, 0), 53_384],
            'fret nul' => [new Resources(10, 20, 30, 0), 0],
            'une seule ressource' => [new Resources(999, 0, 0, 0), 500],
        ] as $quoi => [$cargaison, $fret]) {
            $plafonne = $regle->capByCargo($cargaison, $fret)->resources;

            foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
                $this->assertLessThanOrEqual(
                    $cargaison->{$ressource}->get(),
                    $plafonne->{$ressource}->get(),
                    "« {$quoi} » created {$ressource} that was not there."
                );

                $this->assertSame(
                    floor($plafonne->{$ressource}->get()),
                    $plafonne->{$ressource}->get(),
                    "« {$quoi} » left a fraction on {$ressource}."
                );

                $this->assertGreaterThanOrEqual(0.0, $plafonne->{$ressource}->get(), "« {$quoi} » went negative.");
            }

            $this->assertLessThanOrEqual($fret, $plafonne->sum(), "« {$quoi} » exceeded the cargo it was given.");

            $verifies++;
        }

        $this->assertSame(5, $verifies);

        // Les cinq plafonnements d une resolution passent par un point unique, qui accumule leurs
        // diagnostics au lieu de journaliser cinq fois. Un plafond different d un chemin a l autre
        // donnerait deux cargaisons differentes pour un meme etat.
        $service = (string)file_get_contents(base_path('app/Combat/Services/CombatResolutionService.php'));

        // Sept sites depuis la revue 92 : les cinq d origine, plus les deux de la collecte des
        // Faucheurs attribuee flotte par flotte en attaque groupee — la part de la capacite Faucheur
        // gelee de chaque flotte, et la place libre de son fret gele.
        $this->assertSame(
            7,
            substr_count($service, '$this->capAndCollect('),
            'A capping site was added or removed: each one must go through the same versioned rule.'
        );

        $this->assertStringNotContainsString(
            'LootService::distributeLoot(',
            $service,
            'A capping site bypasses the collecting point, so its diagnostics would be lost.'
        );
    }

    /**
     * Repartit un butin entre des flottes, et rend le resultat.
     *
     * @param Resources $butin
     * @param array<int, int> $tailles Nombre de petits transporteurs par flotte.
     * @return BattleResult
     */
    private function distribute(Resources $butin, array $tailles): BattleResult
    {
        $petitTransporteur = ObjectService::getUnitObjectByMachineName('small_cargo');
        $flottes = [];
        $resultats = [];

        foreach ($tailles as $identifiant => $nombre) {
            $unites = new UnitCollection();
            $unites->addUnit($petitTransporteur, $nombre);

            $flotte = new AttackerFleet();
            $flotte->units = clone $unites;
            $flotte->player = $this->playerService;
            $flotte->fleetMissionId = $identifiant;
            $flotte->ownerId = $this->playerService->getId();
            $flotte->cargoResources = new Resources(0, 0, 0, 0);
            $flotte->isInitiator = $identifiant === 101;
            $flotte->fleetMission = null;

            $flottes[] = $flotte;

            $resultat = new AttackerFleetResult($identifiant, $flotte->ownerId, $unites);
            $resultat->unitsResult = clone $unites;
            $resultat->completelyDestroyed = false;

            $resultats[] = $resultat;
        }

        $moteur = new LootAccountingHarness(
            $flottes,
            $this->planetService,
            [DefenderFleet::fromPlanet($this->planetService)],
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart())
        );

        $resultat = new BattleResult();
        $resultat->loot = (new ExactLootAllocationV1())->capByCargo($butin, (int)(20_000 * array_sum($tailles) / 4))->resources;
        $resultat->attackerFleetResults = $resultats;

        $moteur->runDistributeResources($resultat);

        return $resultat;
    }
}

/**
 * Expose la repartition sans faire tourner de bataille.
 */
class LootAccountingHarness extends BattleEngine
{
    public function runDistributeResources(BattleResult $result): void
    {
        $this->distributeResources($result);
    }

    protected function fightBattleRounds(BattleResult $result): array
    {
        return [];
    }
}
