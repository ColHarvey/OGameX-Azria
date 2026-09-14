<?php

namespace OGame\Console\Commands\Military;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Military\MilitaryTallyReplay;

/**
 * Reprend les événements de cumuls militaires en attente, chacun avec **sa** version de pondération.
 *
 * Idempotente : un événement repris ne l'est qu'une fois, et un événement qui ne peut toujours pas être évalué entier
 * reste en attente. Aucune conversion vers une autre version n'est faite ici (`MilitaryTallyReplay`).
 *
 * Reprendre n'est pas publier : les valeurs reprises entrent dans les compteurs à la prochaine agrégation, et au
 * classement à la prochaine publication.
 */
#[Description('Reprend les evenements de cumuls militaires en attente, chacun avec sa version de ponderation')]
#[Signature('ogamex:military:reprendre-attentes')]
class ReplayPendingMilitaryTallies extends Command
{
    public function handle(MilitaryTallyReplay $reprise): int
    {
        $bilan = $reprise->replay();

        $this->info($bilan['replayed'] . ' evenement(s) repris ; ' . $bilan['pending'] . ' toujours en attente ; ' . $bilan['skipped'] . ' deja repris par un autre passage.');

        if ($bilan['pending'] > 0) {
            $this->warn('Des evenements attendent encore : unite inconnue de leur version, version inconnue ou forme de charge non reconnue. Aucune conversion n est faite par cette commande.');
        }

        return self::SUCCESS;
    }
}
