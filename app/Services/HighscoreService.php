<?php

namespace OGame\Services;

use Cache;
use Exception;
use Illuminate\Support\Facades\DB;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Facades\AppUtil;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\CivilShipObjects;
use OGame\GameObjects\MilitaryShipObjects;
use OGame\Models\Alliance;
use OGame\Models\AllianceHighscore;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Resources;

/**
 * Class Highscore.
 *
 * Service object for calculating and retrieving highscores.
 *
 * @package OGame\Services
 */
class HighscoreService
{
    /**
     * Highscore type to calculate.
     * @var HighscoreTypeEnum
     */
    private HighscoreTypeEnum $highscoreType;

    /**
     * Highscore constructor.
     *
     * @param PlayerServiceFactory $playerServiceFactory PlayerServiceFactory object.
     * @param SettingsService $settingsService SettingsService object.
     */
    public function __construct(private PlayerServiceFactory $playerServiceFactory, private SettingsService $settingsService)
    {
    }

    /**
     * Check if admin users should be visible in highscores.
     *
     * @return bool
     */
    public function isAdminVisibleInHighscore(): bool
    {
        return $this->settingsService->highscoreAdminVisible();
    }

    /**
     * Set the highscore type to calculate.
     *
     * @param int $type
     * @return void
     */
    public function setHighscoreType(int $type): void
    {
        // 0 = general score
        // 1 = economy points
        // 2 = research points
        // 3 = military points
        $this->highscoreType = HighscoreTypeEnum::cases()[$type];
    }

    /**
     * Get player fleet mission score for ships currently in transit.
     * This calculates the general score of all ships that are on active fleet missions.
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    private function getPlayerFleetMissionScore(PlayerService $player): int
    {
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);
        $activeMissions = $fleetMissionService->getActiveFleetMissionsSentByCurrentPlayer();

        $resources_spent = new Resources(0, 0, 0, 0);

        foreach ($activeMissions as $mission) {
            // Skip processed missions (already counted on planet)
            if ($mission->processed) {
                continue;
            }

            // Calculate score for all ships in this mission
            foreach (ObjectService::getShipObjects() as $ship) {
                $amount = $mission->{$ship->machine_name} ?? 0;
                if ($amount > 0) {
                    $raw_price = ObjectService::getObjectRawPrice($ship->machine_name);
                    $resources_spent->add($raw_price->multiply($amount));
                }
            }
        }

        return (int)floor($resources_spent->sum() / 1000);
    }

    /**
     * Get player fleet mission military score for ships currently in transit.
     * Military score includes:
     * - 100% military ships
     * - 50% civil ships
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    private function getPlayerFleetMissionScoreMilitary(PlayerService $player): int
    {
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);
        $activeMissions = $fleetMissionService->getActiveFleetMissionsSentByCurrentPlayer();

        $resources_spent = 0;

        foreach ($activeMissions as $mission) {
            // Skip processed missions (already counted on planet)
            if ($mission->processed) {
                continue;
            }

            // Military ships (100%)
            foreach (ObjectService::getMilitaryShipObjects() as $ship) {
                $amount = $mission->{$ship->machine_name} ?? 0;
                if ($amount > 0) {
                    $raw_price = ObjectService::getObjectRawPrice($ship->machine_name);
                    $resources_spent += $raw_price->multiply($amount)->sum();
                }
            }

            // Civil ships (50%)
            foreach (ObjectService::getCivilShipObjects() as $ship) {
                $amount = $mission->{$ship->machine_name} ?? 0;
                if ($amount > 0) {
                    $raw_price = ObjectService::getObjectRawPrice($ship->machine_name);
                    $resources_spent += $raw_price->multiply($amount)->sum() * 0.5;
                }
            }
        }

        return (int)floor($resources_spent / 1000);
    }

    /**
     * Get player fleet mission economy score for ships currently in transit.
     * Economy score includes:
     * - 50% civil ships
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    private function getPlayerFleetMissionScoreEconomy(PlayerService $player): int
    {
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);
        $activeMissions = $fleetMissionService->getActiveFleetMissionsSentByCurrentPlayer();

        $resources_spent = 0;

        foreach ($activeMissions as $mission) {
            // Skip processed missions (already counted on planet)
            if ($mission->processed) {
                continue;
            }

            // Civil ships (50%)
            foreach (ObjectService::getCivilShipObjects() as $ship) {
                $amount = $mission->{$ship->machine_name} ?? 0;
                if ($amount > 0) {
                    $raw_price = ObjectService::getObjectRawPrice($ship->machine_name);
                    $resources_spent += $raw_price->multiply($amount)->sum() * 0.5;
                }
            }
        }

        return (int)floor($resources_spent / 1000);
    }

    /**
     * Get player score.
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    public function getPlayerScore(PlayerService $player): int
    {
        $score = 0;
        // Get score for buildings and units on player owned planets
        foreach ($player->planets->all() as $planet) {
            $score += $planet->getPlanetScore();
        }

        // Get score for research levels of player
        $score += $player->getResearchScore();

        // Get score for fleets that are on missions (in transit)
        $score += $this->getPlayerFleetMissionScore($player);

        // Cap at PHP_INT_MAX to prevent overflow on PHP 8.5+
        if ($score > PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return $score;
    }

    /**
     * Get player research score.
     *
     * @param PlayerService $player
     * @return int
     */
    public function getPlayerScoreResearch(PlayerService $player): int
    {
        return $player->getResearchScore();
    }

