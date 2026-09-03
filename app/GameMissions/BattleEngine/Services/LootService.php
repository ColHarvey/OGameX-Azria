<?php

namespace OGame\GameMissions\BattleEngine\Services;

use OGame\Combat\Allocation\CappedLoot;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Models\Resources;

/**
 * Class LootService.
 *
 * Facade historique du plafonnement des ressources par la capacite de fret.
 *
 * ## Ce que cette classe n'est plus
 *
 * Elle possedait la regle : un plafond par ressource, puis un partage de la place restante par une
 * division **flottante**. Ce partage produisait des montants comme `17 794,666...`, que le moteur
 * transtypait ensuite en entiers — et les unites tombees dans la fraction disparaissaient. La
 * mesure l'a etabli sur vingt mille repartitions : quarante pour cent d'entre elles perdaient une
 * ou deux unites de cette facon.
 *
 * La regle vit desormais dans `OGame\Combat\Allocation\ExactLootAllocationV1`, sous une version
 * persistee avec chaque combat.
 *
 * ## Pourquoi cette facade ne journalise pas
 *
 * Une seule resolution de combat l'appelle **cinq fois** — Faucheurs des deux camps, plafonnement
 * de leur place restante, et deux plafonds de cargaison de retour. Un avertissement pose ici
 * produirait cinq lignes pour une operation.
 *
 * Les diagnostics de conversion remontent donc comme donnees, et c'est la mission — l'orchestrateur
 * le plus exterieur — qui agrege et ecrit une fois.
 *
 * @package OGame\GameMissions\BattleEngine
 */
class LootService
{
    /**
     * Distribute the loot evenly based on the total cargo capacity.
     *
     * La forme courte, pour les appelants qui n'ont rien a signaler.
     *
     * @param Resources $loot
     * @param int $total_cargo_capacity
     * @param FrozenLootAllocation $allocation L'allocateur de cette operation, choisi a son debut.
     * @return Resources
     */
    public static function distributeLoot(Resources $loot, int $total_cargo_capacity, FrozenLootAllocation $allocation): Resources
    {
        return self::distribute($loot, $total_cargo_capacity, $allocation)->resources;
    }

    /**
     * Le meme plafonnement, avec ce que la conversion a rencontre.
     *
     * ## Pourquoi l'allocation est un parametre, et pourquoi il est obligatoire
     *
     * Cette methode demandait elle-meme au registre son allocateur courant, **au milieu du calcul**.
     * (La forme litterale de cet appel n est pas ecrite ici : la garde architecturale cherche des
     * chaines, et un commentaire qui la cite se compte lui-meme comme un appelant.)
     * Chaque plafonnement d'une meme resolution redemandait donc « la version courante » : un
     * deploiement survenu entre deux appels aurait plafonne la premiere moitie d'une bataille sous
     * une regle et la seconde sous une autre.
     *
     * La rendre facultative, avec un repli sur la version courante, aurait laisse exactement ce
     * chemin ouvert — et il serait revenu par le premier appelant qui aurait omis l'argument. Un
     * appelant doit dire sous quelle regle il plafonne ; c'est le seul moyen que la question soit
     * posee.
     *
     * @param Resources $loot
     * @param int $total_cargo_capacity
     * @param FrozenLootAllocation $allocation
     * @param string $phase
     * @param string $subject
     * @return CappedLoot
     */
    public static function distribute(
        Resources $loot,
        int $total_cargo_capacity,
        FrozenLootAllocation $allocation,
        string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT,
        string $subject = '',
    ): CappedLoot {
        return $allocation->capByCargo($loot, $total_cargo_capacity, $phase, $subject);
    }
}
