<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Presentation\CombatPresentationBroadcaster;

/**
 * Envoie les pertes devenues visibles, a la seconde.
 *
 * ## Pourquoi une boucle, et pas un passage par minute
 *
 * Une perte devient visible a la fin de la periode de son round — quelques secondes apres la
 * precedente. Un passage par minute ferait attendre le joueur jusqu'a cinquante-neuf secondes ; la
 * commande boucle donc pendant sa minute, en regardant l'heure chaque seconde.
 *
 * Elle est planifiee chaque minute avec `withoutOverlapping()` : la boucle sortante et la suivante
 * ne se chevauchent pas, et si un passage meurt, le suivant reprend ce qui n'est pas parti — rien
 * n'est perdu, puisque c'est la colonne `broadcast_at` qui dit ce qui reste a faire.
 *
 * `--duree` et `--pas` existent pour les essais et l'exploitation ; le jeu ne les emploie pas.
 */
#[Description('Diffuser aux joueurs les pertes de combat devenues visibles')]
#[Signature('ogamex:combat:diffuser {--duree=55 : Secondes de veille} {--pas=1 : Secondes entre deux regards}')]
class BroadcastCombatLosses extends Command
{
    public function handle(CombatPresentationBroadcaster $diffuseur): int
    {
        $duree = max(0, (int)$this->option('duree'));
        $pas = max(1, (int)$this->option('pas'));
        $fin = (int)Date::now()->timestamp + $duree;
        $total = 0;

        do {
            $total += $diffuseur->publish((int)Date::now()->timestamp);

            if ((int)Date::now()->timestamp >= $fin) {
                break;
            }

            sleep($pas);
        } while (true);

        if ($total > 0) {
            $this->line('  ' . $total . ' perte(s) diffusee(s).');
        }

        return self::SUCCESS;
    }
}
