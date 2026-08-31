<?php

namespace OGame\Services;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\Models\EventMissionClaim;
use OGame\Models\EventRankClaim;
use OGame\Models\Resources;
use RuntimeException;

/**
 * Evenement de missions quotidiennes.
 *
 * L'evenement est ouvert et borne par l'administrateur. Tant qu'il court, chaque joueur
 * recoit chaque jour un tirage de missions, en gagne du tritium, et debloque cinq rangs de
 * recompenses au fil de son total.
 *
 * Deux partis pris structurent tout le service :
 *
 * 1. Rien n'est instrumente dans le code de jeu. Une mission n'est pas un compteur qu'on
 *    incremente au bon endroit, c'est une requete sur les tables que le jeu remplit deja
 *    (files de construction, missions de flotte, transactions de matiere noire). Le systeme
 *    se greffe a cote du jeu au lieu de s'y infiltrer, et une fusion avec l'amont ne peut
 *    pas le casser.
 *
 * 2. Le tirage n'est pas stocke. Les missions du jour se derivent de l'identifiant du
 *    joueur et de la date : le meme joueur voit les memes missions toute la journee, sans
 *    qu'aucune ligne ne soit ecrite tant qu'il ne reclame rien. Seules les reclamations
 *    vivent en base.
 *
 * Comme dans OGame, une mission se valide au lancement et non a l'achevement : une
 * construction mise en file compte meme si le joueur l'annule ensuite.
 */
class EventMissionService
{
    /**
     * Nombre de rangs de recompenses.
     */
    public const RANK_COUNT = 5;

    /**
     * Bonus de tritium accorde aux joueurs possedant le Conseil d'officiers.
     */
    private const OFFICER_BONUS = 0.2;

    /**
     * Nombre d'officiers actifs requis pour le bonus.
     *
     * Cinq, comme dans OGame. A ce prix peu de joueurs l'atteindront sur ce serveur : c'est
     * un objectif de fin de partie assume. Descendre a trois se fait ici, et nulle part
     * ailleurs.
     */
    private const OFFICER_BONUS_REQUIRED = 5;

    /**
     * Catalogue des missions.
     *
     * 'tritium' suit l'echelle officielle : 100 pour une action de routine, 200 pour un
     * effort, 300 pour une action qui engage des ressources ou de la matiere noire.
     * 'target' est la quantite a atteindre dans la journee.
     *
     * @var array<string, array{tritium: int, target: int}>
     */
    private const MISSIONS = [
        'login' => ['tritium' => 100, 'target' => 1],
        'building' => ['tritium' => 100, 'target' => 1],
        'research' => ['tritium' => 200, 'target' => 1],
        'ships' => ['tritium' => 200, 'target' => 5],
        'defence' => ['tritium' => 200, 'target' => 5],
        'expedition' => ['tritium' => 300, 'target' => 1],
        'espionage' => ['tritium' => 100, 'target' => 3],
        'transport_own' => ['tritium' => 300, 'target' => 50000],
        'transport_other' => ['tritium' => 300, 'target' => 10000],
        'deployment' => ['tritium' => 200, 'target' => 1],
        'fleet_size' => ['tritium' => 200, 'target' => 10],
        'chat' => ['tritium' => 100, 'target' => 1],
        'alliance' => ['tritium' => 100, 'target' => 3],
        'shop' => ['tritium' => 300, 'target' => 1],
        'halving' => ['tritium' => 300, 'target' => 1],
    ];

    /**
     * Seuils des rangs, en pourcentage du tritium total qu'un joueur peut obtenir sur la
     * duree de l'evenement.
     *
     * Exprimes en pourcentage et non en valeur absolue pour que les rangs gardent le meme
     * sens quelle que soit la duree choisie par l'administrateur : un evenement de trois
     * jours et un de quinze jours demandent le meme investissement relatif.
     *
     * @var array<int, int>
     */
    private const RANK_THRESHOLDS = [1 => 15, 2 => 32, 3 => 50, 4 => 70, 5 => 90];