    /**
     * Get player military score.
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    public function getPlayerScoreMilitary(PlayerService $player): int
    {
        $points = 0;

        // Get points (sum of all unit amounts) for units on player owned planets.
        foreach ($player->planets->all() as $planet) {
            $points += $planet->getPlanetMilitaryScore();
        }

        // Get military score for fleets that are on missions (in transit)
        $points += $this->getPlayerFleetMissionScoreMilitary($player);

        return $points;
    }

    /**
     * Get player economy score.
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    public function getPlayerScoreEconomy(PlayerService $player): int
    {
        $points = 0;

        // Get score for buildings and units on player owned planets (economy specific calculation).
        foreach ($player->planets->all() as $planet) {
            $points += $planet->getPlanetScoreEconomy();
        }

        // Get economy score for fleets that are on missions (in transit)
        $points += $this->getPlayerFleetMissionScoreEconomy($player);

        return $points;
    }

    /**
     * Get player's total ship count across all planets and fleets.
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    public function getPlayerTotalShipCount(PlayerService $player): int
    {
        $totalShips = 0;

        // Get all ship objects (military + civil)
        $shipObjects = [...MilitaryShipObjects::get(), ...CivilShipObjects::get()];

        // Count ships on all planets
        foreach ($player->planets->all() as $planet) {
            foreach ($shipObjects as $ship) {
                $totalShips += $planet->getObjectAmount($ship->machine_name);
            }
        }

        // Count ships in active fleet missions (exclude processed missions)
        $fleetMissions = FleetMission::where('user_id', $player->getId())
            ->where('processed', false)
            ->get();
        foreach ($fleetMissions as $mission) {
            // Count ships in the mission
            foreach ($shipObjects as $ship) {
                $shipAmount = $mission->{$ship->machine_name} ?? 0;
                $totalShips += $shipAmount;
            }
        }

        return $totalShips;
    }

    /**
     * Get highscores.
     *
     * @param int $perPage
     * @param int $pageOn
     * @return array<int, array<string,mixed>>
     */
    public function getHighscorePlayers(int $perPage = 100, int $pageOn = 1): array
    {
        // Get all player highscores
        $adminVisible = $this->isAdminVisibleInHighscore();
        return Cache::remember(sprintf('highscores-%s-%d-%s', $this->highscoreType->name, $pageOn, $adminVisible ? '1' : '0'), now()->addMinutes(5), function () use ($perPage, $pageOn, $adminVisible) {
            $parsedHighscores = [];

            $query = Highscore::query()
                ->whereHas('player.tech')
                ->with(['player', 'player.alliance', 'player.roles'])
                ->validRanks()
                ->orderBy($this->highscoreType->name.'_rank');

            // Filter out admin users if setting is disabled
            if (!$adminVisible) {
                $query->whereHas('player', function ($q) {
                    $q->whereDoesntHave('roles', function ($roleQuery) {
                        $roleQuery->where('name', 'admin');
                    });
                });
            }

            $highscores = $query->paginate(perPage: $perPage, page: $pageOn);

            foreach ($highscores as $playerScore) {
                // Load player object
                // TODO we only use this for the planet details now-- could we perhaps store the planet details in the highscore table too?.
                $playerService = $this->playerServiceFactory->make($playerScore->player_id);

                // Get player main planet coords
                $mainPlanet = $playerService->planets->first();

                // Skip players without any planets
                if ($mainPlanet === null) {
                    continue;
                }

                $score = $playerScore->{$this->highscoreType->name} ?? 0;
                $score_formatted = AppUtil::formatNumber($score);

                // Get player's alliance information if they're in one
                $allianceTag = null;
                $allianceId = null;
                if ($playerScore->player->alliance_id) {
                    /** @var Alliance|null $alliance */
                    $alliance = $playerScore->player->alliance;
                    if ($alliance) {
                        $allianceTag = $alliance->alliance_tag;
                        $allianceId = $alliance->id;
                    }
                }

                // Get total ship count for military highscore
                $totalShips = null;
                if ($this->highscoreType === HighscoreTypeEnum::military) {
                    $totalShips = $this->getPlayerTotalShipCount($playerService);
                }

                $parsedHighscores[] = [
                    'id' => $playerScore->player_id,
                    'name' => $playerScore->player->username,
                    'points' => $score,
                    'points_formatted' => $score_formatted,
                    'planet_coords' => $mainPlanet->getPlanetCoordinates(),
                    'rank' => $playerScore->{$this->highscoreType->name.'_rank'},
                    'is_admin' => $playerService->isAdmin(),
                    'alliance_tag' => $allianceTag,
                    'alliance_id' => $allianceId,
                    'total_ships' => $totalShips,
                ];
            }
            return $this->insertFactionRows($parsedHighscores);
        });
    }

