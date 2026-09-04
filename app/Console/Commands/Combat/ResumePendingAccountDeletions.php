<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;

/**
 * Reprend les suppressions de compte qu'un combat retenait.
 *
 * ## Pourquoi une reprise, et pas un refus
 *
 * Un compte qui **renforce** le combat d'un autre joueur ne peut pas disparaitre tout de suite :
 * retirer son renfort changerait une issue deja gelee, et annuler la bataille entiere changerait ce
 * que voient plusieurs tiers. Regle arretee par Keven : le compte passe en suppression en attente,
 * ne lance plus rien, et sa suppression **reprend d'elle-meme** des que ces combats sont finaux.
 * Un combat dure des heures, pas des jours.
 *
 * Cette commande est cette reprise. Elle ne force rien : elle redemande le plan de retrait, et
 * n'agit que sur les comptes dont plus rien ne retient la suppression. Un compte encore retenu voit
 * seulement sa raison rafraichie — celle qui s'affiche a l'administration.
 *
 * Elle est faite pour tourner regulierement. Tant qu'elle n'est pas planifiee, un administrateur
 * l'appelle a la main ; **planifier veut dire modifier `routes/console.php`, donc redemarrer le
 * conteneur du planificateur** au deploiement, sans quoi la tache est installee et ne tourne jamais.
 */
#[Description('Reprendre les suppressions de compte qu un combat retenait, une fois ces combats finaux')]
#[Signature('ogamex:comptes:reprendre-suppressions {--compte= : ne traiter que ce compte}')]
class ResumePendingAccountDeletions extends Command
{
    public function handle(): int
    {
        $comptes = User::query()->whereNotNull('deletion_pending_since')->orderBy('id');

        if ($this->option('compte') !== null) {
            $comptes->whereKey((int)$this->option('compte'));
        }

        $repris = 0;
        $retenus = 0;

        foreach ($comptes->get() as $compte) {
            // **La commande ne decide rien : elle redemande la suppression, et rapporte.** Elle a
            // porte un moment son propre controle « est-ce encore retenu ? » avant d'appeler la
            // suppression — un second moteur de decision, que rien ne pouvait distinguer du premier :
            // la suppression refait son plan et s'arrete d'elle-meme. La mutation qui retirait ce
            // controle survivait, et elle avait raison.
            resolve(PlayerServiceFactory::class)->make((int)$compte->id, true)->delete();

            $reste = User::query()->whereKey($compte->id)->first();

            if ($reste !== null) {
                $this->line('  Le compte ' . $compte->id . ' attend toujours : ' . (string)($reste->deletion_deferred_reason ?? 'un combat le retient'));
                $retenus++;

                continue;
            }

            Log::warning('Suppression de compte reprise apres la fin des combats qui la retenaient.', [
                'user_id' => $compte->id,
                'pending_since' => $compte->deletion_pending_since,
            ]);

            $this->info('  Le compte ' . $compte->id . ' a ete supprime.');
            $repris++;
        }

        $this->line('  ' . $repris . ' supprime(s), ' . $retenus . ' encore en attente.');

        return self::SUCCESS;
    }
}
