<?php

namespace OGame\Console\Commands\Military;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryTallySources;
use OGame\Services\SettingsService;

/**
 * Démarre la collecte des trois cumuls militaires : construits, détruits, perdus.
 *
 * ## Pourquoi une activation, et pas une migration
 *
 * La date affichée aux joueurs — « Cumul depuis le … » — doit être celle où la collecte **commence vraiment**,
 * c'est-à-dire quand tous les chemins de crédit sont raccordés et déployés. Une colonne créée n'est pas une
 * collecte commencée ; et le premier crédit ne l'est pas davantage : si personne ne construit pendant deux jours
 * après l'activation, ces deux jours sont bel et bien couverts, à zéro.
 *
 * Tant que cette date est absente, les trois classements se disent indisponibles. Une fois posée, un zéro est une
 * donnée valide.
 *
 * ## Idempotente, et sans effet sur les compteurs
 *
 * Un second appel ne remet rien à zéro et ne déplace pas la date : réécrire la date effacerait la période déjà
 * couverte et ferait mentir la page. La commande dit alors la date qui existe et sort.
 *
 * **Ajouter cette commande n'est pas l'exécuter** : l'activation est une décision de Keven, après validation du
 * raccordement complet des chemins de crédit.
 */
#[Description('Demarre la collecte des cumuls militaires (construits, detruits, perdus)')]
#[Signature('ogamex:military:demarrer-cumuls')]
class StartMilitaryTallies extends Command
{
    public function handle(SettingsService $settings, MilitaryTallyRecorder $tallies, MilitaryTallySources $sources): int
    {
        foreach ([
            'military_tally_events' => ['event_key', 'player_id', 'status', 'aggregated_at', 'resolved_at'],
            'military_tallies' => ['player_id', 'built_value', 'destroyed_value', 'lost_value'],
            'highscores' => ['military_built', 'military_destroyed', 'military_lost'],
            'alliance_highscores' => ['military_built', 'military_destroyed', 'military_lost'],
        ] as $table => $colonnes) {
            if (!Schema::hasTable($table)) {
                $this->error('Prerequis manquant : la table ' . $table . ' n existe pas. Migration non appliquee ?');

                return self::FAILURE;
            }

            if (!Schema::hasColumns($table, $colonnes)) {
                $this->error('Prerequis manquant : ' . $table . ' n a pas les colonnes des cumuls militaires.');

                return self::FAILURE;
            }
        }

        // **Chaque source de credit doit avoir son temoin d'effet.** Declaree raccordee ne suffit pas : une source sans
        // essai qui prouve qu'elle credite reellement ferait commencer un classement public incomplet des le premier jour.
        $manquantes = $sources->unwired();

        if ($manquantes !== []) {
            $this->error('Activation refusee : sources de credit sans temoin d effet : ' . implode(', ', $manquantes) . '.');

            return self::FAILURE;
        }

        $depuis = $tallies->collectingSince();

        if ($depuis !== null) {
            $this->warn('La collecte a deja commence le ' . Date::createFromTimestamp($depuis)->toDateTimeString() . ' (UTC). Aucun compteur n a ete touche.');

            return self::SUCCESS;
        }

        $maintenant = (int)Date::now()->timestamp;

        // La date et rien d'autre : les compteurs des comptes ne sont jamais remis a zero par cette commande.
        DB::transaction(function () use ($settings, $maintenant): void {
            $settings->set(MilitaryTallyRecorder::SINCE_KEY, (string)$maintenant);
        });

        $this->info('Collecte des cumuls militaires demarree le ' . Date::createFromTimestamp($maintenant)->toDateTimeString() . ' (UTC).');
        $this->line('Les trois classements sont desormais servis ; un zero est une donnee valide.');

        $enAttente = $tallies->pendingCount();

        if ($enAttente > 0) {
            $this->warn($enAttente . ' evenement(s) en attente : le classement dira que ses donnees sont temporairement incompletes.');
        }

        return self::SUCCESS;
    }
}
