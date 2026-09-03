<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Models\CombatInstance;

/**
 * Reprend un combat mis de cote apres trop d'echecs.
 *
 * ## Le chemin audite, a la place de « modifier la table a la main »
 *
 * Un combat qui a echoue cinq fois est laisse a l'exploitation avec sa derniere raison. Une fois la
 * cause corrigee — une donnee reparee, un correctif deploye —, cette commande remet le compteur a
 * zero et le passage planifie le reprend a la minute suivante. Elle affiche la raison qu'elle
 * efface et l'ecrit au journal : une reprise qu'on ne retrouve pas apres coup n'est pas une
 * decision d'exploitation, c'est une disparition.
 *
 * Elle ne corrige rien elle-meme : si la cause n'a pas ete traitee, le combat echouera de nouveau
 * et sera remis de cote, cinq passages plus tard.
 */
#[Description('Reprendre un combat durable mis de cote apres trop d echecs, une fois la cause corrigee')]
#[Signature('ogamex:combat:reprendre {combat : identifiant du combat}')]
class ResumePersistentCombat extends Command
{
    public function handle(): int
    {
        $identifiant = (int)$this->argument('combat');
        $combat = CombatInstance::query()->find($identifiant);

        if ($combat === null) {
            $this->error('  Aucun combat ' . $identifiant . '.');

            return self::FAILURE;
        }

        if ($combat->status->isFinal()) {
            $this->warn('  Le combat ' . $combat->id . ' est « ' . $combat->status->value . ' » : il n y a rien a reprendre.');

            return self::SUCCESS;
        }

        if ((int)$combat->advance_attempts === 0 && $combat->advance_last_error === null) {
            $this->line('  Le combat ' . $combat->id . ' n a rien echoue : rien a reprendre.');

            return self::SUCCESS;
        }

        $raison = $combat->advance_last_error;
        $essais = (int)$combat->advance_attempts;

        $combat->advance_attempts = 0;
        $combat->advance_last_error = null;
        $combat->save();

        Log::warning('Combat durable repris par l exploitation.', [
            'combat_instance_id' => $combat->id,
            'status' => $combat->status->value,
            'attempts_cleared' => $essais,
            'last_error_cleared' => $raison,
        ]);

        $this->line('  Combat ' . $combat->id . ' (' . $combat->status->value . ') : ' . $essais . ' echec(s) effaces.');

        if ($raison !== null) {
            $this->line('  Derniere raison : ' . $raison);
        }

        $this->line('  Le passage planifie le reprendra a la minute suivante (seuil : ' . PersistentCombatAdvancer::MAX_ATTEMPTS . ' echecs).');

        return self::SUCCESS;
    }
}
