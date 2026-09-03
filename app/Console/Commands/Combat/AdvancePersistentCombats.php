<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Services\PersistentCombatAdvancer;

/**
 * Le battement du combat durable, appele par l'ordonnanceur.
 *
 * La commande ne decide rien : elle donne l'heure au service et raconte ce qu'il a fait. Un passage
 * qui ne trouve rien est le cas normal, et ne dit rien de plus qu'une ligne.
 *
 * **Modifier `routes/console.php` impose `ogamex restart ogamex-scheduler`** : le conteneur ne lit
 * le fichier qu'a son demarrage.
 */
#[Description('Fermer les ralliements echus et regler les combats durables termines')]
#[Signature('ogamex:combat:avancer {--instant= : horodatage a utiliser au lieu de maintenant, pour rejouer un retard}')]
class AdvancePersistentCombats extends Command
{
    public function __construct(private readonly PersistentCombatAdvancer $avanceur)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $instant = $this->option('instant');
        $maintenant = is_string($instant) && $instant !== '' ? (int)$instant : (int)Date::now()->timestamp;

        $avance = $this->avanceur->advance($maintenant);

        if ($avance->didSomething()) {
            $this->line('  ' . $avance->closed . ' ralliement(s) ferme(s), ' . $avance->settled . ' combat(s) regle(s).');
        }

        foreach ($avance->failures as $combat => $raison) {
            $this->error('  combat ' . $combat . ' : ' . $raison);
        }

        if ($avance->quarantined > 0) {
            $this->warn(
                '  ' . $avance->quarantined . ' combat(s) mis de cote apres '
                . PersistentCombatAdvancer::MAX_ATTEMPTS . ' echecs. Corriger, puis remettre '
                . '`advance_attempts` a zero pour les reprendre.'
            );
        }

        // Un echec de combat n'est pas un echec du passage : les autres ont ete traites, et le
        // compteur ramenera celui-la a l'attention d'un humain. Rendre un code non nul ferait
        // sonner l'ordonnanceur a chaque minute pour un incident deja enregistre.
        return self::SUCCESS;
    }
}
