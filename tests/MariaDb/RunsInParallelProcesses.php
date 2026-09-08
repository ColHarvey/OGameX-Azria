<?php

namespace Tests\MariaDb;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Des processus reellement concurrents, sur la vraie base.
 *
 * ## Pourquoi des processus, et pas deux connexions
 *
 * Une course se prouve par deux acteurs qui *attendent* l'un sur l'autre. Dans un seul processus,
 * la seconde connexion qui bute sur le verrou de la premiere bloque le processus entier — la
 * premiere ne peut plus valider, et l'essai s'interbloque avec lui-meme. Deux processus, chacun sa
 * connexion, chacun sa transaction : c'est la situation exacte du planificateur et d'une page.
 *
 * ## Ce que le parent fait, et ne fait pas
 *
 * Il se **deconnecte avant de bifurquer** : une connexion heritee est un socket partage, que le
 * premier processus a sortir ferme pour l'autre. Chaque enfant ouvre la sienne, attend le signal
 * de depart pour que tous partent ensemble, fait sa tache, ecrit son issue dans un fichier, puis
 * **se tue** : derouler la fin de PHPUnit dans un enfant en ferait un second rapporteur. Le parent
 * se reconnecte, agit pendant que les enfants travaillent s'il a quelque chose a faire, lit les
 * fichiers, et c'est lui seul qui affirme quelque chose.
 *
 * Seule la connexion par defaut est deconnectee : une connexion nommee que le parent tient — pour
 * garder un verrou pendant qu'un enfant l'attend — survit a la bifurcation, et l'enfant, qui ne
 * s'en sert jamais, ne la ferme pas en mourant.
 *
 * Ces epreuves ne s'executent que sur MariaDB : SQLite ne verrouille rien ligne par ligne, et
 * `lockForUpdate()` n'y compile a rien. Elles vivent hors des suites de `phpunit.xml`, dans
 * `phpunit.mariadb.xml`, que seul le workflow MariaDB lance.
 */
trait RunsInParallelProcesses
{
    protected function requiresMariaDb(): void
    {
        $pilote = DB::connection()->getDriverName();
        if ($pilote !== 'mysql') {
            $this->markTestSkipped('This proof only holds on MariaDB; the driver here is ' . $pilote . '.');
        }
    }

