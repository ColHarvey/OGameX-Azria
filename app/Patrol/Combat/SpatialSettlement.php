<?php

namespace OGame\Patrol\Combat;

use Illuminate\Support\Facades\DB;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\SettingsService;

/**
 * Ce qu une bataille en espace libre laisse derriere elle.
 *
 * ------------------------------------------------------------------------------------
 * QUATRE EFFETS, ET AUCUN QUI SE DEVINE
 *
 * 1. **Les survivants de chaque camp** gardent leurs coques entamees. Une flotte entierement
 *    detruite disparait ; une patrouille entierement detruite meurt avec sa reserve.
 * 2. **Les debris restent au point**, pas sur une planete : il n y en a pas. Un recycleur devra s y
 *    rendre, et c est ce qui donne un interet a se battre loin de tout.
 * 3. **Le butin est la cargaison**, jamais un stock au sol. La reserve de carburant de la patrouille
 *    vit sur sa ligne, pas sur le segment : elle n est donc pas pillable, et c est voulu — sans
 *    carburant une patrouille ne rentrerait jamais.
 * 4. **L attaquante repart**, avec ce qui lui reste et ce qu elle a pris.
 *
 * ------------------------------------------------------------------------------------
 * TOUT VIT DANS UNE TRANSACTION, ET L ORDRE DES ECRITURES EST LE MEME QUE PARTOUT
 *
 * Une panne au milieu ne doit laisser ni flotte fantome, ni debris sans bataille, ni patrouille
 * detruite dont les vaisseaux seraient rentres. Les deux camps sont regles ensemble ou pas du tout.
 */
final class SpatialSettlement
{
    public function __construct(
        private readonly SpatialBattle $bataille,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Applique le resultat : ecrit les deux camps, pose les debris, prepare le retour.
     *
     * @param callable(FleetMission, Resources, UnitCollection): void $creerRetour
     *        Le createur du retour attaquant — delegue a `GameMission::startReturn()`, pour que
     *        cette classe n ait pas a connaitre les regles de duree, de destination et de projection.
     */
    public function settle(
        BattleResult $resultat,
        FleetMission $attaquante,
        Patrol $cible,
        FleetMission $segment,
        callable $creerRetour,
    ): void {
        DB::transaction(function () use ($resultat, $attaquante, $cible, $segment, $creerRetour): void {
            $point = new SpatialPoint((int)$segment->x_to, (int)$segment->y_to);

            // ---- 1. La patrouille : ce qui lui reste, et dans quel etat ----
            //
            // L effectif de **depart** sert de liste des colonnes a reecrire : lui seul dit quels
            // types cette flotte portait, et donc quelles colonnes existent et doivent retomber a
            // zero quand un type est entierement detruit.
            $depart = $this->startingUnitsOf($resultat, (int)$segment->id);
            $survivantsDefense = SpatialBattle::survivorsOf($resultat, (int)$segment->id, false);
            $coquesDefense = SpatialBattle::survivorHullsOf($resultat, (int)$segment->id, false);

            $cargaisonPillee = $resultat->loot;

            if ($survivantsDefense->getAmount() === 0) {
                // **Une patrouille entierement detruite disparait avec sa reserve.** Le segment est
                // marque traite pour qu aucun travailleur ne le reprenne, et la patrouille passe a
                // l etat detruit : elle ne rentrera pas, et son creneau de flotte se libere.
                $this->wipeOut($cible, $segment);
            } else {
                // Les colonnes de vaisseaux sont remises a ce qui survit ; celles qui tombent a zero
                // doivent etre ecrites, sinon un type entierement detruit resterait a son compte.
                //
                // **Seuls les types que le segment portait sont touches.** `getShipObjects()` rend
                // aussi le satellite solaire et la foreuse, qui **n ont pas de colonne** sur
                // `fleet_missions` — ils ne voyagent pas. Les ecrire faisait echouer la requete
                // entiere : « no such column: solar_satellite ». Le premier essai l a montre.
                foreach ($depart->units as $unite) {
                    $segment->{$unite->unitObject->machine_name} =
                        $survivantsDefense->getAmountByMachineName($unite->unitObject->machine_name);
                }

                // La cargaison perdue au pillage quitte le segment.
                $segment->metal = max(0.0, (float)$segment->metal - $cargaisonPillee->metal->get());
                $segment->crystal = max(0.0, (float)$segment->crystal - $cargaisonPillee->crystal->get());
                $segment->deuterium = max(0.0, (float)$segment->deuterium - $cargaisonPillee->deuterium->get());

                if ($this->settings->hullDamageEnabled()) {
                    $segment->damaged_hulls = $coquesDefense->toStorage();
                }

                $segment->save();
            }

            // ---- 2. Les debris restent au point ----
            $this->bataille->leaveDebrisAt(
                (int)$cible->galaxy,
                (int)$cible->system,
                $point,
                $resultat->debris
            );

            // ---- 3. L attaquante repart, avec ce qui lui reste et ce qu elle a pris ----
            $survivantsAttaque = SpatialBattle::survivorsOf($resultat, (int)$attaquante->id, true);

            $attaquante->processed = 1;

            if ($this->settings->hullDamageEnabled()) {
                $attaquante->damaged_hulls = SpatialBattle::survivorHullsOf($resultat, (int)$attaquante->id, true)->toStorage();
            }

            $attaquante->save();

            if ($survivantsAttaque->getAmount() === 0) {
                // Rien ne rentre : la flotte est morte sur place, et `startReturn()` refuserait de
                // toute facon de creer un retour vide.
                return;
            }

            $ramene = new Resources(
                (float)$attaquante->metal + $cargaisonPillee->metal->get(),
                (float)$attaquante->crystal + $cargaisonPillee->crystal->get(),
                (float)$attaquante->deuterium + $cargaisonPillee->deuterium->get(),
                0
            );

            $creerRetour($attaquante, $ramene, $survivantsAttaque);
        });
    }

    /**
     * L effectif de depart d une flotte defensive, tel que le resultat le porte.
     *
     * C est la liste des colonnes que le reglement a le droit d ecrire : une flotte qui n avait pas
     * de recycleur n a pas de colonne a remettre a zero pour lui, et `fleet_missions` n en a de
     * toute facon pas pour tous les types du jeu.
     */
    private function startingUnitsOf(BattleResult $resultat, int $fleetMissionId): UnitCollection
    {
        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte->unitsStart;
            }
        }

        return new UnitCollection();
    }

    /**
     * Une patrouille qui ne survit pas : le segment se ferme, la patrouille meurt.
     *
     * **La reserve de carburant meurt avec elle**, et c est la regle : elle n a jamais ete pillable
     * — elle vit sur la ligne de la patrouille, hors de portee du butin — mais elle ne revient pas
     * non plus a un joueur dont plus aucun vaisseau ne tient le point.
     */
    private function wipeOut(Patrol $cible, FleetMission $segment): void
    {
        $segment->forceFill([
            'processed' => 1,
            'canceled' => 1,
        ])->save();

        $cible->forceFill([
            'state' => PatrolState::Destroyed,
            'current_mission_id' => null,
            'fuel_reserve' => 0,
        ])->save();
    }
}
