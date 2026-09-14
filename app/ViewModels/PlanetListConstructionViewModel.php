<?php

namespace OGame\ViewModels;

use Illuminate\Support\Facades\Date;
use OGame\Services\BuildingQueueService;
use OGame\Services\PlayerService;

/**
 * La cle a molette de la liste des planetes, telle que la page l amorce et que la veille du bandeau la relit.
 *
 * ## Pourquoi elle voyage avec le bandeau des ressources
 *
 * Keven voulait voir la cle s eteindre sans recharger la page quand une construction se termine sur une autre
 * planete. Le bandeau des ressources a deja tout ce qu il faut : une veille de trente secondes, une relecture au
 * retour sur l onglet, un silence apres trois echecs — et une route qui **n ecrit rien** (`ResourceBarController`),
 * donc qui n allume pas l etoile d activite de la Galaxie. Une seconde veille doublerait les requetes de la page.
 *
 * L etat entre donc dans l objet que la page amorce et que `/ajax/resourcebox` rend : les deux disent la meme chose.
 *
 * ## L instant du prochain changement
 *
 * La veille seule laisserait jusqu a trente secondes de retard. Le serveur dit donc aussi **dans combien de
 * secondes** l etat changera au plus tot — la fin la plus proche d une ligne deja commencee — et le navigateur
 * redemande le bandeau a cet instant. Il ne deduit rien lui-meme : le serveur reste la seule source. Des secondes
 * restantes plutot qu un instant absolu, pour ne pas dependre de l horloge du joueur.
 *
 * Une ligne pas encore commencee n a pas d echeance connue : elle ne demarre que lorsque la precedente est
 * appliquee, ce que fait la visite de la planete. Elle garde la cle allumee et ne programme rien.
 *
 * ## Un seul critere
 *
 * « Reste-t-il du travail » se decide par `BuildingQueueService::firstPendingAmong()`, que le gabarit emploie
 * aussi (`PlanetService::isBuilding()`). La page et la veille ne peuvent pas se contredire.
 */
final class PlanetListConstructionViewModel
{
    /**
     * @param PlayerService $player
     * @return array{constructions: list<array{planetId: int, downgrade: bool}>, nextChangeIn: int|null}
     */
    public static function of(PlayerService $player): array
    {
        $queue = resolve(BuildingQueueService::class);
        $now = (int)Date::now()->timestamp;
        $constructions = [];
        $nextChangeIn = null;

        foreach ($player->planets->allPlanets() as $planet) {
            $items = $queue->retrieveQueueItems($planet);
            $pending = BuildingQueueService::firstPendingAmong($items, $now);

            if ($pending !== null) {
                $constructions[] = ['planetId' => $planet->getPlanetId(), 'downgrade' => (bool)$pending->is_downgrade];
            }

            foreach ($items as $item) {
                $remaining = (int)$item->time_end - $now;

                if ((int)$item->time_start > 0 && $remaining > 0) {
                    $nextChangeIn = $nextChangeIn === null ? $remaining : min($nextChangeIn, $remaining);
                }
            }
        }

        return ['constructions' => $constructions, 'nextChangeIn' => $nextChangeIn];
    }
}
