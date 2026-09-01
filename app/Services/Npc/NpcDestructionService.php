<?php

namespace OGame\Services\Npc;

use Illuminate\Support\Facades\Date;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\FleetMission;
use OGame\Services\PlanetService;
use RuntimeException;

/**
 * La chute d'une base hostile : quand elle tombe, et ce que cela entraine.
 *
 * ------------------------------------------------------------------------------------
 * LA REGLE, EN UN SEUL ENDROIT
 *
 * Une base pilotee par le serveur est vaincue lorsque, a l'issue d'un combat :
 *
 *   1. l'attaquant a des survivants — sans quoi il n'a rien gagne ;
 *   2. et la defense adverse a ete integralement balayee pendant la bataille, defenses
 *      et vaisseaux compris, avant que la reparation d'apres-combat n'intervienne.
 *
 * Il n'y a pas de troisieme condition a verifier : si tout est a zero, c'est qu'une
 * attaque gagnante a necessairement eu lieu.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI LA REPARATION NE S'APPLIQUE PAS A UNE BASE VAINCUE
 *
 * La question se posera forcement en relecture, alors voici la reponse.
 *
 * Le moteur remet debout 70 % des defenses detruites apres chaque bataille. C'est une
 * excellente mecanique tant que la base tient : elle en fait un objectif qu'on revient
 * marteler plusieurs fois plutot qu'une cible consommable. La mesure sur le moteur reel,
 * contre cent lanceurs, le montre bien :
 *
 *     20 chasseurs : la base ne bouge pas, elle est imprenable
 *     50 chasseurs : erosion lente, campagne interminable
 *     80 chasseurs : abattue en quatre vagues — le regime interessant
 *    500 chasseurs : abattue d'un seul coup
 *
 * Mais appliquee au coup de grace, cette meme mecanique rendait les bases
 * MATHEMATIQUEMENT INDESTRUCTIBLES. La premiere mesure donnait :
 *
 *     100 -> 61 -> 44 -> 30 -> 20 -> 14 -> 10 -> 7 -> 5 -> 4 -> 3 -> 3 -> 3
 *
 * Un plateau : la reparation finissait par compenser exactement les pertes, la defense
 * n'atteignait jamais zero, et la condition de destruction ci-dessus etait donc
 * inatteignable. Aucune base n'aurait jamais pu tomber.
 *
 * D'ou la regle tranchee ici, et nulle part ailleurs : ce qui vient d'etre balaye au
 * combat ne se repare pas sur une base qui, dans le meme temps, a cesse d'exister. La
 * reparation continue de jouer pleinement partout ou la base survit — c'est-a-dire
 * partout ou elle rend le jeu meilleur.
 *
 * Ce n'est pas un contournement du moteur : le combat, lui, s'est deroule exactement
 * selon les regles du jeu, reparation comprise. Seul le sort des ruines change, et
 * uniquement pour un corps qui disparait dans la foulee.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI ON N'APPELLE PAS markAsDestroyed()
 *
 * Cette methode de PlanetService porte deux garde-fous ecrits pour les joueurs humains,
 * dont « on n'abandonne pas sa derniere planete » — et une base pirate n'en a justement
 * qu'une. Les affaiblir exposerait les joueurs. On emprunte donc directement
 * applyDestroyedFlag(), qui est le travail reel : vidage des files, liberation du corps,
 * purge par le nettoyage quotidien.
 */
class NpcDestructionService
{
    /**
     * Settle a battle against a hostile base: destroy it if it was truly beaten.
     *
     * Point d'entree unique pour le cas d'un combat. Toute la regle vit ici, y compris la
     * decision de ne pas laisser les ruines se reparer, afin qu'un lecteur n'ait pas a
     * reconstituer le raisonnement depuis deux fichiers.
     *
     * @return bool Vrai si la base a ete abattue.
     */
    public function settleBattle(PlanetService $planet, BattleResult $battleResult): bool
    {
        if (!$this->isDefeatedInBattle($battleResult)) {
            return false;
        }

        // Les ruines d'une base vaincue ne se relevent pas. Voir l'explication en tete de
        // classe : sans cela, la condition de destruction serait inatteignable.
        if ($battleResult->repairedDefenses->getAmount() > 0) {
            $planet->removeUnits($battleResult->repairedDefenses, true);
        }

        return $this->destroy($planet);
    }

    /**
     * Get whether a battle left the base with nothing at all to fight with.
     *
     * L'etat regarde est celui d'apres bataille et d'avant reparation. C'est le seul qui
     * dise si la base a reellement ete balayee, plutot que si elle a eu le temps de se
     * relever.
     */
    public function isDefeatedInBattle(BattleResult $battleResult): bool
    {
        $attackerSurvived = $battleResult->attackerUnitsResult->getAmount() > 0;
        $defenceWiped = $battleResult->defenderUnitsResult->getAmount() === 0;

        return $attackerSurvived && $defenceWiped;
    }

    /**
     * Bring down a hostile base that has nothing left to defend itself with.
     *
     * Le champ de debris reste : c'est la recompense du vainqueur. La position se libere au
     * passage suivant de CleanupDestroyedPlanets, a trois heures du matin.
     *
     * @return bool Vrai si la base a bien ete abattue.
     */
    public function destroy(PlanetService $planet): bool
    {
        $player = $planet->getPlayer();

        if ($player === null || !$player->getUser()->is_npc) {
            throw new RuntimeException('destroyNpcPlanet called on a planet that does not belong to a NPC.');
        }

        if ($planet->isDestroyed()) {
            return false;
        }

        // Une base encore capable de se defendre n'est pas vaincue. Le controle est refait
        // ici plutot que fait confiance a l'appelant : c'est la seule porte par laquelle une
        // planete disparait, elle doit se suffire a elle-meme.
        if ($this->stillStanding($planet)) {
            return false;
        }

        // Les flottes encore en vol n'ont plus de port d'attache. Il faut les solder avant
        // de marquer la planete, sinon leur mission de retour viserait une planete purgee.
        $this->groundOutboundFleets($planet);

        $destroyedAt = (int)Date::now()->timestamp;

        if ($planet->isPlanet() && $planet->hasMoon()) {
            $planet->moon()->applyDestroyedFlag($destroyedAt);
        }

        $planet->applyDestroyedFlag($destroyedAt);

        return true;
    }

    /**
     * Get whether the base still has anything left to fight with.
     */
    public function stillStanding(PlanetService $planet): bool
    {
        return $planet->getShipUnits()->getAmount() > 0
            || $planet->getDefenseUnits()->getAmount() > 0;
    }

    /**
     * Settle every mission this base still has in flight.
     *
     * Une base peut tomber pendant que sa flotte est en raid — c'est meme le moment ou elle
     * est le plus vulnerable, et un joueur attentif le sait. L'equipage parti n'a alors plus
     * de monde ou revenir : ses missions sont soldees sur place.
     */
    private function groundOutboundFleets(PlanetService $planet): void
    {
        $owner = $planet->getPlayer();

        if ($owner === null) {
            return;
        }

        FleetMission::where('user_id', $owner->getId())
            ->where('processed', 0)
            ->update(['processed' => 1]);
    }
}
