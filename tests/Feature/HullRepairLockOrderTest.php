<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Hull\DamagedHulls;
use OGame\Hull\HullRepairService;
use OGame\Models\HullRepairOrder;
use OGame\Models\Resources;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le corps avant l ordre — l ordre global des verrous du chantier spatial.
 *
 * ## La regle, et ce qu elle a coute
 *
 * Trois chemins touchent a la fois la ligne d un corps et celle d un ordre de reparation :
 *
 * - la confirmation d une reparation verrouille le corps, puis insere l ordre ;
 * - un depart de flotte verrouille le corps, puis lit ce que le dock retient ;
 * - le reglement d une bataille verrouille les corps — `CombatSettlementService` le fait avant
 *   `resolve()` — puis clot le chantier par `endAnyRunningOn()`.
 *
 * Les trois vont dans le meme sens : **corps, puis ordre**. La fin anticipee, elle, allait dans
 * l autre — ordre, puis corps. Avec le reglement d une bataille, cela faisait un interblocage ABBA :
 * chacun tenant ce que l autre attend. MariaDB en tue un, et le tue aurait ete le reglement de la
 * bataille, mis en echec par une annulation de reparation arrivee au mauvais instant.
 *
 * ## Ce que ce temoin prouve, et ce qu il ne prouve pas
 *
 * Il prouve la **forme** : les instructions partent dans le bon ordre. Il ne prouve pas l **effet**,
 * et ne pretend pas le faire — `lockForUpdate()` ne compile a rien sous SQLite, et un verrou qui
 * n existe pas ne s interbloque avec rien. L effet est etabli sur le bac MariaDB par
 * `Tests\MariaDb\HullRepairRaceTest::testACombatClosureAndAPlayerCancellationNeverDeadlock`.
 *
 * Sa valeur est ailleurs : il tombe le jour ou quelqu un remet les deux verrous dans l autre sens,
 * sans attendre que le bac le dise.
 */
class HullRepairLockOrderTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('hull_damage_enabled', '1');

        // La base d un processus garde les ordres des essais voisins : un ordre etranger encore en
        // cours ferait echouer la confirmation pour une raison qui n est pas celle-ci.
        HullRepairOrder::query()->delete();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('hull_damage_enabled', '0');

        parent::tearDown();
    }

    public function testAnEarlyEndingTakesTheBodyBeforeTheOrder(): void
    {
        $this->planetService->addUnit('cruiser', 20);
        $this->planetService->setObjectLevel(36, 5, true);
        $this->planetService->writeDamagedHulls(DamagedHulls::of(['cruiser' => [5000 => 8]]));
        $this->planetService->addResources(new Resources(5_000_000, 5_000_000, 5_000_000, 0));

        $reparations = resolve(HullRepairService::class);
        $ordre = $reparations->confirm(
            $this->planetService,
            DamagedHulls::of(['cruiser' => [5000 => 8]]),
            '',
            (int)Date::now()->timestamp
        );

        // **La citation est normalisee avant d etre cherchee.** SQLite cite en guillemets doubles,
        // MariaDB en accents graves : huit essais de verrous de ce depot ne voyaient rien sur le bac
        // pour avoir cherche une seule des deux formes.
        $suivies = ['planets', 'hull_repair_orders'];
        $vues = [];

        DB::listen(function (QueryExecuted $requete) use (&$vues, $suivies): void {
            $sql = str_replace('`', '"', $requete->sql);

            foreach ($suivies as $table) {
                if (str_contains($sql, '"' . $table . '"') && !in_array($table, $vues, true)) {
                    $vues[] = $table;
                }
            }
        });

        $this->assertTrue(
            $reparations->endEarly($ordre, HullRepairOrder::BECAUSE_PLAYER, (int)Date::now()->timestamp + 1),
            'La fin anticipee devait aboutir : sans elle, l ordre des instructions ne dit rien.'
        );

        $this->assertSame(
            'planets',
            $vues[0] ?? null,
            'La fin anticipee doit atteindre le corps en premier ; elle a commence par : ' . implode(' → ', $vues)
        );
        $this->assertSame(
            'hull_repair_orders',
            $vues[1] ?? null,
            'L ordre doit venir juste apres le corps ; la sequence observee : ' . implode(' → ', $vues)
        );
    }

    /**
     * Et la confirmation, qui fixe le sens de tout le reste, garde le sien.
     *
     * Elle est la reference : c est parce qu elle prend le corps en premier — elle doit relire le
     * niveau du dock et les degats disponibles sous le verrou — que la fin anticipee a du s aligner
     * sur elle, et non l inverse.
     */
    public function testAConfirmationTakesTheBodyBeforeTheOrder(): void
    {
        $this->planetService->addUnit('cruiser', 20);
        $this->planetService->setObjectLevel(36, 5, true);
        $this->planetService->writeDamagedHulls(DamagedHulls::of(['cruiser' => [5000 => 8]]));
        $this->planetService->addResources(new Resources(5_000_000, 5_000_000, 5_000_000, 0));

        $suivies = ['planets', 'hull_repair_orders'];
        $vues = [];

        DB::listen(function (QueryExecuted $requete) use (&$vues, $suivies): void {
            $sql = str_replace('`', '"', $requete->sql);

            foreach ($suivies as $table) {
                if (str_contains($sql, '"' . $table . '"') && !in_array($table, $vues, true)) {
                    $vues[] = $table;
                }
            }
        });

        resolve(HullRepairService::class)->confirm(
            $this->planetService,
            DamagedHulls::of(['cruiser' => [5000 => 8]]),
            '',
            (int)Date::now()->timestamp
        );

        $this->assertSame(
            'planets',
            $vues[0] ?? null,
            'La confirmation doit atteindre le corps en premier ; elle a commence par : ' . implode(' → ', $vues)
        );
    }
}
