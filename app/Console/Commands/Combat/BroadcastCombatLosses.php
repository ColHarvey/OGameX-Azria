<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Presentation\CombatPresentationBroadcaster;

/**
 * Envoie les pertes et les changements d'etat devenus visibles, a la seconde.
 *
 * ## Pourquoi une boucle, et pas un passage par minute
 *
 * Une perte devient visible a la fin de la periode de son round — quelques secondes apres la
 * precedente. Un passage par minute ferait attendre le joueur jusqu'a cinquante-neuf secondes ; la
 * commande boucle donc pendant sa minute, en regardant l'heure chaque seconde.
 *
 * ## La jonction entre deux minutes
 *
 * Elle est planifiee chaque minute avec `withoutOverlapping()`. Si un passage debordait sur la
 * minute suivante, le tick suivant serait **saute**, et personne ne veillerait pendant une minute
 * entiere. La veille s'arrete donc **avant la frontiere de la minute** ou elle a commence, avec une
 * marge — jamais a « N secondes apres le depart », qui deborderait des que le planificateur part
 * en retard. Ce qui devient visible dans la marge part au tick suivant : la source est durable, et
 * rien ne se perd. Le planificateur du serveur derive de quelques secondes par tick ; ce creux-la
 * se mesure, il ne se suppose pas.
 *
 * `--duree` court-circuite cette regle pour les essais et l'exploitation ; `0` fait un seul passage.
 */
#[Description('Diffuser aux joueurs les pertes de combat et les changements d etat devenus visibles')]
#[Signature('ogamex:combat:diffuser {--duree= : Secondes de veille, vide pour veiller jusqu a la fin de la minute} {--pas=1 : Secondes entre deux regards}')]
class BroadcastCombatLosses extends Command
{
    /**
     * Secondes gardees avant la frontiere de la minute, pour ne jamais deborder sur le tick suivant.
     */
    public const MARGIN = 2;

    public function handle(CombatPresentationBroadcaster $diffuseur): int
    {
        $pas = max(1, (int)$this->option('pas'));
        $duree = $this->option('duree');
        $maintenant = (int)Date::now()->timestamp;
        $fin = $duree === null || $duree === ''
            ? self::endOfWatch($maintenant)
            : $maintenant + max(0, (int)$duree);
        $pertes = 0;
        $etats = 0;

        do {
            $instant = (int)Date::now()->timestamp;
            $etats += $diffuseur->publishStateChanges($instant);
            $pertes += $diffuseur->publish($instant);

            if ((int)Date::now()->timestamp >= $fin) {
                break;
            }

            sleep($pas);
        } while (true);

        if ($pertes > 0 || $etats > 0) {
            $this->line('  ' . $pertes . ' perte(s) et ' . $etats . ' etat(s) diffuses.');
        }

        return self::SUCCESS;
    }

    /**
     * L'instant ou une veille commencee a cet instant doit s'arreter : la frontiere de la minute en
     * cours, moins la marge. Jamais au-dela de cette minute, quelle que soit l'heure du depart.
     */
    public static function endOfWatch(int $now): int
    {
        $frontiere = (intdiv($now, 60) + 1) * 60;

        return $frontiere - self::MARGIN;
    }
}
