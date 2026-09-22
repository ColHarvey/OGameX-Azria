<?php

namespace Tests\MariaDb;

use Closure;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet\Coordinate;
use OGame\Models\WreckField;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;

/**
 * Les courses du circuit des epaves : demarrer, bruler, etendre, recuperer — deux acteurs a la fois, sur MariaDB.
 *
 * ## Ce que le bac prouve, et que SQLite ne peut pas
 *
 * `startRepairs()` et `burnWreckField()` verifiaient puis sauvaient, sans transaction ni verrou : deux commandes
 * simultanees passaient toutes deux leurs controles sur l'etat d'avant, puis ecrivaient toutes deux. Sous SQLite,
 * `lockForUpdate()` ne compile a rien et deux processus n'attendent jamais l'un sur l'autre : le mecanisme se joue en
 * deux etapes dans `tests/Feature/WreckFieldTransitionInterleavingTest.php`, la course se joue ici.
 *
 * ## Comment la course est forcee, et non esperee
 *
 * Le parent tient un verrou sur une connexion a part — la ligne de l'epave, ou celle de la planete —, lance les
 * enfants, et ne le relache que lorsque la base montre les deux arretes. **Avant la correction**, une commande
 * n'etait arretee qu'a sa premiere ecriture : ses controles avaient deja passe sur l'etat d'avant, et la relache
 * faisait ecrire les deux. **Apres**, une commande commence par tenir la planete puis relire l'epave sous verrou :
 * elle est arretee avant de decider — sur la planete, ou sur l'epave —, et la relache fait decider l'une puis
 * l'autre, sur l'etat que la premiere a laisse. Une meme attente — « arrete au moins une seconde sur une instruction
 * qui touche l'epave ou la planete » — couvre les deux mondes ; la branche jetable `wip/jetable-epaves-avant` porte
 * la version d'avant, qui n'attendait que sur l'epave.
 *
 * ## L'ordre des verrous
 *
 * Le reglement d'une bataille tient ses corps avant d'ecrire l'epave. Une recuperation qui prenait le champ puis la
 * planete formait un cycle avec lui : MariaDB tranchait l'interblocage en tuant l'un des deux. Le dernier temoin
 * rejoue ce cycle precis et exige qu'aucun des deux ne meure. Il ne dit rien d'autre : c'est la correction du cycle
 * reproduit, pas une preuve qu'aucun interblocage n'est possible ailleurs dans le jeu.
 *
 * La regle qui en sort est etroite et se verifie a la lecture : **aucun chemin ne prend une epave puis une planete**.
 * Les commandes du joueur prennent la ou les planetes puis l'epave ; la creation par une bataille ne prend que
 * l'epave — lui faire prendre aussi la planete ajoutait, pour une cible lune, la seule ligne que le reglement n'avait
 * pas listee avant ses epaves, donc un cycle neuf.
 */
