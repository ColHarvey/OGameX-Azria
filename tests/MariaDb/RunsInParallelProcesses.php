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
 * se reconnecte, lit les fichiers, et c'est lui seul qui affirme quelque chose.
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
     * @param Closure(int): string $tache
     * @return array<int, string>
     */
    protected function inParallel(int $processus, Closure $tache): array
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
