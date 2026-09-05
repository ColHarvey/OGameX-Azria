<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Policies\NoLootV1;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use Tests\UnitTestCase;

/**
 * Le banc de parite : une seule entree gelee, une seule bande de tirages, deux moteurs, une projection.
 *
 * ## Ce que ce banc prouve
 *
 * Que les deux moteurs, nourris des memes flottes et de la meme graine, produisent la meme bataille :
 * survivants et pertes par participant et par periode, capacites survivantes, taux et versions,
 * butin et parts, debris. Une difference nomme **le premier chemin divergent** et laisse les deux
 * projections JSON dans `storage/logs/` comme artefacts — pas un `assertEquals` illisible sur deux
 * gros tableaux. Il exige en plus que **la bande ait ete consommee a l'identique** : meme nombre de
 * tirages semantiques, meme nombre de tirages bruts — rejets compris —, meme empreinte.
 *
 * ## Des joueurs distincts, et pourquoi cela compte
 *
 * Chaque flotte a **son propre proprietaire**, avec ses technologies et sa classe (voir
 * `BuildsParityScenarios`). Un banc ou toutes les flottes partagent un joueur ne peut pas prouver
 * qu'une caracteristique propre a un participant traverse la couture : un aplatissement par type de
 * vaisseau, ou une classe lue sur le mauvais joueur, y passerait inapercu.
 *
 * **Que le montage porte bien ces faits est verifie ailleurs** — `ParityScenarioFixturesTest`, qui
 * s'execute meme sans bibliotheque. Ce fichier-ci ne s'execute que la ou le `.so` existe, et si son
 * montage mentait, personne ne le verrait sur le poste de developpement.
 *
 * ## Ce qu'il ne prouve pas
 *
 * Que l'un des deux moteurs a raison. Il prouve qu'ils disent la meme chose ; les regles, elles,
 * sont eprouvees par les bancs de chaque moteur.
 */
class RustParityBenchTest extends UnitTestCase
{
    use BuildsParityScenarios;

    protected function setUp(): void
    {
        $this->skipWhenTheRustLibraryIsUnavailable();

        parent::setUp();
    }

    /**
     * Un duel sans butin possible : la cible n'a rien, et les deux moteurs le disent pareil.
     */
    public function testADuelWithNothingToTakeIsFoughtIdenticallyByBothEngines(): void
    {
        $this->assertBothEnginesAgree('duel', $this->aDuelWithNothingToTake());
    }

