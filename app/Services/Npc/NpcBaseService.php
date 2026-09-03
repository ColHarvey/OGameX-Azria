<?php

namespace OGame\Services\Npc;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * Creation et recensement des bases hostiles.
 *
 * Une base est un vrai compte avec une vraie planete. Rien ici n'invente de mecanique : la
 * creation reprend exactement le chemin d'InitializeLegorAccount, deja en production, qui
 * fabrique le compte systeme Legor et sa planete Arakis.
 */
class NpcBaseService
{
    /**
     * Seule faction existante a ce jour.
     *
     * La colonne users.npc_type reste en place et porte deja cette valeur : elle distingue
     * les factions les unes des autres et sert aux requetes de recensement. Une seconde
     * faction viendra s'y ajouter sans migration, mais rien de ce qui la concerne n'est
     * ecrit d'avance — une branche jamais exercee est une dette, pas une avance.
     */
    public const string TYPE_PIRATE = 'pirate';

    /**
     * Noms d'equipages. Une base porte un nom, pas un numero : on ne se fait pas attaquer
     * par « un pirate » mais par une bande dont on a brule la flotte la semaine derniere.
     *
     * @var array<int, string>
     */
    private const array PIRATE_NAMES = [
        'Corsaires Rouges', 'Meute de Vega', 'Freres de la Nebuleuse', 'Ecumeurs d Orion',
        'Lame Noire', 'Charognards de Rigel', 'Convoi Fantome', 'Flibustiers de Deneb',
        'Serres de Cassiopee', 'Cendres de Proxima', 'Rapaces d Altair', 'Chiens de Sirius',
        'Faucheurs d Antares', 'Naufrageurs de Mizar', 'Rongeurs de Bellatrix',
        'Vautours de Polaris', 'Sabre Brise', 'Fils de la Derive', 'Pillards de Tau Ceti',
        'Guetteurs d Arcturus',
    ];

    /**
     * Plan de developpement initial. Volontairement minuscule : une base neuve n'est une
     * menace pour personne le premier jour, elle est une curiosite qu'on regarde grandir.
     *
     * @var array<string, int>
     */
    private const array PIRATE_SEED_BUILDINGS = [
        'metal_mine' => 3,
        'crystal_mine' => 2,
        'deuterium_synthesizer' => 1,
        'solar_plant' => 4,
        'metal_store' => 1,
        'crystal_store' => 1,
    ];

    public function __construct(
        private SettingsService $settings,
        private PlanetServiceFactory $planetServiceFactory,
        private PlayerServiceFactory $playerServiceFactory
    ) {
    }

    /**
     * Get every living planet belonging to a faction.
     *
     * @return Collection<int, Planet>
     */
    public function livingBases(string $type = self::TYPE_PIRATE): Collection
    {
        return Planet::query()
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('users.is_npc', true)
            ->where('users.npc_type', $type)
            ->where('planets.destroyed', 0)
            ->select('planets.*')
            ->get();
    }

    /**
     * Get how many living bases a faction currently holds.
     */
    public function baseCount(string $type = self::TYPE_PIRATE): int
    {
        return $this->livingBases($type)->count();
    }

