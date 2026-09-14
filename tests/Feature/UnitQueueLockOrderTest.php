<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyPublisher;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Models\User;
use OGame\Services\HalvingService;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;

/**
 * **L'ordre des verrous de la file d'unités, tel que les requêtes le demandent.**
 *
 * L'ordre de toute la file : le compte (seulement pour un geste payé en matière noire), puis le corps, puis les lignes
 * de sa file. Et le registre des cumuls ne se verrouille ni ne se modifie jamais par une plage : par sa clef primaire
 * seulement.
 *
 * Ces essais caractérisent la **forme** (`ObservesLockingStatements`) ; l'effet — attentes réelles, verrous
 * d'intervalle, dénouement — est éprouvé par les courses du bac MariaDB.
 */
class UnitQueueLockOrderTest extends AccountTestCase
{
    use ObservesLockingStatements;

    protected function tearDown(): void
    {
        DB::table('settings')->whereIn('key', [MilitaryTallyRecorder::SINCE_KEY, MilitaryTallyPublisher::PUBLISHED_KEY])->delete();
        DB::table('military_tally_events')->where('player_id', $this->currentUserId)->delete();
        DB::table('military_tallies')->where('player_id', $this->currentUserId)->delete();

        parent::tearDown();
    }

    public function testTheHalvingTakesTheAccountThenTheBodyThenItsQueueRow(): void
    {
        $lot = $this->unLotEnCours();

        $instructions = $this->statementsOf(fn () => resolve(HalvingService::class)->halveUnit($this->compte(), $lot, $this->planetService));

        $this->assertSame(['users', 'planets', 'unit_queues'], $this->lockedTablesIn($instructions), 'Le demi-temps ne prend pas le compte, puis le corps, puis sa ligne de file.');
    }

    public function testCompletingTakesTheAccountThenTheBodyThenItsQueueRow(): void
    {
        $lot = $this->unLotEnCours();

        $instructions = $this->statementsOf(fn () => resolve(HalvingService::class)->completeUnit($this->compte(), $lot, $this->planetService));

        $this->assertSame(['users', 'planets', 'unit_queues'], $this->lockedTablesIn($instructions), '« Terminer » ne prend pas le compte, puis le corps, puis sa ligne de file.');
    }

    public function testTheProgressionTakesTheBodyThenRereadsItsQueueRowsUnderLock(): void
    {
        $this->unLotEnCours();

        $instructions = $this->statementsOf(fn () => $this->planetService->updateUnitQueue());

        $this->assertSame(['planets', 'unit_queues'], $this->lockedTablesIn($instructions), 'La progression ne prend pas le corps, puis ne relit pas ses lignes sous verrou.');
    }

    /**
     * La progression de page tient le corps dès sa première instruction verrouillante, et aucune instruction ne
     * touche une ligne de file avant.
     */
    public function testThePageProgressionTouchesNoQueueRowBeforeHoldingTheBody(): void
    {
        $this->unLotEnCours();

        $instructions = $this->statementsOf(fn () => $this->planetService->update());

        $corps = $this->premiere($instructions, static fn (array $i): bool => $i['lock'] && $i['table'] === 'planets');
        $file = $this->premiere($instructions, static fn (array $i): bool => $i['table'] === 'unit_queues');
        $verrouDeFile = $this->premiere($instructions, static fn (array $i): bool => $i['lock'] && $i['table'] === 'unit_queues');

        $this->assertSame(0, $this->premiere($instructions, static fn (array $i): bool => $i['lock']), 'La progression de page a verrouillé autre chose avant le corps.');
        $this->assertSame('planets', $instructions[0]['table'] ?? null, 'La progression de page n’a pas commencé par tenir le corps.');
        $this->assertNotNull($corps);
        $this->assertNotNull($verrouDeFile, 'La progression de page n’a pas relu la file sous verrou.');
        $this->assertLessThan($file, $corps, 'Une ligne de file a été lue avant que le corps soit tenu.');
    }

