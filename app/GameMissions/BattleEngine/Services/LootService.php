<?php

namespace OGame\GameMissions\BattleEngine\Services;

use OGame\Combat\Allocation\CappedLoot;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\LootAllocatorRegistry;
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
     * @return Resources
     */
    public static function distributeLoot(Resources $loot, int $total_cargo_capacity): Resources
    {
        return self::distribute($loot, $total_cargo_capacity)->resources;
    }

    /**
     * Le meme plafonnement, avec ce que la conversion a rencontre.
     *
     * @param Resources $loot
     * @param int $total_cargo_capacity
     * @return CappedLoot
     */
    public static function distribute(Resources $loot, int $total_cargo_capacity, string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT, string $subject = ''): CappedLoot
    {
        return LootAllocatorRegistry::default()->current()->capByCargo($loot, $total_cargo_capacity, $phase, $subject);
    }
}