    /**
     * **Interdit de piller n'est pas « rien a prendre ».** La cible est riche, la flotte a du fret,
     * et pourtant le butin est nul des deux cotes : c'est la politique qui le dit, sous sa version
     * et son motif. Sans ce scenario, le duel a stock nul ne traversait jamais `no_loot_v1`.
     */
    public function testAForbiddenLootIsForbiddenIdenticallyByBothEngines(): void
    {
        $bataille = $this->aBattleWhereLootingIsForbidden();

        $this->assertSame(NoLootV1::VERSION, $bataille['contexte']->policyVersion);
        $this->assertSame(NoLootReason::NpcEncounter, $bataille['contexte']->noLootBecause);
        $this->assertGreaterThan(0, $bataille['contexte']->totalCargo, 'The fleet carries no free cargo: the refusal would hold trivially.');

        [$php, $rust] = $this->assertBothEnginesAgree('interdit', $bataille);

        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $this->assertSame(NoLootV1::VERSION, $resultat->lootPolicyVersion, $moteur . ' fought under another loot policy.');
            $this->assertSame(0, (int)$resultat->loot->sum(), $moteur . ' took loot from a battle where looting is forbidden.');
        }
    }

    /**
     * Une attaque pillarde ordinaire : le butin existe, et les deux moteurs le repartissent pareil.
     */
    public function testAPlunderingAttackIsFoughtIdenticallyByBothEngines(): void
    {
        [$php] = $this->assertBothEnginesAgree('pillage', $this->aPlunderingAttack());

        $this->assertGreaterThan(0, (int)$php->loot->sum(), 'Nothing was taken: the loot paths were never exercised.');
    }

    /**
     * Une union de deux classes differentes, contre une cible inactive : le taux pondere vaut
     * exactement 5833 points de base — ni 5000, ni 7500, ni un multiple de cent.
     */
    public function testAUnionOfTwoClassesAgainstAnInactiveTargetIsWeightedIdenticallyByBothEngines(): void
    {
        $bataille = $this->aUnionOfTwoClassesAgainstAnInactiveTarget();

        $this->assertSame(ParityScenarioFixturesTest::TAUX_UNION_PONDEREE, $bataille['contexte']->rateInBasisPoints);

        [$php, $rust] = $this->assertBothEnginesAgree('union', $bataille);

        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $this->assertSame(ParityScenarioFixturesTest::TAUX_UNION_PONDEREE, $resultat->lootRateInBasisPoints, $moteur . ' fought under another loot rate.');
            $this->assertSame(CargoWeightedV1::VERSION, $resultat->lootPolicyVersion);
        }
    }

    /**
     * **Un meme type de vaisseau des deux cotes de la defense, avec des technologies differentes.**
     *
     * Si la couture aplatissait les caracteristiques par type de vaisseau, les deux flottes
     * combattraient avec les memes, et l'une perdrait ce qu'elle ne doit pas perdre.
     */
    public function testDefendingFleetsSharingAUnitTypeKeepTheirOwnTechnologies(): void
    {
        [$php, $rust] = $this->assertBothEnginesAgree('renforts', $this->aDefenceSharingAUnitTypeWithDifferentTechnologies());

        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $pertes = [];

            foreach ($resultat->defenderFleetResults as $flotte) {
                $pertes[$flotte->fleetMissionId] = (int)$flotte->unitsLost->getAmount();
            }

            $this->assertGreaterThan(0, $pertes[0] ?? 0, $moteur . ': the unshielded garrison lost nothing.');
            $this->assertSame(0, $pertes[2000] ?? -1, $moteur . ': the shielded reinforcement lost fighters it could not lose.');
        }
    }

    /**
     * Le symetrique chez deux attaquants : meme type, technologies differentes.
     */
    public function testAttackingFleetsSharingAUnitTypeKeepTheirOwnTechnologies(): void
    {
        [$php, $rust] = $this->assertBothEnginesAgree('attaquants', $this->anAttackSharingAUnitTypeWithDifferentTechnologies());

        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $pertes = [];

            foreach ($resultat->attackerFleetResults as $flotte) {
                $pertes[$flotte->fleetMissionId] = (int)$flotte->unitsLost->getAmount();
            }

            $this->assertGreaterThan(0, $pertes[1000] ?? 0, $moteur . ': the unshielded attacker lost nothing.');
            $this->assertSame(0, $pertes[1001] ?? -1, $moteur . ': the shielded attacker lost fighters it could not lose.');
        }
    }

    /**
     * Un fret limitant, et un butin qui ne se divise pas : le plafonnement et les plus forts restes
     * sont reellement exerces, et les deux moteurs partagent pareil.
     */
    public function testALimitingCargoAndIndivisibleRemaindersAreSharedIdenticallyByBothEngines(): void
    {
        [$php, $rust] = $this->assertBothEnginesAgree('restes', $this->aLimitingCargoWithIndivisibleRemainders());

        $parts = [];

        foreach ($php->attackerFleetResults as $flotte) {
            $parts[$flotte->fleetMissionId] = (int)$flotte->lootShare->sum();
        }

        foreach ($rust->attackerFleetResults as $flotte) {
            $this->assertSame($parts[$flotte->fleetMissionId], (int)$flotte->lootShare->sum(), 'Rust shared the loot differently.');
        }
    }

    /**
     * L'ordre dans lequel les flottes sont donnees ne change pas la bataille — dans les deux moteurs.
     */
    public function testAPermutationOfTheFleetsFightsTheSameBattleInBothEngines(): void
    {
        $droit = $this->aDefenceSharingAUnitTypeWithDifferentTechnologies();
        $permute = $this->aDefenceSharingAUnitTypeWithDifferentTechnologies(permute: true);

        $phpDroit = CanonicalProjection::of($this->fight(PhpBattleEngine::class, $droit));
        $phpPermute = CanonicalProjection::of($this->fight(PhpBattleEngine::class, $permute));
        $this->assertProjectionsAgree('permutation-php', $phpDroit, $phpPermute);

        $rustDroit = CanonicalProjection::of($this->fight(RustBattleEngine::class, $droit));
        $rustPermute = CanonicalProjection::of($this->fight(RustBattleEngine::class, $permute));
        $this->assertProjectionsAgree('permutation-rust', $rustDroit, $rustPermute);

        $this->assertProjectionsAgree('permutation', $phpDroit, $rustDroit);
    }

    /**
     * Joue la bataille dans les deux moteurs et exige la meme projection et la meme bande.
     *
     * @param array<string, mixed> $bataille
     * @return array{0: BattleResult, 1: BattleResult}
     */
    private function assertBothEnginesAgree(string $nom, array $bataille): array
    {
        $php = $this->fight(PhpBattleEngine::class, $bataille);
        $rust = $this->fight(RustBattleEngine::class, $bataille);

        $projectionPhp = CanonicalProjection::of($php);
        $projectionRust = CanonicalProjection::of($rust);

        $this->assertNotSame([], $projectionPhp['rounds'], 'The battle had no round: the projection would compare nothing.');

        $this->assertProjectionsAgree($nom, $projectionPhp, $projectionRust);

        // **La bande a ete consommee entierement et a l'identique** : memes tirages semantiques,
        // memes tirages bruts — rejets compris —, meme empreinte de genre, borne et valeur.
        $this->assertNotNull($php->drawsConsumed, 'The PHP engine kept no journal of its draws.');
        $this->assertNotNull($rust->drawsConsumed, 'The Rust engine returned no journal of its draws.');
        $this->assertGreaterThan(0, $php->drawsConsumed['count']);
        $this->assertGreaterThanOrEqual($php->drawsConsumed['count'], $php->drawsConsumed['raw']);
        $this->assertSame($php->drawsConsumed, $rust->drawsConsumed, 'Scenario « ' . $nom . ' » : the two engines did not consume the same draws.');

        return [$php, $rust];
    }

    /**
     * @param array<string, mixed> $php
     * @param array<string, mixed> $rust
     */
    private function assertProjectionsAgree(string $nom, array $php, array $rust): void
    {
        $divergence = CanonicalProjection::firstDivergence($php, $rust);

        if ($divergence === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $dossier = storage_path('logs');
        $etiquette = preg_replace('/[^a-z0-9]+/', '-', strtolower($nom)) ?? 'scenario';
        file_put_contents($dossier . '/parite-' . $etiquette . '-php.json', json_encode($php, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dossier . '/parite-' . $etiquette . '-rust.json', json_encode($rust, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->fail('Scenario « ' . $nom . ' » : the two engines diverge at ' . $divergence . ' (both projections are in storage/logs/parite-' . $etiquette . '-*.json).');
    }
}
