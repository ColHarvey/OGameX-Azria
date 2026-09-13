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
     * **Des flottes deja entamees se battent pareil des deux cotes.**
     *
     * Les coques traversent la frontiere FFI dans les deux sens : PHP les calcule et les envoie en
     * `initial_hulls`, Rust rend l'etat des survivants en `survivor_hulls`. **Aucun autre scenario
     * n'en pose** — sans celui-ci, un moteur pourrait appliquer les degats et l'autre les ignorer
     * sans qu'un seul job ne rougisse.
     */
    public function testFleetsThatArriveDamagedAreFoughtIdenticallyByBothEngines(): void
    {
        $this->assertBothEnginesAgree('degats', $this->fleetsThatArriveAlreadyDamaged());
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
        // Le fret se mesure sur la flotte : une politique sans pillage ne photographie aucun fret.
        $this->assertGreaterThan(
            0,
            $bataille['attaquantes'][0]->units->getTotalCargoCapacity($bataille['attaquantes'][0]->player),
            'The fleet carries no free cargo: the refusal would hold trivially.'
        );

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
     * **Le bonus de classe du General traverse la couture a l identique.**
     *
     * Deux attaquantes aux memes vaisseaux et aux memes technologies, l une Generale : leurs tirs ne
     * different que par la classe. Rust ne recalcule rien, il recoit la puissance, le bouclier et la
     * coque de chaque flotte — un bonus perdu, aplati ou lu sur le mauvais joueur ferait diverger les
     * deux moteurs ici. `ParityScenarioFixturesTest` etablit, sans bibliotheque, que ce montage porte
     * bien l ecart annonce.
     */
    public function testAGeneralsClassBonusCrossesTheSeamIdentically(): void
    {
        $this->assertBothEnginesAgree('general', $this->aGeneralAndItsClasslessTwinAgainstAGarrison());
    }

    /**
     * **La classe gelee a l admission traverse la couture a l identique.**
     *
     * Le compte est Collecteur, le combattant a ete admis General. Les puissances de tir et les capacites de fret
     * se mesurent cote PHP, sur le porteur de la classe gelee : une classe lue sur le compte au lieu du porteur
     * ferait diverger les tirs, les capacites et le butin. `ParityScenarioFixturesTest` etablit, sans
     * bibliotheque, que le montage porte bien cet ecart.
     *
     * **La manoeuvre de Hamill n est pas dans ce scenario**, et c est voulu : elle a son propre banc,
     * `testASuccessfulHamillManoeuvreCrossesTheSeamIdentically`, ou elle **aboutit**. Melanger les deux
     * rendrait chacun moins lisible — celui-ci porte la classe gelee, l autre la manoeuvre.
     */
    public function testAClassFrozenAtAdmissionCrossesTheSeamIdentically(): void
    {
        $this->assertBothEnginesAgree('classe-gelee', $this->aGeneralFrozenAtAdmissionWhoseAccountBecameACollector());
    }

    /**
     * **Une manoeuvre de Hamill qui reussit traverse la couture a l identique.**
     *
     * C est le temoin que la revue de Codex demandait : la manoeuvre **aboutit**, et les deux moteurs sont
     * compares sur les unites restantes, les tirs de chaque round, les pertes par participant — que la
     * projection canonique porte — puis sur ce que la projection ne porte pas et que le rapport montre : le
     * drapeau de la manoeuvre, le depart annonce du defenseur et l Etoile comptee perdue.
     *
     * Avant la correction, ce banc etait rouge et disait pourquoi : `completely_destroyed` valait `false` sous
     * PHP et `true` sous Rust, l Etoile continuant d y tirer.
     */
    public function testASuccessfulHamillManoeuvreCrossesTheSeamIdentically(): void
    {
        $chance = $this->settingsService->hamillManoeuvreChance();
        $this->settingsService->set('hamill_manoeuvre_chance', 1);

        try {
            [$php, $rust] = $this->assertBothEnginesAgree('hamill', $this->aGeneralWhoseHamillManoeuvreSucceeds());

            foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
                $this->assertTrue($resultat->hamillManoeuvreTriggered, $moteur . ' : la manoeuvre ne s est pas jouee.');
                $this->assertSame(2, $resultat->defenderUnitsStart->getAmountByMachineName('deathstar'), $moteur . ' : le depart annonce ne porte pas les deux Etoiles.');
                $this->assertSame(1, $resultat->defenderUnitsLost->getAmountByMachineName('deathstar'), $moteur . ' : la manoeuvre n a detruit aucune Etoile.');
                $this->assertSame(1, $resultat->defenderUnitsResult->getAmountByMachineName('deathstar'), $moteur . ' : la seconde Etoile n a pas survecu.');
            }
        } finally {
            $this->settingsService->set('hamill_manoeuvre_chance', $chance);
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
     * L'ordre dans lequel les flottes sont donnees ne change pas la bataille — dans les deux moteurs,
     * des deux cotes, et jusqu'a la bande de tirages consommee.
     */
    public function testAPermutationOfTheFleetsFightsTheSameBattleInBothEngines(): void
    {
        $droit = $this->anEngagementToPermuteOnBothSides();
        $permute = $this->anEngagementToPermuteOnBothSides(permute: true);

        // **Precondition : l'ordre a vraiment change des deux cotes**, et les memes flottes sont
        // engagees. Sans elle, « la permutation ne change rien » serait vrai d'une permutation nulle.
        ParityScenarioFixturesTest::assertTheOrderReallyChanged($this, $droit, $permute);

        $phpDroit = $this->fight(PhpBattleEngine::class, $droit);
        $phpPermute = $this->fight(PhpBattleEngine::class, $permute);
        $this->assertProjectionsAgree('permutation-php', CanonicalProjection::of($phpDroit), CanonicalProjection::of($phpPermute));
        $this->assertSame($phpDroit->drawsConsumed, $phpPermute->drawsConsumed, 'PHP consumed another band once the fleets were reordered.');

        $rustDroit = $this->fight(RustBattleEngine::class, $droit);
        $rustPermute = $this->fight(RustBattleEngine::class, $permute);
        $this->assertProjectionsAgree('permutation-rust', CanonicalProjection::of($rustDroit), CanonicalProjection::of($rustPermute));
        $this->assertSame($rustDroit->drawsConsumed, $rustPermute->drawsConsumed, 'Rust consumed another band once the fleets were reordered.');

        // Et les deux moteurs se rejoignent, projection et bande — ce que la comparaison directe de
        // deux projections ne verifiait pas ici.
        $this->assertBothEnginesAgree('permutation', $droit);
        $this->assertBothEnginesAgree('permutation-inverse', $permute);
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
