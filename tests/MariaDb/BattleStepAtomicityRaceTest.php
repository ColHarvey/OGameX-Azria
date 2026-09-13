<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\StepAlreadyPlayed;
use OGame\Combat\Services\BattleFieldStateStore;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;

/**
 * Un round ne se joue jamais deux fois, et une etape ne s ecrit jamais a moitie.
 *
 * ## Ce que seul le banc peut dire
 *
 * L unicite `(combat, index d etape)` et la transaction qui lie l etat a son historique ne se
 * prouvent pas sous SQLite : `lockForUpdate()` n y compile a rien, et deux appels sequentiels
 * etabliraient l idempotence, jamais la course. Ici, deux processus reels se disputent la meme
 * ligne, sur un vrai MariaDB.
 *
 * ## Ce que chaque epreuve joue, nomme exactement
 *
 * **La course** : deux travailleurs jouent le meme round au meme instant. Un seul doit ecrire ; le
 * second doit le savoir, pas l ignorer. Ce que l on exige n est pas « l un des deux echoue » — ce
 * serait vrai aussi si les deux echouaient — mais **exactement une ecriture, et un historique
 * ecrit une seule fois avec elle**.
 *
 * **Le processus tue** : un enfant ouvre sa transaction, ecrit l etat, et se fait tuer par signal
 * **avant de valider**. Le serveur annule en perdant la connexion. C est plus proche d une panne
 * qu une exception levee dans le code — mais ce n est toujours pas la panne de la machine, dont la
 * durabilite depend des reglages de vidage du serveur. Le dire est la moitie du temoin.
 */
