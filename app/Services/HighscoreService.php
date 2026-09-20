<?php

namespace OGame\Services;

use Cache;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Facades\AppUtil;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\CivilShipObjects;
use OGame\GameObjects\MilitaryShipObjects;
use OGame\Lifeforms\Score\LifeformScoreCalculator;
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
     * @throws InvalidArgumentException when no ranking answers this type
     */
    public function setHighscoreType(int $type): void
    {
        // 0 = general score
        // 1 = economy points
        // 2 = research points
        // 3 = military points
        // 4 = honour points
        //
        // **Un type inconnu se refuse.** `cases()[$type]` faisait tomber la requete, et le classement tournait sans fin.
        // Une premiere correction rendait le classement militaire aux trois cumuls, qui n avaient pas de compteur ;
        // Keven l a refuse le 13 septembre 2026 — ce sont des donnees differentes. Les trois cumuls ont desormais leurs
        // colonnes, et le controleur ne les sert qu une fois la collecte activee. Ce refus-ci garde tout appelant d un
        // choix fait en silence.
        $this->highscoreType = HighscoreTypeEnum::tryFrom($type)
            ?? throw new InvalidArgumentException('Unknown highscore type ' . $type . '.');
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

        // **Les formes de vie entrent dans le General, jamais dans l Economie ni dans la Recherche.**
        // C est la structure du jeu officiel, etablie le 20 septembre 2026 par deux preuves independantes
        // conservees au journal (§173) : la correction d un membre du conseil enterinee par un administrateur
        // de jeu, et l arithmetique de l API publique du serveur `en1`, ou le Total moins l Economie, la
        // Recherche et le Militaire laisse exactement le total Formes de vie.
        $score += $this->getPlayerScoreLifeform($player);

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
     * Les points des batiments de formes de vie — categorie `Lifeform Economy` du jeu officiel.
     *
     * La somme des ressources se fait sur toutes les planetes **avant** la division par mille : arrondir
     * planete par planete perdrait un reste par corps, donc jusqu a neuf cent quatre-vingt-dix-neuf
     * ressources a chaque fois.
     */
    public function getPlayerScoreLifeformEconomy(PlayerService $player): int
    {
        $calculateur = resolve(LifeformScoreCalculator::class);
        $ressources = new Resources(0, 0, 0, 0);

        foreach ($player->planets->all() as $planet) {
            $ressources->add($calculateur->buildingResourcesOf($planet->getPlanetId()));
        }

        return (int)floor($ressources->sum() / 1000);
    }

    /**
     * Les points des technologies de formes de vie — categorie `Lifeform Technology` du jeu officiel.
     */
    public function getPlayerScoreLifeformTechnology(PlayerService $player): int
    {
        $calculateur = resolve(LifeformScoreCalculator::class);
        $ressources = new Resources(0, 0, 0, 0);

        foreach ($player->planets->all() as $planet) {
            $ressources->add($calculateur->technologyResourcesOf($planet->getPlanetId()));
        }

        return (int)floor($ressources->sum() / 1000);
    }

    /**
     * Le total des formes de vie : la somme des deux, **en points**.
     *
     * C est bien la somme des points et non celle des ressources : l API officielle donne, sur un meme
     * joueur, 37 413 544 349 et 35 989 655 471 pour un total de 73 403 207 171 — soit la somme des deux a
     * 7 351 pres, l ecart venant de releves non simultanes.
     */
    public function getPlayerScoreLifeform(PlayerService $player): int
    {
        return $this->getPlayerScoreLifeformEconomy($player) + $this->getPlayerScoreLifeformTechnology($player);
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
     * Get the scores a ranking photograph stores for a player.
     *
     * **L honneur se lit, il ne se calcule pas.** Il vit sur le compte et bouge a chaque bataille ; le classement
     * en prend une photographie, comme des autres scores. Il peut etre negatif : un combat deshonorant en retire.
     *
     * La composition vit ici plutot que dans la tache planifiee, pour qu un essai la lise sur un seul joueur : la
     * tache parcourt tous les comptes de la base, plusieurs centaines dans un processus de la suite.
     *
     * **Les trois cumuls militaires n y sont pas.** Leurs valeurs et leurs rangs sont publies ensemble, depuis un seul
     * etat agrege, par `MilitaryTallyPublisher` : les ecrire ici, joueur apres joueur, melangerait deux passages.
     *
     * @param PlayerService $player
     * @return array{general: int, economy: int, research: int, military: int, honor: int, lifeform_economy: int, lifeform_technology: int, lifeform: int}
     * @throws Exception
     */
    public function getPlayerScores(PlayerService $player): array
    {
        return [
            'general' => $this->getPlayerScore($player),
            'economy' => $this->getPlayerScoreEconomy($player),
            'research' => $this->getPlayerScoreResearch($player),
            'military' => $this->getPlayerScoreMilitary($player),
            'honor' => resolve(HonorService::class)->pointsOf($player->getUser()),
            'lifeform_economy' => $this->getPlayerScoreLifeformEconomy($player),
            'lifeform_technology' => $this->getPlayerScoreLifeformTechnology($player),
            'lifeform' => $this->getPlayerScoreLifeform($player),
        ];
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
     * Oublie les pages mises en cache d un classement, joueurs et alliances.
     *
     * Les clefs sont celles que `getHighscorePlayers()` et `getHighscoreAlliances()` ecrivent, pour les cent pages
     * que la tache des rangs oublie deja.
     */
    public static function forgetCachedPagesOf(HighscoreTypeEnum $type): void
    {
        for ($page = 1; $page <= 100; $page++) {
            Cache::forget(sprintf('highscores-%s-%d-0', $type->name, $page));
            Cache::forget(sprintf('highscores-%s-%d-1', $type->name, $page));
            Cache::forget(sprintf('alliance-highscores-%s-%d', $type->name, $page));
        }
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
                // **Un rang absent se range en dernier.** Un classement neuf n a pas encore de rang tant que la
                // tache planifiee n est pas passee, et un tri ascendant placerait ces NULL en tete.
                ->orderByRaw($this->highscoreType->name.'_rank IS NULL')
                ->orderBy($this->highscoreType->name.'_rank');

            // **Un cumul militaire ne montre que ce que sa derniere publication a range.** Une ligne creee depuis n a ni
            // valeur ni rang publies : l afficher a zero melangerait deux passages.
            if ($this->highscoreType->isMilitaryTally()) {
                $query->whereNotNull($this->highscoreType->name.'_rank');
            }

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
                    'honor_points' => resolve(HonorService::class)->pointsOf($playerScore->player),
                    'alliance_tag' => $allianceTag,
                    'alliance_id' => $allianceId,
                    'total_ships' => $totalShips,
                    // **La ventilation des formes de vie, lue sur LA MEME photographie que le score.** Elle ne
                    // recalcule rien : les trois colonnes viennent du meme enregistrement, donc le detail ne peut
                    // pas contredire le total, et rien n est compte deux fois (decision de Keven, 20 septembre 2026).
                    'lifeform_points' => (int)($playerScore->lifeform ?? 0),
                    'lifeform_economy_points' => (int)($playerScore->lifeform_economy ?? 0),
                    'lifeform_technology_points' => (int)($playerScore->lifeform_technology ?? 0),
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
                // Une faction n'est pas un joueur : elle n'a pas d'honneur, et en montrer un
                // laisserait croire que la ligne designe quelqu'un.
                'honor_points' => null,
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
                // Un cumul militaire ne montre que les alliances que sa derniere publication a rangees.
                ->when($this->highscoreType->isMilitaryTally(), fn ($requete) => $requete->whereNotNull($this->highscoreType->name.'_rank'))
                ->orderByRaw($this->highscoreType->name.'_rank IS NULL')
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
                    // Meme photographie que le score, donc meme total : voir `getHighscorePlayers()`.
                    'lifeform_points' => (int)($allianceScore->lifeform ?? 0),
                    'lifeform_economy_points' => (int)($allianceScore->lifeform_economy ?? 0),
                    'lifeform_technology_points' => (int)($allianceScore->lifeform_technology ?? 0),
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