    /**
     * Slot the hostile faction rows into a page of the player highscore.
     *
     * Les comptes PNJ individuels restent hors classement, au rang 0 : ce n'etait pas une
     * question d'affichage mais de calcul, puisque la mediane des joueurs actifs produit le
     * seuil a partir duquel les factions s'interessent a un joueur, et que les y laisser
     * entrer creerait une boucle. Ces deux lignes-ci sont donc purement affichees : elles
     * n'existent pas dans la table highscores, ne portent aucun rang, et n'entrent dans
     * aucun calcul.
     *
     * La moyenne plutot que le total, et pas seulement parce que c'est plus lisible : un
     * total croitrait avec le nombre de bases, donc avec la population du serveur, et la
     * faction paraitrait deux fois plus forte le jour ou l'on passe de cinq a dix bases
     * alors que chaque base serait identique. La moyenne mesure ce a quoi ressemble une
     * base typique — elle monte quand elles se developpent, elle chute quand un joueur en
     * abat une et qu'une neuve renait minuscule.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function insertFactionRows(array $rows): array
    {
        if (!$this->settingsService->npcHighscoreRows() || $this->highscoreType !== HighscoreTypeEnum::general) {
            return $rows;
        }

        // Une seule faction existe a ce jour. La boucle est conservee telle quelle : le jour
        // ou une seconde arrivera, elle s'ajoutera ici et nulle part ailleurs.
        $factions = [
            'pirate' => 'status_abbr_pirate',
        ];

        foreach ($factions as $type => $colourClass) {
            $stats = DB::table('highscores')
                ->join('users', 'users.id', '=', 'highscores.player_id')
                ->where('users.is_npc', true)
                ->where('users.npc_type', $type)
                ->selectRaw('COUNT(*) AS bases, AVG(highscores.general) AS moyenne')
                ->first();

            $bases = (int)($stats->bases ?? 0);

            if ($bases === 0 && $this->settingsService->npcHighscoreHideEmpty()) {
                continue;
            }

            // Une moyenne sur zero element n'a pas de sens : on affiche zero, jamais une
            // division impossible.
            $average = $bases > 0 ? (int)round((float)($stats->moyenne ?? 0)) : 0;

            $rows = $this->placeFactionRow($rows, [
                'id' => 0,
                'name' => __('t_ingame.highscore.faction_' . $type),
                'points' => $average,
                'points_formatted' => AppUtil::formatNumber($average),
                'planet_coords' => null,
                // Pas de rang : les joueurs humains gardent les leurs, contigus et
                // inchanges. La faction montre ou elle se situe sans occuper une place.
                'rank' => null,
                'is_admin' => false,
                'is_faction' => true,
                'faction_type' => $type,
                'faction_bases' => $bases,
                'colour_class' => $colourClass,
                'alliance_tag' => null,
                'alliance_id' => null,
                'total_ships' => null,
            ]);
        }

        return $rows;
    }

    /**
     * Put a faction row at its score position, but only on the page it belongs to.
     *
     * Sans ce controle la ligne se repeterait sur chacune des pages du classement.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $factionRow
     * @return array<int, array<string, mixed>>
     */
    private function placeFactionRow(array $rows, array $factionRow): array
    {
        if ($rows === []) {
            return $rows;
        }

        $score = (int)$factionRow['points'];
        $highest = (int)($rows[0]['points'] ?? 0);
        $lowest = (int)($rows[count($rows) - 1]['points'] ?? 0);

        if ($score > $highest || $score < $lowest) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            if ($score >= (int)$row['points']) {
                array_splice($rows, $index, 0, [$factionRow]);

                return $rows;
            }
        }

