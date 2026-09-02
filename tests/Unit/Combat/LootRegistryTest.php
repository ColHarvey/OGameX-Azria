<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Enums\UnsupportedSideReason;
use OGame\Combat\Exceptions\UnknownLootAllocatorVersion;
use OGame\Combat\Exceptions\UnknownLootPolicyVersion;
use OGame\Combat\Exceptions\UnsupportedActorSide;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\Combat\Policies\LootPolicySelector;
use OGame\Combat\Policies\NoLootV1;
use OGame\Combat\Policies\NpcBaseV1;
use OGame\Combat\Support\AttackerCargoShare;
use Tests\UnitTestCase;

/**
 * Les registres de versions, et le choix de la regle applicable.
 *
 * ## Ce que ces essais protegent
 *
 * Une version persistee ne vaut que si l'implementation qui l'a produite reste joignable. Un
 * registre qui accepterait deux formules sous un meme nom, ou une valeur par defaut absente de sa
 * collection, rendrait cette promesse creuse — et le defaut ne se verrait qu'au premier combat
 * relu, des mois plus tard.
 */
class LootRegistryTest extends UnitTestCase
{
    /**
     * Deux implementations sous le meme nom sont refusees.
     *
     * Une version doit designer une seule formule. Deux candidates, et elle ne dit plus rien de ce
     * qui a ete calcule.
     */
    public function testTwoImplementationsUnderOneVersionAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LootPolicyRegistry::of([new CargoWeightedV1(), new CargoWeightedV1()], CargoWeightedV1::VERSION);
    }

    /**
     * Une valeur par defaut absente de la collection est refusee.
     *
     * Les nouveaux combats se reclameraient d'une regle que rien ne saurait appliquer : ils
     * naitraient deja illisibles.
     */
    public function testADefaultOutsideTheCollectionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LootPolicyRegistry::of([new CargoWeightedV1()], NpcBaseV1::VERSION);
    }

    /**
     * Une version inconnue est refusee, et non remplacee par la courante.
     */
    public function testAnUnknownVersionIsRefusedRatherThanSubstituted(): void
    {
        $registre = LootPolicyRegistry::of([new CargoWeightedV1()], CargoWeightedV1::VERSION);

        $this->expectException(UnknownLootPolicyVersion::class);

        $registre->forVersion('cargo_weighted_v404');
    }

    /**
     * Une version connue reste disponible quand une autre devient la valeur par defaut.
     *
     * **C'est la propriete qui justifie le registre.** Changer la valeur par defaut regit les
     * nouveaux combats, et rien d'autre.
     */
    public function testAKnownVersionSurvivesADefaultChange(): void
    {
        $registre = LootPolicyRegistry::of([new CargoWeightedV1(), new NpcBaseV1()], NpcBaseV1::VERSION);

        $this->assertSame(NpcBaseV1::VERSION, $registre->currentVersion());
        $this->assertSame(CargoWeightedV1::VERSION, $registre->forVersion(CargoWeightedV1::VERSION)->version());
    }

    /**
     * La cle d'enregistrement **est** la version declaree : elles ne peuvent pas diverger.
     *
     * Le registre s'indexe sur `version()` lui-meme. Il n'y a donc pas de cle a confronter a la
     * declaration : la question ne se pose pas, et c'est mieux qu'un controle qui pourrait
     * s'oublier.
     */
    public function testTheKeyIsTheDeclaredVersion(): void
    {
        $registre = LootPolicyRegistry::default();

        foreach ($registre->knownVersions() as $version) {
            $this->assertSame($version, $registre->forVersion($version)->version());
        }
    }

    /**
     * Le registre du jeu connait les trois regles d'aujourd'hui.
     */
    public function testTheGameRegistryKnowsTodaysThreeRules(): void
    {
        $connues = LootPolicyRegistry::default()->knownVersions();
        sort($connues);

        $this->assertSame(['cargo_weighted_v1', 'no_loot_v1', 'npc_base_v1'], $connues);
        $this->assertSame(CargoWeightedV1::VERSION, LootPolicyRegistry::default()->currentVersion());
    }

    /**
     * Le registre des allocateurs applique les memes regles.
     */
    public function testTheAllocatorRegistryBehavesTheSameWay(): void
    {
        $this->assertSame([ExactLootAllocationV1::VERSION], LootAllocatorRegistry::default()->knownVersions());
        $this->assertSame(ExactLootAllocationV1::VERSION, LootAllocatorRegistry::default()->currentVersion());

        try {
            LootAllocatorRegistry::default()->forVersion('largest_remainder_v1');
            $this->fail('The registry accepted a version it does not implement.');
        } catch (UnknownLootAllocatorVersion) {
            $this->addToAssertionCount(1);
        }

        try {
            LootAllocatorRegistry::of([new ExactLootAllocationV1()], 'autre_chose');
            $this->fail('The registry accepted a default it does not contain.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * La permission de la mission prime sur la composition du camp.
     *
     * Une expedition reste sans butin meme si tout son camp est pilote par le serveur : c'est la
     * nature de la mission qui tranche.
     */
    public function testAMissionWithoutLootWinsOverEveryComposition(): void
    {
        foreach ([
            'camp joueur' => [101 => ActorKind::Player],
            'camp pirate' => [101 => ActorKind::Npc],
            'camp mixte' => [101 => ActorKind::Player, 102 => ActorKind::Npc],
        ] as $quoi => $genres) {
            $politique = LootPolicySelector::select(
                NoLootReason::NpcEncounter,
                $genres,
                true,
                new AttackerCargoShare(10, 10)
            );

            $this->assertSame(NoLootV1::VERSION, $politique->version, "The mission refusal was overridden for « {$quoi} ».");
            $this->assertSame(0, $politique->maximumRateInBasisPoints());
        }
    }

    /**
     * Un camp entierement compose de joueurs releve de la ponderation par le fret.
     */
    public function testAnAllPlayerSideUsesTheCargoWeightedRule(): void
    {
        $politique = LootPolicySelector::select(
            null,
            [101 => ActorKind::Player, 102 => ActorKind::Player],
            true,
            new AttackerCargoShare(10_000, 10_000)
        );

        $this->assertSame(CargoWeightedV1::VERSION, $politique->version);
        $this->assertSame(7_500, $politique->maximumRateInBasisPoints());
    }

    /**
     * Un camp entierement pilote par le serveur releve de sa regle propre.
     */
    public function testAnAllServerDrivenSideUsesItsOwnRule(): void
    {
        $politique = LootPolicySelector::select(
            null,
            [101 => ActorKind::Npc, 102 => ActorKind::Npc],
            true,
            new AttackerCargoShare(0, 10_000)
        );

        $this->assertSame(NpcBaseV1::VERSION, $politique->version);
        $this->assertSame(5_000, $politique->maximumRateInBasisPoints());
    }

    /**
     * Un camp mixte, vide, ou comprenant le compte systeme est refuse.
     *
     * **Aucune regle ne le couvre, et en choisir une reviendrait a inventer une mecanique dans un
     * bloc `else`.** Le refus est explicite ; c'est au site d'execution de decider quoi en faire.
     */
    public function testAMixedEmptyOrSystemSideIsRefused(): void
    {
        $attendus = [
            'joueur et pirate' => [[101 => ActorKind::Player, 102 => ActorKind::Npc], UnsupportedSideReason::MixedPlayerNpc],
            'pirate et joueur' => [[101 => ActorKind::Npc, 102 => ActorKind::Player], UnsupportedSideReason::MixedPlayerNpc],
            'compte systeme seul' => [[101 => ActorKind::System], UnsupportedSideReason::SystemActorPresent],
            'systeme et joueur' => [[101 => ActorKind::System, 102 => ActorKind::Player], UnsupportedSideReason::SystemActorPresent],
            'systeme et pirate' => [[101 => ActorKind::System, 102 => ActorKind::Npc], UnsupportedSideReason::SystemActorPresent],
            'camp vide' => [[], UnsupportedSideReason::EmptySide],
        ];

        foreach ($attendus as $quoi => [$genres, $raison]) {
            try {
                LootPolicySelector::select(null, $genres, true, new AttackerCargoShare(0, 10));
                $this->fail("The selector silently chose a rule for « {$quoi} ».");
            } catch (UnsupportedActorSide $refus) {
                $this->assertSame($raison, $refus->reason, "The refusal of « {$quoi} » carries the wrong reason.");
            }
        }
    }

    /**
     * Chaque raison de refus se traduit en une raison de non-pillage persistable.
     *
     * **Le balayage porte sur l enumeration entiere.** Une raison ajoutee sans decision
     * correspondante fera echouer le `match` exhaustif — et donc ce test — au lieu de retomber sur
     * une valeur generique qui rendrait l audit muet.
     */
    public function testEveryRefusalReasonMapsToAPersistableOne(): void
    {
        $vues = [];

        foreach (UnsupportedSideReason::cases() as $raison) {
            $persistable = $raison->noLootReason();

            $this->assertNotContains(
                $persistable,
                $vues,
                "Two side reasons map to the same persisted reason: the audit could not tell them apart."
            );

            $vues[] = $persistable;
        }

        $this->assertCount(3, $vues, 'A side reason was added or removed without deciding what it persists.');
    }
}
