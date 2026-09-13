<?php

namespace Tests\Feature\Combat;

use OGame\Models\CombatInstance;

/**
 * **Un montage identifie SON combat, jamais celui d un voisin.**
 *
 * ## L entrelacement fautif, mesure
 *
 * Deux bancs ecrivent une ligne de `combat_instances` avec **`mission_id = 1` en dur**, en ralliement, sans
 * barriere, et ne l effacent pas : `CombatClassBonusOnShotsTest` (corps vise reel) et `SpatialOpeningStateTest`
 * (corps vise nul, point de l espace). Tous deux descendent d `AccountTestCase`, qui n enveloppe rien : la
 * ligne survit a l essai et reste dans la base du processus.
 *
 * Une base par processus, un compteur par base : la premiere mission envoyee d un processus recoit
 * l identifiant 1. Quand cette mission-la etait celle d un essai de combat durable, sa recherche
 * `where('mission_id', 1)->first()` ramenait **la plus ancienne** ligne portant cet identifiant — celle du
 * voisin. Sans barriere, la fermeture rend « combat inconnu », **sans journal** : c est une issue normale.
 * Le symptome etait « The rally did not close on arrival », sur des essais differents d un passage a l autre.
 *
 * ## La regle
 *
 * Le corps vise **et** le plus recent. Le corps ecarte la ligne d un voisin qui vise ailleurs ; le plus
 * recent ecarte celle qui vise le meme corps et precede la notre. Les deux sont necessaires, et
 * `ANeighbourRallyIsNeverMineTest` le prouve leurre par leurre.
 */
trait FindsItsOwnCombat
{
    /**
     * Le combat que cette mission a ouvert sur ce corps, ou `null` si elle n en a ouvert aucun.
     *
     * `$targetBodyId` peut etre nul — un combat en espace libre ne vise aucun corps —, et la comparaison
     * reste alors exacte : `where(..., null)` ne rend que les lignes dont la colonne est nulle.
     */
    protected function theCombatOf(int $missionId, int|null $targetBodyId): CombatInstance|null
    {
        return CombatInstance::query()
            ->where('mission_id', $missionId)
            ->where('target_planet_id', $targetBodyId)
            ->orderByDesc('id')
            ->first();
    }
}
