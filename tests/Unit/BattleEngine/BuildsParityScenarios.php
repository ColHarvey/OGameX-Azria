<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Le montage des scenarios de parite : des joueurs reels, chacun avec ses technologies et sa classe.
 *
 * ## Pourquoi ce montage vit a part
 *
 * Le banc de parite ne s'execute que la ou la bibliotheque Rust existe — pas sur le poste de
 * developpement. Un montage qui ne porterait pas les faits qu'il annonce (deux classes distinctes,
 * deux proprietaires, une cible reellement inactive, des boucliers differents pour un meme type de
 * vaisseau) rendrait le banc vert sans rien prouver, et **personne ne le verrait ici**.
 *
 * Le montage est donc partage : `RustParityBenchTest` compare les deux moteurs la-bas,
 * `ParityScenarioFixturesTest` verifie ici que chaque scenario porte bien ses faits.
 */
trait BuildsParityScenarios
{
    /**
     * La graine de toutes les batailles du banc.
     */
    private const int GRAINE = 20260904;

    /**
     * Monte une bataille : une cible, ses defenseurs, ses attaquantes, chacun avec son proprietaire.
     *
     * @param array<string, int> $planete
     * @param array<int, array<string, mixed>> $attaquantes
     * @param array<int, array<string, mixed>> $renforts
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function aBattle(
        array $planete,
        array $attaquantes,
        array $renforts = [],
        bool $cibleInactive = false,
        NoLootReason|null $sansPillage = null,
        bool $permute = false,
    ): array {
        // Le defenseur possede le corps ; son inactivite decide du bonus de pillage.
        $defenseur = $this->aPlayer(9_000, derniereConnexionIlYAJours: $cibleInactive ? 8 : 0);
        $cible = $this->aBodyOwnedBy($defenseur, $planete);

        $flottes = [];
        foreach ($attaquantes as $rang => $description) {
            $flottes[] = $this->anAttackingFleet(1_000 + $rang, $rang === 0, $description);
        }

        $defenseurs = [DefenderFleet::fromPlanet($cible)];
        foreach ($renforts as $rang => $description) {
            $defenseurs[] = $this->aReinforcement(2_000 + $rang, $description);
        }

        if ($permute) {
            // L'initiatrice reste en tete — le moteur l'exige — et tout le reste change d'ordre.
            $flottes = [$flottes[0], ...array_reverse(array_slice($flottes, 1))];
            $defenseurs = array_reverse($defenseurs);
        }

        $allocation = FrozenLootAllocation::atOperationStart();
        $contexte = $sansPillage === null
            ? LiveLootContextFactory::forBattle($flottes, $cible, $allocation)
            : LiveLootContextFactory::withoutLoot($sansPillage, $flottes, $cible, $allocation);

        return ['attaquantes' => $flottes, 'defenseurs' => $defenseurs, 'cible' => $cible, 'contexte' => $contexte];
    }

    /**
     * @param class-string<BattleEngine> $classe
     * @param array<string, mixed> $bataille
     */
    private function fight(string $classe, array $bataille): BattleResult
    {
        $moteur = new $classe(
            $bataille['attaquantes'],
            $bataille['cible'],
            $bataille['defenseurs'],
            $this->settingsService,
            $bataille['contexte']
        );

        return $moteur->withDraws(new SeededDraws(self::GRAINE))->simulateBattle();
    }

    /**
     * Un joueur isole : son propre utilisateur, ses propres technologies, sa propre classe.
     *
     * @param array<string, int> $tech
     */
    private function aPlayer(int $id, array $tech = [], CharacterClass|null $classe = null, int $derniereConnexionIlYAJours = 0): PlayerService
    {
        $joueur = resolve(PlayerService::class, ['player_id' => 0]);

        $utilisateur = $joueur->getUser();
        $utilisateur->id = $id;
        $utilisateur->username = 'joueur-' . $id;
        $utilisateur->is_npc = false;
        $utilisateur->character_class = $classe?->value;
        $utilisateur->time = (string)now()->subDays($derniereConnexionIlYAJours)->getTimestamp();

        $joueur->setUserTech(UserTech::factory()->make($tech + ['user_id' => $id]));

        return $joueur;
    }

    /**
     * Le corps attaque, possede par ce joueur-la.
     *
     * @param array<string, int> $attributs
     */
    private function aBodyOwnedBy(PlayerService $proprietaire, array $attributs): PlanetService
    {
        $corps = resolve(PlanetServiceFactory::class)->makeForPlayer($proprietaire, 0, useCache: false);

        $modele = Planet::factory()->make($attributs + ['galaxy' => 1, 'system' => 1, 'planet' => 1, 'user_id' => $proprietaire->getId()]);
        $modele->id = 1;

        $corps->setPlanet($modele);
        $corps->updateResourceProductionStats(false);

        return $corps;
    }

