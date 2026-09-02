<?php

namespace OGame\Console\Commands\Npc;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\NpcBaseSnapshot;
use OGame\Models\User;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcPopulationService;
use OGame\Services\Npc\NpcThreatService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

#[Description('Report what the hostile factions would have done over an observation period')]
#[Signature('ogamex:npc:report {--days=7 : duree de la periode observee}')]
class NpcReport extends Command
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly NpcPopulationService $population,
        private readonly NpcBaseService $bases,
        private readonly NpcThreatService $threat
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Le rapport ne juge pas : il compte. C'est a la lecture des chiffres, et non avant, que
     * les reglages doivent bouger — la premiere calibration de l'evenement missions etait
     * six a vingt fois trop genereuse et rien ne l'avait laisse voir avant la mesure.
     */
    public function handle(): int
    {
        $days = max(1, (int)$this->option('days'));
        $since = Date::now()->subDays($days);
        $total = $this->observations($since)->count();

        $this->line('');
        $this->line('  =====================================================');
        $this->line(sprintf('   FACTIONS HOSTILES — %d derniers jours', $days));
        $this->line('  =====================================================');
        $this->line('');
        $this->line(sprintf(
            '  mode               %s',
            $this->settings->npcEnabled()
                ? ($this->settings->npcSimulation() ? 'SIMULATION (aucune flotte envoyee)' : 'ACTIF')
                : 'DESACTIVE'
        ));

        if ($total === 0) {
            $this->line('');
            $this->warn('  Aucune observation sur la periode.');
            $this->line('');
            $this->line("  Si le systeme vient d'etre active, laisser passer quelques heures :");
            $this->line('  le tick tourne tous les quarts d heure. Sinon, verifier que');
            $this->line('  npc_enabled est a oui et que le conteneur ogamex-scheduler tourne.');
            $this->line('');

            return Command::SUCCESS;
        }

        $this->reportServer($since);
        $this->reportRaids($since, $total);
        $this->reportRefusals($since, $total);
        $this->reportThreat();
        $this->reportBases($since);
        $this->reportNewcomers();

        $this->line('');

        return Command::SUCCESS;
    }

    /**
     * Get the observation rows for the period.
     */
    private function observations(Carbon $since): \Illuminate\Database\Query\Builder
    {
        return DB::table('npc_observations')->where('observed_at', '>=', $since);
    }

    /**
     * Report how the server itself moved over the period.
     */
    private function reportServer(Carbon $since): void
    {
        $first = $this->observations($since)->orderBy('observed_at')->first();
        $last = $this->observations($since)->orderByDesc('observed_at')->first();

        $this->line('');
        $this->line('  --- LE SERVEUR ---');
        $this->line('');

        if ($first === null || $last === null) {
            return;
        }

        $this->line(sprintf('  joueurs actifs      %d  ->  %d', $first->active_players, $last->active_players));
        $this->line(sprintf('  mediane             %d  ->  %d', $first->median_score, $last->median_score));
        $this->line(sprintf('  seuil d eligibilite %d  ->  %d', $first->threshold, $last->threshold));
        $this->line(sprintf('  bases vivantes      %d  ->  %d', $first->living_bases, $last->living_bases));
    }

    /**
     * Report the raids the factions decided upon.
     */
    private function reportRaids(Carbon $since, int $total): void
    {
        $nombre = $this->observations($since)->where('outcome', 'raid')->count();

        $this->line('');
        $this->line('  --- LES RAIDS ---');
        $this->line('');
        $this->line(sprintf('  evaluations          %d', $total));
        $this->line(sprintf('  raids decides        %d', $nombre));
        $this->line(sprintf(
            '  effectivement partis %d',
            $this->observations($since)->where('outcome', 'raid')->where('executed', true)->count()
        ));

        if ($nombre === 0) {
            return;
        }

        $this->line(sprintf(
            '  joueurs vises        %d',
            $this->observations($since)->where('outcome', 'raid')->distinct()->count('user_id')
        ));
        $this->line(sprintf(
            '  puissance moyenne    %d points',
            (int)round((float)$this->observations($since)->where('outcome', 'raid')->avg('power'))
        ));
        $this->line(sprintf(
            '  flotte moyenne       %d vaisseaux',
            (int)round((float)$this->observations($since)->where('outcome', 'raid')->avg('fleet_size'))
        ));
        $this->line(sprintf(
            '  butin estime         %s au total, %s par raid',
            $this->format((float)$this->observations($since)->where('outcome', 'raid')->sum('estimated_loot')),
            $this->format(round((float)$this->observations($since)->where('outcome', 'raid')->avg('estimated_loot')))
        ));

        $motifs = $this->observations($since)
            ->where('outcome', 'raid')
            ->selectRaw('motive, COUNT(*) AS total')
            ->groupBy('motive')
            ->orderByDesc('total')
            ->get();

        $this->line('');
        $this->line('  motifs');

        foreach ($motifs as $motif) {
            $this->line(sprintf('    %-16s %d', $motif->motive ?? '?', $motif->total));
        }
    }

    /**
     * Report why the factions left players alone.
     *
     * C'est la partie la plus utile du rapport. Chaque raison designe un reglage precis :
     * savoir laquelle domine dit exactement quel curseur bouger, la ou un simple « peu de
     * raids » n'apprendrait rien.
     */
    private function reportRefusals(Carbon $since, int $total): void
    {
        $refus = $this->observations($since)
            ->where('outcome', 'declined')
            ->selectRaw('reason, COUNT(*) AS total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->get();

        if ($refus->isEmpty()) {
            return;
        }

        $this->line('');
        $this->line('  --- POURQUOI LES AUTRES ONT ETE ECARTES ---');
        $this->line('');

        foreach ($refus as $ligne) {
            $this->line(sprintf(
                '    %-24s %5d   %5.1f %%',
                $ligne->reason ?? '?',
                $ligne->total,
                $total > 0 ? (int)$ligne->total / $total * 100 : 0
            ));
        }
    }

    /**
     * Report the rancour players are carrying right now.
     */
    private function reportThreat(): void
    {
        $this->line('');
        $this->line('  --- LA MENACE AUJOURD HUI ---');
        $this->line('');

        $lignes = DB::table('npc_threats')
            ->join('users', 'users.id', '=', 'npc_threats.user_id')
            ->where('npc_threats.threat', '>', 0)
            ->select('users.id', 'users.username', 'npc_threats.threat', 'npc_threats.last_motive')
            ->orderByDesc('npc_threats.threat')
            ->limit(15)
            ->get();

        if ($lignes->isEmpty()) {
            $this->line('    personne n a encore provoque les factions');

            return;
        }

        $this->line(sprintf(
            '  joueurs ayant deja provoque les factions : %d',
            DB::table('npc_threats')->where('threat', '>', 0)->count()
        ));
        $this->line(sprintf(
            '  menace accumulee moyenne                 : %d',
            (int)round((float)DB::table('npc_threats')->where('threat', '>', 0)->avg('threat'))
        ));
        $this->line('');
        $this->line('    joueur               accumulee  effective  dernier acte');

        foreach ($lignes as $ligne) {
            // La valeur stockee est ce que le joueur a accumule ; la valeur effective est
            // celle que son exposition lui autorise reellement. Un joueur loin de toute base
            // peut avoir cent points au compteur et plafonner a quarante — n'afficher que la
            // premiere donnerait une image fausse de ce qu'il risque.
            $player = resolve(PlayerService::class, ['player_id' => (int)$ligne->id]);

            $this->line(sprintf(
                '    %-20s %9d  %9d  %s',
                $ligne->username,
                $ligne->threat,
                $this->threat->threatOf($player),
                $ligne->last_motive ?? ''
            ));
        }
    }

    /**
     * Report what happened to the bases themselves.
     */
    private function reportBases(Carbon $since): void
    {
        $detruites = DB::table('planets')
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('users.is_npc', true)
            ->where('planets.destroyed', '>', 0)
            ->count();

        $this->line('');
        $this->line('  --- LES BASES ---');
        $this->line('');
        $this->line(sprintf(
            '  vivantes           %d  (cible : %d)',
            $this->bases->baseCount(),
            $this->population->targetBaseCount()
        ));
        $this->line(sprintf('  detruites          %d', $detruites));

        $lastSpawn = $this->settings->npcLastSpawnAt();

        if ($lastSpawn > 0) {
            $this->line(sprintf(
                '  derniere naissance il y a %d h',
                (int)floor(((int)Date::now()->timestamp - $lastSpawn) / 3600)
            ));
        }

        $bases = DB::table('planets')
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('users.is_npc', true)
            ->where('planets.destroyed', 0)
            ->select('planets.id', 'users.username', 'planets.galaxy', 'planets.system', 'planets.planet', 'users.created_at')
            ->get();

        if ($bases->isEmpty()) {
            return;
        }

        $this->line('');

        foreach ($bases as $base) {
            $age = $base->created_at !== null
                ? (int)Date::parse((string)$base->created_at)->diffInDays(Date::now())
                : 0;

            // Le nom, les coordonnees et l'age ne disent pas si la base grandit. Un score de
            // douze est bon signe s'il en valait quatre au debut de la periode, et mauvais
            // s'il en valait douze : c'est la comparaison qui informe, pas la valeur.
            $debut = NpcBaseSnapshot::query()
                ->where('planet_id', (int)$base->id)
                ->where('observed_at', '>=', $since)
                ->orderBy('observed_at')
                ->first();

            $fin = NpcBaseSnapshot::query()
                ->where('planet_id', (int)$base->id)
                ->orderByDesc('observed_at')
                ->first();

            $progression = $debut !== null && $fin !== null
                ? sprintf(
                    'score %d -> %d, maturite %d%% -> %d%%',
                    $debut->score,
                    $fin->score,
                    $debut->maturity,
                    $fin->maturity
                )
                : 'pas encore de releve';

            $this->line(sprintf(
                '    %-24s %d:%d:%d   %d jour(s)   %s',
                $base->username,
                $base->galaxy,
                $base->system,
                $base->planet,
                $age,
                $progression
            ));
        }

        $this->line('');
        $this->line('    Detail par base, caisses comprises : ogamex:npc:bases');
    }

    /**
     * Report whether the newest accounts are still out of reach.
     *
     * La question qui compte le plus a long terme : le debutant qui arrive dans six mois
     * reste-t-il protege alors que l'echelle du serveur aura change sous lui.
     */
    private function reportNewcomers(): void
    {
        $recents = User::where('is_npc', false)
            ->where('username', '!=', User::SYSTEM_ACCOUNT_USERNAME)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $this->line('');
        $this->line('  --- LES DERNIERS INSCRITS ---');
        $this->line('');

        foreach ($recents as $user) {
            $player = resolve(PlayerService::class, ['player_id' => $user->id]);

            $this->line(sprintf(
                '    %-20s %3d jour(s)  %5d points  %s',
                $user->username,
                $this->population->daysSinceRegistration($user),
                $player->getCachedGeneralScore(),
                match ($this->population->stateOf($player)) {
                    NpcPopulationService::STATE_PROTECTED => 'protege',
                    NpcPopulationService::STATE_SPOTTED => 'repere',
                    default => 'CIBLABLE',
                }
            ));
        }
    }

    /**
     * Format a large number the way the rest of the reports do.
     */
    private function format(float $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
