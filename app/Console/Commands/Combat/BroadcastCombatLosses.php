<?php

namespace OGame\Console\Commands\Combat;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Presentation\CombatBroadcasterLease;
use OGame\Combat\Presentation\CombatPresentationBroadcaster;

/**
 * Envoie les pertes et les changements d'etat devenus visibles, a la seconde.
 *
 * ## Pourquoi une boucle, et pas un passage par minute
 *
 * Une perte devient visible a la fin de la periode de son round — quelques secondes apres la
 * precedente. Un passage par minute ferait attendre le joueur jusqu'a cinquante-neuf secondes ; la
 * commande regarde donc l'heure chaque seconde.
 *
 * ## Pourquoi un diffuseur continu, et pas une veille bornee a la minute
 *
 * L'entrypoint du serveur enchaine `schedule:run` puis `sleep 60`. Les commandes planifiees
 * s'executent **en ligne** dans `schedule:run` : la periode reelle d'un tick vaut donc soixante
 * secondes plus la duree de ce qui tourne, et un tick peut ne jamais tomber dans une minute
 * donnee — `everyMinute()` la saute alors entierement. Une veille bornee a sa minute laissait ainsi
 * des creux d'une minute a la jonction, pas de deux secondes. Ce chemin est celui du fichier
 * `docker/entrypoint.sh` ; il n'a pas ete mesure sur le serveur, il a ete lu.
 *
 * En mode `--continu`, la commande prend un bail en base et tourne **sans fin**. Le planificateur
 * n'est que son superviseur : chaque tick en lance une autre en arriere-plan, qui s'efface aussitot
 * si le bail bat encore, et prend la releve s'il a cesse de battre. Une releve apres panne attend
 * le tick suivant plus la tolerance du bail — un creux dit, apres une panne, jamais a la jonction
 * nominale.
 *
 * ## Ce que le bail garantit exactement
 *
 * Un detenteur en base — pas un seul emetteur. Un diffuseur suspendu pendant un appel reseau plus
 * longtemps que la tolerance peut voir un autre prendre la releve avant de reprendre. Le contrat
 * qui tient : la validite du bail est verifiee **avant chaque lot** (le battement, conditionne au
 * detenteur, sert de garde) ; un battement perdu arrete immediatement, sans autre lot ; la
 * liberation est conditionnee au detenteur, si bien qu'un diffuseur depasse ne libere jamais le
 * bail de son successeur ; les appels transport sont bornes par la configuration du client. Un
 * lot deja engage au moment de la releve peut partir deux fois — une par diffuseur — et c'est
 * admis : chaque perte porte son identite, le navigateur deduplique.
 *
 * Sans `--continu`, la commande fait un passage borne : `--duree` secondes, ou un seul passage a
 * zero. C'est la forme des essais et de l'exploitation manuelle.
 */
#[Description('Diffuser aux joueurs les pertes de combat et les changements d etat devenus visibles')]
#[Signature('ogamex:combat:diffuser {--continu : Tenir le bail et tourner sans fin} {--duree=0 : Secondes de veille sans bail, zero pour un seul passage} {--pas=1 : Secondes entre deux regards} {--tours=0 : En mode continu, nombre de tours avant de rendre le bail, zero pour sans fin}')]
class BroadcastCombatLosses extends Command
{
    public function handle(CombatPresentationBroadcaster $diffuseur): int
    {
        $pas = max(1, (int)$this->option('pas'));

        if ((bool)$this->option('continu')) {
            return $this->watchContinuously($diffuseur, $pas, max(0, (int)$this->option('tours')));
        }

        $fin = (int)Date::now()->timestamp + max(0, (int)$this->option('duree'));
        [$pertes, $etats] = [0, 0];

        do {
            [$p, $e] = $this->pass($diffuseur);
            $pertes += $p;
            $etats += $e;

            if ((int)Date::now()->timestamp >= $fin) {
                break;
            }

            sleep($pas);
        } while (true);

        $this->report($pertes, $etats);

        return self::SUCCESS;
    }

    /**
     * Le mode continu : prendre le bail, battre a chaque tour, s'arreter des qu'un autre le tient.
     */
    private function watchContinuously(CombatPresentationBroadcaster $diffuseur, int $pas, int $tours): int
    {
        $bail = new CombatBroadcasterLease(self::holderName());
        $maintenant = (int)Date::now()->timestamp;

        if (!$bail->acquire($maintenant)) {
            // Un diffuseur bat encore : celui-ci n'a rien a faire, et le dit.
            $this->line('  Un diffuseur tient deja le bail (' . (CombatBroadcasterLease::currentHolder() ?? '?') . ').');

            return self::SUCCESS;
        }

        $this->line('  Bail pris : ' . self::holderName());
        [$pertes, $etats, $tour] = [0, 0, 0];

        // **La garde** : un battement, conditionne au detenteur. Consultee avant chaque lot par le
        // diffuseur, elle arrete l'emission des que le bail est passe a un autre.
        $garde = static fn (): bool => $bail->heartbeat((int)Date::now()->timestamp);

        try {
            while (true) {
                if (!$garde()) {
                    // La releve a ete prise pendant qu'on dormait : s'effacer sans rien diffuser de
                    // plus, sinon deux diffuseurs tourneraient.
                    $this->line('  Bail perdu : un autre diffuseur a pris la releve.');

                    break;
                }

                [$p, $e] = $this->pass($diffuseur, $garde);
                $pertes += $p;
                $etats += $e;
                $tour++;

                if ($tours > 0 && $tour >= $tours) {
                    break;
                }

                sleep($pas);
            }
        } finally {
            $bail->release();
        }

        $this->report($pertes, $etats);

        return self::SUCCESS;
    }

    /**
     * @param Closure(): bool|null $garde
     * @return array{0: int, 1: int}
     */
    private function pass(CombatPresentationBroadcaster $diffuseur, Closure|null $garde = null): array
    {
        $instant = (int)Date::now()->timestamp;

        return [$diffuseur->publish($instant, 500, $garde), $diffuseur->publishStateChanges($instant, 200, $garde)];
    }

    private function report(int $pertes, int $etats): void
    {
        if ($pertes > 0 || $etats > 0) {
            $this->line('  ' . $pertes . ' perte(s) et ' . $etats . ' etat(s) diffuses.');
        }
    }

    /**
     * Le nom sous lequel ce processus tient le bail : la machine et son identifiant de processus.
     */
    public static function holderName(): string
    {
        return gethostname() . ':' . getmypid();
    }
}
