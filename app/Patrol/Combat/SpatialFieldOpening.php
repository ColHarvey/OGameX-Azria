<?php

namespace OGame\Patrol\Combat;

use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;

/**
 * L etat de champ initial d un combat en espace libre, et rien de plus.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CETTE CLASSE NE COMPOSE RIEN ELLE-MEME
 *
 * Composer un champ, c est ranger les flottes en **ordre canonique**, etendre chaque type
 * de vaisseau en unites portant coque, bouclier et puissance, poser les deux bandes de
 * tirages et jouer la manoeuvre de Hamill. Le moteur sait deja tout cela : `openTheField()`
 * le fait pour chaque bataille du jeu.
 *
 * Le refaire ici donnerait un **second composeur**. Il tiendrait un temps, puis divergerait
 * — un tri oublie, un bonus applique d un cote seulement — et la divergence ne se verrait
 * pas : les deux champs auraient la meme forme, et seuls les tirs differeraient. L ordre est
 * porteur de sens, les cibles se choisissant **par position** parmi les unites restantes.
 *
 * Cette classe n est donc qu une **ouverture** sur ce que le moteur sait faire : elle rend
 * public l etat initial, sans jouer un seul round.
 *
 * ------------------------------------------------------------------------------------
 * CE QUI EST GELE L EST AVANT D ARRIVER ICI
 *
 * Le moteur lit les niveaux sur le joueur de chaque flotte. On ne lui passe donc jamais un
 * joueur vivant : les attaquants portent un `FrozenCombatant`, le defenseur aussi, et le
 * lieu est un `SpatialCombatSite`. Une fois les unites construites elles ne portent plus que
 * des nombres, et le codec les persiste tels quels — **le gel devient structurel**.
 *
 * ------------------------------------------------------------------------------------
 * LES TIRAGES SONT RECUS, JAMAIS CHOISIS ICI
 *
 * **Le generateur des combats progressifs n est pas arrete** (reserve de Keven, 10 septembre
 * 2026). Cette classe n en fabrique donc aucun : elle prend ce qu on lui donne, par
 * `withDraws()`. Un banc lui passe une suite reproductible ; le jour ou la production en aura
 * une, ce sera la meme porte.
 *
 * Fabriquer un generateur par defaut ici transformerait un choix d essai en choix de
 * production — sans decision, et sans que personne ne s en apercoive.
 *
 * ------------------------------------------------------------------------------------
 * CE QU ELLE NE FAIT PAS — ET CE QU ELLE FAIT QUAND MEME
 *
 * Aucun round n est joue : l etat rendu porte `roundsPlayed = 0`, et c est l avanceur qui
 * jouera, entre deux pas duquel les renforts entreront. Aucun debris, aucun butin, aucun
 * rapport : rien n est ecrit hors de l etat.
 *
 * **Mais zero round ne veut pas dire zero effet de combat**, et l ecrire ainsi serait faux.
 * L ouverture joue la **manoeuvre de Hamill** : un General attaquant avec des chasseurs
 * legers peut y detruire une Etoile de la Mort avant le premier tir. Cette ouverture
 * **consomme donc des tirages** de la bande de bataille, et peut retirer une unite.
 *
 * Deux consequences pour la reprise, et elles sont distinctes :
 *
 *   - l **effet** doit etre conserve — l Etoile detruite ne revient pas, et la survivante ne
 *     se fait pas detruire une seconde fois ;
 *   - la **position des deux bandes** doit reprendre la ou elle s est arretee, jamais au mot
 *     initial de la graine, sans quoi toute la suite des tirages se decale.
 *
 * Rejouer la manoeuvre au rechargement produirait une seconde manoeuvre que personne n a
 * decidee. Le temoin qui l etablit demande un **autre processus** : une reprise en memoire
 * pourrait passer en gardant vivant ce que la persistance a perdu.
 */
final class SpatialFieldOpening extends PhpBattleEngine
{
    /**
     * Le champ tel qu il est **avant le premier tir**.
     *
     * Le resultat que le moteur remplit au passage est volontairement jete : il porte les
     * effectifs de depart et les niveaux, qui appartiennent au rapport, et le rapport n existe
     * pas avant la resolution.
     */
    public function initialField(): BattleFieldState
    {
        $resultat = new BattleResult();

        // **Les deux seuls champs que l ouverture du moteur lit du resultat**, et rien de plus.
        //
        // Le chemin ordinaire les remplit au passage, au milieu d une longue preparation qui pose
        // aussi le butin, les niveaux rapportes et les resultats par flotte. Tout cela appartient au
        // **rapport**, qui n existe pas avant la resolution : le poser ici ecrirait des faits qu
        // aucun round n a encore produits.
        //
        // Les deux boucles refletent celles du moteur. C est une duplication, et elle est assumee
        // pour rester dans le perimetre de cette tranche : la sortie propre serait d extraire cette
        // preparation dans le moteur partage, ce qui toucherait le chemin des corps.
        $resultat->attackerUnitsStart = new UnitCollection();

        foreach ($this->attackers as $flotte) {
            $resultat->attackerUnitsStart->addCollection($flotte->units);
        }

        $resultat->defenderUnitsStart = new UnitCollection();

        foreach ($this->defenders as $flotte) {
            $resultat->defenderUnitsStart->addCollection($flotte->units);
        }

        return $this->openTheField($resultat);
    }
}
