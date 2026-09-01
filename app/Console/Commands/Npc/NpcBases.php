<?php

namespace OGame\Console\Commands\Npc;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\NpcBaseSnapshot;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\Npc\NpcPopulationService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;

#[Description('Show the current state and recent growth of every hostile base')]
#[Signature('ogamex:npc:bases {--days=7 : duree de la progression affichee}')]
class NpcBases extends Command
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly NpcPopulationService $population,
        private readonly NpcBaseService $bases,
        private readonly NpcGrowthService $growth,
        private readonly PlanetServiceFactory $planetServiceFactory
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Le relevé hebdomadaire répond à « qu'auraient fait les pirates ». Celui-ci répond à une
     * autre question, qui se pose bien avant le premier raid : est-ce qu'ils grandissent. Une
     * base qui stagne ne le dit pas d'elle-meme — elle affiche la meme ligne tranquille a
     * chaque tick — et la difference entre « elle attend son tour » et « elle est bloquee »
     * se lit dans ses caisses, pas dans son score.
     */
    public function handle(): int
    {
        $days = max(1, (int)$this->option('days'));
        $depuis = Date::now()->subDays($days);

        $this->line('');
        $this->line('  =====================================================');
        $this->line('   BASES HOSTILES — etat et progression');
        $this->line('  =====================================================');
        $this->line('');

        if (!$this->settings->npcEnabled()) {
            $this->line('  Le systeme est eteint : les bases existent mais ne grandissent plus.');
            $this->line('');
        }

        $vivantes = $this->bases->livingBases();

        if ($vivantes->isEmpty()) {
            $this->line('  Aucune base vivante. Peupler avec : ogamex:npc:seed');
            $this->line('');

            return Command::SUCCESS;
        }

        $plafond = $this->growth->powerCeiling();

        $this->line(sprintf(
            '  plafond de maturite  %d points   (mediane serveur %d, seuil %d)',
            $plafond,
            $this->population->medianScore(),
            $this->population->threshold()
        ));

        $planetes = [];

        foreach ($vivantes as $base) {
            $planete = $this->planetServiceFactory->make($base->id, true);

            if ($planete !== null) {
                $planetes[] = $planete;
            }
        }

        $this->reportEtat($planetes);
        $this->reportProgression($planetes, $days, $depuis);
        $this->reportBlocages($planetes);

        $this->line('');

        return Command::SUCCESS;
    }

    /**
     * Show what every base looks like right now.
     *
     * @param array<int, PlanetService> $planetes
     */
    private function reportEtat(array $planetes): void
    {
        $this->line('');
        $this->line('  --- ETAT ACTUEL ---');
        $this->line('');
        $this->line('    base                     coord        age   score  matur.  bat.  vaiss.   def.');

        foreach ($planetes as $planete) {
            $proprietaire = $planete->getPlayer();
            $creation = $proprietaire?->getUser()->created_at;

            $this->line(sprintf(
                '    %-24s %-12s %3dj  %6d   %4d%%  %4d  %6d  %5d',
                $planete->getPlanetName(),
                $planete->getPlanetCoordinates()->asString(),
                $creation !== null ? (int)$creation->diffInDays(Date::now()) : 0,
                (int)$planete->getPlanetScore(),
                $this->growth->maturityOf($planete),
                $planete->getBuildingCount(),
                (int)$planete->getShipUnits()->getAmount(),
                (int)$planete->getDefenseUnits()->getAmount()
            ));
        }
    }

    /**
     * Compare each base with what it was at the start of the period.
     *
     * C'est la seule section qui repond vraiment a la question posee. Un score seul ne dit
     * rien : une base a douze points est en bonne voie si elle en avait quatre il y a une
     * semaine, et a l'arret si elle en avait douze.
     *
     * @param array<int, PlanetService> $planetes
     */
    private function reportProgression(array $planetes, int $days, Carbon $depuis): void
    {
        $this->line('');
        $this->line(sprintf('  --- PROGRESSION SUR %d JOUR(S) ---', $days));
        $this->line('');

        $mesure = false;

        foreach ($planetes as $planete) {
            $premier = NpcBaseSnapshot::query()
                ->where('planet_id', $planete->getPlanetId())
                ->where('observed_at', '>=', $depuis)
                ->orderBy('observed_at')
                ->first();

            if ($premier === null) {
                continue;
            }

            $mesure = true;

            $this->line(sprintf(
                '    %-24s score %d -> %d   maturite %d%% -> %d%%   batiments %d -> %d',
                $planete->getPlanetName(),
                $premier->score,
                (int)$planete->getPlanetScore(),
                $premier->maturity,
                $this->growth->maturityOf($planete),
                $premier->buildings,
                $planete->getBuildingCount()
            ));
        }

        if (!$mesure) {
            $this->line('    Aucun releve sur la periode.');
            $this->line('');
            $this->line('    Les releves sont ecrits par le tick, une fois par heure et par base.');
            $this->line('    Si le systeme vient d etre installe, laisser passer une heure.');
        }
    }

    /**
     * Say which bases are stuck, and on what.
     *
     * Une base peut n'avoir rien construit pour deux raisons opposees : elle est deja au
     * plafond de maturite — c'est voulu, elle attend que le serveur grandisse — ou elle n'a
     * pas les ressources. La ligne du tick dit « rien d abordable » dans les deux cas, ce qui
     * ne permet pas de trancher. Les caisses, si.
     *
     * @param array<int, PlanetService> $planetes
     */
    private function reportBlocages(array $planetes): void
    {
        $this->line('');
        $this->line('  --- CE QUE CHAQUE BASE FAIT ---');
        $this->line('');

        foreach ($planetes as $planete) {
            $ressources = $planete->getResources();
            $encours = $this->workInProgress($planete);

            if ($encours !== []) {
                $etat = implode(' + ', $encours);
            } elseif ($this->growth->isAtCeiling($planete)) {
                $etat = 'au plafond de maturite, en attente que le serveur grandisse';
            } else {
                // Aucune file ouverte : on retombe sur la derniere decision consignee, en
                // disant clairement qu'elle date.
                $dernier = NpcBaseSnapshot::query()
                    ->where('planet_id', $planete->getPlanetId())
                    ->orderByDesc('observed_at')
                    ->first();

                $etat = $dernier !== null
                    ? 'rien en cours — au dernier releve : ' . trim($dernier->action . ' ' . $dernier->detail)
                    : 'rien en cours, aucun releve encore';
            }

            $this->line(sprintf(
                '    %-24s %s',
                $planete->getPlanetName(),
                $etat
            ));

            $this->line(sprintf(
                '    %-24s   caisses : M %s  C %s  D %s',
                '',
                number_format($ressources->metal->getRounded(), 0, ',', ' '),
                number_format($ressources->crystal->getRounded(), 0, ',', ' '),
                number_format($ressources->deuterium->getRounded(), 0, ',', ' ')
            ));
        }
    }

    /**
     * Read what the base has on the fire right now, straight from the game's own queues.
     *
     * Lire le dernier releve horaire ne repondait pas a la question posee. Une base qui vient
     * de lancer une mine affichait encore la decision d'il y a cinquante minutes, et apres un
     * deploiement elle affichait celle d'avant le correctif — au point de faire croire a un
     * blocage alors que les trois files tournaient. Les files, elles, disent l'instant present.
     *
     * @return array<int, string>
     */
    private function workInProgress(PlanetService $planete): array
    {
        $encours = [];
        $maintenant = (int)Date::now()->timestamp;

        $tables = [
            'building_queues' => 'batiment',
            'research_queues' => 'recherche',
            'unit_queues' => 'chantier',
        ];

        foreach ($tables as $table => $libelle) {
            $ligne = DB::table($table)
                ->where('planet_id', $planete->getPlanetId())
                ->where('processed', 0)
                ->orderBy('time_end')
                ->first();

            if ($ligne === null) {
                continue;
            }

            $objet = ObjectService::getObjectById((int)$ligne->object_id);
            $reste = max(0, (int)$ligne->time_end - $maintenant);

            $encours[] = sprintf(
                '%s %s%s (%s)',
                $libelle,
                $objet->machine_name,
                isset($ligne->object_amount) ? ' x' . (int)$ligne->object_amount : '',
                $reste > 0 ? 'fini dans ' . $this->duree($reste) : 'termine, sera encaisse au prochain tick'
            );
        }

        return $encours;
    }

    /**
     * Render a number of seconds the way a player reads a countdown.
     */
    private function duree(int $secondes): string
    {
        if ($secondes < 60) {
            return $secondes . ' s';
        }

        if ($secondes < 3600) {
            return intdiv($secondes, 60) . ' min';
        }

        if ($secondes < 86400) {
            return intdiv($secondes, 3600) . ' h ' . intdiv($secondes % 3600, 60) . ' min';
        }

        return intdiv($secondes, 86400) . ' j ' . intdiv($secondes % 86400, 3600) . ' h';
    }
}
