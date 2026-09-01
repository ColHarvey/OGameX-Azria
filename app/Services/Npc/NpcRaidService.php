<?php

namespace OGame\Services\Npc;

use Illuminate\Support\Facades\Date;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\HighscoreService;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Throwable;

/**
 * La decision d'attaquer : qui, avec quelle force, et pourquoi.
 *
 * Ce service repond a la troisieme des trois questions. Il ne decide jamais seul qu'un
 * joueur est concerne — c'est l'eligibilite — ni qu'il merite des ennuis — c'est la menace.
 * Il repond seulement a « que peut-on lui envoyer ».
 *
 * Un raid est une AttackMission ordinaire, creee par le meme chemin que l'attaque d'un
 * joueur humain. C'est ce qui lui fait heriter gratuitement du mode vacances, de la
 * protection des administrateurs, de l'alerte d'attaque, de la phalange, du combat, du
 * butin limite par les soutes, des rapports et du nettoyage.
 */
class NpcRaidService
{
    /**
     * Motifs possibles d'un raid. Le motif est repris dans le rapport de combat pour
     * expliquer au joueur pourquoi la flotte est venue.
     *
     * REGLE INTANGIBLE : une cle deja en production ne change jamais de sens.
     */
    public const array MOTIVES = [
        'first_contact',
        'reconnaissance',
        'reprisal',
        'vendetta',
        'neighbourhood',
        'plunder',
        'scavenger',
    ];

    /**
     * Verdicts possibles d'une evaluation.
     */
    public const string OUTCOME_RAID = 'raid';
    public const string OUTCOME_DECLINED = 'declined';

    /**
     * Raisons pour lesquelles les factions laissent un joueur tranquille.
     *
     * Chacune designe un reglage precis : savoir laquelle domine sur une semaine
     * d'observation dit exactement quel curseur bouger, la ou un simple « aucun raid »
     * n'apprendrait rien.
     */
    public const string REASON_NOT_ELIGIBLE = 'non eligible';
    public const string REASON_VACATION = 'mode vacances';
    public const string REASON_THREAT_TOO_LOW = 'menace insuffisante';
    public const string REASON_COOLDOWN = 'delai de garde';
    public const string REASON_DAILY_CAP = 'plafond journalier';
    public const string REASON_NO_BASE = 'aucune base assez mure';
    public const string REASON_NO_TARGET = 'aucune planete a viser';
    public const string REASON_NO_FLEET = 'base sans vaisseaux';
    public const string REASON_NO_FUEL = 'carburant insuffisant';

    /**
     * Vaisseaux qu'une base peut engager dans un raid, du plus utile au moins utile.
     *
     * Les soutes viennent en premier : sans elles le raid ne rapporte rien, puisque le
     * butin est borne par la capacite de fret des survivants.
     *
     * @var array<int, string>
     */
    private const array RAID_SHIPS = [
        'small_cargo',
        'light_fighter',
        'heavy_fighter',
        'cruiser',
        'large_cargo',
    ];

    public function __construct(
        private SettingsService $settings,
        private NpcPopulationService $population,
        private NpcThreatService $threat,
        private NpcGrowthService $growth,
        private NpcBaseService $bases,
        private PlanetServiceFactory $planetServiceFactory,
        private PlayerServiceFactory $playerServiceFactory,
        private HighscoreService $highscore
    ) {
    }

    /**
     * Work out every raid the factions would like to launch right now.
     *
     * Cette methode ne lance rien : elle decide. C'est ce qui rend le mode simulation
     * possible sans dupliquer la moindre regle — l'appelant choisit d'executer ou non.
     *
     * @return array<int, array<string, mixed>> Une decision par joueur retenu.
     */
    public function planRaids(): array
    {
        if (!$this->settings->npcEnabled()) {
            return [];
        }

        $decisions = [];

        foreach ($this->candidatePlayers() as $player) {
            $decision = $this->decideFor($player);

            if ($decision !== null) {
                $decisions[] = $decision;
            }
        }

        return $decisions;
    }

