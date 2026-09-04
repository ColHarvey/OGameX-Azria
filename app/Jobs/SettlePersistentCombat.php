<?php

namespace OGame\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OGame\GameMissions\AttackMission;
use Throwable;

/**
 * Regle un combat durable a son echeance, sans attendre le passage de la minute.
 *
 * ## Pourquoi ce travail existe
 *
 * Le passage planifie tourne chaque minute — c'est la granularite la plus fine que le planificateur
 * de Laravel offre. Une bataille de cinq secondes garderait donc son corps verrouille pres d'une
 * minute de plus que sa duree, et la fenetre dynamique existe justement pour supprimer cette
 * attente. Ce travail est programme **a l'echeance exacte**, dans la transaction de cloture.
 *
 * ## Le passage minute reste, en rattrapage
 *
 * Une file peut perdre un message, un travailleur peut avoir ete arrete, une base peut avoir ete
 * restauree. Le passage minute continue donc de balayer les combats dus : il ne trouve rien quand
 * ce travail a fait son office, et il rattrape quand il ne l'a pas fait. Les deux chemins passent
 * par la meme frontiere, qui refuse un combat deja regle et n'ecrit rien deux fois.
 *
 * ## Ce qu'il ne transporte pas
 *
 * Un identifiant, et rien d'autre. Ni heure, ni resultat, ni participants : un message rejoue
 * appliquerait sinon des faits perimes. L'heure vient de l'horloge de la frontiere, tout le reste
 * de l'instance relue sous verrou.
 */
class SettlePersistentCombat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $combatInstanceId)
    {
    }

    public function handle(): void
    {
        try {
            $issue = resolve(AttackMission::class)->settlePersistentCombat($this->combatInstanceId);
        } catch (Throwable $echec) {
            // **L'echec est journalise, pas propage.** Le compteur d'essais et la mise de cote
            // vivent dans le passage planifie, qui repassera sur ce combat : laisser la file
            // reessayer en parallele donnerait deux mecanismes de reprise pour un meme incident.
            Log::warning('Le reglement programme d un combat durable a echoue ; le passage planifie reprendra.', [
                'combat_instance_id' => $this->combatInstanceId,
                'error' => $echec->getMessage(),
            ]);

            return;
        }

        if (!$issue->settled) {
            Log::info('Le reglement programme d un combat durable n avait rien a faire.', [
                'combat_instance_id' => $this->combatInstanceId,
                'reason' => $issue->reason,
            ]);
        }
    }
}
