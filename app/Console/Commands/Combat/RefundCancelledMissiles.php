<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Services\MissileRefundClaims;

/**
 * Rend les missiles qu'un combat a annules et que rien n'avait pu crediter sur le moment.
 *
 * ## Ce qu'elle regle, et ce qu'elle ne regle pas
 *
 * Un missile parti par une course serveur est **rendu**, jamais detruit. Quand la restitution est
 * possible a l'annulation, elle a lieu et cette commande n'a rien a faire. Elle existe pour l'autre
 * cas : le corps de depart avait disparu, et le protocole canonique de destination ne designait rien
 * **a cet instant**. Ce qui etait du a ete inscrit ; la commande le rend des qu'une destination
 * existe — apres la fin du combat, autant de fois qu'il le faut.
 *
 * Elle ne devine aucune destination : c'est le meme protocole qui ramene une flotte refusee. Une
 * creance qui n'en trouve toujours pas reste due, et la commande le dit.
 */
#[Description('Rendre les missiles dus par des annulations de combat qui n avaient pas de destination')]
#[Signature('ogamex:combat:rembourser-missiles {--liste : afficher les creances sans rien rendre}')]
class RefundCancelledMissiles extends Command
{
    public function handle(MissileRefundClaims $creances): int
    {
        $dues = $creances->pending();

        if ($dues === []) {
            $this->info('  Aucune creance de restitution.');

            return self::SUCCESS;
        }

        $this->line('  ' . count($dues) . ' creance(s) de restitution :');
        foreach ($dues as $creance) {
            $this->line(sprintf(
                '    mission %d — %d missile(s) dus au joueur %d (%s, inscrite le %s)',
                $creance->fleetMissionId,
                $creance->missiles,
                $creance->ownerId,
                $creance->reason,
                Date::createFromTimestamp($creance->claimedAt)->toDateTimeString()
            ));
        }

        if ($this->option('liste')) {
            return self::SUCCESS;
        }

        $issue = $creances->settlePending((int)Date::now()->timestamp);

        $this->info('  ' . $issue['credited'] . ' rendue(s).');
        if ($issue['waiting'] > 0) {
            $this->warn('  ' . $issue['waiting'] . ' encore due(s) : leur proprietaire n a aucune destination pour l instant.');
        }

        return self::SUCCESS;
    }
}