    /**
     * Recompenses proposees a chaque rang : trois choix, dont un seul est attribue.
     *
     * Les montants sont le levier de reglage de tout l'evenement. Reperes : le pack de
     * bienvenue donne 3 000 metal au jour 1, une expedition rapporte 150 a 200 MN, et un
     * objet de boutique coute 250 a 1 800 MN. Les references d'objets sont celles du
     * catalogue de ShopService.
     *
     * @var array<int, array<string, array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>}>>
     */
    private const RANK_REWARDS = [
        1 => [
            'resources' => ['metal' => 10000, 'crystal' => 5000, 'deuterium' => 0, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 300, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['40f6c78e11be01ad3389b7dccd6ab8efa9347f3c']],
        ],
        2 => [
            'resources' => ['metal' => 25000, 'crystal' => 12000, 'deuterium' => 3000, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 600, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['d3d541ecc23e4daa0c698e44c32f04afd2037d84']],
        ],
        3 => [
            'resources' => ['metal' => 50000, 'crystal' => 25000, 'deuterium' => 8000, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 1200, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['d26f4dab76fdc5296e3ebec11a1e1d2558c713ea']],
        ],
        4 => [
            'resources' => ['metal' => 100000, 'crystal' => 50000, 'deuterium' => 20000, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 2000, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['4a58d4978bbe24e3efb3b0248e21b3b4b1bfbd8a', '27cbcd52f16693023cb966e5026d8a1efbbfc0f9']],
        ],
        5 => [
            'resources' => ['metal' => 200000, 'crystal' => 100000, 'deuterium' => 50000, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 4000, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['929d5e15709cc51a4500de4499e19763c879f7f7', '0968999df2fe956aa4a07aea74921f860af7d97f']],
        ],
    ];

    public function __construct(
        private SettingsService $settings,
        private OfficerService $officerService,
        private DarkMatterService $darkMatterService,
        private ShopService $shopService
    ) {
    }

    /**
     * Returns whether the event is currently open to players.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        if (!$this->settings->eventMissionsEnabled()) {
            return false;
        }

        $start = $this->getStart();
        $end = $this->getEnd();

        if ($start === null || $end === null) {
            return false;
        }

        $today = Date::now()->startOfDay();

        return $today->greaterThanOrEqualTo($start) && $today->lessThanOrEqualTo($end);
    }

    /**
     * Returns the event start date, or null when it is not configured.
     *
     * @return Carbon|null
     */
    public function getStart(): Carbon|null
    {
        return $this->parseDate($this->settings->eventMissionsStart());
    }

    /**
     * Returns the event end date, or null when it is not configured.
     *
     * @return Carbon|null
     */
    public function getEnd(): Carbon|null
    {
        return $this->parseDate($this->settings->eventMissionsEnd());
    }

    /**
     * Returns today's missions for a player, with their progress.
     *
     * @param PlayerService $player
     * @return array<int, array{key: string, tritium: int, target: int, progress: int, done: bool, claimed: bool}>
     */
    public function getDailyMissions(PlayerService $player): array
    {
        $today = Date::now()->startOfDay();

        $claimed = EventMissionClaim::where('user_id', $player->getId())
            ->whereDate('mission_date', $today)
            ->pluck('mission_key')
            ->all();

        $missions = [];
        foreach ($this->drawMissionKeys($player->getId(), $today) as $key) {
            $progress = $this->measure($player, $key, $today);
            $target = self::MISSIONS[$key]['target'];

            $missions[] = [
                'key' => $key,
                'tritium' => $this->tritiumFor($player, $key),
                'target' => $target,
                'progress' => min($progress, $target),
                'done' => $progress >= $target,
                'claimed' => in_array($key, $claimed, true),
            ];
        }

        return $missions;
    }

