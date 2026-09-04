<?php

namespace OGame\Combat\Application;

use OGame\Enums\CharacterClass;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Les faits du monde dont l'application d'un resultat depend encore.
 *
 * ## Le probleme que cette interface ferme
 *
 * Appliquer un resultat de bataille n'est pas seulement l'ecrire : quelques faits **changent ce qui
 * est ecrit**. Un attaquant de classe General laisse un champ d'epaves ; le niveau de son chantier
 * spatial et la part des vaisseaux detruits qui devient debris en fixent la taille ; deux seuils
 * decident si ce champ existe ; sa duree de vie et l'instant ou il nait decident quand il disparait ;
 * un Faucheur ramasse une part des debris ; la classe des deux camps figure au rapport.
 *
 * Sur le chemin instantane, les lire dans le monde courant est juste : rien n'a bouge entre le
 * calcul et l'application, ils sont separes par quelques millisecondes.
 *
 * Sur le chemin durable, **des heures separent les deux**. Un joueur qui change de classe pendant la
 * bataille, un chantier spatial monte d'un niveau, un reglage ajuste par un administrateur, un
 * travailleur qui applique en retard — chacun changerait l'issue d'une bataille deja calculee, et
 * personne ne saurait pourquoi deux rejeux du meme combat ne donnent pas le meme rapport.
 *
 * ## Une interface, deux sources, un seul applicateur
 *
 * L'applicateur ne sait pas d'ou viennent ces faits : il les demande. Le chemin instantane lui donne
 * `LiveCombatApplicationContext`, qui interroge les services ; le chemin durable lui donne
 * `FrozenCombatApplicationContext`, relu de la photographie prise a la cloture. **Aucun second
 * applicateur n'existe** — c'est la meme methode qui ecrit dans les deux cas, avec deux sources de
 * faits.
 */
interface CombatApplicationContext
{
    /**
     * Ce joueur porte-t-il la classe General, qui recupere un champ d'epaves ?
     */
    public function isGeneral(PlayerService $player): bool;

    /**
     * La part des debris qu'un Faucheur de ce joueur ramasse automatiquement.
     */
    public function reaperDebrisCollectionPercentage(PlayerService $player): float;

    /**
     * La classe de ce joueur, telle que le rapport la nomme.
     */
    public function characterClassOf(PlayerService $player): CharacterClass|null;

    /**
     * Le niveau de chantier spatial qui gouverne le champ d'epaves d'une flotte partie de ce corps.
     *
     * Le corps donne est l'origine de la flotte — une lune emprunte le chantier de sa planete, et
     * c'est cette resolution-la qui est figee, pas le corps.
     */
    public function spaceDockLevelFor(PlanetService $originBody): int;

    /**
     * La cargaison qu'une Defense ACS retenue portait, telle que ce contexte la connait.
     *
     * ## Pourquoi ce fait passe par le contexte
     *
     * A la fin d'une bataille, la cargaison d'un renfort survivant est reduite en proportion de sa
     * capacite restante. L'application lisait pour cela les colonnes de la mission **au moment ou
     * elle ecrivait** : sur le chemin instantane c'est juste, quelques millisecondes separent le
     * calcul de l'application. Sur le chemin durable, des heures les separent, et la valeur lue
     * n'est plus celle sur laquelle la bataille a ete calculee — un rejeu ne redonnerait pas le
     * meme retour.
     *
     * La photographie prise a la cloture porte donc cette cargaison, et l'application la demande
     * ici plutot que de la relire.
     *
     * @param int $fleetMissionId La mission du renfort retenu.
     * @return Resources
     */
    public function heldFleetCargo(int $fleetMissionId): Resources;

    /**
     * La perte minimale, en ressources, au-dessous de laquelle aucun champ d'epaves n'existe.
     */
    public function wreckFieldMinResourcesLoss(): int;

    /**
     * La part minimale de flotte detruite, en pour-cent, au-dessous de laquelle il n'y en a pas non plus.
     */
    public function wreckFieldMinFleetPercentage(): int;

    /**
     * La part des vaisseaux detruits qui devient debris, en pour-cent ; le reste peut devenir epaves.
     */
    public function debrisFieldFromShips(): int;

    /**
     * La duree de vie d'un champ d'epaves, en heures, comptee depuis l'instant d'application.
     */
    public function wreckFieldLifetimeHours(): int;

    /**
     * L'instant auquel l'application est datee.
     *
     * Le chemin instantane applique maintenant. Le chemin durable applique **a l'echeance du
     * combat**, meme si le travailleur passe plus tard : un champ d'epaves ne vit pas plus
     * longtemps parce que le serveur a eu du retard.
     */
    public function applicationInstant(): int;

    /**
     * Le dernier motif qu'une faction hostile a inscrit contre ce joueur.
     *
     * Il ne change pas ce qui est debite, mais il change ce que le rapport **raconte**. Le
     * chemin instantane le lit a l'arrivee ; le combat durable le fige a la cloture, sinon un
     * motif inscrit pendant la bataille expliquerait un raid decide avant lui.
     */
    public function npcMotiveAgainst(PlayerService $defender): string|null;

    /**
     * La variante narrative de ce raid, tiree une seule fois.
     *
     * Un tirage a l'application donnerait une histoire differente a chaque rejeu ; un tirage a
     * l'affichage en donnerait une differente a chaque lecture du meme rapport.
     */
    public function npcNarrativeVariation(int $variations): int;
}
