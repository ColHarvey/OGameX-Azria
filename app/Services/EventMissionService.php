<?php

namespace OGame\Services;

use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\Models\EventMissionClaim;
use OGame\Models\EventMissionDraw;
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
 *
 * INVARIANT : il n'existe jamais deux evenements simultanement. Toute la configuration
 * vit dans la table settings, en un seul jeu de cles, et la date de debut sert
 * d'identifiant logique dans les trois tables. Le jour ou deux evenements devraient
 * coexister, il faudra une vraie table d'instances et cet identifiant devra la suivre —
 * ne pas s'en remettre a event_start ce jour-la.
 */
class EventMissionService
{
    /**
     * Nombre de rangs de recompenses.
     */
    public const RANK_COUNT = 7;

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
     * REGLE INTANGIBLE : une cle deja partie en production ne change jamais de sens.
     * Les tirages figes en base ne conservent que la cle et sa valeur ; c'est ce
     * catalogue qui dit ce que la cle signifie et comment on la mesure. Lui faire dire
     * autre chose reecrirait retroactivement des missions deja affichees, parfois deja
     * creditees. Modifier 'tritium' ou 'target' d'une cle existante est en revanche
     * sans danger : le tritium est fige au tirage, et la mesure ne porte que sur le jour
     * en cours. Retirer une cle est permis — les tirages qui la portent l'ignorent — mais
     * elle ne doit alors jamais etre reintroduite avec un autre sens.
     *
     * @var array<string, array{tritium: int, target: int, icon: string}>
     */
    private const MISSIONS = [
        'login' => ['tritium' => 100, 'target' => 1, 'icon' => 'objects/research/computer_technology_small.jpg'],
        'building' => ['tritium' => 100, 'target' => 1, 'icon' => 'objects/buildings/metal_mine_small.jpg'],
        'research' => ['tritium' => 200, 'target' => 1, 'icon' => 'objects/buildings/research_lab_small.jpg'],
        'ships' => ['tritium' => 200, 'target' => 5, 'icon' => 'objects/buildings/shipyard_small.jpg'],
        'defence' => ['tritium' => 200, 'target' => 5, 'icon' => 'objects/units/rocket_launcher_small.jpg'],
        'expedition' => ['tritium' => 300, 'target' => 1, 'icon' => 'objects/units/pathfinder_small.jpg'],
        'espionage' => ['tritium' => 100, 'target' => 3, 'icon' => 'objects/units/espionage_probe_small.jpg'],
        'transport_own' => ['tritium' => 300, 'target' => 50000, 'icon' => 'objects/units/small_cargo_small.jpg'],
        'transport_other' => ['tritium' => 300, 'target' => 10000, 'icon' => 'objects/units/large_cargo_small.jpg'],
        'deployment' => ['tritium' => 200, 'target' => 1, 'icon' => 'objects/units/battleship_small.jpg'],
        'fleet_size' => ['tritium' => 200, 'target' => 10, 'icon' => 'objects/units/cruiser_small.jpg'],
        'chat' => ['tritium' => 100, 'target' => 1, 'icon' => 'objects/research/intergalactic_research_network_small.jpg'],
        'alliance' => ['tritium' => 100, 'target' => 3, 'icon' => 'objects/buildings/alliance_depot_small.jpg'],
        'shop' => ['tritium' => 300, 'target' => 1, 'icon' => 'objects/buildings/space_dock_small.jpg'],
        'halving' => ['tritium' => 300, 'target' => 1, 'icon' => 'objects/buildings/nanite_factory_small.jpg'],
    ];

