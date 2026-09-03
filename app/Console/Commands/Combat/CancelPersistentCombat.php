<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Services\CombatCancellationOutcome;
use OGame\GameMissions\AttackMission;

/**
 * Annule un combat durable : les flottes rentrent, le corps se libere, rien n'est applique.
 *
 * ## Quand l'exploitation y recourt
 *
 * Un combat que le reglement ne sait plus appliquer et qu'aucune reprise ne guerira — resultat
 * fige corrompu au-dela de toute reparation, montants que le stockage ne porte pas. Le laisser
 * tiendrait son corps pour toujours.
 *
 * La bataille calculee n'est jamais ecrite : le defenseur ne perd rien, l'attaquant ne prend rien.
 * Chaque flotte rentre avec ce qu'elle portait, avec un message qui donne la cause. La cause est
 * persistee sur l'instance et ecrite au journal.
 *
 * Aucune cause n'est a la portee d'un joueur : cette commande est le seul chemin, et il passe par
 * un administrateur qui la nomme.
 */
#[Description('Annuler un combat durable que le reglement ne sait plus appliquer : les flottes rentrent, le corps se libere')]
#[Signature('ogamex:combat:annuler {combat : identifiant du combat} {--cause=administrative_decision : administrative_decision, target_disappeared, attacker_removed ou inconsistent_snapshot}')]
class CancelPersistentCombat extends Command
{
    public function handle(): int
    {
        $identifiant = (int)$this->argument('combat');
        $option = $this->option('cause');
        $cause = is_string($option) ? CombatCancellationCause::tryFrom($option) : null;

        if ($cause === null) {
            $this->error('  Cause inconnue : ' . (is_string($option) ? $option : '(absente)') . '. Causes admises : '
                . implode(', ', array_map(static fn (CombatCancellationCause $c): string => $c->value, CombatCancellationCause::cases())) . '.');

            return self::FAILURE;
        }

        $issue = resolve(AttackMission::class)->cancelPersistentCombat($identifiant, $cause, (int)Date::now()->timestamp);

        if ($issue->reason === CombatCancellationOutcome::REASON_UNKNOWN_COMBAT) {
            $this->error('  Aucun combat ' . $identifiant . '.');

            return self::FAILURE;
        }

        if (!$issue->cancelled) {
            $this->warn('  Le combat ' . $identifiant . ' est deja termine : rien a annuler.');

            return self::SUCCESS;
        }

        $this->line('  Combat ' . $identifiant . ' annule (' . $cause->value . ') : ' . $issue->fleetsSentHome . ' flotte(s) renvoyee(s), corps libere.');

        if ($issue->fleetsAlreadyGone > 0) {
            $this->warn('  ' . $issue->fleetsAlreadyGone . ' flotte(s) inscrite(s) etai(en)t deja traitee(s) : laissee(s) telle(s) quelle(s), voir le journal.');
        }

        return self::SUCCESS;
    }
}