#[Group('mariadb')]
final class BattleStepAtomicityRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    /**
     * @var array<int, int>
     */
    private array $combats = [];

    /**
     * @var array<int, int>
     */
    private array $missions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    protected function tearDown(): void
    {
        if ($this->combats !== []) {
            DB::table('combat_presentation_events')->whereIn('combat_instance_id', $this->combats)->delete();
            DB::table('combat_field_states')->whereIn('combat_instance_id', $this->combats)->delete();
            DB::table('combat_entry_characteristics')->whereIn('combat_instance_id', $this->combats)->delete();
            CombatInstance::query()->whereIn('id', $this->combats)->delete();
            $this->combats = [];
        }

        if ($this->missions !== []) {
            FleetMission::query()->whereIn('id', $this->missions)->delete();
            $this->missions = [];
        }

        parent::tearDown();
    }

    /**
     * Deux travailleurs, le meme round : une seule ecriture, un seul historique.
     */
    public function testTwoWorkersPlayingTheSameRoundLeaveExactlyOneStep(): void
    {
        $combat = $this->aCombat();

        $issues = $this->inParallel(2, function (int $rang) use ($combat): string {
            try {
                resolve(BattleFieldStateStore::class)->writeStep(
                    $combat,
                    1,
                    $this->aState(),
                    fn () => $this->anEventOfTheRound($combat, $rang)
                );

                return 'ecrite';
            } catch (StepAlreadyPlayed) {
                return 'refusee';
            }
        });

        $this->assertSame(
            1,
            count(array_filter($issues, static fn (string $issue): bool => $issue === 'ecrite')),
            'The two workers did not settle into exactly one write: ' . implode(' | ', $issues)
        );

        $this->assertSame(
            1,
            DB::table('combat_field_states')->where('combat_instance_id', $combat)->count(),
            'The round was written more than once, or not at all: it would have been counted twice.'
        );

        $this->assertSame(
            1,
            DB::table('combat_presentation_events')->where('combat_instance_id', $combat)->count(),
            'The history of the round does not match its state: one of the two was written without the other.'
        );
    }

    /**
     * **Un processus tue au milieu de sa transaction ne laisse rien.**
     *
     * L enfant ecrit vraiment — il le dit par un fichier avant de mourir — puis se fait tuer avant
     * de valider. Sans ce fichier, un temoin vert ne distinguerait pas « annule » de « jamais
     * ecrit », et prouverait donc l inverse de ce qu il annonce.
     */
    public function testAWorkerKilledBeforeItsCommitLeavesNothing(): void
    {
        $combat = $this->aCombat();
        $marque = sys_get_temp_dir() . '/ogamex-tue-' . bin2hex(random_bytes(6));

        $etat = $this->aState();

        DB::disconnect();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('The process could not fork.');
        }

        if ($pid === 0) {
            DB::reconnect();

            DB::beginTransaction();

            DB::table('combat_field_states')->insert([
                'combat_instance_id' => $combat,
                'step_index' => 1,
                'schema_version' => 1,
                'state' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // La ligne existe dans SA transaction : il le dit avant de disparaitre.
            file_put_contents($marque, (string)DB::table('combat_field_states')->where('combat_instance_id', $combat)->count());

            // La meme fin que les enfants du banc : le processus s arrete net, sans derouler
            // la fin de PHPUnit — et sans valider.
            posix_kill(posix_getpid(), defined('SIGKILL') ? SIGKILL : 9);
        }

        $statut = 0;
        pcntl_waitpid($pid, $statut);

        DB::reconnect();

        $this->assertFileExists($marque, 'The child died before writing anything: nothing would have been rolled back.');
        $this->assertSame('1', trim((string)file_get_contents($marque)), 'The child never saw its own row: it wrote nothing to roll back.');
        @unlink($marque);

        $this->assertSame(
            0,
            DB::table('combat_field_states')->where('combat_instance_id', $combat)->count(),
            'A step written by a process that died before its commit survived it.'
        );

        // Et l ecriture reste possible ensuite : rien n est reste verrouille derriere le mort.
        resolve(BattleFieldStateStore::class)->writeStep($combat, 1, $etat, function () use ($combat): void {
            $this->anEventOfTheRound($combat, 0);
        });

        $this->assertSame(1, DB::table('combat_field_states')->where('combat_instance_id', $combat)->count());
    }

    /**
     * L historique du round, minimal mais reel : une ligne du fil que le joueur lit.
     */
    private function anEventOfTheRound(int $combat, int $rang): void
    {
        DB::table('combat_presentation_events')->insert([
            'combat_instance_id' => $combat,
            'version' => 'v1',
            'sequence' => 1,
            'visible_at' => 1_700_000_600,
            'participant_key' => CombatParticipantKey::forFleet(1000),
            'side' => 'attacker',
            'unit' => 'light_fighter',
            'amount' => 1 + $rang,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function aCombat(): int
    {
        $corps = (int)Planet::query()->where('user_id', $this->currentUserId)->value('id');

        $initiatrice = FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'planet_id_to' => $corps,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 1,
            'processed' => 1,
        ]);

        $this->missions[] = (int)$initiatrice->id;

        $combat = CombatInstance::query()->create([
            'mission_id' => $initiatrice->id,
            'target_type' => 1,
            'galaxy' => 1,
            'system' => 1,
            'position' => 1,
            'target_planet_id' => $corps,
            'status' => CombatState::Active->value,
            'started_at' => 1_700_000_000,
            'ends_at' => 1_700_003_600,
        ]);

        $this->combats[] = (int)$combat->id;

        return (int)$combat->id;
    }

    private function aState(): BattleFieldState
    {
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');

        $premier = new BattleUnit($chasseur, 400, 10, 50, 1000, $this->currentUserId);
        $second = new BattleUnit($chasseur, 400, 10, 50, 1000, $this->currentUserId);
        $second->currentHullPlating = 12;

        $restantes = new UnitCollection();
        $restantes->addUnit($chasseur, 2);

        return new BattleFieldState(
            [$premier, $second],
            [],
            new SeededDraws(20260909),
            new SeededDraws(20260909),
            1,
            $restantes,
            new UnitCollection(),
            new UnitCollection(),
            new UnitCollection(),
            [],
            [],
        );
    }
}
