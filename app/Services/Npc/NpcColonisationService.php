<?php

namespace OGame\Services\Npc;

use Illuminate\Support\Facades\Date;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\NpcBaseSnapshot;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Throwable;

/**
 * L'essaimage : une base qui a prospere finit par en fonder une autre.
 *
 * Rien ici n'invente de mecanique. Le vaisseau de colonisation est fabrique par le chantier
 * comme n'importe quelle unite, et il part en mission de colonisation ordinaire — celle qu'un
 * joueur declenche depuis la galaxie. La planete n'apparait donc qu'a l'arrivee de la flotte,
 * apres un vrai temps de vol et une vraie consommation de deuterium.
 *
 * Trois garde-fous, et les trois comptent :
 *
 *   - l'astrophysique du compte limite le nombre de planetes et les positions atteignables,
 *     exactement comme pour un joueur ;
 *   - le plafond de population des factions limite le total, sans quoi une base prospere
 *     essaimerait indefiniment ;
 *   - une base ne colonise qu'apres etre restee a son plafond de maturite un certain temps.
 *     Une base qui grandit encore a mieux a faire de ses ressources, et l'essaimage doit
 *     rester la recompense d'une base qu'on a laissee tranquille.
 */
class NpcColonisationService
{
    /**
     * Le vaisseau part seul : le fret ne sert a rien et une escorte serait du gachis.
     */
    private const int COLONY_SHIP_COUNT = 1;

    /**
     * Combien de positions on tire au sort avant d'abandonner pour ce passage.
     *
     * L'astrophysique n'ouvre pas toutes les positions d'un systeme : une base jeune ne peut
     * viser que le centre. Un tirage peut donc tomber sur une case interdite, et il faut
     * simplement en essayer une autre.
     */
    private const int PLACEMENT_ATTEMPTS = 25;

    /**
     * En dessous de ce nombre de cases libres, la base cherche ailleurs.
     *
     * Deux et non zero : il faut qu'il reste de quoi poser le chantier ou le terraformeur si
     * l'un des deux manquait encore. Une base qui attend d'etre a zero pour reagir est une
     * base qui ne reagit plus.
     */
    private const int FIELDS_LEFT_TO_STAY = 2;

    public function __construct(
        private SettingsService $settings,
        private NpcBaseService $bases,
        private PlanetServiceFactory $planetServiceFactory,
        private PlayerServiceFactory $playerServiceFactory
    ) {
    }

    /**
     * Send out every colony ship that is ready to leave.
     *
     * @return array<int, array{base: string, target: string}> Ce qui est parti, pour le journal.
     */
    public function swarm(bool $simulation): array
    {
        if (!$this->settings->npcSwarmEnabled()) {
            return [];
        }

        $departs = [];

        foreach ($this->bases->livingBases() as $row) {
            if ($this->bases->baseCount() + count($departs) >= $this->settings->npcBaseCountMax()) {
                break;
            }

            $base = $this->planetServiceFactory->make($row->id, true);

            if ($base === null || !$this->isReadyToSwarm($base)) {
                continue;
            }

            $target = $this->findColonyTarget($base);

            if ($target === null) {
                continue;
            }

            if ($simulation) {
                $departs[] = ['base' => $base->getPlanetName(), 'target' => $target->asString()];
                continue;
            }

            if ($this->sendColonyShip($base, $target)) {
                $departs[] = ['base' => $base->getPlanetName(), 'target' => $target->asString()];
            }
        }

        return $departs;
    }

    /**
     * Get whether this base has earned the right to found another.
     */
    private function isReadyToSwarm(PlanetService $base): bool
    {
        if ($base->getObjectAmount('colony_ship') < self::COLONY_SHIP_COUNT) {
            return false;
        }

        $owner = $base->getPlayer();

        if ($owner === null) {
            return false;
        }

        // Le compte est reconstruit depuis la base : sa liste de planetes et son niveau
        // d'astrophysique doivent etre ceux d'aujourd'hui, pas ceux du chargement.
        $player = $this->playerServiceFactory->make($owner->getId(), true);

        if ($player->planets->planetCount() >= $player->getMaxPlanetAmount()) {
            return false;
        }

        // Deux raisons de partir, et la seconde est une tactique, pas une recompense.
        //
        // Une base qui a rempli ses cases ne peut plus rien construire : le terraformeur ne
        // repousse la limite que d'un cran, et une fois celui-ci au bout, la seule facon de
        // continuer a grandir est d'aller ailleurs. C'est exactement ce que fait un joueur.
        // Sans cette porte, une base saturee resterait a se regarder construire en boucle des
        // vaisseaux, ses caisses debordant sans emploi.
        return $this->hasRunOutOfFields($base) || $this->hasSatAtItsCeiling($base);
    }

