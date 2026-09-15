<?php

namespace Tests\Support;

use OGame\Lifeforms\Research\LifeformSlotHistory;
use OGame\Models\Lifeforms\LifeformSlot;

/**
 * Pose une technologie dans un emplacement de recherche **avec sa ligne d historique**.
 *
 * ## Pourquoi cette aide existe
 *
 * L occupation des emplacements se lit a un instant par `lifeform_slot_history`, pas par la colonne :
 * c est ce qui empeche une remise a zero faite apres une arrivee de desarmer la flotte qui arrivait
 * (journal §155.10). Un montage qui ecrit la colonne seule laisse donc l historique vide — et tout ce
 * qui lit le passe repond « aucune technologie » sans se plaindre. Le banc serait vert et ne prouverait
 * rien.
 *
 * C est la meme lecon que les lignes de classe et d appartenance (§154.11), payee une fois : **un banc
 * n ecrit jamais une occupation sans son historique.** `LifeformSlotHistoryGuardTest` refuse un fichier
 * qui ecrirait `lifeform_slots` sans passer par ici ou par le service.
 */
trait PlacesLifeformSlots
{
    /**
     * @param int $at instant du choix ; il date aussi la ligne d historique
     */
    protected function placeLifeformSlot(int $planetId, int $slot, int $objectId, int $at): void
    {
        LifeformSlot::query()->updateOrCreate(
            ['planet_id' => $planetId, 'slot' => $slot],
            ['object_id' => $objectId, 'chosen_via' => 'local', 'selected_at' => $at]
        );
        resolve(LifeformSlotHistory::class)->record($planetId, $slot, $objectId, $at);
    }
}