    /**
     * **Le registre et les compteurs ne se modifient ni ne se verrouillent par une plage.** Seule la sélection des
     * candidats, une lecture sans verrou, cherche.
     *
     * Une garde de forme, qui ne remplace pas la course : elle dit ce que le code demande, pas ce que le moteur pose.
     */
    public function testTheRegisterAndTheCountersAreOnlyLockedAndWrittenByPrimaryKey(): void
    {
        DB::table('settings')->insert(['key' => MilitaryTallyRecorder::SINCE_KEY, 'value' => (string)((int)Date::now()->timestamp - 1_000), 'created_at' => Date::now(), 'updated_at' => Date::now()]);
        $this->unLotEnCours(entierementEchu: true);

        $instructions = $this->statementsOf(function (): void {
            $this->planetService->updateUnitQueue();
            resolve(MilitaryTallyAggregator::class)->aggregate();
            resolve(MilitaryTallyPublisher::class)->publish();
        });

        $vues = ['insertion' => 0, 'relecture' => 0, 'marque' => 0, 'compteur' => 0];

        foreach ($instructions as $instruction) {
            if (!in_array($instruction['table'], ['military_tally_events', 'military_tallies'], true)) {
                continue;
            }

            $sql = strtolower($instruction['sql']);
            $ecriture = str_starts_with(ltrim($sql), 'insert') || str_starts_with(ltrim($sql), 'update') || str_starts_with(ltrim($sql), 'delete');

            if (!$ecriture && !$instruction['lock']) {
                continue;
            }

            if ($instruction['table'] === 'military_tally_events') {
                if (str_starts_with(ltrim($sql), 'insert')) {
                    $vues['insertion']++;
                    continue;
                }

                $this->assertMatchesRegularExpression('/where "event_key" (?:= \?|in \()/', $sql, 'Le registre est verrouillé ou modifié autrement que par sa clef primaire : ' . $instruction['sql']);
                $instruction['lock'] ? $vues['relecture']++ : $vues['marque']++;
                continue;
            }

            $this->assertMatchesRegularExpression('/on conflict \("player_id"\)|where "player_id" = \?/', $sql, 'Un compteur est verrouillé ou modifié autrement que par sa clef primaire : ' . $instruction['sql']);
            $vues['compteur']++;
        }

        foreach ($vues as $genre => $nombre) {
            $this->assertGreaterThan(0, $nombre, "Aucune instruction « $genre » observée : la garde ne vérifierait rien.");
        }
    }

    /**
     * Un lot de cent chasseurs commencé, au milieu de sa production, avec de quoi payer un demi-temps.
     */
    private function unLotEnCours(bool $entierementEchu = false): int
    {
        $maintenant = (int)Date::now()->timestamp;
        DB::table('users')->where('id', $this->currentUserId)->update(['dark_matter' => 1_000_000]);

        return (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $this->planetService->getPlanetId(),
            'object_id' => ObjectService::getUnitObjectByMachineName('light_fighter')->id,
            'object_amount' => 100,
            'time_duration' => 1_000,
            'time_start' => $entierementEchu ? $maintenant - 1_500 : $maintenant - 305,
            'time_end' => $entierementEchu ? $maintenant - 500 : $maintenant + 695,
            'time_progress' => 0,
            'object_amount_progress' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
        ]);
    }

    private function compte(): User
    {
        $compte = User::query()->find($this->currentUserId);
        $this->assertInstanceOf(User::class, $compte);

        return $compte;
    }

    /**
     * @param list<array{table: string, sql: string, lock: bool, bindings: array<int, mixed>}> $instructions
     * @param callable(array{table: string, sql: string, lock: bool, bindings: array<int, mixed>}): bool $condition
     */
    private function premiere(array $instructions, callable $condition): int|null
    {
        foreach ($instructions as $rang => $instruction) {
            if ($condition($instruction)) {
                return $rang;
            }
        }

        return null;
    }
}
