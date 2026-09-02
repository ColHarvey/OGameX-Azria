<?php

namespace OGame\Combat\Allocation;

use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Models\Resources;

/**
 * Le chemin complet qui transforme un stock en parts entieres attribuees a des flottes.
 *
 * ## Pourquoi une seule version pour tout le chemin
 *
 * Le pillage comporte trois etapes qui arrondissent chacune : la conversion du stock en unites
 * entieres au taux du combat, le plafonnement par le fret total reparti entre metal, cristal et
 * deuterium, puis l'attribution entre les flottes.
 *
 * Versionner la derniere seule ne protegerait rien : modifier le plafonnement changerait le butin
 * d'un ancien combat **sous la meme version**, et la version cesserait alors de designer une
 * formule. Les trois etapes appartiennent donc a une seule implementation versionnee, et
 * `LootService` comme `BattleEngine` en sont les appelants, pas les proprietaires d'une moitie de
 * la regle.
 *
 * ## Ce qu'une version garantit
 *
 * Qu'un combat calcule sous elle donnera toujours le meme resultat, meme apres l'arrivee d'une
 * version suivante. Une implementation publiee ne se modifie plus : on en ajoute une autre.
 */
interface LootAllocator
{
    /**
     * L'identifiant persiste avec chaque combat.
     *
     * @return string
     */
    public function version(): string;

    /**
     * Etape 1 : ce qu'un stock permet de piller, au taux de ce combat.
     *
     * @param float $inStock Le stock photographie sur la cible.
     * @param int $rateInBasisPoints Le taux, en centiemes de pour-cent.
     * @return int
     */
    public function lootableAmount(
        float $inStock,
        int $rateInBasisPoints,
        string $phase,
        ResourceNormalizationDiagnostics &$diagnostics,
    ): int;

    /**
     * Etape 2 : le butin ramene a ce que le fret total peut emporter.
     *
     * Les diagnostics de conversion voyagent avec le resultat : le pipeline ne journalise pas.
     *
     * @param Resources $loot
     * @param int $totalCargoCapacity
     * @return CappedLoot
     */
    public function capByCargo(Resources $loot, int $totalCargoCapacity, string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT, string $subject = ''): CappedLoot;

    /**
     * Etape 3 : l'attribution d'une ressource entre les flottes, en unites entieres.
     *
     * @param int $amount Le montant a repartir.
     * @param array<int, int> $weights Le fret survivant de chaque flotte, par identifiant de mission.
     * @param array<int, int> $remainingCapacity La place encore libre de chaque flotte.
     * @param int $initiatorFleetMissionId La flotte prioritaire en cas d'egalite.
     * @return array<int, int> Ce qui revient a chaque flotte, par identifiant de mission.
     */
    public function shareBetweenFleets(
        int $amount,
        array $weights,
        array $remainingCapacity,
        int $initiatorFleetMissionId,
    ): array;
}