    /**
     * Claims one of today's missions.
     *
     * @param PlayerService $player
     * @param string $key
     * @return void
     * @throws RuntimeException Si la mission n'est pas reclamable.
     */
    public function claimMission(PlayerService $player, string $key): void
    {
        if (!$this->isRunning()) {
            throw new RuntimeException(__('t_ingame.events.error_not_running'));
        }

        $today = Date::now()->startOfDay();

        if (!in_array($key, $this->drawMissionKeys($player->getId(), $today), true)) {
            throw new RuntimeException(__('t_ingame.events.error_unknown_mission'));
        }

        if ($this->measure($player, $key, $today) < self::MISSIONS[$key]['target']) {
            throw new RuntimeException(__('t_ingame.events.error_not_done'));
        }

        try {
            EventMissionClaim::create([
                'user_id' => $player->getId(),
                'mission_date' => $today,
                'mission_key' => $key,
                'tritium' => $this->tritiumFor($player, $key),
                'claimed_at' => Date::now(),
            ]);
        } catch (QueryException $e) {
            if ((int)$e->getCode() === 23000) {
                throw new RuntimeException(__('t_ingame.events.error_already_claimed'));
            }

            throw $e;
        }
    }

    /**
     * Returns the tritium a player has accumulated during the current event.
     *
     * @param PlayerService $player
     * @return int
     */
    public function getTritium(PlayerService $player): int
    {
        $start = $this->getStart();
        $end = $this->getEnd();

        if ($start === null || $end === null) {
            return 0;
        }

        return (int)EventMissionClaim::where('user_id', $player->getId())
            ->whereBetween('mission_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->sum('tritium');
    }

    /**
     * Returns the tritium a player could obtain over the whole event.
     *
     * Le maximum est calcule sur le tirage reel du joueur, jour par jour, et non sur une
     * moyenne : deux joueurs n'ont pas les memes missions, donc pas le meme plafond. Les
     * seuils de rang restent ainsi equitables. Le bonus d'officiers est volontairement exclu
     * du calcul : c'est ce qui le rend avantageux.
     *
     * @param PlayerService $player
     * @return int
     */
    public function getMaxTritium(PlayerService $player): int
    {
        $start = $this->getStart();
        $end = $this->getEnd();

        if ($start === null || $end === null) {
            return 0;
        }

        $total = 0;
        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            foreach ($this->drawMissionKeys($player->getId(), $day) as $key) {
                $total += self::MISSIONS[$key]['tritium'];
            }
        }

        return $total;
    }

    /**
     * Returns the five ranks with their thresholds, state and available rewards.
     *
     * @param PlayerService $player
     * @return array<int, array{rank: int, threshold: int, reached: bool, claimed: bool, chosen: string|null, rewards: array<string, array{reward: array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>}, summary: string}>}>
     */
    public function getRanks(PlayerService $player): array
    {
        $tritium = $this->getTritium($player);
        $max = $this->getMaxTritium($player);
        $start = $this->getStart();

        $claims = [];
        if ($start !== null) {
            $claims = EventRankClaim::where('user_id', $player->getId())
                ->whereDate('event_start', $start)
                ->pluck('reward_key', 'rank')
                ->all();
        }

        $ranks = [];
        for ($rank = 1; $rank <= self::RANK_COUNT; $rank++) {
            $threshold = (int)ceil($max * self::RANK_THRESHOLDS[$rank] / 100);

            $ranks[$rank] = [
                'rank' => $rank,
                'threshold' => $threshold,
                'reached' => $max > 0 && $tritium >= $threshold,
                'claimed' => isset($claims[$rank]),
                'chosen' => $claims[$rank] ?? null,
                'rewards' => $this->describeRewards($rank),
            ];
        }

        return $ranks;
    }