    /**
     * Evaluate every candidate player and return each verdict, raid or not.
     *
     * C'est ce que le tick consigne : les refus comptent autant que les raids, puisque ce
     * sont eux qui disent quel reglage empeche le systeme de vivre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluateAll(): array
    {
        if (!$this->settings->npcEnabled()) {
            return [];
        }

        $evaluations = [];

        foreach ($this->candidatePlayers() as $player) {
            $evaluations[] = $this->evaluate($player);
        }

        return $evaluations;
    }

    /**
     * Work out whether one player is due a raid, and what it would look like.
     *
     * @return array<string, mixed>|null
     */
    public function decideFor(PlayerService $player): array|null
    {
        $evaluation = $this->evaluate($player);

        return $evaluation['outcome'] === self::OUTCOME_RAID ? $evaluation : null;
    }

    /**
     * Work out what the factions would do about this player, and say why.
     *
     * Contrairement a decideFor(), cette methode ne rend jamais null : elle nomme toujours
     * la raison de son verdict. C'est ce qui rend la semaine d'observation exploitable —
     * savoir qu'un raid n'a pas eu lieu ne vaut rien, savoir qu'il a ete refuse par le delai
     * de garde plutot que faute de carburant dit exactement quel reglage revoir.
     *
     * @return array<string, mixed>
     */
    public function evaluate(PlayerService $player): array
    {
        if (!$this->population->canBeRaided($player)) {
            return $this->declined($player, self::REASON_NOT_ELIGIBLE);
        }

        // Verrou 1 du mode vacances : un joueur en conge n'entre jamais dans la liste des
        // cibles. Les deux autres verrous sont l'envoi, ou isMissionPossible() refuse la
        // mission, et l'arrivee, ou une flotte PNJ fait demi-tour si le joueur est parti
        // pendant le vol.
        if ($player->isInVacationMode()) {
            return $this->declined($player, self::REASON_VACATION);
        }

        $threat = $this->threat->threatOf($player);
        $band = $this->threat->bandOf($player);

        if ($band === NpcThreatService::BAND_IGNORED || $band === NpcThreatService::BAND_RECON) {
            return $this->declined($player, self::REASON_THREAT_TOO_LOW, $threat, $band);
        }

        if (!$this->cooldownElapsed($player)) {
            return $this->declined($player, self::REASON_COOLDOWN, $threat, $band);
        }

        if ($this->raidsInLastDay($player) >= $this->settings->npcMaxRaids24h()) {
            return $this->declined($player, self::REASON_DAILY_CAP, $threat, $band);
        }

        $base = $this->pickBaseFor($player);

        if ($base === null) {
            return $this->declined($player, self::REASON_NO_BASE, $threat, $band);
        }

        $targetPlanet = $this->pickTargetPlanet($player);

        if ($targetPlanet === null) {
            return $this->declined($player, self::REASON_NO_TARGET, $threat, $band);
        }

        $power = $this->raidPowerFor($player, $base, $threat);
        $fleet = $this->assembleFleet($base, $power);

        if ($fleet->getAmount() === 0) {
            return $this->declined($player, self::REASON_NO_FLEET, $threat, $band);
        }

        // Une flotte ne part pas sans carburant. Le controle est fait ici, a la decision,
        // et non a l'envoi : sans lui le tick proposerait indefiniment des raids que le
        // jeu refuserait ensuite, et le journal de simulation annoncerait des attaques qui
        // n'auraient jamais pu avoir lieu.
        if (!$this->canAffordFuel($base, $targetPlanet, $fleet)) {
            return $this->declined($player, self::REASON_NO_FUEL, $threat, $band);
        }

        return [
            'outcome' => self::OUTCOME_RAID,
            'reason' => null,
            'player' => $player,
            'base' => $base,
            'target' => $targetPlanet,
            'threat' => $threat,
            'ceiling' => $this->threat->ceilingFor($player),
            'band' => $band,
            'power' => $power,
            'fleet' => $fleet,
            'motive' => $this->motiveFor($player, $threat),
            'maturity' => $this->growth->maturityOf($base),
            'estimated_loot' => $this->estimateLoot($targetPlanet, $fleet, $base),
        ];
    }