    protected function requiresProcesses(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are needed to run concurrent processes.');
        }
    }

    /**
     * Lance la tache dans `$processus` processus a la fois, et rend l'issue de chacun, par rang.
     *
     * `$pendant` est ce que le parent fait une fois les enfants partis, sur sa connexion par defaut
     * reouverte — changer une ligne qu'un enfant attend, relacher un verrou tenu ailleurs.
     *
     * @param Closure(int): string $tache
     * @param Closure(): void|null $pendant
     * @return array<int, string>
     */
    protected function inParallel(int $processus, Closure $tache, Closure|null $pendant = null): array
    {
        $dossier = sys_get_temp_dir() . '/ogamex-course-' . bin2hex(random_bytes(6));
        mkdir($dossier, 0700, true);
        $depart = $dossier . '/depart';

        DB::disconnect();

        $enfants = [];
        for ($rang = 0; $rang < $processus; $rang++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('The process could not fork.');
            }
            if ($pid === 0) {
                $this->runAsChild($rang, $tache, $dossier, $depart);
            }
            $enfants[] = $pid;
        }

        // Tous attendent le meme signal : le depart est aussi simultane que le systeme le permet.
        touch($depart);

        DB::reconnect();
        if ($pendant !== null) {
            $pendant();
        }

        foreach ($enfants as $pid) {
            $statut = 0;
            pcntl_waitpid($pid, $statut);
        }

        DB::reconnect();

        $issues = [];
        for ($rang = 0; $rang < $processus; $rang++) {
            $fichier = $dossier . '/' . $rang . '.txt';
            $this->assertFileExists($fichier, "Process {$rang} reported nothing: it died before writing its outcome.");
            $contenu = (string)file_get_contents($fichier);
            if (str_starts_with($contenu, 'erreur:')) {
                $this->fail("Process {$rang} failed: " . substr($contenu, strlen('erreur:')));
            }
            $issues[$rang] = substr($contenu, strlen('ok:'));
        }

        return $issues;
    }

    /**
     * Attend qu'un autre processus soit en attente d'un verrou — l'enfant qui bute sur ce que le parent
     * tient. Le fait est lu dans la base, pas suppose : sa requete `for update` reste visible dans
     * `information_schema.PROCESSLIST` tant qu'elle attend, et sa transaction se dit `LOCK WAIT` dans
     * `information_schema.INNODB_TRX`. L'un ou l'autre suffit ; un echec dit ce que la base montrait.
     */
    protected function waitUntilAProcessWaitsOnALock(int $timeoutMs = 15_000): string
    {
        return $this->waitUntilAProcessWaitsOnALockOn(null, $timeoutMs);
    }

    /**
     * Attend qu un autre processus bute sur un verrou **de cette table**, ou de n importe laquelle
     * quand aucune n est nommee.
     *
     * ## Pourquoi la table compte
     *
     * Un chemin du jeu prend plusieurs verrous a la suite. Un parent qui relache des le premier
     * venu libere trop tot : l enfant lit alors deja l etat neuf la ou la course voulait qu il lise
     * l ancien, et la mutation que l epreuve devait tuer devient invisible. Mesure : une course de
     * patrouille a laisse passer sa mutation deux fois pour cette raison.
     *
     * ## Nommer l instruction, pas seulement la table
     *
     * Une table ne suffit pas non plus : un meme chemin peut la verrouiller deux fois, a deux
     * profondeurs, et attendre « la table » relache encore au mauvais moment. L attente rend donc
     * **l instruction qu elle a vue**, pour que l epreuve exige la bonne et refuse de conclure d une
     * autre. Une course qui ne dit pas sur quoi elle a attendu ne prouve pas sur quoi elle a porte.
     *
     * @return string L instruction en attente, vide quand seule `INNODB_TRX` a repondu.
     */
    protected function waitUntilAProcessWaitsOnALockOn(string|null $table = null, int $timeoutMs = 15_000): string
    {
        $limite = microtime(true) + $timeoutMs / 1000;
        $attenteBloquee = "SELECT LEFT(trx_query, 400) AS instruction FROM information_schema.INNODB_TRX"
            . " WHERE trx_state = 'LOCK WAIT' AND trx_query LIKE ? LIMIT 1";

        do {
            $motif = $table === null ? '%for update%' : '%' . $table . '%for update%';

            if ($table !== null) {
                // **Un processus qui attend, et ce qu il attend.** `PROCESSLIST` montre
                // l instruction **en cours**, pas celle qui bloque : un chemin voisin qui
                // verrouille la meme table sans etre gene y ressemble trait pour trait, et le
                // parent relacherait alors sur le verrou d un autre. Une transaction en
                // `LOCK WAIT` dont la requete vise cette table nomme les deux a la fois — le
                // processus, et le verrou qu il ne peut pas prendre.
                $bloquee = DB::selectOne($attenteBloquee, [$motif]);

                if ($bloquee !== null && $bloquee->instruction !== null) {
                    return (string)$bloquee->instruction;
                }

                usleep(20_000);

                continue;
            }

            $requete = DB::selectOne("SELECT LEFT(INFO, 400) AS instruction FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE ? LIMIT 1", [$motif]);

            if ($requete !== null && $requete->instruction !== null) {
                return (string)$requete->instruction;
            }

            $transaction = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'");

            if ($transaction !== null && (int)$transaction->n > 0) {
                return '';
            }

            usleep(20_000);
        } while (microtime(true) < $limite);

        $vu = [];
        foreach (DB::select('SELECT ID, COMMAND, TIME, STATE, LEFT(INFO, 120) AS INFO FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID()') as $ligne) {
            $vu[] = implode(' | ', [(string)$ligne->ID, (string)$ligne->COMMAND, (string)$ligne->TIME, (string)$ligne->STATE, (string)$ligne->INFO]);
        }
        foreach (DB::select('SELECT trx_state, LEFT(trx_query, 120) AS trx_query FROM information_schema.INNODB_TRX') as $ligne) {
            $vu[] = 'trx ' . (string)$ligne->trx_state . ' | ' . (string)$ligne->trx_query;
        }

        $this->fail("No process came to wait on the lock: the scenario would prove nothing. Seen:\n" . implode("\n", $vu));
    }

    /**
     * Attend qu'une condition lue en base devienne vraie, ou echoue en le disant.
     *
     * @param Closure(): bool $condition
     */
    protected function waitUntil(Closure $condition, string $sinon, int $timeoutMs = 15_000): void
    {
        $limite = microtime(true) + $timeoutMs / 1000;
        do {
            if ($condition()) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $limite);

        $this->fail($sinon);
    }

    /**
     * @param Closure(int): string $tache
     */
    private function runAsChild(int $rang, Closure $tache, string $dossier, string $depart): never
    {
        try {
            DB::reconnect();
            $attente = 0;
            while (!file_exists($depart) && $attente++ < 5000) {
                usleep(1000);
            }
            $issue = 'ok:' . $tache($rang);
        } catch (Throwable $erreur) {
            $issue = 'erreur:' . $erreur::class . ' : ' . $erreur->getMessage();
        }

        file_put_contents($dossier . '/' . $rang . '.txt', $issue);

        // Sortir sans derouler PHPUnit : le parent seul rapporte. SIGKILL ne laisse aucune fonction
        // d'arret s'executer dans l'enfant.
        posix_kill(posix_getpid(), defined('SIGKILL') ? SIGKILL : 9);
        exit(0);
    }
}