    /**
     * Claims one rank reward, of the player's choosing.
     *
     * Le tritium n'est pas consomme : atteindre le seuil suffit, et le total reste acquis
     * pour les rangs suivants. C'est la regle du jeu officiel.
     *
     * @param PlayerService $player
     * @param int $rank
     * @param string $rewardKey
     * @return void
     * @throws RuntimeException Si le rang n'est pas reclamable.
     */
    public function claimRank(PlayerService $player, int $rank, string $rewardKey): void
    {
        if (!$this->isRunning()) {
            throw new RuntimeException(__('t_ingame.events.error_not_running'));
        }

        $ranks = $this->getRanks($player);

        if (!isset($ranks[$rank])) {
            throw new RuntimeException(__('t_ingame.events.error_unknown_rank'));
        }

        if (!$ranks[$rank]['reached']) {
            throw new RuntimeException(__('t_ingame.events.error_rank_locked'));
        }

        if (!isset($ranks[$rank]['rewards'][$rewardKey])) {
            throw new RuntimeException(__('t_ingame.events.error_unknown_reward'));
        }

        $reward = $ranks[$rank]['rewards'][$rewardKey]['reward'];
        $start = $this->getStart();

        if ($start === null) {
            throw new RuntimeException(__('t_ingame.events.error_not_running'));
        }

        DB::transaction(function () use ($player, $rank, $rewardKey, $reward, $start): void {
            // L'enregistrement precede la distribution : si la contrainte d'unicite rejette
            // l'insertion, la transaction est abandonnee et rien n'est attribue.
            try {
                EventRankClaim::create([
                    'user_id' => $player->getId(),
                    'event_start' => $start,
                    'rank' => $rank,
                    'reward_key' => $rewardKey,
                    'claimed_at' => Date::now(),
                ]);
            } catch (QueryException $e) {
                if ((int)$e->getCode() === 23000) {
                    throw new RuntimeException(__('t_ingame.events.error_rank_already_claimed'));
                }

                throw $e;
            }

            if ($reward['metal'] + $reward['crystal'] + $reward['deuterium'] > 0) {
                $player->planets->current()->addResources(
                    new Resources($reward['metal'], $reward['crystal'], $reward['deuterium'], 0)
                );
            }

            if ($reward['dark_matter'] > 0) {
                $this->darkMatterService->credit(
                    $player->getUser(),
                    $reward['dark_matter'],
                    DarkMatterTransactionType::EVENT_REWARD->value,
                    __('t_ingame.events.transaction_description', ['rank' => $rank])
                );
            }

            foreach ($reward['items'] as $ref) {
                $this->shopService->addToInventory($player->getUser(), $ref);
            }
        });
    }

    /**
     * Draws the mission keys of one player for one day.
     *
     * Le tirage est deterministe : classer les cles par le hachage de "cle|joueur|date"
     * donne un ordre stable, different pour chaque joueur et pour chaque jour, sans rien
     * ecrire en base et sans toucher a l'etat global du generateur aleatoire.
     *
     * @param int $userId
     * @param Carbon $day
     * @return array<int, string>
     */
    public function drawMissionKeys(int $userId, Carbon $day): array
    {
        $graine = $userId . '|' . $day->format('Y-m-d');

        $cles = array_keys(self::MISSIONS);
        usort($cles, function (string $a, string $b) use ($graine): int {
            return strcmp(md5($a . '|' . $graine), md5($b . '|' . $graine));
        });

        $nombre = max(1, min($this->settings->eventMissionsPerDay(), count($cles)));

        return array_slice($cles, 0, $nombre);
    }

    /**
     * Returns the tritium one mission is worth to one player, officer bonus included.
     *
     * @param PlayerService $player
     * @param string $key
     * @return int
     */
    private function tritiumFor(PlayerService $player, string $key): int
    {
        $tritium = self::MISSIONS[$key]['tritium'];

        if ($this->officerService->countActive($player->getUser()) >= self::OFFICER_BONUS_REQUIRED) {
            $tritium = (int)round($tritium * (1 + self::OFFICER_BONUS));
        }

        return $tritium;
    }