    /**
     * Build the verdict for a player the factions are leaving alone, and name the reason.
     *
     * @return array<string, mixed>
     */
    private function declined(PlayerService $player, string $reason, int $threat = 0, string $band = ''): array
    {
        return [
            'outcome' => self::OUTCOME_DECLINED,
            'reason' => $reason,
            'player' => $player,
            'threat' => $threat,
            'band' => $band,
        ];
    }

    /**
     * Estimate what a raid would actually carry away.
     *
     * Le butin reel est borne par la capacite de fret des survivants, que seul le combat
     * determine. On ne peut donc qu'estimer, en supposant la flotte intacte : le chiffre
     * majore ce qui partirait vraiment, et sert a mesurer un ordre de grandeur sur la
     * semaine d'observation, pas a promettre un montant.
     */
    private function estimateLoot(PlanetService $target, UnitCollection $fleet, PlanetService $base): int
    {
        $available = (int)$target->metal()->get()
            + (int)$target->crystal()->get()
            + (int)$target->deuterium()->get();

        $basePlayer = $base->getPlayer();

        if ($basePlayer === null) {
            return 0;
        }

        $capacity = $fleet->getTotalCargoCapacity($basePlayer);
        $percentage = (int)$this->settings->get('loot_percentage', 50);
        $share = (int)round($available * ($percentage / 100));

        return min($share, $capacity);
    }

