<?php

namespace OGame\Console\Commands\Npc;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcPopulationService;
use OGame\Services\SettingsService;

#[Description('Create the starting population of hostile NPC bases')]
#[Signature('ogamex:npc:seed {--count= : nombre de bases, sinon la cible calculee} {--dry-run : montre les positions retenues sans rien ecrire}')]
class SeedNpcBases extends Command
{
    public function __construct(
        private readonly NpcBaseService $bases,
        private readonly NpcPopulationService $population,
        private readonly SettingsService $settings
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * La commande est idempotente : elle compte les bases vivantes et ne cree que le
     * manque. La relancer deux fois ne double rien, exactement comme la commande qui
     * fabrique le compte Legor verifie son existence avant d'agir.
     */
    public function handle(): int
    {
        $existing = $this->bases->baseCount();
        $target = $this->option('count') !== null
            ? max(0, (int)$this->option('count'))
            : $this->population->targetBaseCount();

        $missing = max(0, $target - $existing);

        $this->line('');
        $this->line('  joueurs actifs     ' . $this->population->activePlayerCount());
        $this->line('  mediane            ' . $this->population->medianScore());
        $this->line('  seuil              ' . $this->population->threshold());
        $this->line("  bases vivantes     {$existing}");
        $this->line("  cible              {$target}");
        $this->line("  a creer            {$missing}");
        $this->line('  distance imposee   ' . $this->settings->npcSeedMinDistance() . ' a ' . $this->settings->npcSeedMaxDistance() . ' systemes');
        $this->line('');

        if ($missing === 0) {
            $this->info('Rien a faire : la population est deja au complet.');

            return Command::SUCCESS;
        }

        $dryRun = (bool)$this->option('dry-run');

        if ($dryRun) {
            // En simulation on ne peut pas reserver les positions au fur et a mesure, donc
            // deux propositions pourraient tomber dans le meme systeme. Ce n'est pas ce que
            // fera la vraie execution, ou chaque base creee est prise en compte par la
            // suivante : l'apercu sert a verifier l'ordre de grandeur et la repartition,
            // pas a predire les positions au systeme pres.
            $this->warn('Simulation : aucune base ne sera creee.');
            $this->line('');

            for ($i = 1; $i <= $missing; $i++) {
                $coordinate = $this->bases->findSpawnCoordinate();

                if ($coordinate === null) {
                    $this->error("  base {$i} : aucune position acceptable trouvee");
                    continue;
                }

                $this->line("  base {$i} : {$coordinate->asString()}");
            }

            $this->line('');
            $this->info('Relancer sans --dry-run pour creer reellement ces bases.');

            return Command::SUCCESS;
        }

        $created = 0;

        for ($i = 1; $i <= $missing; $i++) {
            $planet = $this->bases->createBase();

            if ($planet === null) {
                $this->error('  aucune position acceptable trouvee, arret.');
                $this->line('');
                $this->warn(sprintf(
                    "  L'univers n'offre plus de case libre a %d systemes au moins de toute planete",
                    $this->settings->npcSeedMinDistance()
                ));
                $this->warn('  humaine, et a ' . $this->settings->npcSeedMaxDistance() . ' au plus. Sur un univers dense, abaisser');
                $this->warn('  npc_seed_min_distance est le reglage a revoir en premier.');
                break;
            }

            $created++;
            $this->info("  cree : {$planet->getPlanetName()} en {$planet->getPlanetCoordinates()->asString()}");
        }

        $this->line('');
        $this->info("{$created} base(s) creee(s). Total vivant : " . $this->bases->baseCount() . '.');

        return Command::SUCCESS;
    }
}