    /**
     * Create one base for a faction, or return null when no suitable position exists.
     *
     * @param Coordinate|null $at Position imposee, sinon le serveur en choisit une.
     */
    public function createBase(string $type = self::TYPE_PIRATE, Coordinate|null $at = null): PlanetService|null
    {
        $coordinate = $at ?? $this->findSpawnCoordinate();

        if ($coordinate === null) {
            return null;
        }

        // Derniere verification, et elle n'est pas redondante avec celle de la recherche.
        //
        // Elle couvre deux cas que l'autre laisse passer. Une position imposee — la commande
        // de peuplement, un test, une reprise a la main — n'est jamais passee par la
        // recherche et n'a donc jamais ete verifiee du tout. Et entre le moment ou la
        // recherche declare une case libre et celui ou on l'occupe, il s'ecoule la creation
        // d'un compte : un joueur peut coloniser la case pendant ce temps.
        //
        // La planete d'un joueur ne risquait pas d'etre ecrasee — createPlanetAtPosition
        // refuse une case prise — mais elle refusait en levant une exception, apres que le
        // compte pirate a deja ete ecrit. Une naissance malchanceuse laissait donc un compte
        // orphelin derriere elle et faisait tomber le tick entier, privant toutes les autres
        // bases de leur croissance.
        if ($this->planetServiceFactory->planetExistsAtCoordinate($coordinate)) {
            return null;
        }

        try {
            // La transaction est ce qui garantit qu'une course perdue ne laisse rien : sans
            // elle, le compte et sa fiche technique survivraient a l'echec de la planete.
            return DB::transaction(fn (): PlanetService => $this->createBaseAt($type, $coordinate));
        } catch (RuntimeException $exception) {
            // Discriminant honnete : apres l'annulation, la case n'est occupee que si un
            // joueur l'a effectivement prise. Toute autre panne doit remonter — la taire
            // transformerait un defaut en base manquante inexplicable.
            if ($this->planetServiceFactory->planetExistsAtCoordinate($coordinate)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Write one base and everything it needs, inside the caller's transaction.
     */
    private function createBaseAt(string $type, Coordinate $coordinate): PlanetService
    {
        $name = $this->pickName();

        $user = new User();
        $user->username = $this->uniqueUsername($name);
        $user->email = Str::lower(Str::slug($user->username, '.')) . '@npc.invalid';
        // Mot de passe aleatoire jamais conserve : le compte n'est connectable par personne,
        // pas meme par l'administrateur.
        $user->password = Hash::make(Str::random(32));
        $user->lang = 'fr';
        $user->time = (string)Date::now()->timestamp;
        $user->is_npc = true;
        $user->npc_type = $type;
        $user->save();

        UserTech::create(['user_id' => $user->id]);

        // Surtout pas app()->make(PlayerService::class) suivi d'un load() : le middleware du
        // jeu enregistre le joueur de la requete en cours comme instance partagee de cette
        // classe, si bien qu'un load() dessus remplacerait le joueur courant par le compte
        // pirate qu'on vient de creer. La fabrique, elle, construit une instance distincte.
        $playerService = $this->playerServiceFactory->make($user->id, true);

        $planetService = $this->planetServiceFactory->createPlanetAtPosition(
            $playerService,
            $coordinate,
            $name
        );

        // Un compte sans planete courante n'est pas un compte valide, meme pilote par le
        // serveur : plusieurs chemins du jeu remontent a la planete courante et echouent si
        // elle n'est pas posee. Le cas se manifeste au pire moment, lors de la destruction
        // de la base, ou getCurrentPlanetId() est interroge.
        $user->planet_current = $planetService->getPlanetId();
        $user->save();

        // Le service joueur avait ete construit avant que la planete n'existe : sa liste est
        // vide et sa planete courante inconnue. Le service de planete qu'on s'apprete a
        // rendre porte cette copie perimee, et le defaut ne se manifesterait qu'au pire
        // moment — a la destruction de la base, ou l'on remonte au proprietaire. On
        // reconstruit donc les deux depuis la base de donnees.
        $this->playerServiceFactory->make($user->id, true);
        $freshPlanet = $this->planetServiceFactory->make($planetService->getPlanetId(), true);

        if ($freshPlanet === null) {
            throw new RuntimeException('The base planet vanished immediately after creation.');
        }

        $this->applySeedBuildings($freshPlanet);

        $this->settings->set('npc_last_spawn_at', (int)Date::now()->timestamp);

        return $freshPlanet;
    }

    /**
     * Find a position for a new base, or null when the universe offers none.
     *
     * Les regles sont plus strictes qu'une simple recherche de case libre, et elles le sont
     * volontairement : le jour de la mise en ligne, personne n'a demande a avoir des pirates
     * comme voisins. Une base naît donc loin, visible et atteignable mais chez elle. Le
     * rapprochement viendra ensuite des joueurs eux-memes — de leurs colonisations, et de
     * l'essaimage d'une base qu'ils auront laissee prosperer.
     */
    public function findSpawnCoordinate(int $attempts = 200): Coordinate|null
    {
        $humanPlanets = $this->humanPlanetCoordinates();
        $basePlanets = $this->npcPlanetCoordinates();
        $minDistance = $this->settings->npcSeedMinDistance();
        $maxDistance = $this->settings->npcSeedMaxDistance();
        $maxGalaxy = max(1, $this->settings->numberOfGalaxies());

        for ($i = 0; $i < $attempts; $i++) {
            $candidate = new Coordinate(
                random_int(UniverseConstants::MIN_GALAXY, $maxGalaxy),
                random_int(UniverseConstants::MIN_SYSTEM, UniverseConstants::MAX_SYSTEM_COUNT),
                random_int(UniverseConstants::MIN_PLANET_POSITION, UniverseConstants::MAX_PLANET_POSITION)
            );

            if ($this->planetServiceFactory->planetExistsAtCoordinate($candidate)) {
                continue;
            }

            if (!$this->respectsHumanDistance($candidate, $humanPlanets, $minDistance, $maxDistance)) {
                continue;
            }

            if ($this->sharesSystemWithBase($candidate, $basePlanets)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Get whether the candidate sits at an acceptable distance from every human planet.
     *
     * Deux bornes, et les deux comptent. Trop pres, on impose des pirates a un joueur qui
     * n'a rien demande. Trop loin, la base ne produit aucun contenu : personne n'ira jamais
     * la voir, et elle ne sera qu'une ligne de plus dans la base de donnees.
     *
     * @param array<int, Coordinate> $humanPlanets
     */
    private function respectsHumanDistance(
        Coordinate $candidate,
        array $humanPlanets,
        int $minDistance,
        int $maxDistance
    ): bool {
        if ($humanPlanets === []) {
            return true;
        }

        $closest = PHP_INT_MAX;

        foreach ($humanPlanets as $planet) {
            if ($planet->galaxy !== $candidate->galaxy) {
                continue;
            }

            $distance = abs($planet->system - $candidate->system);

            if ($distance < $minDistance) {
                return false;
            }

            $closest = min($closest, $distance);
        }

        // Aucun humain dans cette galaxie : la base serait injoignable en pratique.
        if ($closest === PHP_INT_MAX) {
            return false;
        }

        return $closest <= $maxDistance;
    }

    /**
     * Get whether another base already occupies that system.
     *
     * @param array<int, Coordinate> $basePlanets
     */
    private function sharesSystemWithBase(Coordinate $candidate, array $basePlanets): bool
    {
        foreach ($basePlanets as $base) {
            if ($base->galaxy === $candidate->galaxy && $base->system === $candidate->system) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the coordinates of every living human planet.
     *
     * @return array<int, Coordinate>
     */
    private function humanPlanetCoordinates(): array
    {
        $rows = Planet::query()
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('users.is_npc', false)
            ->where('planets.destroyed', 0)
            ->select('planets.galaxy', 'planets.system', 'planets.planet')
            ->get();

        return $rows->map(
            fn ($row) => new Coordinate((int)$row->galaxy, (int)$row->system, (int)$row->planet)
        )->all();
    }

    /**
     * Get the coordinates of every living NPC planet, all factions together.
     *
     * @return array<int, Coordinate>
     */
    private function npcPlanetCoordinates(): array
    {
        $rows = Planet::query()
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('users.is_npc', true)
            ->where('planets.destroyed', 0)
            ->select('planets.galaxy', 'planets.system', 'planets.planet')
            ->get();

        return $rows->map(
            fn ($row) => new Coordinate((int)$row->galaxy, (int)$row->system, (int)$row->planet)
        )->all();
    }

    /**
     * Give a freshly created base its starting buildings.
     */
    private function applySeedBuildings(PlanetService $planet): void
    {
        foreach (self::PIRATE_SEED_BUILDINGS as $machineName => $level) {
            $object = ObjectService::getObjectByMachineName($machineName);
            $planet->setObjectLevel($object->id, $level, false);
        }

        $planet->save();
        $planet->updateResourceProductionStats();
        $planet->updateResourceStorageStats();
    }

    /**
     * Pick a crew name for a new base.
     */
    private function pickName(): string
    {
        return self::PIRATE_NAMES[array_rand(self::PIRATE_NAMES)];
    }

    /**
     * Turn a crew name into a username nobody else holds.
     *
     * Deux bandes peuvent porter le meme nom au fil du temps — une base detruite renait
     * ailleurs — mais deux comptes ne peuvent pas. Le suffixe reste discret pour que le nom
     * lu en galaxie demeure celui de l'equipage.
     */
    private function uniqueUsername(string $name): string
    {
        if (!User::where('username', $name)->exists()) {
            return $name;
        }

        for ($i = 2; $i < 500; $i++) {
            $candidate = $name . ' ' . $i;

            if (!User::where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not find a free username for a NPC base.');
    }
}
