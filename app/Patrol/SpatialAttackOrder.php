<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Enums\AccountDeletionState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Services\AccountDeletionBarrier;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;

/**
 * Le lancement d une attaque contre une patrouille.
 *
 * ------------------------------------------------------------------------------------
 * UNE ATTAQUE ORDINAIRE, VERS UN ENDROIT QUI N EST PAS UN CORPS
 *
 * La mission creee est une **attaque de genre 1** : elle occupe un creneau de flotte, se rappelle,
 * s affiche dans les mouvements et rentre comme toutes les autres. Ce qui change tient en trois
 * colonnes — `target_patrol_id`, `target_patrol_owner_id`, `x_to`/`y_to` — et en une seule absence :
 * `planet_id_to` reste vide, parce qu il n y a pas de corps a l arrivee.
 *
 * Reecrire un genre de mission pour cela aurait duplique le rappel, le retour, le creneau et la
 * porte des mouvements. Le chemin d arrivee, lui, est bien distinct : `AttackMission` reconnait la
 * cible spatiale et resout la bataille autrement.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CE SERVICE VERIFIE, ET CE QU IL NE VERIFIE PAS
 *
 * Il verifie ce qui appartient au **depart** : le corps est au joueur, il porte les vaisseaux, un
 * creneau est libre, le compte n est pas en cours de suppression. La **cible**, elle, a deja ete
 * jugee par `PatrolAttackEligibility` — detection, protections, patrouille posee — et arrive ici
 * gelee. Les deux jugements restent separes : melanger « puis-je partir » et « ai-je le droit de
 * viser ceci » ferait deux moteurs de decision pour une seule question.
 */
final class SpatialAttackOrder
{
    public function __construct(
        private readonly PatrolPricing $pricing,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Cree la mission d attaque, retire les vaisseaux, et rend la ligne.
     *
     * @throws PatrolOrderRefused
     */
    public function launch(
        PlanetService $from,
        UnitCollection $units,
        FrozenPatrolTarget $cible,
        float $speedPercent,
        int $now,
    ): FleetMission {
        if (!$this->settings->patrolsEnabled()) {
            throw new PatrolOrderRefused('disabled');
        }

        if ($units->getAmount() === 0) {
            throw new PatrolOrderRefused('no_units');
        }

        $joueur = $from->getPlayer();

        if ($joueur === null) {
            throw new PatrolOrderRefused('no_owner');
        }

        // **La regle des unites immobiles, ecrite une fois et appliquee ici aussi.** Un satellite
        // solaire, un foreur ou une defense n ont pas de vitesse : la duree du vol serait infinie.
        // C etait le seul depart du chantier qui ne passait pas par ce garde, et rien ne l arretait
        // sinon une division par zero au fond du calcul de duree — une protection accidentelle, qui
        // tomberait le jour ou une defense recevrait une vitesse, et qui rendait une erreur 500 la
        // ou le joueur attend un refus lisible.
        if (PatrolOrders::hasImmobileUnit($joueur, $units)) {
            throw new PatrolOrderRefused('immobile_unit');
        }

        // **Le devis d une patrouille, reutilise tel quel.** Aller frapper un point de l espace coute
        // ce que coute s y rendre : meme geometrie, meme distance, meme carburant. Ecrire une seconde
        // tarification aurait cree deux formules pour un seul trajet, et elles auraient derive.
        //
        // La reserve vaut zero : une attaque ne stationne pas, elle frappe et rentre.
        $depart = $from->getPlanetCoordinates();

        $devis = $this->pricing->quote(
            $joueur,
            $units,
            0.0,
            $depart->galaxy,
            $depart->system,
            $this->pricing->geometry()->bodyPoint($depart->position),
            PatrolDestination::spatialPoint(
                $this->pricing->geometry(),
                $cible->galaxy,
                $cible->system,
                $cible->point()
            ),
            $speedPercent,
            0,
            $depart,
        );

        if (!$devis->isPossible()) {
            throw new PatrolOrderRefused((string)$devis->refusal);
        }

        return DB::transaction(function () use ($from, $joueur, $units, $cible, $devis, $now): FleetMission {
            // **Les memes protections qu un lancement de flotte ordinaire**, et pour les memes
            // raisons : une flotte ne part pas pendant qu on efface son proprietaire, et le creneau
            // se compte sous le verrou qui serialise les departs du meme joueur.
            if (AccountDeletionBarrier::heldState($joueur->getId()) === AccountDeletionState::Pending) {
                throw new PatrolOrderRefused('account_being_deleted');
            }

            if ($joueur->getFleetSlotsInUse() >= $joueur->getFleetSlotsMax()) {
                throw new PatrolOrderRefused('no_fleet_slot');
            }

            // Le carburant part du corps, comme pour toute mission.
            $aRetirer = new Resources(0, 0, $devis->fuelCost, 0);

            // **Le point unique des departs** : il applique la regle des plus intactes, refuse les
            // unites tenues au dock, et rend les degats emportes.
            $degatsEmportes = $from->detachUnitsForDeparture($aRetirer, $units);

            if ($degatsEmportes === null) {
                throw new PatrolOrderRefused('not_enough_on_planet');
            }

            $depart = $from->getPlanetCoordinates();

            $mission = new FleetMission();
            $mission->user_id = $joueur->getId();
            $mission->mission_type = 1;

            $mission->planet_id_from = $from->getPlanetId();
            $mission->type_from = $from->getPlanetType()->value;
            $mission->galaxy_from = $depart->galaxy;
            $mission->system_from = $depart->system;
            $mission->position_from = $depart->position;

            // **Aucun corps a l arrivee**, et c est ce qui distingue cette mission de toutes les
            // autres attaques. `type_to` reste celui d un point de l espace.
            $mission->planet_id_to = null;
            $mission->type_to = PlanetType::SpatialPoint->value;
            $mission->galaxy_to = $cible->galaxy;
            $mission->system_to = $cible->system;
            $mission->position_to = 0;
            $mission->x_to = $cible->x;
            $mission->y_to = $cible->y;

            // L identite gelee de la cible : les trois faits que l arrivee comparera.
            $mission->target_patrol_id = $cible->patrolId;
            $mission->target_patrol_owner_id = $cible->ownerId;

            $mission->time_departure = $now;
            $mission->time_arrival = $now + $devis->durationSeconds;
            $mission->processed = 0;
            $mission->canceled = 0;

            $mission->metal = 0;
            $mission->crystal = 0;
            $mission->deuterium = 0;
            $mission->deuterium_consumption = $devis->fuelCost;

            foreach ($units->units as $unite) {
                $mission->{$unite->unitObject->machine_name} = $unite->amount;
            }

            if (!$degatsEmportes->isEmpty()) {
                $mission->damaged_hulls = $degatsEmportes->toStorage();
            }

            $mission->save();

            return $mission;
        });
    }

    /**
     * Les degats qu une flotte emporterait — expose pour les essais et les devis.
     */
    public function hullsThatWouldLeave(PlanetService $from, UnitCollection $units): DamagedHulls
    {
        $degats = $from->damagedHulls();
        $partent = DamagedHulls::none();

        foreach ($units->units as $unite) {
            $type = $unite->unitObject->machine_name;
            [$emportes] = $degats->takeMostIntact($type, $unite->amount, $from->getObjectAmount($type));
            $partent = $partent->merge($emportes);
        }

        return $partent;
    }
}