#[Group('mariadb')]
final class WreckFieldTransitionRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    /**
     * Assez de vaisseaux pour que la duree de reparation depende du nombre : sous le plancher de trente minutes,
     * 4000 et 6000 vaisseaux donneraient le meme minuteur, et « juste » et « faux » coincideraient.
     */
    private const int SHIPS = 4000;

    private const int MORE_SHIPS = 2000;

    private const int REPAIRED_SHIPS = 40;

    /**
     * Les deux tables sur lesquelles une commande peut etre arretee : l'epave (avant la correction, a son ecriture ;
     * apres, a sa relecture sous verrou) et la planete (apres la correction, tenue en premier).
     */
    private const array TABLES = ['wreck_fields', 'planets'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->wreckFieldsHere()->delete();
        DB::table('planets')->where('id', $this->planetService->getPlanetId())->update(['space_dock' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_temoin');
        $this->wreckFieldsHere()->delete();
        parent::tearDown();
    }

    public function testTwoSimultaneousStartsOnTheSameWreckStartOneRepairAndWriteOneTimer(): void
    {
        $champ = $this->anActiveWreck(self::SHIPS);
        $temoin = $this->aConnectionHolding('wreck_fields', $champ->id);
        [$joueur, $ici] = [$this->currentUserId, $this->here()];

        $issues = $this->inParallel(
            2,
            static fn (int $rang): string => self::startThrough($joueur, $ici),
            function () use ($temoin): void {
                $this->waitUntilProcessesAreStoppedOn(self::TABLES, 2);
                $temoin->commit();
            }
        );

        sort($issues);
        $this->assertSame('demarree', $issues[0], 'Neither start was written: ' . implode(' / ', $issues));
        $this->assertStringStartsWith('refusee:', $issues[1], 'Both starts were written on the same wreck: ' . implode(' / ', $issues));
        $this->assertSame('repairing', $this->statusOf($champ));
        $this->assertSame(1, $this->wreckFieldsHere()->where('status', 'repairing')->count());
    }

    public function testAStartAndABurnAtOnceLeaveExactlyOneOutcome(): void
    {
        $champ = $this->anActiveWreck(self::SHIPS);
        $temoin = $this->aConnectionHolding('wreck_fields', $champ->id);
        [$joueur, $ici] = [$this->currentUserId, $this->here()];

        $issues = $this->inParallel(
            2,
            static fn (int $rang): string => $rang === 0 ? self::startThrough($joueur, $ici) : self::burnThrough($joueur, $ici),
            function () use ($temoin): void {
                $this->waitUntilProcessesAreStoppedOn(self::TABLES, 2);
                $temoin->commit();
            }
        );

        $faites = array_values(array_filter($issues, static fn (string $issue): bool => in_array($issue, ['demarree', 'brulee'], true)));
        $this->assertCount(1, $faites, 'Both commands reported success, or neither: ' . implode(' / ', $issues));

        $etat = $this->statusOf($champ);
        $minuteur = DB::table('wreck_fields')->where('id', $champ->id)->value('repair_started_at');
        if ($faites[0] === 'demarree') {
            $this->assertSame('repairing', $etat, 'The start was reported done and the wreck is not repairing.');
            $this->assertNotNull($minuteur);
        } else {
            $this->assertSame('burned', $etat, 'The burn was reported done and the wreck is not burned.');
            $this->assertNull($minuteur, 'A burned wreck carries a repair timer: a start was written under the burn.');
        }
    }

    /**
     * Deux epaves du meme proprietaire aux memes coordonnees : une seule peut etre en reparation a la fois.
     *
     * `hasRepairingWreckFieldAt()` porte cette regle ; deux demarrages qui la lisent avant que l'un ait ecrit la
     * violent tous les deux. Le second ne peut pas viser l'autre epave par les coordonnees : il la charge par son
     * identifiant.
     */
    public function testTwoStartsOnTwoWrecksOfTheSameOwnerAtOnceLeaveOneRepairing(): void
    {
        $premiere = $this->anActiveWreck(self::SHIPS);
        $seconde = $this->anActiveWreck(self::SHIPS);
        $temoin = $this->aConnectionHolding('wreck_fields', $premiere->id);
        $joueur = $this->currentUserId;
        [$idPremiere, $idSeconde] = [(int)$premiere->id, (int)$seconde->id];

        $issues = $this->inParallel(
            2,
            static fn (int $rang): string => self::startByIdThrough($joueur, $rang === 0 ? $idPremiere : $idSeconde),
            function () use ($temoin, $seconde): void {
                // Avant la correction, le demarrage de la seconde epave n'attendait personne : il etait deja ecrit
                // quand le premier etait arrete. Apres, les deux tiennent la planete a tour de role et attendent.
                $this->waitUntilProcessesAreStoppedOn(self::TABLES, 1);
                $this->waitUntilProcessesAreStoppedOn(self::TABLES, 2, fn (): bool => $this->statusOf($seconde) === 'repairing');
                $temoin->commit();
            }
        );

        $enReparation = $this->wreckFieldsHere()->where('status', 'repairing')->count();
        $this->assertSame(1, $enReparation, 'Two wrecks of the same owner are repairing at the same coordinates: ' . implode(' / ', $issues));
        sort($issues);
        $this->assertSame('demarree', $issues[0]);
        $this->assertStringStartsWith('refusee:', $issues[1]);
    }

    /**
     * Une bataille qui etend l'epave et un demarrage au meme instant : le minuteur couvre exactement les vaisseaux de l'epave.
     *
     * Deux mondes sont justes — l'extension puis le demarrage (une epave en reparation, 6000 vaisseaux, un minuteur pour
     * 6000), ou le demarrage puis la bataille (une epave en reparation pour 4000, une epave bloquee de 2000). Le monde
     * faux est celui des deux ecritures croisees : 6000 vaisseaux sous un minuteur calcule pour 4000.
     */
    public function testABattleExtensionAndAStartAtOnceKeepTheTimerTrueToTheShipsItCovers(): void
    {
        $champ = $this->anActiveWreck(self::SHIPS);
        $temoin = $this->aConnectionHolding('wreck_fields', $champ->id);
        [$joueur, $ici] = [$this->currentUserId, $this->here()];

        $issues = $this->inParallel(
            2,
            static fn (int $rang): string => $rang === 0 ? self::extendThrough($joueur, $ici, self::MORE_SHIPS) : self::startThrough($joueur, $ici),
            function () use ($temoin): void {
                $this->waitUntilProcessesAreStoppedOn(self::TABLES, 2);
                $temoin->commit();
            }
        );

        $this->assertContains('demarree', $issues, 'The start was refused although a wreck was there to repair: ' . implode(' / ', $issues));

        $champs = $this->wreckFieldsHere()->whereIn('status', ['active', 'repairing', 'blocked'])->get();
        $total = 0;
        foreach ($champs as $present) {
            $total += $present->getTotalShips();
        }
        $this->assertSame(self::SHIPS + self::MORE_SHIPS, $total, 'Ships were lost or duplicated between the extension and the start.');

        $enReparation = $champs->where('status', 'repairing');
        $this->assertCount(1, $enReparation, 'Exactly one wreck should be repairing.');
        /** @var WreckField $reparee */
        $reparee = $enReparation->first();
        $this->assertNotNull($reparee->repair_started_at);
        $this->assertNotNull($reparee->repair_completed_at);
        $ecrite = (int)$reparee->repair_completed_at->timestamp - (int)$reparee->repair_started_at->timestamp;
        $this->assertSame(
            $this->repairDurationFor($reparee->getTotalShips()),
            $ecrite,
            'The repair timer covers another ship count than the wreck holds: an extension was merged under a timer set for fewer ships.'
        );

        if ($reparee->getTotalShips() === self::SHIPS) {
            $bloquee = $champs->where('status', 'blocked');
            $this->assertCount(1, $bloquee, 'The start came first, so the battle should have left a separate blocked wreck.');
            $this->assertSame(self::MORE_SHIPS, $bloquee->first()?->getTotalShips());
        } else {
            $this->assertCount(1, $champs, 'The extension came first, so a single merged wreck should remain.');
        }
    }

    /**
     * Une recuperation et un brulage au meme instant : les vaisseaux sont deployes ou brules, jamais les deux.
     *
     * Le parent tient la planete. Avant la correction, la recuperation tenait deja l'epave et attendait la planete, le
     * brulage s'arretait a son ecriture ; relaches, la recuperation deployait et effacait l'epave, puis le brulage
     * ecrivait sur une ligne disparue — et se disait fait. Apres, les deux attendent la planete et decident l'un
     * apres l'autre.
     */
    public function testACollectionAndABurnAtOnceDeployOrBurnTheShipsNeverBoth(): void
    {
        $champ = $this->aCompletedWreck(self::REPAIRED_SHIPS);
        $planete = $this->planetService->getPlanetId();
        $avant = (int)DB::table('planets')->where('id', $planete)->value('light_fighter');
        $temoin = $this->aConnectionHolding('planets', $planete);
        [$joueur, $ici] = [$this->currentUserId, $this->here()];

        $issues = $this->inParallel(
            2,
            static fn (int $rang): string => $rang === 0 ? self::collectThrough($joueur, $ici, $planete) : self::burnThrough($joueur, $ici),
            function () use ($temoin, $champ): void {
                $this->waitUntilProcessesAreStoppedOn(self::TABLES, 2, fn (): bool => $this->statusOf($champ) === 'burned');
                $temoin->commit();
            }
        );

        $faites = array_values(array_filter($issues, static fn (string $issue): bool => $issue === 'brulee' || str_starts_with($issue, 'recuperee:')));
        $this->assertCount(1, $faites, 'Both commands reported success, or neither: ' . implode(' / ', $issues));

        $apres = (int)DB::table('planets')->where('id', $planete)->value('light_fighter');
        if ($faites[0] === 'brulee') {
            $this->assertSame('burned', $this->statusOf($champ));
            $this->assertSame($avant, $apres, 'Ships were deployed although the wreck was burned.');
        } else {
            $this->assertSame('recuperee:' . self::REPAIRED_SHIPS, $faites[0]);
            $this->assertSame($avant + self::REPAIRED_SHIPS, $apres, 'The collection reported ships it did not deploy.');
            $this->assertSame('', $this->statusOf($champ), 'The collected wreck should be gone.');
        }
    }

    /**
     * Le reglement d'une bataille tient la planete puis ecrit l'epave ; une recuperation qui prenait l'epave puis la
     * planete formait un cycle avec lui. Aucun des deux ne doit mourir.
     */
    public function testABattleHoldingThePlanetAndACollectionNeverDeadlock(): void
    {
        $champ = $this->aCompletedWreck(self::REPAIRED_SHIPS);
        $planete = $this->planetService->getPlanetId();
        $avant = (int)DB::table('planets')->where('id', $planete)->value('light_fighter');
        $temoin = $this->aConnectionHolding('planets', $planete);
        [$joueur, $ici] = [$this->currentUserId, $this->here()];
        $ecriture = 'jamais tentee';

        $issues = $this->inParallel(
            1,
            static fn (int $rang): string => self::collectThrough($joueur, $ici, $planete),
            function () use ($temoin, $champ, &$ecriture): void {
                $this->waitUntilAProcessWaitsOnALockOn('planets');

                // La bataille ecrit maintenant l'epave, la planete toujours tenue — comme `createWreckField()` le fait.
                try {
                    $temoin->table('wreck_fields')->where('id', $champ->id)->update(['expires_at' => now()->addHours(72)]);
                    $ecriture = 'ecrite';
                } catch (QueryException $erreur) {
                    $ecriture = 'interblocage:' . $erreur->getMessage();
                }

                try {
                    $temoin->commit();
                } catch (Exception) {
                    // Une transaction tuee par l'interblocage n'a plus rien a valider ; le verdict est deja dans $ecriture.
                }
            }
        );

        $this->assertSame('ecrite', $ecriture, 'The battle s write on the wreck died in a deadlock with the collection.');
        $this->assertSame('recuperee:' . self::REPAIRED_SHIPS, $issues[0], 'The collection did not complete once the battle released the planet.');
        $this->assertSame($avant + self::REPAIRED_SHIPS, (int)DB::table('planets')->where('id', $planete)->value('light_fighter'));
        $this->assertSame('', $this->statusOf($champ), 'The collected wreck should be gone.');
    }

    /**
     * Le parent tient une ligne sur une connexion a part, que la bifurcation ne ferme pas.
     */
    private function aConnectionHolding(string $table, int $id): ConnectionInterface
    {
        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);
        $temoin = DB::connection('mysql_temoin');
        $temoin->beginTransaction();
        $this->assertNotNull($temoin->table($table)->where('id', $id)->lockForUpdate()->first(), 'The row to hold does not exist.');

        return $temoin;
    }

    /**
     * Attend que `$combien` autres processus soient arretes depuis au moins une seconde sur une instruction qui
     * touche l'une de ces tables — une lecture verrouillante comme une ecriture —, ou que `$ouBien` soit vrai.
     *
     * @param array<int, string> $tables
     * @param Closure(): bool|null $ouBien
     */
    private function waitUntilProcessesAreStoppedOn(array $tables, int $combien, Closure|null $ouBien = null, int $timeoutMs = 15_000): void
    {
        $limite = microtime(true) + $timeoutMs / 1000;
        $condition = implode(' OR ', array_fill(0, count($tables), 'INFO LIKE ?'));
        $motifs = array_map(static fn (string $table): string => '%' . $table . '%', $tables);

        do {
            $vus = (int)(DB::selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND (' . $condition . ') AND TIME >= 1',
                $motifs
            )->n ?? 0);

            if ($vus >= $combien || ($ouBien !== null && $ouBien())) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $limite);

        $vu = [];
        foreach (DB::select('SELECT ID, COMMAND, TIME, STATE, LEFT(INFO, 160) AS INFO FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID()') as $ligne) {
            $vu[] = implode(' | ', [(string)$ligne->ID, (string)$ligne->COMMAND, (string)$ligne->TIME, (string)$ligne->STATE, (string)$ligne->INFO]);
        }

        $this->fail("Fewer than {$combien} processes came to a stop on " . implode('/', $tables) . ": the race would not be forced. Seen:\n" . implode("\n", $vu));
    }

    private static function aCommandFor(int $joueur): WreckFieldService
    {
        return new WreckFieldService(resolve(PlayerServiceFactory::class)->make($joueur, true), resolve(SettingsService::class));
    }

    private static function startThrough(int $joueur, Coordinate $ou): string
    {
        $commande = self::aCommandFor($joueur);
        if (!$commande->loadForCoordinates($ou)) {
            return 'refusee:aucune epave';
        }

        try {
            $commande->startRepairs(1);

            return 'demarree';
        } catch (Exception $refus) {
            return 'refusee:' . $refus->getMessage();
        }
    }

    private static function startByIdThrough(int $joueur, int $id): string
    {
        $commande = self::aCommandFor($joueur);
        if (!$commande->loadById($id)) {
            return 'refusee:aucune epave';
        }

        try {
            $commande->startRepairs(1);

            return 'demarree';
        } catch (Exception $refus) {
            return 'refusee:' . $refus->getMessage();
        }
    }

    private static function burnThrough(int $joueur, Coordinate $ou): string
    {
        $commande = self::aCommandFor($joueur);
        if (!$commande->loadForCoordinates($ou)) {
            return 'refusee:aucune epave';
        }

        try {
            $commande->burnWreckField();

            return 'brulee';
        } catch (Exception $refus) {
            return 'refusee:' . $refus->getMessage();
        }
    }

    private static function extendThrough(int $joueur, Coordinate $ou, int $vaisseaux): string
    {
        $champ = self::aCommandFor($joueur)->createWreckField($ou, [
            ['machine_name' => 'light_fighter', 'quantity' => $vaisseaux, 'repair_progress' => 0],
        ], $joueur);

        return 'etendue:' . $champ->id . ':' . $champ->status;
    }

    private static function collectThrough(int $joueur, Coordinate $ou, int $planete): string
    {
        $issue = self::aCommandFor($joueur)->collectRepairedShipsAtomic($ou, $planete);
        if (!$issue['success']) {
            return 'refusee:' . $issue['message'];
        }

        $deployes = 0;
        foreach ($issue['collected_ships'] as $vaisseau) {
            $deployes += (int)$vaisseau['quantity'];
        }

        return 'recuperee:' . $deployes;
    }

    /**
     * La duree que `WreckFieldService::calculateRepairDuration()` ecrit, epinglee ici pour que le minuteur soit compare a
     * une valeur independante du code qui l'ecrit.
     */
    private function repairDurationFor(int $vaisseaux): int
    {
        $reglages = resolve(SettingsService::class);
        $plancher = max(0, $reglages->wreckFieldRepairMinMinutes()) * 60;
        $heures = $reglages->wreckFieldRepairMaxHours() <= 0 ? 12 : $reglages->wreckFieldRepairMaxHours();
        $plafond = max($plancher, $heures * 3600);

        return min($plafond, max($plancher, (int)round(sqrt($vaisseaux * 30) * 10)));
    }

    private function anActiveWreck(int $vaisseaux): WreckField
    {
        $ici = $this->here();

        /** @var WreckField $champ */
        $champ = WreckField::factory()->create([
            'galaxy' => $ici->galaxy,
            'system' => $ici->system,
            'planet' => $ici->position,
            'owner_player_id' => $this->currentUserId,
            'status' => 'active',
            'created_at' => now()->subHour(),
            'expires_at' => now()->addHours(72),
            'ship_data' => [
                ['machine_name' => 'light_fighter', 'quantity' => $vaisseaux, 'repair_progress' => 0],
            ],
        ]);

        return $champ;
    }

    private function aCompletedWreck(int $vaisseaux): WreckField
    {
        $ici = $this->here();

        /** @var WreckField $champ */
        $champ = WreckField::factory()->completed()->create([
            'galaxy' => $ici->galaxy,
            'system' => $ici->system,
            'planet' => $ici->position,
            'owner_player_id' => $this->currentUserId,
            'created_at' => now()->subHours(3),
            'expires_at' => now()->addHours(72),
            'ship_data' => [
                ['machine_name' => 'light_fighter', 'quantity' => $vaisseaux, 'repair_progress' => 100],
            ],
        ]);

        return $champ;
    }

    private function statusOf(WreckField $champ): string
    {
        return (string)DB::table('wreck_fields')->where('id', $champ->id)->value('status');
    }

    private function here(): Coordinate
    {
        return $this->planetService->getPlanetCoordinates();
    }

    /**
     * @return Builder<WreckField>
     */
    private function wreckFieldsHere()
    {
        $ici = $this->here();

        return WreckField::query()->where('galaxy', $ici->galaxy)->where('system', $ici->system)->where('planet', $ici->position);
    }
}
