<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\ReturnDestinationResolver;
use OGame\Combat\Services\ReturnPlanner;
use OGame\Factories\PlanetServiceFactory;
use OGame\Hull\DamagedHulls;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;

/**
 * Le retour d une flotte partie du point d une patrouille.
 *
 * ------------------------------------------------------------------------------------
 * DEUX ISSUES, ET LA SECONDE N EST PAS UNE PANNE
 *
 * Une patrouille envoie ses vaisseaux frapper une cible detectee ; le point reste le sien pendant
 * tout le raid. Au retour, deux choses peuvent etre vraies :
 *
 *   - **elle attend toujours** : la flotte se repose a son point, la patrouille redevient posee, et
 *     son prochain rendez-vous — le retour de securite — est rearme ;
 *   - **elle n existe plus** : le compte a ete supprime, la ligne a disparu, ou son etat ne tient
 *     plus aucun point. La flotte n est pas perdue pour autant : elle atterrit sur un corps du
 *     joueur, choisi par le **protocole unique des destinations de retour**.
 *
 * ------------------------------------------------------------------------------------
 * L ORDRE DES VERROUS EST CELUI DU JEU, ET IL N A PAS D EXCEPTION
 *
 * `planets` d abord, `patrols` ensuite. C est l ordre mesure sur tous les chemins existants ;
 * l inverser ici ferait un interblocage que SQLite ne montrerait **jamais** — `lockForUpdate()` n y
 * compile a rien — et qui n apparaitrait qu en production, sous MariaDB.
 *
 * ------------------------------------------------------------------------------------
 * AUCUN `??` NE FABRIQUE UNE DESTINATION
 *
 * La retombee sur un corps n est pas un defaut cache derriere un operateur : c est
 * `ReturnPlanner::planFor()` qui la prononce, sous verrou, apres avoir constate que la patrouille
 * ne tient plus de point. Le meme code decide pour une flotte refusee par un combat et pour une
 * annulation d exploitation — un second decideur aurait derive.
 */
final class PatrolHomecoming
{
    public function __construct(
        private readonly PatrolOrders $orders,
        private readonly SettingsService $settings,
        // **La fabrique se recoit, elle ne se fabrique pas.** Elle a ses propres dependances, et un
        // service qui les devinerait ici ferait une seconde facon de la construire.
        private readonly PlanetServiceFactory $planets,
        private readonly ReturnPlanner $planner = new ReturnPlanner(),
        private readonly ReturnDestinationResolver $destinations = new ReturnDestinationResolver(),
    ) {
    }

    /**
     * Cette mission est-elle le retour d une flotte partie d une patrouille ?
     *
     * Les deux faits comptent. `patrol_id` seul designe aussi les segments ordinaires d une
     * patrouille, qui ne passent jamais par ici ; `type_to` seul designe toute flotte visant un
     * point, patrouille ou non. **Comparer un objet a ses pairs** : c est exactement le couple que
     * `startReturn()` ecrit pour ce cas, et lui seul.
     */
    public static function isHomecomingOfAPatrol(FleetMission $mission): bool
    {
        return $mission->patrol_id !== null
            && (int)$mission->type_to === PlanetType::SpatialPoint->value;
    }

    /**
     * Repose la flotte, ou la fait atterrir si sa patrouille n existe plus.
     */
    public function receive(FleetMission $retour): void
    {
        DB::transaction(function () use ($retour): void {
            // **Les corps d abord.** Ils decident de la retombee, et ils se tiennent avant la
            // patrouille dans l ordre global.
            $this->destinations->holdTheDecidingBodies($this->planner->bodiesThatDecideFor($retour));
            $this->destinations->holdTheDecidingPatrols($this->planner->patrolsThatDecideFor($retour));

            $plan = $this->planner->planFor($retour);

            if ($plan->landsOnAPoint()) {
                $this->parkAgain($retour, (int)$plan->patrolId);

                return;
            }

            $this->landOnABody($retour, $plan->isPossible() ? $plan->planetId : null);
        });
    }

