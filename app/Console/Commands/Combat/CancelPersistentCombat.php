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
 * Chaque flotte rentre avec ce qu'elle portait — attaquantes et renforts defensifs —, avec un
 * message qui donne la cause ; la cible l'apprend aussi. La cause, la note et l'instant sont
 * persistes sur l'instance et ecrits au journal, avec l'empreinte des faits abandonnes.
 *
 * ## Ni cause ni note par defaut
 *
 * Une cause implicite ferait qu'un administrateur annule sans dire pourquoi, et une note absente
 * qu'il annule sans dire ce qu'il a vu. Les deux se nomment, a chaque fois. Aucune cause n'est a
 * la portee d'un joueur : cette commande est le seul chemin, et il passe par un administrateur.
 */
#[Description('Annuler un combat durable que le reglement ne sait plus appliquer : les flottes rentrent, le corps se libere')]
#[Signature('ogamex:combat:annuler {combat : identifiant du combat} {--cause= : administrative_decision, target_disappeared, attacker_removed ou inconsistent_snapshot} {--note= : ce que vous avez constate, pour l audit}')]
class CancelPersistentCombat extends Command
{
    public function handle(): int
    {
        $identifiant = (int)$this->argument('combat');

        $option = $this->option('cause');
        $cause = is_string($option) ? CombatCancellationCause::tryFrom($option) : null;

        if ($cause === null) {
            $this->error('  Cause ' . (is_string($option) ? 'inconnue : ' . $option : 'absente') . '. Causes admises : '
                . implode(', ', array_map(static fn (CombatCancellationCause $c): string => $c->value, CombatCancellationCause::cases())) . '.');

            return self::FAILURE;
        }

        $note = $this->option('note');
        $note = is_string($note) ? trim($note) : '';

        if ($note === '') {
            $this->error('  Note absente : dites ce que vous avez constate (--note="...").');

            return self::FAILURE;
        }

        $issue = resolve(AttackMission::class)->cancelPersistentCombat($identifiant, $cause, $note, (int)Date::now()->timestamp);

        if ($issue->reason === CombatCancellationOutcome::REASON_UNKNOWN_COMBAT) {
            $this->error('  Aucun combat ' . $identifiant . '.');

            return self::FAILURE;
        }

        if (!$issue->cancelled) {
            $this->warn('  Le combat ' . $identifiant . ' est deja termine : rien a annuler.');

            return self::SUCCESS;
        }

        $this->line('  Combat ' . $identifiant . ' annule (' . $cause->value . ') : ' . $issue->fleetsSentHome
            . ' flotte(s) attaquante(s) et ' . $issue->defendersSentHome . ' renfort(s) defensif(s) renvoye(s), corps libere.');

        if ($issue->fleetsAlreadyGone > 0) {
            $this->warn('  ' . $issue->fleetsAlreadyGone . ' flotte(s) inscrite(s) etai(en)t deja traitee(s) : laissee(s) telle(s) quelle(s), voir le journal.');
        }

        return self::SUCCESS;
    }
}
