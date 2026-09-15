<?php

namespace OGame\Console\Commands\Military;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Military\HamillPendingConversion;

/**
 * La conversion des batailles en attente d une manoeuvre de Hamill non nommee : a blanc par defaut, ecrite avec
 * `--appliquer`. Rien n est credite ici — la reprise (`ogamex:military:reprendre-attentes`) applique ensuite le
 * groupe entier, ou rien.
 */
#[Description('Convertit, explicitement et avec audit, les batailles en attente d une manoeuvre de Hamill non nommee que des faits conserves etablissent exactement')]
#[Signature('ogamex:military:convertir-attentes-hamill {--appliquer : Ecrire la conversion ; sans cette option, dire seulement ce qui serait fait}')]
class ConvertHamillPendingTallies extends Command
{
    public function handle(HamillPendingConversion $conversion): int
    {
        $appliquer = (bool)$this->option('appliquer');
        $bilan = $conversion->convert($appliquer);

        foreach ($bilan['groups'] as $groupe) {
            $this->line($groupe['group'] . ' : ' . $groupe['outcome'] . ' — ' . $groupe['detail']);
        }

        $this->info(($appliquer ? 'Converti : ' : 'Convertible : ') . $bilan['converted'] . ' groupe(s) ; non resolu : ' . $bilan['unresolved'] . ' groupe(s).');

        if (!$appliquer) {
            $this->warn('Passage a blanc : rien n a ete ecrit. Relancer avec --appliquer pour convertir.');
        } elseif ($bilan['converted'] > 0) {
            $this->warn('Les groupes convertis restent en attente : ogamex:military:reprendre-attentes les applique d un bloc.');
        }

        return self::SUCCESS;
    }
}