    /**
     * @param array<string, mixed> $description
     */
    private function anAttackingFleet(int $missionId, bool $initiatrice, array $description): AttackerFleet
    {
        $flotte = new AttackerFleet();
        $flotte->units = $this->units($description['units']);
        $flotte->player = $this->aPlayer(
            $missionId,
            is_array($description['tech'] ?? null) ? $description['tech'] : [],
            $description['classe'] ?? null
        );
        $flotte->fleetMissionId = $missionId;
        $flotte->ownerId = $flotte->player->getId();
        $flotte->cargoResources = new Resources(0, 0, 0, 0);
        $flotte->isInitiator = $initiatrice;
        $flotte->fleetMission = null;

        return $flotte;
    }

    /**
     * @param array<string, mixed> $description
     */
    private function aReinforcement(int $missionId, array $description): DefenderFleet
    {
        $renfort = new DefenderFleet();
        $renfort->units = $this->units($description['units']);
        $renfort->player = $this->aPlayer($missionId, is_array($description['tech'] ?? null) ? $description['tech'] : []);
        $renfort->fleetMissionId = $missionId;
        $renfort->ownerId = $renfort->player->getId();
        $renfort->fleetMission = null;

        return $renfort;
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

    // ---------------------------------------------------------------------------------------------
    // Les scenarios, decrits une fois et joues des deux cotes.
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function aDuelWithNothingToTake(): array
    {
        return $this->aBattle(
            planete: ['rocket_launcher' => 60],
            attaquantes: [['units' => ['light_fighter' => 80]]],
        );
    }

    /**
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function aBattleWhereLootingIsForbidden(): array
    {
        return $this->aBattle(
            planete: ['metal' => 400_000, 'crystal' => 200_000, 'deuterium' => 50_000, 'rocket_launcher' => 30],
            attaquantes: [['units' => ['small_cargo' => 40, 'light_fighter' => 200]]],
            sansPillage: NoLootReason::NpcEncounter,
        );
    }

    /**
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function aPlunderingAttack(): array
    {
        return $this->aBattle(
            planete: ['metal' => 400_000, 'crystal' => 200_000, 'deuterium' => 50_000, 'rocket_launcher' => 30],
            attaquantes: [['units' => ['small_cargo' => 40, 'light_fighter' => 200]]],
        );
    }

    /**
     * Une union de deux classes differentes contre une cible inactive.
     *
     * Le Decouvreur engage dix cargos, son allie sans classe en engage vingt : un tiers du fret
     * total est donc au Decouvreur, et le taux vaut 5000 + 833 = 5833 points de base.
     *
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function aUnionOfTwoClassesAgainstAnInactiveTarget(): array
    {
        return $this->aBattle(
            planete: ['metal' => 600_000, 'crystal' => 300_000, 'deuterium' => 100_000, 'rocket_launcher' => 40],
            attaquantes: [
                ['units' => ['small_cargo' => 10, 'light_fighter' => 120], 'classe' => CharacterClass::DISCOVERER],
                ['units' => ['small_cargo' => 20, 'cruiser' => 15]],
            ],
            cibleInactive: true,
        );
    }

    /**
     * Un meme type de vaisseau dans la garnison et dans un renfort, avec des boucliers differents.
     *
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function aDefenceSharingAUnitTypeWithDifferentTechnologies(bool $permute = false): array
    {
        return $this->aBattle(
            planete: ['metal' => 50_000, 'crystal' => 50_000, 'light_fighter' => 40],
            attaquantes: [['units' => ['light_fighter' => 200]]],
            renforts: [
                ['units' => ['light_fighter' => 40], 'tech' => ['shielding_technology' => 9_000]],
                ['units' => ['heavy_fighter' => 25]],
            ],
            permute: $permute,
        );
    }

    /**
     * Le symetrique chez deux attaquants.
     *
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function anAttackSharingAUnitTypeWithDifferentTechnologies(): array
    {
        return $this->aBattle(
            planete: ['metal' => 50_000, 'crystal' => 50_000, 'rocket_launcher' => 120],
            attaquantes: [
                ['units' => ['light_fighter' => 40]],
                ['units' => ['light_fighter' => 40], 'tech' => ['shielding_technology' => 9_000]],
            ],
        );
    }

    /**
     * Un fret limitant, et un butin qui ne se divise pas.
     *
     * @return array{attaquantes: array<int, AttackerFleet>, defenseurs: array<int, DefenderFleet>, cible: PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function aLimitingCargoWithIndivisibleRemainders(): array
    {
        return $this->aBattle(
            planete: ['metal' => 1_000_003, 'crystal' => 500_001, 'deuterium' => 250_007, 'rocket_launcher' => 5],
            attaquantes: [
                ['units' => ['small_cargo' => 7, 'light_fighter' => 30]],
                ['units' => ['small_cargo' => 3, 'light_fighter' => 30]],
            ],
        );
    }
}