    /**
     * La patrouille attend toujours : la flotte redevient son segment pose.
     *
     * **Le retour ne se marque pas traite**, et c est tout l objet du geste. Un segment pose reste
     * `processed = 0` : c est ce qui lui donne son creneau de flotte, sa place dans la boite
     * d evenements et son inscription a un combat. `park()` lui pose un `time_holding` qui porte
     * jusqu au retour de securite, et le travailleur ne le reprendra qu a cette echeance.
     */
    private function parkAgain(FleetMission $retour, int $patrolId): void
    {
        $patrouille = Patrol::query()->whereKey($patrolId)->first();

        if (!$patrouille instanceof Patrol) {
            // Deja exclu par le plan, qui vient de la lire sous verrou ; redit pour l analyse
            // statique, et pour qu un futur appelant ne puisse pas court-circuiter le plan.
            $this->landOnABody($retour, null);

            return;
        }

        $patrouille->forceFill(['current_mission_id' => $retour->id])->save();

        // `park()` ecrit l etat, le point, les curseurs de facturation et le prochain rendez-vous.
        // Elle exige que la ligne de la mission soit tenue et que l etat admette le stationnement :
        // les deux le sont — le travailleur tient la mission, et nous sortons d un raid.
        //
        // **Le vol n a pas deplace la patrouille**, et c est ce qu il faut lui dire. Son point, sa
        // galaxie et son systeme sont exactement ceux d avant le raid : ce sont ses vaisseaux qui
        // sont alles ailleurs. Laisser `park()` deduire du segment qu elle a change de systeme
        // revoquait tous les contacts poses sur elle et relancait l horloge d acquisition.
        $this->orders->park($patrouille, $retour, false);
    }

    /**
     * Plus de patrouille : la flotte atterrit sur le corps que le protocole a designe.
     *
     * ## Ce qui est rendu, et ce qui ne l est pas
     *
     * Les vaisseaux, leurs coques entamees et la cargaison rentrent. **La reserve de carburant,
     * non** : elle vit sur la ligne de la patrouille, et cette ligne n existe plus ou ne tient plus
     * rien. La rendre demanderait de la lire quelque part ou elle n est pas ; l inventer serait pire
     * que de la perdre.
     */
    private function landOnABody(FleetMission $retour, int|null $bodyId): void
    {
        $retour->forceFill(['processed' => 1])->save();

        if ($bodyId === null) {
            // **Aucun corps ne reste au joueur.** Le compte est en train de disparaitre ; la flotte
            // n a nulle part ou se poser, et la mission est close sans credit plutot que reprise
            // indefiniment par le travailleur.
            return;
        }

        $corps = $this->planets->make($bodyId, true);

        if ($corps === null) {
            return;
        }

        $corps->addUnits(resolve(FleetMissionService::class)->getFleetUnits($retour));

        if ($this->settings->hullDamageEnabled()) {
            $corps->landDamagedHulls(DamagedHulls::fromStorage($retour->damaged_hulls));
        }

        $corps->addResourcesAtomic(new Resources(
            (float)$retour->metal,
            (float)$retour->crystal,
            (float)$retour->deuterium,
            0
        ));

        $patrouille = $retour->patrol_id === null
            ? null
            : Patrol::query()->whereKey((int)$retour->patrol_id)->first();

        // La patrouille existe encore mais ne tenait plus de point — immobilisee ailleurs, deja
        // terminee. Son vol courant vient de se poser : elle n en a plus. Le vol courant est ce
        // retour, ou **le segment dont ce retour est ne** : un rappel generique laissait la
        // patrouille pointer sur son segment annule pendant que la flotte rentrait.
        $volsQuiSePosent = [(int)$retour->id];

        if ($retour->parent_id !== null) {
            $volsQuiSePosent[] = (int)$retour->parent_id;
        }

        if ($patrouille instanceof Patrol
            && (int)$patrouille->user_id === (int)$retour->user_id
            && in_array((int)$patrouille->current_mission_id, $volsQuiSePosent, true)
        ) {
            $patrouille->forceFill([
                'state' => PatrolState::Finished,
                'current_mission_id' => null,
                'x' => null,
                'y' => null,
                'finished_at' => (int)$retour->time_arrival,
                'finish_reason' => 'came_home',
            ])->save();
        }
    }
}