    /**
     * Recompenses proposees a chaque rang : trois choix, dont un seul est attribue.
     *
     * CALIBRE SUR LA PRODUCTION REELLE DU SERVEUR, le 31 aout 2026. Mesure faite sur les
     * quatorze joueurs actifs : vitesse d'economie x1, mines de niveau 3 a 12, production
     * mediane de 89 metal/h, meilleur joueur a 1 018. Les montants sont exprimes de sorte
     * que le rang 7 vaille environ une journee de production pour le joueur de tete et deux
     * pour un joueur moyen, en equivalent metal au ratio 3:2:1 du marchand.
     *
     * Une premiere version calibree a vue donnait un rang 7 valant huit jours de production
     * au joueur de tete et soixante-dix a un debutant. Ne pas rehausser ces montants sans
     * remesurer : c'est la production qui commande, pas l'intuition.
     *
     * @var array<int, array<string, array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>}>>
     */
    private const RANK_REWARDS = [
        1 => [
            'resources' => ['metal' => 1500, 'crystal' => 700, 'deuterium' => 0, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 120, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['40f6c78e11be01ad3389b7dccd6ab8efa9347f3c']],
        ],
        2 => [
            'resources' => ['metal' => 3000, 'crystal' => 1500, 'deuterium' => 300, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 200, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['d3d541ecc23e4daa0c698e44c32f04afd2037d84']],
        ],
        3 => [
            'resources' => ['metal' => 5000, 'crystal' => 2500, 'deuterium' => 700, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 320, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['da4a2a1bb9afd410be07bc9736d87f1c8059e66d']],
        ],
        4 => [
            'resources' => ['metal' => 7500, 'crystal' => 3800, 'deuterium' => 1200, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 480, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['4a58d4978bbe24e3efb3b0248e21b3b4b1bfbd8a']],
        ],
        5 => [
            'resources' => ['metal' => 11000, 'crystal' => 5500, 'deuterium' => 2000, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 700, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['27cbcd52f16693023cb966e5026d8a1efbbfc0f9']],
        ],
        6 => [
            'resources' => ['metal' => 15000, 'crystal' => 7500, 'deuterium' => 3000, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 1000, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['d26f4dab76fdc5296e3ebec11a1e1d2558c713ea']],
        ],
        7 => [
            'resources' => ['metal' => 20000, 'crystal' => 10000, 'deuterium' => 5000, 'dark_matter' => 0, 'items' => []],
            'dark_matter' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 1600, 'items' => []],
            'item' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['929d5e15709cc51a4500de4499e19763c879f7f7', '0968999df2fe956aa4a07aea74921f860af7d97f']],
        ],
    ];

    /**
     * Recompenses supplementaires, attribuees en plus du choix principal.
     *
     * Regle reprise du jeu officiel : elles ne sont accordees que si le joueur possede le
     * Conseil d'officiers au moment ou il reclame le rang.
     *
     * DIMENSIONNEES A ENVIRON UN TIERS de la recompense principale, volontairement. Le
     * Conseil beneficie deja de +20 % de tritium sur chaque mission, donc il atteint les
     * rangs plus vite : le doter en plus d'un bonus comparable a la recompense principale
     * doublerait un avantage deja acquis. Le bonus doit se voir, pas ecraser.
     *
     * @var array<int, array<int, array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>}>>
     */
    private const RANK_BONUS = [
        1 => [
            ['metal' => 600, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 40, 'items' => []],
        ],
        2 => [
            ['metal' => 1200, 'crystal' => 600, 'deuterium' => 0, 'dark_matter' => 0, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 70, 'items' => []],
        ],
        3 => [
            ['metal' => 2000, 'crystal' => 1000, 'deuterium' => 0, 'dark_matter' => 0, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 100, 'items' => []],
        ],
        4 => [
            ['metal' => 3000, 'crystal' => 1500, 'deuterium' => 400, 'dark_matter' => 0, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 140, 'items' => []],
        ],
        5 => [
            ['metal' => 4500, 'crystal' => 2200, 'deuterium' => 700, 'dark_matter' => 0, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 190, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['40f6c78e11be01ad3389b7dccd6ab8efa9347f3c']],
        ],
        6 => [
            ['metal' => 6000, 'crystal' => 3000, 'deuterium' => 1000, 'dark_matter' => 0, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 240, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['d3d541ecc23e4daa0c698e44c32f04afd2037d84']],
        ],
        7 => [
            ['metal' => 7000, 'crystal' => 3500, 'deuterium' => 1500, 'dark_matter' => 0, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 300, 'items' => []],
            ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'items' => ['d26f4dab76fdc5296e3ebec11a1e1d2558c713ea']],
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
     * Instant de reference de la requete en cours.
     */
    private Carbon|null $maintenant = null;

    /**
     * Returns the single point in time every decision of this request relies on.
     *
     * L'horloge n'est lue qu'une fois. Sans cela, une requete qui traverse minuit ou la
     * cloture de l'evenement pourrait juger l'evenement ouvert au debut et ferme a la
     * fin, ou changer de jour entre le tirage et le credit.
     *
     * @return Carbon
     */
    private function now(): Carbon
    {
        if ($this->maintenant === null) {
            $this->maintenant = Date::now();
        }

        return $this->maintenant->copy();
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

        $today = $this->now()->startOfDay();

        return $today->greaterThanOrEqualTo($start) && $today->lessThanOrEqualTo($end);
    }

    /**
     * Returns the instant the administrator switched the event on, if it is known.
     *
     * Sert de plancher a la fenetre de mesure du premier jour : rien de ce qui precede
     * l ouverture ne doit etre credite. Les evenements ouverts avant que ce reglage
     * existe renvoient null, et retombent alors sur minuit.
     *
     * @return Carbon|null
     */
    public function getOpenedAt(): Carbon|null
    {
        $horodatage = (int)$this->settings->eventMissionsOpenedAt();

        if ($horodatage <= 0) {
            return null;
        }

        return Date::createFromTimestamp($horodatage);
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
     * Returns the drawn missions of one player for one day, freezing them on first read.
     *
     * L'instantane fige **la cle et sa valeur en tritium**. Figer la seule cle ne suffirait
     * pas : creditEventDays rattrape les jours passes, et une valeur modifiee au catalogue
     * entre-temps crediterait un ancien jour au nouveau tarif.
     *
     * @param PlayerService $player
     * @param Carbon $day
     * @return array<int, array{key: string, tritium: int, target?: int}>
     */
    public function getDrawForDay(PlayerService $player, Carbon $day): array
    {
        $start = $this->getStart();

        if ($start === null) {
            return [];
        }

        $existant = EventMissionDraw::where('user_id', $player->getId())
            ->whereDate('event_start', $start)
            ->whereDate('mission_date', $day)
            ->first();

        if ($existant !== null) {
            return $existant->missions;
        }

        $tirage = [];
        foreach ($this->drawMissionKeys($player->getId(), $day) as $key) {
            $tirage[] = [
                'key' => $key,
                'tritium' => self::MISSIONS[$key]['tritium'],
                'target' => self::MISSIONS[$key]['target'],
            ];
        }

        try {
            EventMissionDraw::create([
                'user_id' => $player->getId(),
                'event_start' => $start,
                'mission_date' => $day,
                'missions' => $tirage,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Deux onglets ont fige le tirage en meme temps : relire celui qui a gagne.
            // Toute autre erreur de base remonte : une contrainte de cle etrangere ou une
            // colonne manquante n'est pas un doublon, et la confondre avec un ferait
            // passer une panne de production pour un cas metier normal.
            $existant = EventMissionDraw::where('user_id', $player->getId())
                ->whereDate('event_start', $start)
                ->whereDate('mission_date', $day)
                ->first();

            if ($existant !== null) {
                return $existant->missions;
            }
        }

        return $tirage;
    }

    /**
     * Credits every completed mission of every event day not yet credited.
     *
     * **Reglement differe assume.** L'accomplissement d'une mission se deduit des donnees du
     * jeu et existe donc des l'action du joueur ; le tritium, lui, n'est inscrit qu'a la
     * prochaine visite de la page. Ce depot n'a pas de systeme d'evenements — un seul,
     * ChatMessageSent — et instrumenter le moteur etait precisement ce qu'on voulait eviter.
     * Un tritium qui apparait le vendredi pour une mission faite le lundi n'est donc pas un
     * defaut : c'est le modele.
     *
     * Trois bornes encadrent le balayage :
     * - il ne commence jamais avant l'inscription du joueur, sinon un compte cree au
     *   cinquieme jour encaisserait les missions « se connecter » des quatre premiers ;
     * - il ne depasse jamais la date de fin de l'evenement ;
     * - il ne fait rien hors periode.
     *
     * @param PlayerService $player
     * @return int Nombre de missions creditees lors de cet appel.
     */
    public function creditEventDays(PlayerService $player): int
    {
        if (!$this->isRunning()) {
            return 0;
        }

        $start = $this->getStart();
        $end = $this->getEnd();

        if ($start === null || $end === null) {
            return 0;
        }

        $premier = $this->firstEligibleDay($player, $start);
        $dernier = $this->lastEligibleDay($player, $end);

        $creditees = 0;

        for ($day = $premier->copy(); $day->lessThanOrEqualTo($dernier); $day->addDay()) {
            $tirage = $this->getDrawForDay($player, $day);

            $deja = EventMissionClaim::where('user_id', $player->getId())
                ->whereDate('event_start', $start)
                ->whereDate('mission_date', $day)
                ->pluck('mission_key')
                ->all();

            // Ne comptent que les missions encore presentes au catalogue : une cle retiree
            // depuis le tirage ne sera jamais creditee, et sans cette precaution le jour ne
            // serait jamais considere comme solde — chaque visite relancerait ses requetes.
            $creditables = 0;
            foreach ($tirage as $mission) {
                if (isset(self::MISSIONS[$mission['key']])) {
                    $creditables++;
                }
            }

            // Un jour entierement solde n'a plus rien a mesurer : sans ce raccourci, une
            // simple visite relancerait toutes les requetes de tous les jours ecoules.
            if (count($deja) >= $creditables) {
                continue;
            }

            foreach ($tirage as $mission) {
                $key = $mission['key'];

                if (!isset(self::MISSIONS[$key]) || in_array($key, $deja, true)) {
                    continue;
                }

                if ($this->measure($player, $key, $day) < $this->targetOf($mission)) {
                    continue;
                }

                if ($this->creditMission($player, $key, $day, $start, $mission['tritium'])) {
                    $creditees++;
                }
            }
        }

        return $creditees;
    }

    /**
     * Returns today's missions for a player, with their progress.
     *
     * Cette methode ne credite rien : elle lit. Le credit est le travail de
     * creditEventDays(), appele avant elle.
     *
     * @param PlayerService $player
     * @return array<int, array{key: string, icon: string, tritium: int, target: int, progress: int, done: bool, claimed: bool}>
     */
    public function getDailyMissions(PlayerService $player): array
    {
        $today = $this->now()->startOfDay();
        $start = $this->getStart();

        if ($start === null) {
            return [];
        }

        $claimed = EventMissionClaim::where('user_id', $player->getId())
            ->whereDate('event_start', $start)
            ->whereDate('mission_date', $today)
            ->pluck('mission_key')
            ->all();

        $missions = [];

        foreach ($this->getDrawForDay($player, $today) as $mission) {
            $key = $mission['key'];

            // Une cle figee dans un tirage mais retiree du catalogue depuis : on l'ignore
            // plutot que de faire echouer la page.
            if (!isset(self::MISSIONS[$key])) {
                continue;
            }

            $progress = $this->measure($player, $key, $today);
            $target = $this->targetOf($mission);

            $missions[] = [
                'key' => $key,
                'icon' => self::MISSIONS[$key]['icon'],
                'tritium' => $this->withOfficerBonus($player, $mission['tritium']),
                'target' => $target,
                'progress' => min($progress, $target),
                'done' => $progress >= $target,
                'claimed' => in_array($key, $claimed, true),
            ];
        }

        // Tri par valeur croissante : la page regroupe les missions par palier de tritium,
        // comme le jeu officiel.
        usort($missions, fn (array $a, array $b): int => $a['tritium'] <=> $b['tritium']);

        return $missions;
    }

    /**
     * Credits one completed mission, once per player, event and day.
     *
     * **Aucune transaction n'enveloppe cette methode, et c'est voulu.** Le credit n'est pas
     * « verifier puis ecrire » : la ligne inseree *est* le credit, le total se calculant par
     * somme. Un seul INSERT, donc atomique par nature, et c'est la contrainte d'unicite qui
     * arbitre deux requetes simultanees — pas un SELECT prealable, que les deux pourraient
     * franchir ensemble.
     *
     * @param PlayerService $player
     * @param string $key
     * @param Carbon $day
     * @param Carbon $start
     * @param int $baseTritium Valeur figee au tirage, avant bonus d'officiers.
     * @return bool Vrai si la ligne a bien ete creee.
     */
    private function creditMission(PlayerService $player, string $key, Carbon $day, Carbon $start, int $baseTritium): bool
    {
        try {
            EventMissionClaim::create([
                'user_id' => $player->getId(),
                'event_start' => $start,
                'mission_date' => $day,
                'mission_key' => $key,
                'tritium' => $this->withOfficerBonus($player, $baseTritium),
                'claimed_at' => $this->now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Doublon, et rien d'autre : la mission etait deja creditee. Toute autre
            // erreur de base — connexion perdue, colonne inexistante, cle etrangere —
            // continue de remonter. Les traiter toutes comme un doublon transformerait
            // une panne en 'mission deja traitee', silencieusement.
            return false;
        }

        return true;
    }

    /**
     * Returns the quantity a drawn mission asked for.
     *
     * La valeur figee au tirage prime sur le catalogue : un jour rattrape doit etre juge
     * sur la quantite qui etait affichee au joueur ce jour-la, pas sur celle d'aujourd'hui.
     * Le repli sur le catalogue ne sert qu'aux tirages ecrits avant l'ajout de ce champ.
     *
     * @param array{key: string, tritium: int, target?: int} $mission
     * @return int
     */
    private function targetOf(array $mission): int
    {
        return $mission['target'] ?? self::MISSIONS[$mission['key']]['target'];
    }

    /**
     * Returns the first event day a player is entitled to.
     *
     * Un compte cree au cinquieme jour d'un evenement ne doit pas encaisser retroactivement
     * les quatre premiers : la mission « se connecter » est acquise d'office, elle lui
     * offrirait quatre jours de tritium pour rien.
     *
     * @param PlayerService $player
     * @param Carbon $start
     * @return Carbon
     */
    private function firstEligibleDay(PlayerService $player, Carbon $start): Carbon
    {
        $inscription = $player->getUser()->created_at;

        if ($inscription === null) {
            return $start->copy();
        }

        $jourInscription = $inscription->copy()->startOfDay();

        return $jourInscription->greaterThan($start) ? $jourInscription : $start->copy();
    }

    /**
     * Returns the last event day a player is entitled to.
     *
     * Le mode vacances **borne** le balayage, il ne l'annule pas. Un joueur qui accomplit
     * ses missions le lundi puis part en vacances le mardi garde son lundi : geler un
     * compte ne doit pas effacer du travail deja fait. Seuls les jours ou le compte etait
     * deja gele sont ecartes — production arretee, aucune attaque possible, et la mission
     * 'se connecter' qui serait offerte chaque jour sans rien faire.
     *
     * @param PlayerService $player
     * @param Carbon $end
     * @return Carbon
     */
    private function lastEligibleDay(PlayerService $player, Carbon $end): Carbon
    {
        $today = $this->now()->startOfDay();
        $dernier = $today->lessThan($end) ? $today : $end->copy();

        if (!$player->isInVacationMode()) {
            return $dernier;
        }

        // activateVacationMode() ecrit toujours les deux colonnes ensemble ; une date
        // absente signale un enregistrement incoherent, auquel cas on ne retire rien.
        $gel = $player->getUser()->vacation_mode_activated_at;

        if ($gel === null) {
            return $dernier;
        }

        // La coupure est a la JOURNEE, pas a l heure : le jour du depart reste entierement
        // acquis, y compris une mission accomplie apres l activation. Tout le systeme
        // raisonne par journee — tirage quotidien, mesures bornees a [00:00, 23:59], cle
        // d unicite portant une date. Couper a l heure exacte obligerait a reecrire les
        // quinze requetes de measure() pour accepter des bornes arbitraires, pour empecher
        // quelques centaines de tritium le jour du depart.
        $jourGel = $gel->copy()->startOfDay();

        return $jourGel->lessThan($dernier) ? $jourGel : $dernier;
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

        if ($start === null) {
            return 0;
        }

        return (int)EventMissionClaim::where('user_id', $player->getId())
            ->whereDate('event_start', $start)
            ->sum('tritium');
    }

    /**
     * Returns how many days remain, today included, or 0 outside the event.
     *
     * Sert uniquement a prevenir le joueur : rien n'est rattrapable apres la cloture,
     * ni le tritium non credite ni les rangs non reclames.
     *
     * @return int
     */
    public function getDaysLeft(): int
    {
        $end = $this->getEnd();

        if ($end === null || !$this->isRunning()) {
            return 0;
        }

        return (int)$this->now()->startOfDay()->diffInDays($end) + 1;
    }

    /**
     * Returns what the whole event yields, for the administrator to size it.
     *
     * Le tirage etant equilibre, le total quotidien est **le meme pour tous** : il n'y a ni
     * minimum ni maximum a distinguer, seulement une valeur. C'est ce qui rend des seuils
     * fixes defendables — sans cela, un joueur malchanceux plafonnerait a un tiers du
     * potentiel d'un autre et ne verrait jamais les derniers rangs.
     *
     * @return array{days: int, per_day: int, total: int}
     */
    public function getPotential(): array
    {
        $start = $this->getStart();
        $end = $this->getEnd();

        if ($start === null || $end === null) {
            return ['days' => 0, 'per_day' => 0, 'total' => 0];
        }

        $jours = (int)$start->diffInDays($end) + 1;

        $parJour = 0;
        foreach ($this->drawQuotas() as $valeur => $nombre) {
            $parJour += $valeur * $nombre;
        }

        return [
            'days' => $jours,
            'per_day' => $parJour,
            'total' => $parJour * $jours,
        ];
    }

    /**
     * Returns the five ranks with their thresholds, state and available rewards.
     *
     * @param PlayerService $player
     * @return array<int, array{rank: int, threshold: int, reached: bool, claimed: bool, chosen: string|null, rewards: array<string, array{reward: array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>}, summary: string, detail: array<int, array{kind: string, label: string, amount: string, image: string|null}>}>, bonus: array<int, array{summary: string, detail: array<int, array{kind: string, label: string, amount: string, image: string|null}>}>}>
     */
    public function getRanks(PlayerService $player): array
    {
        $tritium = $this->getTritium($player);
        $start = $this->getStart();
        $pas = $this->settings->eventRankStep();

        $claims = [];
        if ($start !== null) {
            $claims = EventRankClaim::where('user_id', $player->getId())
                ->whereDate('event_start', $start)
                ->pluck('reward_key', 'rank')
                ->all();
        }

        $ranks = [];
        for ($rank = 1; $rank <= self::RANK_COUNT; $rank++) {
            // Seuils fixes et identiques pour tous, comme dans le jeu officiel :
            // 1 000, 2 000, ... 7 000 avec le pas par defaut. Un seuil calcule sur le
            // potentiel individuel serait illisible et impossible a annoncer.
            $threshold = $pas * $rank;

            $ranks[$rank] = [
                'rank' => $rank,
                'threshold' => $threshold,
                'reached' => $tritium >= $threshold,
                'claimed' => isset($claims[$rank]),
                'chosen' => $claims[$rank] ?? null,
                'rewards' => $this->describeRewards($rank),
                'bonus' => $this->describeBonus($rank),
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
                    'claimed_at' => $this->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Seul le doublon devient un message au joueur ; les autres erreurs de
                // base remontent telles quelles.
                throw new RuntimeException(__('t_ingame.events.error_rank_already_claimed'));
            }

            $this->grantReward($player, $reward, $rank);

            // Recompenses supplementaires. La regle officielle les fait dependre du
            // Conseil d officiers **au moment de la reclamation**, et non de son etat
            // pendant l evenement : un joueur peut donc attendre de l avoir pour
            // encaisser ses rangs.
            if ($this->hasCommandingStaff($player)) {
                foreach (self::RANK_BONUS[$rank] as $bonus) {
                    $this->grantReward($player, $bonus, $rank);
                }
            }
        });
    }

    /**
     * Draws the mission keys of one player for one day.
     *
     * Le tirage est **equilibre** : il prend un nombre fixe de missions dans chaque palier
     * de valeur, puis choisit lesquelles au sein de chaque palier. Deux joueurs recoivent
     * donc des missions differentes, mais rigoureusement le meme total possible.
     *
     * C'est indispensable depuis que les seuils de rang sont fixes. Un tirage libre de 5
     * missions parmi 15 pouvait donner 500 tritium un jour et 1 500 un autre : sur sept
     * jours, un joueur malchanceux plafonnait a 3 500 et ne voyait jamais les rangs 4 a 7,
     * par pur hasard.
     *
     * Le choix au sein d'un palier reste deterministe : classer par le hachage de
     * "cle|joueur|date" donne un ordre stable, different pour chaque joueur et chaque jour,
     * sans rien ecrire en base et sans toucher a l'etat global du generateur aleatoire.
     * Le seed ne repose que sur des donnees que le joueur ne peut pas changer.
     *
     * @param int $userId
     * @param Carbon $day
     * @return array<int, string>
     */
    public function drawMissionKeys(int $userId, Carbon $day): array
    {
        $graine = $userId . '|' . $day->format('Y-m-d');
        $quotas = $this->drawQuotas();

        $parValeur = [];
        foreach (self::MISSIONS as $key => $mission) {
            $parValeur[$mission['tritium']][] = $key;
        }

        $tirage = [];
        foreach ($quotas as $valeur => $nombre) {
            $candidats = $parValeur[$valeur] ?? [];

            usort($candidats, function (string $a, string $b) use ($graine): int {
                return strcmp(md5($a . '|' . $graine), md5($b . '|' . $graine));
            });

            foreach (array_slice($candidats, 0, $nombre) as $key) {
                $tirage[] = $key;
            }
        }

        return $tirage;
    }

    /**
     * Returns how many missions are drawn from each tritium tier.
     *
     * Les missions sont reparties aussi egalement que possible entre les paliers de valeur ;
     * le reste va aux paliers les plus genereux. Avec cinq missions et trois paliers, cela
     * donne 1 x 100, 2 x 200 et 2 x 300, soit 1 100 tritium par jour pour tout le monde.
     *
     * @return array<int, int> Valeur en tritium => nombre de missions tirees.
     */
    private function drawQuotas(): array
    {
        $valeurs = array_unique(array_column(self::MISSIONS, 'tritium'));
        sort($valeurs);

        $disponibles = [];
        foreach (self::MISSIONS as $mission) {
            $disponibles[$mission['tritium']] = ($disponibles[$mission['tritium']] ?? 0) + 1;
        }

        $souhaite = max(1, min($this->settings->eventMissionsPerDay(), count(self::MISSIONS)));
        $base = intdiv($souhaite, count($valeurs));
        $reste = $souhaite % count($valeurs);

        // Le reste va aux valeurs les plus hautes : repartition la plus favorable au
        // joueur, et celle qui donne 1 x 100, 2 x 200, 2 x 300 pour cinq missions.
        $favorisees = array_slice(array_reverse($valeurs), 0, $reste);

        $quotas = [];
        foreach ($valeurs as $valeur) {
            $nombre = $base + (in_array($valeur, $favorisees, true) ? 1 : 0);

            // Un palier ne peut pas fournir plus de missions qu'il n'en contient.
            $quotas[$valeur] = min($nombre, $disponibles[$valeur] ?? 0);
        }

        return $quotas;
    }

    /**
     * Applies the Commanding Staff bonus to a mission value.
     *
     * Le bonus s'applique a l'accomplissement de la mission, pas a la reclamation d'un rang :
     * ce sont deux mecanismes distincts dans le jeu officiel.
     *
     * @param PlayerService $player
     * @param int $baseTritium
     * @return int
     */
    private function withOfficerBonus(PlayerService $player, int $baseTritium): int
    {
        if ($this->officerService->countActive($player->getUser()) < self::OFFICER_BONUS_REQUIRED) {
            return $baseTritium;
        }

        return (int)round($baseTritium * (1 + self::OFFICER_BONUS));
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
        // La fenetre commence a minuit, SAUF le premier jour ou elle commence a l ouverture
        // de l evenement. Sans ce plancher, tout ce qu un joueur a fait dans la matinee
        // precedant l ouverture lui serait credite : il verrait des missions validees sans
        // avoir rien fait depuis l annonce. Le max ne mord que sur le jour de l ouverture,
        // les jours suivants commencant apres.
        $debutDate = $day->copy()->startOfDay();
        $ouverture = $this->getOpenedAt();

        if ($ouverture !== null && $ouverture->greaterThan($debutDate)) {
            $debutDate = $ouverture->copy();
        }

        $finDate = $day->copy()->endOfDay();
        $debut = (int)$debutDate->timestamp;
        $fin = (int)$finDate->timestamp;

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
     * @return array<string, array{reward: array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>}, summary: string, detail: array<int, array{kind: string, label: string, amount: string, image: string|null}>}>
     */
    private function describeRewards(int $rank): array
    {
        $rewards = [];

        foreach (self::RANK_REWARDS[$rank] as $rewardKey => $reward) {
            $rewards[$rewardKey] = [
                'reward' => $reward,
                'summary' => $this->describe($reward),
                'detail' => $this->detail($reward),
            ];
        }

        return $rewards;
    }

    /**
     * Breaks a reward into displayable parts: one icon and one amount each.
     *
     * La page affiche chaque recompense en vignette, avec l'illustration et la quantite,
     * plutot qu'en une phrase. Les ressources et la matiere noire utilisent .resourceIcon du
     * theme ; les objets, leur image de catalogue en 100 px.
     *
     * @param array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>} $reward
     * @return array<int, array{kind: string, label: string, amount: string, image: string|null}>
     */
    private function detail(array $reward): array
    {
        $parts = [];

        foreach (['metal', 'crystal', 'deuterium', 'dark_matter'] as $key) {
            if ($reward[$key] <= 0) {
                continue;
            }

            $parts[] = [
                'kind' => $key === 'dark_matter' ? 'darkmatter' : $key,
                'label' => __('t_ingame.rewards.gain_' . $key),
                'amount' => number_format($reward[$key], 0, ',', ' '),
                'image' => null,
            ];
        }

        foreach ($reward['items'] as $ref) {
            $item = $this->shopService->getItemByRef($ref);

            if ($item === null) {
                continue;
            }

            $parts[] = [
                'kind' => 'item',
                'label' => __('t_resources.' . $item['name_key'] . '.title')
                    . ' ' . __('t_ingame.shop.tier_' . $item['tier_key']),
                'amount' => $item['duration'],
                'image' => '/img/icons/' . $item['image_hash'] . '-100x.png',
            ];
        }

        return $parts;
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

    /**
     * Returns whether a player currently holds the full Commanding Staff.
     *
     * @param PlayerService $player
     * @return bool
     */
    public function hasCommandingStaff(PlayerService $player): bool
    {
        return $this->officerService->countActive($player->getUser()) >= self::OFFICER_BONUS_REQUIRED;
    }

    /**
     * Grants one reward to a player.
     *
     * Factorise ici parce que les recompenses principales et supplementaires se distribuent
     * exactement de la meme facon : dupliquer les trois branches inviterait a n en corriger
     * qu une le jour ou elles changent.
     *
     * @param PlayerService $player
     * @param array{metal: int, crystal: int, deuterium: int, dark_matter: int, items: array<int, string>} $reward
     * @param int $rank
     * @return void
     */
    private function grantReward(PlayerService $player, array $reward, int $rank): void
    {
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
    }

    /**
     * Returns the readable summaries of one rank's additional rewards.
     *
     * @param int $rank
     * @return array<int, array{summary: string, detail: array<int, array{kind: string, label: string, amount: string, image: string|null}>}>
     */
    private function describeBonus(int $rank): array
    {
        return array_map(
            fn (array $bonus): array => ['summary' => $this->describe($bonus), 'detail' => $this->detail($bonus)],
            self::RANK_BONUS[$rank]
        );
    }
}