    /**
     * Get whether this base has filled its planet and can only grow elsewhere.
     */
    private function hasRunOutOfFields(PlanetService $base): bool
    {
        return ($base->getPlanetFieldMax() - $base->getBuildingCount()) <= self::FIELDS_LEFT_TO_STAY;
    }

    /**
     * Get whether the base has been at its growth ceiling long enough to spare a colony ship.
     *
     * La duree se lit dans les releves horaires, et c'est la seule facon honnete de la
     * mesurer : une base peut atteindre son plafond, redescendre parce que le serveur a
     * grandi sous elle, puis y revenir. Seul l'historique distingue « au plafond depuis une
     * semaine » de « au plafond depuis dix minutes ».
     */
    private function hasSatAtItsCeiling(PlanetService $base): bool
    {
        $depuis = Date::now()->subDays($this->settings->npcSwarmDelayDays());

        $releves = NpcBaseSnapshot::query()
            ->where('planet_id', $base->getPlanetId())
            ->where('observed_at', '>=', $depuis)
            ->oldest('observed_at')
            ->get();

        if ($releves->isEmpty()) {
            return false;
        }

        // Le plus ancien releve de la fenetre doit deja etre au plafond, sinon la base n'y
        // est pas restee assez longtemps. La tolerance de deux heures absorbe un tick manque
        // ou un redemarrage du planificateur, qui ne disent rien de la base elle-meme.
        if ($releves->first()->observed_at->greaterThan($depuis->copy()->addHours(2))) {
            return false;
        }

        foreach ($releves as $releve) {
            if ($releve->maturity < 100) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find a free position this base is actually allowed to colonise.
     */
    private function findColonyTarget(PlanetService $base): Coordinate|null
    {
        $owner = $base->getPlayer();

        if ($owner === null) {
            return null;
        }

        $player = $this->playerServiceFactory->make($owner->getId(), true);

        for ($i = 0; $i < self::PLACEMENT_ATTEMPTS; $i++) {
            // Les regles de placement des bases s'appliquent telles quelles : une colonie ne
            // doit pas plus tomber dans le jardin d'un debutant qu'une base fondatrice.
            $candidate = $this->bases->findSpawnCoordinate(40);

            if ($candidate === null) {
                continue;
            }

            if (!$player->canColonizePosition($candidate->position)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Send the colony ship on its way, as a player would.
     */
    private function sendColonyShip(PlanetService $base, Coordinate $target): bool
    {
        $fleet = new UnitCollection();
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName('colony_ship'), self::COLONY_SHIP_COUNT);

        // Le service de flotte est construit pour un joueur donne : c'est celui de la base
        // qu'il faut, pas celui de la requete en cours.
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $base->getPlayer()]);

        $mission = GameMissionFactory::getMissionById(7, [
            'fleetMissionService' => $fleetMissionService,
            'messageService' => resolve(MessageService::class),
        ]);

        // On interroge le meme controle qu'un joueur : position libre, astrophysique
        // suffisante pour ce creneau, vaisseau de colonisation present. On ne force jamais
        // son refus.
        $possible = $mission->isMissionPossible($base, $target, PlanetType::Planet, $fleet);

        if (!$possible->possible) {
            return false;
        }

        try {
            $fleetMissionService->createNewFromPlanet(
                $base,
                $target,
                PlanetType::Planet,
                7,
                $fleet,
                new Resources(0, 0, 0, 0),
                10
            );
        } catch (Throwable) {
            // Deuterium insuffisant, creneau de flotte pris, position occupee entre-temps :
            // la base reessaiera au prochain passage. Rien de ceci ne merite d'interrompre le
            // tick des autres bases.
            return false;
        }

        return true;
    }
}