        return $rows;
    }

    /**
     * Return rank of player.
     *
     * @param PlayerService $player
     * @return int
     * @throws Exception
     */
    public function getHighscorePlayerRank(PlayerService $player): int
    {
        // Find the player in the highscore list to determine its rank.
        return Highscore::where('player_id', $player->getId())->first()->general_rank ?? 0;
    }

    /**
     * Returns the amount of players in the game to determine paging for highscore page.
     *
     * @return int
     */
    public function getHighscorePlayerAmount(): int
    {
        $adminVisible = $this->isAdminVisibleInHighscore();
        return Cache::remember('highscore-player-count-' . ($adminVisible ? '1' : '0'), now()->addMinutes(5), function () use ($adminVisible) {
            $query = Highscore::query()->validRanks();

            // Filter out admin users if setting is disabled
            if (!$adminVisible) {
                $query->whereHas('player', function ($q) {
                    $q->whereDoesntHave('roles', function ($roleQuery) {
                        $roleQuery->where('name', 'admin');
                    });
                });
            }

            return $query->count();
        });
    }

    /**
     * Get alliance highscores.
     *
     * @param int $perPage
     * @param int $pageOn
     * @return array<int, array<string,mixed>>
     */
    public function getHighscoreAlliances(int $perPage = 100, int $pageOn = 1): array
    {
        // Get all alliance highscores
        return Cache::remember(sprintf('alliance-highscores-%s-%d', $this->highscoreType->name, $pageOn), now()->addMinutes(5), function () use ($perPage, $pageOn) {
            $parsedHighscores = [];

            $highscores = AllianceHighscore::query()
                ->with('alliance.members')
                ->validRanks()
                ->orderBy($this->highscoreType->name.'_rank')
                ->paginate(perPage: $perPage, page: $pageOn);

            foreach ($highscores as $allianceScore) {
                // Skip if alliance doesn't exist
                if (!$allianceScore->alliance) {
                    continue;
                }

                $score = $allianceScore->{$this->highscoreType->name} ?? 0;
                $score_formatted = AppUtil::formatNumber($score);
                $memberCount = $allianceScore->alliance->members->count();
                $averageScore = $memberCount > 0 ? $score / $memberCount : 0;
                $averageScore_formatted = AppUtil::formatNumber($averageScore);

                $parsedHighscores[] = [
                    'id' => $allianceScore->alliance_id,
                    'name' => $allianceScore->alliance->alliance_name,
                    'tag' => $allianceScore->alliance->alliance_tag,
                    'points' => $score,
                    'points_formatted' => $score_formatted,
                    'average_points' => $averageScore,
                    'average_points_formatted' => $averageScore_formatted,
                    'member_count' => $memberCount,
                    'rank' => $allianceScore->{$this->highscoreType->name.'_rank'},
                ];
            }
            return $parsedHighscores;
        });
    }

    /**
     * Return rank of alliance.
     *
     * @param int $allianceId
     * @return int
     */
    public function getHighscoreAllianceRank(int $allianceId): int
    {
        // Find the alliance in the highscore list to determine its rank.
        $allianceHighscore = AllianceHighscore::where('alliance_id', $allianceId)->first();
        if (!$allianceHighscore) {
            return 0;
        }
        return $allianceHighscore->{$this->highscoreType->name.'_rank'} ?? 0;
    }

    /**
     * Returns the amount of alliances in the game to determine paging for highscore page.
     *
     * @return int
     */
    public function getHighscoreAllianceAmount(): int
    {
        return Cache::remember('highscore-alliance-count', now()->addMinutes(5), function () {
            return AllianceHighscore::query()->validRanks()->count();
        });
    }
}