    /**
     * Measures a player's progress on one mission for one day.
     *
     * Chaque mesure interroge les tables que le jeu remplit deja. Comme dans OGame, ce qui
     * compte est le lancement de l'action : une flotte rappelee ou une construction annulee
     * valide quand meme la mission.
     *
     * @param PlayerService $player
     * @param string $key
     * @param Carbon $day
     * @return int
     */
    private function measure(PlayerService $player, string $key, Carbon $day): int
    {
        $userId = $player->getId();
        $debut = (int)$day->copy()->startOfDay()->timestamp;
        $fin = (int)$day->copy()->endOfDay()->timestamp;
        $debutDate = $day->copy()->startOfDay();
        $finDate = $day->copy()->endOfDay();

        return match ($key) {
            // Consulter la page prouve la connexion : la mesure est toujours acquise.
            'login' => 1,

            'building' => DB::table('building_queues')
                ->whereIn('planet_id', $this->planetIds($userId))
                ->whereBetween('time_start', [$debut, $fin])
                ->count(),

            'research' => DB::table('research_queues')
                ->whereIn('planet_id', $this->planetIds($userId))
                ->whereBetween('time_start', [$debut, $fin])
                ->count(),

            'ships' => $this->sumUnits($userId, $this->shipIds(), $debut, $fin),
            'defence' => $this->sumUnits($userId, $this->defenceIds(), $debut, $fin),

            'expedition' => $this->countMissions($userId, 15, $debut, $fin),
            'espionage' => $this->countMissions($userId, 6, $debut, $fin),
            'deployment' => $this->countMissions($userId, 4, $debut, $fin),

            'transport_own' => $this->sumTransport($userId, true, $debut, $fin),
            'transport_other' => $this->sumTransport($userId, false, $debut, $fin),

            'fleet_size' => $this->largestFleet($userId, $debut, $fin),

            'chat' => DB::table('chat_messages')
                ->where('sender_id', $userId)
                ->whereBetween('created_at', [$debutDate, $finDate])
                ->count(),

            // Etat et non action : appartenir a une alliance d'au moins trois membres suffit,
            // quel que soit le jour ou le joueur l'a rejointe.
            'alliance' => $this->allianceSize($userId),

            'shop' => $this->countDarkMatter($userId, DarkMatterTransactionType::SHOP_ITEM, $debutDate, $finDate),
            'halving' => $this->countDarkMatter($userId, DarkMatterTransactionType::HALVING, $debutDate, $finDate),

            default => 0,
        };
    }

    /**
     * Sums the units of a given family queued by a player in a window.
     *
     * @param int $userId
     * @param array<int, int> $objectIds
     * @param int $debut
     * @param int $fin
     * @return int
     */
    private function sumUnits(int $userId, array $objectIds, int $debut, int $fin): int
    {
        return (int)DB::table('unit_queues')
            ->whereIn('planet_id', $this->planetIds($userId))
            ->whereIn('object_id', $objectIds)
            ->whereBetween('time_start', [$debut, $fin])
            ->sum('object_amount');
    }

    /**
     * Sums the resources a player sent by transport in a window.
     *
     * @param int $userId
     * @param bool $versSoi Vers ses propres planetes, ou vers celles d'un autre joueur.
     * @param int $debut
     * @param int $fin
     * @return int
     */
    private function sumTransport(int $userId, bool $versSoi, int $debut, int $fin): int
    {
        $planetIds = $this->planetIds($userId);

        $requete = DB::table('fleet_missions')
            ->where('user_id', $userId)
            ->where('mission_type', 3)
            ->where('canceled', 0)
            ->whereBetween('time_departure', [$debut, $fin]);

        if ($versSoi) {
            $requete->whereIn('planet_id_to', $planetIds);
        } else {
            $requete->whereNotNull('planet_id_to')->whereNotIn('planet_id_to', $planetIds);
        }

        // selectRaw plutot que sum() sur une expression : la somme porte sur trois colonnes,
        // et COALESCE evite un null quand aucune ligne ne correspond.
        $total = $requete->selectRaw('COALESCE(SUM(metal + crystal + deuterium), 0) AS total')->value('total');

        return (int)$total;
    }

    /**
     * Returns the size of the largest fleet a player launched in a window.
     *
     * Le plus grand envoi de la journee, et non le cumul : la mission demande une flotte
     * d'au moins N vaisseaux, pas N vaisseaux repartis sur dix envois.
     *
     * @param int $userId
     * @param int $debut
     * @param int $fin
     * @return int
     */
    private function largestFleet(int $userId, int $debut, int $fin): int
    {
        $somme = 'light_fighter + heavy_fighter + cruiser + battle_ship + battlecruiser'
            . ' + bomber + destroyer + deathstar + small_cargo + large_cargo'
            . ' + colony_ship + recycler + espionage_probe';

        $total = DB::table('fleet_missions')
            ->where('user_id', $userId)
            ->where('canceled', 0)
            ->whereBetween('time_departure', [$debut, $fin])
            ->selectRaw('COALESCE(MAX(' . $somme . '), 0) AS total')
            ->value('total');

        return (int)$total;
    }