    /**
     * Actually send a raid that was decided upon.
     *
     * Un seul point d'entree, et c'est celui du jeu. Inserer directement dans
     * fleet_missions pour aller plus vite ferait perdre en silence toutes les protections
     * que ce chemin applique.
     *
     * @param array<string, mixed> $decision
     */
    public function execute(array $decision): FleetMission|null
    {
        /** @var PlanetService $base */
        $base = $decision['base'];
        /** @var PlanetService $target */
        $target = $decision['target'];
        /** @var UnitCollection $fleet */
        $fleet = $decision['fleet'];
        /** @var PlayerService $player */
        $player = $decision['player'];

        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $base->getPlayer()]);

        $attackMission = GameMissionFactory::getMissionById(1, [
            'fleetMissionService' => $fleetMissionService,
            'messageService' => resolve(MessageService::class),
        ]);

        // Verrou 2 : le chemin normal refuse la mission si la cible est en conge ou
        // protegee. On interroge le meme controle que celui d'un joueur humain, et on ne
        // force jamais son refus.
        $possible = $attackMission->isMissionPossible(
            $base,
            $target->getPlanetCoordinates(),
            PlanetType::Planet,
            $fleet
        );

        if (!$possible->possible) {
            return null;
        }

        try {
            $mission = $fleetMissionService->createNewFromPlanet(
                $base,
                $target->getPlanetCoordinates(),
                PlanetType::Planet,
                1,
                $fleet,
                new Resources(0, 0, 0, 0),
                10
            );
        } catch (Throwable) {
            return null;
        }

        $this->threat->recordRaid($player, (string)$decision['motive']);

        return $mission;
    }

    /**
     * Get the raid power a base may bring against this player.
     *
     * La croissance est volontairement sous-lineaire. Une relation lineaire ferait doubler
     * le raid quand le joueur double sa flotte, et grossir ne protegerait donc jamais : le
     * joueur comprendrait vite que sa flotte ne lui sert a rien contre les pirates, et
     * l'incitation deviendrait de rester faible. Or la promesse d'OGame est l'inverse.
     */
    public function raidPowerFor(PlayerService $player, PlanetService $base, int $threat): int
    {
        // Le seuil d'eligibilite sert de puissance de reference : joueur et seuil sont tous
        // deux exprimes en points, un point valant mille ressources depensees.
        $reference = max(1, $this->population->threshold());
        $playerPower = max(1, $this->highscore->getPlayerScoreMilitary($player));

        $ratio = ($playerPower / $reference) ** $this->settings->npcPowerExponent();

        $threatFactor = $this->threatFactor($threat);
        $baseFactor = max(0.1, $this->growth->maturityOf($base) / 100);

        $power = $reference * $ratio * $threatFactor * $baseFactor;

        // Plancher : sans lui, un joueur purement economique sans un seul vaisseau recevrait
        // un raid de puissance nulle, et le joueur le plus vulnerable du serveur serait le
        // seul que les pirates ignorent.
        $floor = max(1, (int)round($reference * 0.15));

        // Plafond : a menace maximale le pirate peut battre le joueur, c'est voulu, mais il
        // ne doit jamais l'ecraser.
        $ceiling = (int)round($playerPower * $this->settings->npcPowerCeiling());

        // L'ordre compte, et il n'est pas anodin : le plancher est applique en dernier, donc
        // il l'emporte sur le plafond. C'est deliberé. Chez un joueur sans le moindre
        // vaisseau, le plafond vaut zero puisqu'il se calcule sur sa force militaire ; sans
        // cette priorite, le joueur le plus vulnerable du serveur serait le seul que les
        // pirates ignoreraient.
        return (int)max($floor, min($ceiling, round($power)));
    }

    /**
     * Turn a threat value into the share of its power the faction commits.
     */
    private function threatFactor(int $threat): float
    {
        $max = $this->settings->npcThreatMax();
        $from = $max * 0.4;

        if ($threat <= $from) {
            return 0.4;
        }

        return min(1.0, 0.4 + 0.6 * (($threat - $from) / max(1, $max - $from)));
    }

    /**
     * Build a fleet from what the base actually owns, up to the wanted power.
     *
     * La force d'un raid est donc bornee par ce que la base a reellement construit : une
     * base jeune ne peut pas frapper fort, meme contre un joueur tres provocateur. C'est ce
     * qui relie la croissance a la menace sans qu'aucune regle ne l'enonce.
     */
    public function assembleFleet(PlanetService $base, int $wantedPower): UnitCollection
    {
        $fleet = new UnitCollection();
        $accumulated = 0;

        foreach (self::RAID_SHIPS as $machineName) {
            $available = $base->getObjectAmount($machineName);

            if ($available <= 0) {
                continue;
            }

            $unitScore = $this->unitScore($machineName, $base);

            if ($unitScore <= 0) {
                continue;
            }

            $needed = (int)ceil(($wantedPower - $accumulated) / $unitScore);

            if ($needed <= 0) {
                break;
            }

            // La base ne part jamais entierement : elle garde une part de sa flotte chez
            // elle, sinon un raid la laisserait sans defense et le joueur n'aurait qu'a
            // attendre le depart pour la prendre sans combat.
            $engageable = (int)floor($available * 0.7);

            if ($engageable <= 0) {
                continue;
            }

            $take = min($needed, $engageable);
            $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $take);
            $accumulated += $take * $unitScore;

            if ($accumulated >= $wantedPower) {
                break;
            }
        }

        return $fleet;
    }

    /**
     * Get whether the base holds enough deuterium to fly this fleet to the target.
     */
    private function canAffordFuel(PlanetService $base, PlanetService $target, UnitCollection $fleet): bool
    {
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $base->getPlayer()]);

        try {
            $consumption = $fleetMissionService->calculateConsumption(
                $base,
                $fleet,
                $target->getPlanetCoordinates(),
                0,
                10
            );
        } catch (Throwable) {
            return false;
        }

        return (int)$base->deuterium()->get() >= (int)$consumption;
    }

    /**
     * Get the points one unit of this type is worth.
     */
    private function unitScore(string $machineName, PlanetService $planet): int
    {
        $price = ObjectService::getObjectPrice($machineName, $planet);

        return (int)floor($price->sum() / 1000);
    }

    /**
     * Get which motive explains this raid.
     */
    public function motiveFor(PlayerService $player, int $threat): string
    {
        $last = $this->threat->lastMotiveOf($player);
        $max = $this->settings->npcThreatMax();

        return match (true) {
            $last === 'base_destroyed' => 'vendetta',
            $last === 'fleet_wiped' => 'reprisal',
            $last === 'debris_harvest' => 'scavenger',
            $last === 'espionage' => 'reconnaissance',
            $threat > $max * 0.6 => 'plunder',
            $this->threat->proximityMultiplierFor($player) > 1.0 => 'neighbourhood',
            default => 'first_contact',
        };
    }

    /**
     * Get every human player the factions might consider today.
     *
     * @return array<int, PlayerService>
     */
    private function candidatePlayers(): array
    {
        $limit = Date::now()->subDays(7)->timestamp;

        $userIds = User::query()
            ->where('is_npc', false)
            ->where('vacation_mode', false)
            ->where('username', '!=', 'Legor')
            ->whereRaw('users.time + 0 >= ?', [$limit])
            ->pluck('id')
            ->all();

        $players = [];

        foreach ($userIds as $userId) {
            $players[] = $this->playerServiceFactory->make((int)$userId, true);
        }

        return $players;
    }

    /**
     * Get whether enough time has passed since this player's last raid.
     */
    private function cooldownElapsed(PlayerService $player): bool
    {
        $last = $this->threat->lastRaidAt($player);

        if ($last === null) {
            return true;
        }

        return $last->copy()->addHours($this->settings->npcRaidCooldownHours())->isPast();
    }

    /**
     * Count the raids sent against this player over the past day.
     *
     * Compte les missions reelles plutot qu'un compteur stocke : la source de verite est le
     * jeu lui-meme, et un compteur separe pourrait deriver.
     */
    private function raidsInLastDay(PlayerService $player): int
    {
        $planetIds = [];

        foreach ($player->planets->all() as $planet) {
            $planetIds[] = $planet->getPlanetId();
        }

        if ($planetIds === []) {
            return 0;
        }

        return FleetMission::query()
            ->join('users', 'users.id', '=', 'fleet_missions.user_id')
            ->where('users.is_npc', true)
            ->where('fleet_missions.mission_type', 1)
            ->whereIn('fleet_missions.planet_id_to', $planetIds)
            ->where('fleet_missions.time_departure', '>=', Date::now()->subDay()->timestamp)
            ->count();
    }

    /**
     * Choose which base sends the raid.
     *
     * La base la plus proche et assez mure. Le voisinage compte : ce sont les pirates qui
     * vivent a cote qui ont un probleme avec ce joueur.
     */
    private function pickBaseFor(PlayerService $player): PlanetService|null
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($this->bases->livingBases() as $base) {
            $planet = $this->planetServiceFactory->make($base->id, true);

            if ($planet === null) {
                continue;
            }

            if ($this->growth->maturityOf($planet) < 20) {
                continue;
            }

            $distance = $this->distanceToPlayer($planet, $player);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $planet;
            }
        }

        return $best;
    }

    /**
     * Choose which of the player's planets the raid aims at.
     *
     * La plus riche : un pirate cherche des ressources, pas un trophee.
     */
    private function pickTargetPlanet(PlayerService $player): PlanetService|null
    {
        $best = null;
        $bestValue = -1;

        foreach ($player->planets->all() as $planet) {
            if ($planet->isMoon()) {
                continue;
            }

            $value = (int)$planet->metal()->get() + (int)$planet->crystal()->get() + (int)$planet->deuterium()->get();

            if ($value > $bestValue) {
                $bestValue = $value;
                $best = $planet;
            }
        }

        return $best;
    }

    /**
     * Get a coarse distance between a base and the player's nearest planet.
     */
    private function distanceToPlayer(PlanetService $base, PlayerService $player): int
    {
        $from = $base->getPlanetCoordinates();
        $closest = PHP_INT_MAX;

        foreach ($player->planets->all() as $planet) {
            $to = $planet->getPlanetCoordinates();

            $distance = $from->galaxy === $to->galaxy
                ? abs($from->system - $to->system)
                : abs($from->galaxy - $to->galaxy) * 20000;

            $closest = min($closest, $distance);
        }

        return $closest;
    }

    /**
     * Get the planets belonging to a faction, as raw models.
     *
     * @return array<int, Planet>
     */
    public function basePlanets(): array
    {
        return $this->bases->livingBases()->all();
    }
}
