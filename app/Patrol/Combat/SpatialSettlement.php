<?php

namespace OGame\Patrol\Combat;

use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
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
 * 3. **Le butin serait la cargaison, jamais un stock au sol — mais rien ne la rend encore pillable.** Le
 *    moteur prend ce qu il peut piller dans les ressources protegees de la photographie, ou a defaut sur le
 *    corps vise. Un point de l espace n a pas de corps, et **aucune ressource protegee ne lui est passee** :
 *    `$resultat->loot` vaut donc zero, toujours. Le reglement le soustrait quand meme, pour que le jour ou
 *    la decision sera prise il n y ait qu un seul endroit a changer. La reserve de carburant de la
 *    patrouille, elle, vit sur sa ligne et non sur le segment : elle ne sera pillable dans aucun cas, et
 *    c est voulu — sans carburant une patrouille ne rentrerait jamais.
 * 4. **L attaquante repart avec la cargaison de ses survivants**, et ce qu elle a pris. Ce que portaient ses
 *    vaisseaux detruits est perdu, comme dans toute bataille : une soute qui explose n arrive nulle part.
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

                // **La cargaison d une patrouille meurt avec ses vaisseaux.** Elle restait entiere quelles que
                // soient ses pertes : une patrouille qui perdait la moitie de ses transporteurs gardait tout
                // son metal. La part qui survit se mesure sur les capacites de la bataille — celles-la memes
                // que le combat durable emploie pour un renfort — et le pillage prend **ensuite**, sur ce qui
                // reste : la destruction precede le pillage, et rien ne se prend a ce qui n existe plus.
                $partSurvivante = self::survivingCargoShareOf($resultat, (int)$segment->id);

                $segment->metal = self::whatIsLeft((float)$segment->metal, $partSurvivante, $cargaisonPillee->metal->get());
                $segment->crystal = self::whatIsLeft((float)$segment->crystal, $partSurvivante, $cargaisonPillee->crystal->get());
                $segment->deuterium = self::whatIsLeft((float)$segment->deuterium, $partSurvivante, $cargaisonPillee->deuterium->get());

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

            // **Seule la cargaison des survivants rentre, et le butin ne prend que la place qui reste.**
            //
            // Le moteur a deja fait les deux calculs pour cette flotte : la cargaison ramenee a la part de
            // capacite qui a survecu, et la part de butin bornee par le fret encore libre. Les relire evite
            // une seconde formule — et celle qui vivait ici rendait **la colonne entiere**, cargaison des
            // vaisseaux detruits comprise : une flotte qui perdait ses transporteurs rentrait avec ce qu ils
            // portaient.
            $flotteAttaquante = self::attackerResultOf($resultat, (int)$attaquante->id);

            $ramene = new Resources(
                $flotteAttaquante->survivingCargo->metal->get() + $flotteAttaquante->lootShare->metal->get(),
                $flotteAttaquante->survivingCargo->crystal->get() + $flotteAttaquante->lootShare->crystal->get(),
                $flotteAttaquante->survivingCargo->deuterium->get() + $flotteAttaquante->lootShare->deuterium->get(),
                0
            );

            $creerRetour($attaquante, $ramene, $survivantsAttaque);
        });
    }

    /**
     * La part de la cargaison d une flotte defensive qui survit : le rapport des capacites de la bataille.
     *
     * **Les deux capacites bougent ensemble, mais pas du meme facteur** — la survivante ne compte que les
     * vaisseaux restants —, et c est ce rapport qui dit ce qui reste a bord. Une flotte sans capacite de
     * depart ne portait rien : sa part est nulle.
     */
    private static function survivingCargoShareOf(BattleResult $resultat, int $fleetMissionId): float
    {
        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte->startingCargoCapacity > 0
                    ? $flotte->survivingCargoCapacity / $flotte->startingCargoCapacity
                    : 0.0;
            }
        }

        // Inatteignable par l appelant : il a deja lu les survivants de cette flotte dans ce meme resultat.
        // La garde reste, parce qu une cargaison effacee par un resultat incomplet ne se verrait pas.
        throw new LogicException('Le resultat ne porte pas la flotte defensive ' . $fleetMissionId . ' : sa cargaison ne peut pas etre reduite.');
    }

    /**
     * Ce qui reste d une cargaison : la part qui survit aux pertes, moins ce que le pillage a pris.
     *
     * L unite est entiere avant la soustraction : une cargaison se compte en unites, et un reste
     * fractionnaire reapparaitrait a chaque bataille suivante.
     */
    private static function whatIsLeft(float $porte, float $partSurvivante, float $pille): float
    {
        return max(0.0, (float)(int)($porte * $partSurvivante) - $pille);
    }

    /**
     * Le resultat de la flotte attaquante, ou un refus.
     */
    private static function attackerResultOf(BattleResult $resultat, int $fleetMissionId): AttackerFleetResult
    {
        foreach ($resultat->attackerFleetResults as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte;
            }
        }

        // Inatteignable de meme : l appelant vient de lire les survivants de cette flotte.
        throw new LogicException('Le resultat ne porte pas la flotte attaquante ' . $fleetMissionId . ' : son retour ne peut pas etre compose.');
    }

    /**     * L effectif de depart d une flotte defensive, tel que le resultat le porte.
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