    /**
     * Counts fleet missions of one type launched by a player in a window.
     *
     * @param int $userId
     * @param int $missionType
     * @param int $debut
     * @param int $fin
     * @return int
     */
    private function countMissions(int $userId, int $missionType, int $debut, int $fin): int
    {
        return DB::table('fleet_missions')
            ->where('user_id', $userId)
            ->where('mission_type', $missionType)
            ->where('canceled', 0)
            ->whereBetween('time_departure', [$debut, $fin])
            ->count();
    }

    /**
     * Counts dark matter transactions of one type in a window.
     *
     * @param int $userId
     * @param DarkMatterTransactionType $type
     * @param Carbon $debut
     * @param Carbon $fin
     * @return int
     */
    private function countDarkMatter(int $userId, DarkMatterTransactionType $type, Carbon $debut, Carbon $fin): int
    {
        return DB::table('dark_matter_transactions')
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->whereBetween('created_at', [$debut, $fin])
            ->count();
    }

    /**
     * Returns the number of members in the player's alliance, 0 when they have none.
     *
     * @param int $userId
     * @return int
     */
    private function allianceSize(int $userId): int
    {
        $allianceId = DB::table('alliance_members')->where('user_id', $userId)->value('alliance_id');

        if ($allianceId === null) {
            return 0;
        }

        return DB::table('alliance_members')->where('alliance_id', $allianceId)->count();
    }

    /**
     * Returns the ids of a player's planets.
     *
     * @param int $userId
     * @return array<int, int>
     */
    private function planetIds(int $userId): array
    {
        return DB::table('planets')->where('user_id', $userId)->pluck('id')->all();
    }

    /**
     * Returns the object ids of every ship.
     *
     * @return array<int, int>
     */
    private function shipIds(): array
    {
        return array_map(fn (GameObject $objet): int => $objet->id, ObjectService::getShipObjects());
    }

    /**
     * Returns the object ids of every defence unit.
     *
     * @return array<int, int>
     */
    private function defenceIds(): array
    {
        return array_map(fn (GameObject $objet): int => $objet->id, ObjectService::getDefenseObjects());
    }

    /**
     * Parses a Y-m-d setting into a date, or null when it is empty or malformed.
     *
     * @param string $valeur
     * @return Carbon|null
     */
    private function parseDate(string $valeur): Carbon|null
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) !== 1) {
            return null;
        }

        try {
            return Date::parse($valeur)->startOfDay();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Returns the rewards of one rank, each with a readable summary.
     *
     * Le resume est construit ici et non dans la vue : la vue reste declarative, et le
     * libelle d'un objet suit le catalogue de ShopService plutot qu'une copie figee.
     *
     * @param int $rank
     * @return array<string, array{reward: array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>}, summary: string}>
     */
    private function describeRewards(int $rank): array
    {
        $rewards = [];

        foreach (self::RANK_REWARDS[$rank] as $rewardKey => $reward) {
            $rewards[$rewardKey] = ['reward' => $reward, 'summary' => $this->describe($reward)];
        }

        return $rewards;
    }

    /**
     * Readable summary of one reward, for example "10 000 metal, 5 000 cristal".
     *
     * @param array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>} $reward
     * @return string
     */
    private function describe(array $reward): string
    {
        $parts = [];

        foreach (['metal', 'crystal', 'deuterium', 'dark_matter'] as $key) {
            if ($reward[$key] > 0) {
                $parts[] = number_format($reward[$key], 0, ',', ' ') . ' ' . __('t_ingame.rewards.gain_' . $key);
            }
        }

        foreach ($reward['items'] as $ref) {
            $item = $this->shopService->getItemByRef($ref);

            if ($item === null) {
                continue;
            }

            $parts[] = __('t_resources.' . $item['name_key'] . '.title')
                . ' ' . __('t_ingame.shop.tier_' . $item['tier_key']);
        }

        return implode(', ', $parts);
    }
}
