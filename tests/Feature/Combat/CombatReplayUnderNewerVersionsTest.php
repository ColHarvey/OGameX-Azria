<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\Combat\Allocation\CappedLoot;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\LootAllocator;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Models\CombatDurationEstimate;
use OGame\Combat\Projection\SnapshotProjectionRegistry;
use OGame\Combat\Projection\SnapshotProjectionRule;
use OGame\Combat\Projection\SnapshotProjectionV1;
use OGame\Combat\Services\CombatDurationEstimator;
use OGame\Combat\Services\CombatEngagementService;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatSnapshotInclusion;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Un combat ouvert sous V1 se rejoue sous V1, meme quand les V2 sont devenues courantes.
 *
 * ## La garantie centrale du versionnement
 *
 * Un combat dure deux heures. Un deploiement peut tomber au milieu. **Aucune mise a jour de regle ne
 * doit changer l'issue d'une bataille deja engagee** — sans quoi le resultat dependrait du moment ou
 * le worker s'est reveille, et personne ne pourrait le reproduire.
 *
 * ## Ce que cet essai prouve
 *
 * Les cinq versions persistees a l'ouverture ne bougent pas, l'empreinte des faits geles non plus,
 * et une fermeture rejouee alors que les courantes ont change ecrit **exactement la meme
 * photographie** : memes participants, memes inclusions, memes budgets.
 *
 * ## Ce qu'il ne prouve pas encore
 *
 * Ni le pillage effectif, ni le plan lunaire, ni les cles d'idempotence de resolution : ils
 * demandent la resolution atomique, qui n'existe pas. L'allocateur est couvert separement par
 * `FrozenLootAllocationTest`, et cet essai verifie seulement qu'il se resout depuis les faits geles.
 *
 * Le dire evite de compter ces preuves-la comme acquises.
 */
class CombatReplayUnderNewerVersionsTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Les cinq versions gelees survivent a une bascule des courantes.
     */
    public function testTheFiveFrozenVersionsSurviveASwitchOfTheCurrentOnes(): void
    {
        $combat = $this->anOpenedCombat();

        $geles = FrozenCombatVersionSet::fromInstance($combat);
        $empreinte = $combat->frozen_facts_fingerprint;

        // Les courantes passent en V2. Le combat n'en sait rien, et c'est le sujet.
        $courantes = FrozenCombatVersionSet::chosenAtOpening(
            projections: $this->aProjectionRegistryWhereV2IsCurrent()
        );

        $this->assertNotSame(
            $geles->projection,
            $courantes->projection,
            'The fake V2 registry was not actually current: the test would prove nothing.'
        );

        $combat->refresh();

        $this->assertSame(
            $geles->toStorage(),
            FrozenCombatVersionSet::fromInstance($combat)->toStorage(),
            'A combat changed its rules because the world moved on.'
        );

        $this->assertSame($empreinte, $combat->frozen_facts_fingerprint);
    }

    /**
     * Une fermeture rejouee sous des courantes V2 ecrit la meme photographie.
     *
     * **C'est le rejeu proprement dit.** Le combat repasse en ralliement pour que la fermeture
     * recommence son travail, et le service recoit un registre ou V2 est courante — mais ou V1 reste
     * connue, sans quoi le rejeu s'arreterait au lieu de se comparer.
     */
    public function testAReplayedClosureUnderV2WritesTheSameSnapshot(): void
    {
        $combat = $this->anOpenedCombat();

        // **La bataille se calcule une fois.** Le moteur tire au sort : un rejeu qui la recalculerait
        // ecrirait un autre resultat. L'estimateur compte ses appels — il n'est appele qu'apres un
        // calcul — et le rejeu ne doit pas l'appeler une seconde fois.
        $estimations = 0;
        $estimateurCompteur = new class ($estimations) extends CombatDurationEstimator {
            public function __construct(private int &$appels)
            {
            }

            public function estimate(
                BattleResult $result,
                float $rate = self::DEFAULT_RATE,
                int $minimumSeconds = self::DEFAULT_MINIMUM_SECONDS,
                float $damping = self::DEFAULT_DAMPING,
            ): CombatDurationEstimate {
                $this->appels++;

                return parent::estimate($result, $rate, $minimumSeconds, $damping);
            }
        };

        (new RallyClosureService(engagement: new CombatEngagementService(estimator: $estimateurCompteur)))->close($combat->id, self::OPENING + 60);

        $premiere = $this->snapshotOf($combat);

        $this->assertNotSame([], $premiere['participants'], 'The first closure registered nobody.');
        $this->assertNotSame([], $premiere['inclusions'], 'The first closure included nothing.');
        $this->assertSame(1, $estimations, 'The first closure did not compute the battle exactly once.');

        $combat->refresh();
        $resultatEcrit = $combat->battle_result;
        $echeanceEcrite = $combat->ends_at;
        $this->assertNotNull($resultatEcrit, 'The first closure wrote no battle result.');

        // Le combat repasse en ralliement. `refresh()` d'abord : l'instance en memoire porte encore
        // l'ancien statut, et lui reaffecter la meme valeur ne la rendrait pas modifiee.
        $combat->status = CombatState::Rallying;
        $combat->save();

        $sousV2 = new RallyClosureService(
            projections: $this->aProjectionRegistryWhereV2IsCurrent(),
            engagement: new CombatEngagementService(estimator: $estimateurCompteur),
        );

        $sousV2->close($combat->id, self::OPENING + 120);

        $this->assertSame(
            $premiere,
            $this->snapshotOf($combat),
            'A replay under newer current versions wrote a different snapshot.'
        );

        $combat->refresh();
        $this->assertSame(1, $estimations, 'The replayed closure computed the battle again.');
        $this->assertSame($resultatEcrit, $combat->battle_result, 'The replayed closure overwrote the battle result.');
        $this->assertSame($echeanceEcrite, $combat->ends_at, 'The replayed closure moved the end of the combat.');
    }

    /**
     * La fermeture inscrit la projection **du combat**, alors qu'une plus recente est courante.
     *
     * ## Pourquoi la V2 doit etre courante des la premiere fermeture
     *
     * J'avais d'abord installe la V2 seulement pour un rejeu, apres une premiere fermeture sous
     * V1. Quatre mutations y ont **survecu** : les inclusions existaient deja, le rejeu ne les
     * reecrivait pas, et remplacer « la version gelee » par « la version courante » ne changeait
     * rien puisque les deux valaient V1.
     *
     * Un essai ou la valeur juste et la valeur fausse coincident ne prouve rien. Il faut que la
     * courante differe **au moment ou l'ecriture a lieu**.
     */
    public function testTheClosureWritesTheProjectionOfTheCombatWhileANewerIsCurrent(): void
    {
        $combat = $this->anOpenedCombat();

        $this->assertSame(
            SnapshotProjectionV1::VERSION,
            $combat->projection_version,
            'The opening did not freeze v1.'
        );

        // La V2 devient courante **avant** que la photographie ne soit prise.
        $sousV2 = new RallyClosureService(
            projections: $this->aProjectionRegistryWhereV2IsCurrent()
        );

        $sousV2->close($combat->id, self::OPENING + 60);

        $inclusions = CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->get();

        $this->assertNotSame(0, $inclusions->count(), 'The closure included nothing.');

        foreach ($inclusions as $ligne) {
            $this->assertSame(
                SnapshotProjectionV1::VERSION,
                $ligne->projection_version,
                'An inclusion was written under the current projection instead of the one the combat began with.'
            );

            $this->assertSame(
                self::OPENING,
                $ligne->included_at,
                'An inclusion took the worker clock instead of the opening instant.'
            );
        }
    }

    /**
     * L'ouverture gele la projection de son ensemble, pas celle du registre du jeu.
     *
     * Les registres sont injectes : un combat ouvert alors qu'une V2 est courante doit porter
     * **V2**, et un combat ouvert sous V1 doit porter V1. Ce sont les deux moities de la meme
     * garantie, et la seconde seule ne distingue pas « gele » de « courant ».
     */
    public function testTheOpeningFreezesTheProjectionOfItsOwnSet(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;
        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $sousV2 = new CombatOpeningService(
            projections: $this->aProjectionRegistryWhereV2IsCurrent()
        );

        $combat = $sousV2->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertSame(
            'projection_v2',
            $combat->projection_version,
            'The opening ignored the registry it was given and read the game default.'
        );
    }

    /**
     * L'allocateur du combat se resout depuis ses faits geles, pas depuis la courante.
     */
    public function testTheAllocatorResolvesFromTheFrozenFacts(): void
    {
        $combat = $this->anOpenedCombat();

        $geles = FrozenCombatVersionSet::fromInstance($combat);

        // **Un registre ou une autre version est courante.** Sans cela, « gele » et « courant »
        // coincident, et l'essai passerait quoi qu'il arrive.
        $registre = LootAllocatorRegistry::of(
            [new ExactLootAllocationV1(), $this->anAllocatorOn('exact_loot_allocation_v2')],
            'exact_loot_allocation_v2'
        );

        $allocation = FrozenLootAllocation::fromFrozenSet($geles, $registre);

        $this->assertSame(
            $geles->lootAllocator,
            $allocation->version,
            'The combat would be resolved under an allocator it never began with.'
        );

        $this->assertNotSame('exact_loot_allocation_v2', $allocation->version);
    }

    /**
     * Un allocateur factice, qui ne porte qu'une version.
     */
    private function anAllocatorOn(string $version): LootAllocator
    {
        return new class ($version) implements LootAllocator {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }

            public function lootableAmount(
                float $inStock,
                int $rateInBasisPoints,
                string $phase,
                ResourceNormalizationDiagnostics &$diagnostics,
            ): int {
                throw new LogicException('Ce double ne porte qu une version.');
            }

            public function capByCargo(Resources $loot, int $totalCargoCapacity, string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT, string $subject = ''): CappedLoot
            {
                throw new LogicException('Ce double ne porte qu une version.');
            }

            /**
             * @param array<int, int> $weights
             * @param array<int, int> $remainingCapacity
             * @return array<int, int>
             */
            public function shareBetweenFleets(
                int $amount,
                array $weights,
                array $remainingCapacity,
                int $initiatorFleetMissionId,
            ): array {
                throw new LogicException('Ce double ne porte qu une version.');
            }
        };
    }

    /**
     * Tout ce que la fermeture a ecrit, sous une forme comparable.
     *
     * **La reservation de butin n'y figure pas**, et son absence est deliberee : elle n'a aucun
     * ecrivain en premiere version. Une valeur toujours nulle ne prouverait rien sur la regle
     * active, et laisserait croire que le rejeu la surveille. C'est
     * `LootReservationHasNoWriterTest` qui porte cette decision, seul et explicitement.
     *
     * **L'ordre est fixe explicitement.** Comparer deux lectures dont l'ordre depend de la base
     * ferait echouer l'essai pour une raison qui n'a rien a voir avec le rejeu.
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(CombatInstance $combat): array
    {
        $combat->refresh();

        return [
            'status' => $combat->status->value,
            'fleets_admitted' => $combat->fleets_admitted,
            'players_admitted' => $combat->players_admitted,
            'fingerprint' => $combat->frozen_facts_fingerprint,
            'versions' => FrozenCombatVersionSet::fromInstance($combat)->toStorage(),
            'participants' => CombatParticipant::where('combat_instance_id', $combat->id)
                ->orderBy('participant_key')
                ->pluck('participant_key')
                ->all(),
            'inclusions' => CombatSnapshotInclusion::where('combat_instance_id', $combat->id)
                ->orderBy('event_identity')
                ->get()
                ->map(static fn (CombatSnapshotInclusion $ligne): array => [
                    'event' => $ligne->event_identity,
                    'projection' => $ligne->projection_version,
                    'contributions' => $ligne->contributions,
                    'included_at' => $ligne->included_at,
                ])
                ->all(),
        ];
    }

    /**
     * Un registre ou une V2 est courante, mais ou V1 reste lisible.
     *
     * **Les deux moities comptent.** Sans la V2 courante, rien n'aurait bascule ; sans la V1
     * connue, le rejeu s'arreterait sur une version inconnue au lieu de se comparer.
     */
    private function aProjectionRegistryWhereV2IsCurrent(): SnapshotProjectionRegistry
    {
        $v2 = new class () implements SnapshotProjectionRule {
            public function version(): string
            {
                return 'projection_v2';
            }
        };

        return SnapshotProjectionRegistry::of([new SnapshotProjectionV1(), $v2], 'projection_v2');
    }

    /**
     * Un combat ouvert par une vague de deux flottes du meme joueur.
     */
    private function anOpenedCombat(): CombatInstance
    {
        $joueur = $this->aPlayer();
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;

        $planete = Planet::find($corps);
        $this->assertNotNull($planete);

        $planete->metal = 80_000;
        $planete->crystal = 40_000;
        $planete->deuterium = 10_000;
        $planete->save();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        return (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);
    }

    /**
     * Une attaque en vol vers ce corps.
     */
    private function anAttackAt(int $targetBodyId, int $arrivesAt, User $owner): FleetMission
    {
        return FleetMission::forceCreate([
            'user_id' => $owner->id,
            // L'engagement exige une planete d'origine : le retour y revient, et le moteur la nomme.
            'planet_id_from' => $this->aPlanetIdOf($owner),
            'type_from' => 1,
            'planet_id_to' => $targetBodyId,
            'mission_type' => 1,
            'time_departure' => self::OPENING - 600,
            'time_arrival' => $arrivesAt,
            'galaxy_to' => 8,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);
    }

    /**
     * La planete d'un joueur cree par ces fixtures.
     */
    private function aPlanetIdOf(User $owner): int
    {
        $id = Planet::query()->where('user_id', $owner->id)->value('id');

        if (!is_int($id)) {
            $this->fail('The player ' . $owner->id . ' owns no planet: no attack could leave from anywhere.');
        }

        return $id;
    }

    /**
     * Un joueur, avec une planete.
     */
    private function aPlayer(): User
    {
        $utilisateur = User::factory()->create();

        $this->aPlanetOwnedBy($utilisateur);

        return $utilisateur;
    }

    /**
     * Une planete a des coordonnees libres, deterministes.
     */
    private function aPlanetOwnedBy(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 8,
            'system' => 400 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
