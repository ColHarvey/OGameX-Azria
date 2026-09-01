<?php

namespace OGame\Console\Commands\Npc;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\NpcBaseSnapshot;
use OGame\Models\NpcObservation;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\Npc\NpcPopulationService;
use OGame\Services\Npc\NpcRaidService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Throwable;

#[Description('Grow hostile NPC bases, replace destroyed ones, and decide raids')]
#[Signature('ogamex:npc:tick {--force-real : ignore le mode simulation pour ce passage}')]
class NpcTick extends Command
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly NpcPopulationService $population,
        private readonly NpcBaseService $bases,
        private readonly NpcGrowthService $growth,
        private readonly NpcRaidService $raids,
        private readonly PlanetServiceFactory $planetServiceFactory
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Le journal consigne l'environnement calcule et non seulement la decision. Sans les
     * valeurs qui ont produit le choix, un raid aberrant est indebogable : on ne peut pas
     * distinguer une regle qui a mal fonctionne d'un environnement qui a bouge sous elle.
     */
    public function handle(): int
    {
        if (!$this->settings->npcEnabled()) {
            return Command::SUCCESS;
        }

        $simulation = $this->settings->npcSimulation() && !$this->option('force-real');
        $stamp = Date::now()->format('Y-m-d H:i');

        $this->line('');
        $this->line($simulation ? "[NPC SIM] {$stamp}" : "[NPC] {$stamp}");
        $this->line('');
        $this->line(sprintf(
            '  serveur    actifs %d | mediane %d | seuil %d | bases %d | plafond de base %d',
            $this->population->activePlayerCount(),
            $this->population->medianScore(),
            $this->population->threshold(),
            $this->bases->baseCount(),
            $this->growth->powerCeiling()
        ));

        $this->growBases();
        $this->replaceMissingBases($simulation);
        $this->decideRaids($simulation);
        $this->purgeOldObservations();

        $this->line('');

        return Command::SUCCESS;
    }

    /**
     * Bring every living base up to date and give it its next job.
     *
     * Les bases grandissent meme en simulation, et c'est deliberé : geler la croissance
     * ferait porter la semaine d'observation sur un monde immobile, qui ne dirait rien de
     * ce qui se passera reellement. Seul l'envoi des flottes est suspendu.
     */
    private function growBases(): void
    {
        $lines = [];

        foreach ($this->bases->livingBases() as $base) {
            $planet = $this->planetServiceFactory->make($base->id, true);

            if ($planet === null) {
                continue;
            }

            try {
                $result = $this->growth->grow($planet);
            } catch (Throwable $e) {
                $this->error('  croissance ' . $base->id . ' : ' . $e->getMessage());
                continue;
            }

            $maturity = $this->growth->maturityOf($planet);

            $this->recordSnapshot($planet, $maturity, $result);

            $lines[] = sprintf(
                '    %-24s %3d%%  %-14s %s',
                $planet->getPlanetName(),
                $maturity,
                $result['action'],
                $result['detail']
            );
        }

        if ($lines === []) {
            return;
        }

        $this->line('  croissance');

        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    /**
     * Record what this base looks like right now, at most once an hour.
     *
     * « Est-ce que les bases evoluent bien » ne se repond pas en regardant une base : il faut
     * comparer deux instants. Le tick affichait deja sa ligne de croissance, mais sur la
     * sortie standard d'une commande planifiee que personne ne lit.
     *
     * Une trace par heure et non par tick : le tick tourne au quart d'heure, ce qui ferait
     * quatre fois plus de lignes sans rien apprendre de plus — une base ne change pas
     * d'allure en quinze minutes, ses batiments se comptent en heures.
     *
     * Les ressources sont enregistrees avec le reste, et ce n'est pas accessoire : une base
     * qui repete « rien d'abordable » n'est pas arretee par une regle mais par ses caisses
     * vides, et seul le stock permet de faire la difference.
     *
     * @param array{action: string, detail: string} $result
     */
    private function recordSnapshot(PlanetService $planet, int $maturity, array $result): void
    {
        $planetId = $planet->getPlanetId();
        $now = Date::now();

        $dernier = NpcBaseSnapshot::query()
            ->where('planet_id', $planetId)
            ->orderByDesc('observed_at')
            ->value('observed_at');

        if ($dernier !== null && Date::parse((string)$dernier)->greaterThan($now->copy()->subHour())) {
            return;
        }

        $owner = $planet->getPlayer();

        if ($owner === null) {
            return;
        }

        $resources = $planet->getResources();

        NpcBaseSnapshot::create([
            'user_id' => $owner->getId(),
            'planet_id' => $planetId,
            'score' => (int)$planet->getPlanetScore(),
            'maturity' => $maturity,
            'buildings' => $planet->getBuildingCount(),
            'ships' => (int)$planet->getShipUnits()->getAmount(),
            'defences' => (int)$planet->getDefenseUnits()->getAmount(),
            'metal' => $resources->metal->getRounded(),
            'crystal' => $resources->crystal->getRounded(),
            'deuterium' => $resources->deuterium->getRounded(),
            'action' => $result['action'],
            'detail' => $result['detail'],
            'observed_at' => $now,
        ]);
    }

    /**
     * Create a base to replace one that was destroyed, once the delay has run out.
     *
     * Une base detruite ne revient ni tout de suite, ni au meme endroit, ni a la meme
     * taille : elle renait minuscule et recommence son cycle. Sans le changement de
     * position les joueurs memoriseraient les coordonnees ; sans le retour a zero,
     * detruire une base n'aurait aucune valeur durable.
     */
    private function replaceMissingBases(bool $simulation): void
    {
        $target = $this->population->targetBaseCount();
        $living = $this->bases->baseCount();

        if ($living >= $target) {
            return;
        }

        $lastSpawn = $this->settings->npcLastSpawnAt();
        $minHours = $this->settings->npcRespawnMinHours();
        $maxHours = $this->settings->npcRespawnMaxHours();

        // Le delai est tire au sort une fois pour toutes a partir de la date du dernier
        // essaimage, ce qui evite de stocker une minuterie par base.
        $delay = random_int($minHours, $maxHours);
        $due = $lastSpawn + $delay * 3600;

        $now = (int)Date::now()->timestamp;

        if ($lastSpawn > 0 && $now < $due) {
            $this->line(sprintf(
                '  releve     %d/%d bases, prochaine dans %d h environ',
                $living,
                $target,
                (int)ceil(($due - $now) / 3600)
            ));

            return;
        }

        if ($simulation) {
            $this->line(sprintf('  releve     %d/%d bases, une naissance serait due — SIMULATION', $living, $target));

            return;
        }

        $planet = $this->bases->createBase();

        if ($planet === null) {
            $this->line('  releve     aucune position acceptable trouvee');

            return;
        }

        $this->line(sprintf(
            '  releve     %s nait en %s',
            $planet->getPlanetName(),
            $planet->getPlanetCoordinates()->asString()
        ));
    }

    /**
     * Work out which raids are due and, outside simulation, send them.
     */
    private function decideRaids(bool $simulation): void
    {
        $evaluations = $this->raids->evaluateAll();

        // Chaque verdict est consigne, refus compris. C'est la seule facon de repondre plus
        // tard aux questions qui comptent : un « aucun raid » sur trois jours ne dit rien,
        // alors que « quarante refus pour delai de garde » designe le reglage a revoir.
        $this->record($evaluations);

        $refus = [];
        $decisions = [];

        foreach ($evaluations as $evaluation) {
            if ($evaluation['outcome'] === NpcRaidService::OUTCOME_RAID) {
                $decisions[] = $evaluation;
                continue;
            }

            $reason = (string)$evaluation['reason'];
            $refus[$reason] = ($refus[$reason] ?? 0) + 1;
        }

        if ($refus !== []) {
            arsort($refus);
            $resume = [];

            foreach ($refus as $reason => $nombre) {
                $resume[] = "{$reason} x{$nombre}";
            }

            $this->line('  ecartes    ' . implode(' | ', $resume));
        }

        if ($decisions === []) {
            $this->line('  raids      aucun');

            return;
        }

        foreach ($decisions as $decision) {
            $player = $decision['player'];
            $base = $decision['base'];
            $target = $decision['target'];

            $this->line('');
            $this->line(sprintf('  joueur     %s | points %d', $player->getUsername(false), $player->getCachedGeneralScore()));
            $this->line(sprintf('             menace %d / plafond %d | palier %s', $decision['threat'], $decision['ceiling'], $decision['band']));
            $this->line(sprintf('  base       %s | %s | maturite %d%%', $base->getPlanetName(), $base->getPlanetCoordinates()->asString(), $decision['maturity']));
            $this->line(sprintf('  cible      %s | %s', $target->getPlanetName(), $target->getPlanetCoordinates()->asString()));
            $this->line(sprintf('  puissance  %d points | flotte %d vaisseaux', $decision['power'], $decision['fleet']->getAmount()));
            $this->line(sprintf('  motif      %s', $decision['motive']));

            if ($simulation) {
                $this->line('  DECISION   raid possible — MISSION REELLE : NON');
                continue;
            }

            $mission = $this->raids->execute($decision);

            if ($mission === null) {
                $this->line('  DECISION   refusee par le controle de mission');
                continue;
            }

            $this->line(sprintf('  DECISION   flotte envoyee, arrivee a %s', Date::createFromTimestamp($mission->time_arrival, config('app.timezone'))->format('H:i')));

            NpcObservation::where('user_id', $player->getId())
                ->where('observed_at', '>=', Date::now()->subMinute())
                ->update(['executed' => true]);
        }
    }

    /**
     * Drop observation rows nobody will read again.
     *
     * Le tick passe tous les quarts d'heure et evalue chaque joueur actif : la table grossit
     * d'un millier de lignes par jour sur un petit serveur, et sans borne elle finirait par
     * peser plus que le jeu lui-meme. Trente jours couvrent largement une periode
     * d'observation, et ces lignes ne servent a rien d'autre.
     */
    private function purgeOldObservations(): void
    {
        $limite = Date::now()->subDays(30);

        NpcObservation::where('observed_at', '<', $limite)->delete();
        NpcBaseSnapshot::where('observed_at', '<', $limite)->delete();
    }

    /**
     * Write every verdict of this pass to the observation log.
     *
     * L'environnement calcule est consigne avec chaque ligne, et pas seulement la decision :
     * sans les valeurs qui ont produit le choix, un chiffre aberrant serait indebogable, et
     * l'on ne pourrait pas distinguer une regle qui a mal fonctionne d'un serveur qui a bouge
     * sous elle.
     *
     * @param array<int, array<string, mixed>> $evaluations
     */
    private function record(array $evaluations): void
    {
        if ($evaluations === []) {
            return;
        }

        $environnement = [
            'active_players' => $this->population->activePlayerCount(),
            'median_score' => $this->population->medianScore(),
            'threshold' => $this->population->threshold(),
            'living_bases' => $this->bases->baseCount(),
            'observed_at' => Date::now(),
        ];

        foreach ($evaluations as $evaluation) {
            /** @var PlayerService $player */
            $player = $evaluation['player'];
            $raid = $evaluation['outcome'] === NpcRaidService::OUTCOME_RAID;

            NpcObservation::create($environnement + [
                'user_id' => $player->getId(),
                'outcome' => (string)$evaluation['outcome'],
                'reason' => $evaluation['reason'],
                'threat' => (int)$evaluation['threat'],
                'band' => $evaluation['band'] !== '' ? $evaluation['band'] : null,
                'motive' => $raid ? $evaluation['motive'] : null,
                'power' => $raid ? $evaluation['power'] : null,
                'fleet_size' => $raid ? $evaluation['fleet']->getAmount() : null,
                'estimated_loot' => $raid ? $evaluation['estimated_loot'] : null,
                'base_coordinate' => $raid ? $evaluation['base']->getPlanetCoordinates()->asString() : null,
                'target_coordinate' => $raid ? $evaluation['target']->getPlanetCoordinates()->asString() : null,
                // En simulation la decision est prise mais rien ne part. Le drapeau est
                // repasse a vrai plus bas si la flotte est reellement envoyee.
                'executed' => false,
            ]);
        }
    }
}
