<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Patrol\Enums\PatrolState;

/**
 * Efface le corps d arrivee des segments de patrouille qui ne s y posent pas.
 *
 * ## Ce que cette ligne donnait a voir
 *
 * Un segment de flotte inscrit son corps d arrivee dans `planet_id_to`, et le jeu rend a chaque
 * joueur **toute mission qui arrive sur une de ses planetes** : c est ainsi qu une attaque
 * s annonce, et c est voulu. Une patrouille qui stationne au **voisinage** d un corps n y arrive
 * pas — elle se pose a cote — mais elle y inscrivait quand meme l identite du corps. Le
 * proprietaire de ce corps voyait donc la patrouille d un tiers dans sa boite d evenements, dans son
 * compteur de flottes et sur sa carte tactique, **sans aucun detecteur**.
 *
 * L ecriture est corrigee a la source : seul un atterrissage nomme son corps
 * (`PatrolDestination::landingOn()`). Corriger la source ne suffit pas — une ligne ecrite avant
 * porterait encore la destination fautive — d ou ce nettoyage, appele une fois par une migration.
 *
 * ## Comment un vrai retour est reconnu, et pourquoi ce n est pas par le proprietaire du corps
 *
 * Une premiere version distinguait le retour du stationnement en comparant le proprietaire du corps
 * a celui de la mission : un retour se pose sur sa propre base, donc un corps d autrui trahissait un
 * stationnement. **Cette regle n est vraie qu au depart.** Entre l ecriture du segment et le
 * nettoyage, la base a pu etre detruite ou changer de mains — et le retour, bien reel, se serait
 * alors vu effacer sa destination : il arriverait sans corps ou se poserait a cote.
 *
 * Le retour est donc reconnu a ce qui est vrai **maintenant** : la patrouille est dans l etat
 * `returning` et ce segment est celui qu elle a en vol. Aucune propriete de corps n est consultee.
 *
 * Seuls les segments **non traites** sont touches : eux seuls sont rendus par la boite d evenements
 * et par la couche des mouvements, et eux seuls decident encore d une arrivee.
 *
 * ## Ce qui disparait avec, volontairement
 *
 * Un stationnement qui nommait un corps **du joueur lui-meme** est efface comme les autres :
 * l ambiguite disparait entierement plutot que d etre toleree. Rien ne s en trouve change a
 * l arrivee — `PatrolMission::processArrival()` ne lit ce champ que pour un retour — ni a
 * l affichage, puisqu un joueur voit ses propres missions par leur proprietaire.
 *
 * **Cette classe decrit une correction datee.** Elle est appelee par une migration, et une migration
 * fusionnee ne se reecrit pas : changer ce qu elle fait changerait le passe d une base deja migree.
 * Ne pas la modifier ; une correction ulterieure est une nouvelle migration.
 */
final class PatrolLegDestinationCleanup
{
    /**
     * Applique le nettoyage et rend le nombre de segments corriges.
     *
     * @return int
     */
    public static function run(): int
    {
        return DB::table('fleet_missions')
            ->where('mission_type', 11)
            ->where('processed', 0)
            ->whereNotNull('planet_id_to')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('patrols')
                    ->whereColumn('patrols.current_mission_id', 'fleet_missions.id')
                    ->where('patrols.state', PatrolState::Returning->value);
            })
            ->update(['planet_id_to' => null]);
    }
}
