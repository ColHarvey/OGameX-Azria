<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Combat\Services\CombatDurationEstimator;
use OGame\Combat\Support\CombatCalibrationScenarios;
use OGame\GameMissions\BattleEngine\Models\BattleResult;

/**
 * Le tableau qui sert a choisir le coefficient de rythme.
 *
 * La regle de duree vient du moteur de combat ; l'echelle, non. Le rythme convertit une
 * intensite en secondes, et ce nombre-la se choisit en regardant des durees et en disant
 * « trop long » ou « trop court ». Cette commande produit ce qu'il faut pour en decider :
 * quatre batailles fixes, leur detail round par round, et la duree que chaque rythme leur
 * donnerait.
 *
 * Aucune ecriture, aucun effet : elle calcule et affiche.
 */
#[Description('Afficher les durees calculees pour des batailles types, afin de calibrer le rythme')]
#[Signature('ogamex:combat:durees {--rythme=* : un ou plusieurs coefficients a comparer} {--minimum=5 : duree plancher en secondes} {--racine=3 : racine appliquee au travail, pour comprimer l ecart entre petites et grandes batailles} {--detail : detailler chaque round}')]
class CombatDurations extends Command
{
    public function __construct(private readonly CombatDurationEstimator $calculateur)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rythmes = $this->rythmes();
        $minimum = (int)$this->option('minimum');
        $racine = (float)$this->option('racine');
        $scenarios = CombatCalibrationScenarios::all();

        $this->line('');
        $this->line('  <options=bold>Calibrage de la duree des combats</>');
        $this->line('  <fg=gray>Le rythme est le travail consomme par seconde. Plus il est grand, plus les combats sont courts.</>');
        $this->line('  <fg=gray>Duree plancher : ' . $minimum . ' s — aucun plafond, par decision explicite.' . ($racine > 1.0 ? ' Amortissement : racine ' . $racine . '.' : ' Aucun amortissement.') . '</>');
        $this->line('');

        $entetes = ['Scenario', 'Rounds', 'Travail'];

        foreach ($rythmes as $rythme) {
            $entetes[] = 'rythme ' . $this->exposant($rythme);
        }

        $rangees = [];

        foreach ($scenarios as $nom => $bataille) {
            $reference = $this->calculateur->estimate($bataille, $rythmes[0], $minimum, $racine);

            $rangee = [
                $nom,
                (string)count($reference->rounds),
                $this->exposant($reference->totalWork),
            ];

            foreach ($rythmes as $rythme) {
                $estimation = $this->calculateur->estimate($bataille, $rythme, $minimum, $racine);
                $duree = $this->duree($estimation->seconds);

                if ($estimation->implausible) {
                    $duree = '<fg=red>' . $duree . '</>';
                } elseif ($estimation->minimumApplied) {
                    $duree = '<fg=gray>' . $duree . ' (plancher)</>';
                }

                $rangee[] = $duree;
            }

            $rangees[] = $rangee;
        }

        $this->table($entetes, $rangees);

        if ($this->option('detail')) {
            $this->detailler($scenarios, $rythmes[0], $minimum, $racine);
        }

        $this->line('');
        $this->line('  <fg=gray>En rouge : une duree qui depasse le seuil d\'alerte technique (' . $this->duree(CombatDurationEstimator::IMPLAUSIBLE_SECONDS) . ').</>');
        $this->line('  <fg=gray>Elle n\'est jamais rabotee — c\'est le signe que le rythme est mal calibre.</>');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Show, per round, the figures that produced the work.
     *
     * @param array<string, BattleResult> $scenarios
     * @param float $rythme
     * @param int $minimum
     * @param float $racine
     * @return void
     */
    private function detailler(array $scenarios, float $rythme, int $minimum, float $racine): void
    {
        foreach ($scenarios as $nom => $bataille) {
            $estimation = $this->calculateur->estimate($bataille, $rythme, $minimum, $racine);

            $this->line('');
            $this->line('  <options=bold>' . $nom . '</> <fg=gray>— ' . $this->duree($estimation->seconds) . '</>');

            $rangees = [];

            foreach ($estimation->rounds as $round) {
                $rangees[] = [
                    (string)$round->number,
                    number_format($round->hitsAttacker, 0, ',', ' '),
                    number_format($round->hitsDefender, 0, ',', ' '),
                    $this->exposant($round->shieldPressure),
                    number_format($round->balance, 3, ',', ' '),
                    $this->exposant($round->work),
                    $this->duree($round->seconds),
                ];
            }

            $this->table(
                ['Round', 'Tirs att.', 'Tirs def.', 'Boucliers', 'Equilibre', 'Travail', 'Duree'],
                $rangees
            );
        }
    }

    /**
     * Read the rates to compare, with a spread of defaults.
     *
     * @return array<int, float>
     */
    private function rythmes(): array
    {
        /** @var array<int, string> $donnes */
        $donnes = (array)$this->option('rythme');

        if ($donnes === []) {
            // Le rythme retenu, encadre par les deux voisins examines puis ecartes.
            return [3000.0, 2083.0, 1500.0];
        }

        return array_map(static fn (string $r): float => (float)$r, $donnes);
    }

    /**
     * Render a large number in a form the eye can compare.
     */
    private function exposant(float $valeur): string
    {
        if ($valeur === 0.0) {
            return '0';
        }

        $puissance = (int)floor(log10(abs($valeur)));

        if ($puissance < 6) {
            return number_format($valeur, 0, ',', ' ');
        }

        return number_format($valeur / (10 ** $puissance), 2, ',', ' ') . 'e' . $puissance;
    }

    /**
     * Render a duration the way the game shows it.
     */
    private function duree(int $secondes): string
    {
        if ($secondes <= 0) {
            return 'immediat';
        }

        $unites = ['j' => 86400, 'h' => 3600, 'min' => 60, 's' => 1];
        $reste = $secondes;
        $morceaux = [];

        foreach ($unites as $nom => $taille) {
            $valeur = intdiv($reste, $taille);
            $reste -= $valeur * $taille;

            if ($valeur > 0) {
                $morceaux[] = $valeur . ' ' . $nom;
            }
        }

        return implode(' ', array_slice($morceaux, 0, 2));
    }
}
