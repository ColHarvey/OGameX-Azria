<?php

namespace OGame\GameMissions;

use Illuminate\Support\Facades\Date;
use OGame\Enums\FleetMissionStatus;
use OGame\Enums\FleetSpeedType;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\Models\MissionPossibleStatus;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet\Coordinate;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\PatrolOrders;
use OGame\Services\PlanetService;
use RuntimeException;

/**
 * Un segment de patrouille : le vol d une patrouille vers un point libre de l espace (genre 11).
 *
 * ## Ce qu un segment est, et n est pas
 *
 * C est une ligne de `fleet_missions` comme les autres : elle porte les unites et la cargaison,
 * occupe un creneau de flotte, passe la porte des mouvements, s annonce sur la carte. Ce qu elle
 * n est pas : la patrouille elle-meme. L identite durable — etat, point, reserve, version d ordre,
 * base d attache — vit dans `patrols`, et c est le service des mouvements de patrouille qui regle
 * l arrivee d un segment (poser la patrouille, la facturer, la faire repartir), pas le traitement
 * generique des missions. Voir le journal §114 et les revues 117 a 121 de Codex.
 *
 * ## Ce qui est possible depuis une planete
 *
 * Partir vers un point spatial (type 5), ou vers le point de reference d une planete ou d une lune
 * — la patrouille se pose alors *pres* du corps, jamais dessus. Un champ de debris ou l espace
 * profond de l expedition ne sont pas des destinations : l un est un objet a recycler, l autre une
 * mission a part entiere. Et une patrouille ne part pas sans un vaisseau capable de voler.
 */
class PatrolMission extends GameMission
{
    protected static string $name = 'Patrol';
    protected static int $typeId = 11;
    protected static bool $hasReturnMission = false;
    protected static FleetSpeedType $fleetSpeedType = FleetSpeedType::holding;
    protected static FleetMissionStatus $friendlyStatus = FleetMissionStatus::Friendly;

    /**
     * @inheritdoc
     */
    public function isMissionPossible(PlanetService $planet, Coordinate $targetCoordinate, PlanetType $targetType, UnitCollection $units): MissionPossibleStatus
    {
        $parentCheck = parent::isMissionPossible($planet, $targetCoordinate, $targetType, $units);
        if (!$parentCheck->possible) {
            return $parentCheck;
        }

        // L interrupteur du chantier : eteint, la mission n existe pas pour le joueur.
        if (!$this->settings->patrolsEnabled()) {
            return new MissionPossibleStatus(false);
        }

        if (!in_array($targetType, [PlanetType::SpatialPoint, PlanetType::Planet, PlanetType::Moon], true)) {
            return new MissionPossibleStatus(false);
        }

        if ($units->getAmount() === 0) {
            return new MissionPossibleStatus(false);
        }

        // Un satellite solaire ou un foreur a une vitesse nulle : la duree d un vol serait infinie.
        $player = $planet->getPlayer();
        if ($player === null) {
            return new MissionPossibleStatus(false);
        }

        if (PatrolOrders::hasImmobileUnit($player, $units)) {
            return new MissionPossibleStatus(false, __('t_ingame.patrol.refusal_immobile_unit'));
        }

        return new MissionPossibleStatus(true);
    }

    /**
     * @inheritdoc
     *
     * ## Trois arrivees, une seule porte
     *
     * Le travailleur du jeu reprend un segment quand `time_arrival + time_holding` est atteint. Pour
     * une patrouille cela arrive trois fois, et l etat dit laquelle :
     *
     *  - elle **volait** : elle se pose, et son prochain rendez-vous est arme ;
     *  - elle **rentrait** : elle atterrit, tout revient a la planete, elle cesse d exister ;
     *  - elle **stationnait** et son echeance est venue : le retour de securite part.
     *
     * Aucun autre etat ne peut arriver ici. Une patrouille terminee n a plus de segment ; une
     * patrouille immobilisee n a plus de quoi partir, et son segment porte un rendez-vous lointain.
     */
    protected function processArrival(FleetMission $mission): void
    {
        $patrouille = Patrol::query()->find($mission->patrol_id);

        if ($patrouille === null) {
            throw new RuntimeException('Le segment ' . $mission->id . ' ne rattache a aucune patrouille.');
        }

        $orders = resolve(PatrolOrders::class);
        $maintenant = (int)Date::now()->timestamp;

        if ($patrouille->state === PatrolState::Returning) {
            // **La destination dit ou l on allait ; elle n autorise pas la livraison.** Entre le depart
            // et l arrivee, la base a pu etre detruite ou changer de mains : se poser dessus
            // livrerait la flotte, la cargaison et la reserve au proprietaire du moment. Le decideur
            // rend le corps seulement s il est encore celui du joueur.
            $base = $orders->homecomingBase($patrouille, $mission);

            // **Le refus sous verrou compte autant que celui du decideur.** La planete peut changer
            // de mains entre les deux lectures ; `land()` rend alors `false` sans rien marquer ni
            // crediter, et la patrouille prend le meme chemin que si le corps etait deja perdu.
            //
            // **Cette branche-la n est pas prouvee ici, et c est dit.** Elle ne s atteint que si la
            // ligne change entre le decideur et le verrou : un seul processus ne peut pas l ouvrir,
            // et une mutation qui la supprime survit a toute la suite. Sa preuve appartient au bac
            // MariaDB, avec les autres courses, et elle y est due.
            if ($base !== null && $orders->land($patrouille, $mission, $maintenant, $base)) {
                return;
            }

            // Plus de base valable : la patrouille stationne la ou elle est — elle y est
            // physiquement — et reprend le retour de securite vers ce qui lui reste. Rien n est
            // perdu ; unites, cargaison et reserve restent a bord.
            $orders->park($patrouille, $mission);
            $orders->launchSafetyReturn($patrouille, $mission, $maintenant);

            return;
        }

        if ($patrouille->state === PatrolState::Stationed) {
            $orders->launchSafetyReturn($patrouille, $mission, $maintenant);

            return;
        }

        $orders->park($patrouille, $mission);
    }

    /**
     * @inheritdoc
     */
    protected function processReturn(FleetMission $mission): void
    {
        throw new RuntimeException('Le retour d un segment de patrouille est regle par le service des mouvements de patrouille, jamais par le traitement generique (mission ' . $mission->id . ').');
    }
}
