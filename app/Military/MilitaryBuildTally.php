<?php

namespace OGame\Military;

use OGame\Combat\Support\UnitQueueProduction;
use OGame\GameObjects\Models\UnitObject;
use OGame\Military\Exceptions\UnknownMilitaryUnit;
use OGame\Models\UnitQueue;

/**
 * Le cumul « construits » d'une file d'unités : une tranche livrée, un événement.
 *
 * ## La clef
 *
 * Une tranche fait passer l'avancement d'une ligne de file de `avant` à `après` : sa clef est
 * `build:<ligne>:<avant>-<après>`. L'appelant a relu l'avancement **sous le verrou de la ligne**, dans la
 * transaction qui livre les unités et écrit l'avancement : deux tranches d'une même ligne ne se chevauchent jamais,
 * et l'événement ne peut pas exister sans la livraison qu'il décrit.
 *
 * ## L'instant
 *
 * L'unité `k` d'un lot s'achève à `UnitQueueProduction::finishInstantOf(début, fin, quantité, k)` — la formule que
 * la progression et la photographie d'un combat emploient déjà. **Seules les unités achevées à partir de
 * l'activation comptent** : une tranche traitée en retard qui chevauche l'activation ne crédite que sa partie
 * d'après, et sa clef le dit. L'événement porte l'instant de sa dernière unité.
 *
 * Un demi-temps livre ses unités à l'instant où il s'applique : c'est cet instant qui compte pour elles.
 *
 * ## Rien d'inventé
 *
 * Une unité qu'aucune famille ne sait pondérer met la tranche **en attente, entière** : aucune valeur n'est créditée.
 */
final class MilitaryBuildTally
{
    public function __construct(private MilitaryTallyRecorder $recorder)
    {
    }

    /**
     * La tranche qu'une progression vient de livrer : de `$before` à l'avancement actuel de la ligne.
     */
    public function recordProgress(UnitQueue $item, UnitObject $object, int $playerId, int $before): void
    {
        $depuis = $this->recorder->collectingSince();
        $apres = (int)$item->object_amount_progress;

        if ($depuis === null || $apres <= $before) {
            return;
        }

        $debut = (int)$item->time_start;
        $fin = (int)$item->time_end;
        $quantite = (int)$item->object_amount;

        // La première unité de la tranche qui s'achève à partir de l'activation ; les instants croissent avec `k`.
        $premiere = $before + 1;
        while ($premiere <= $apres && UnitQueueProduction::finishInstantOf($debut, $fin, $quantite, $premiere) < $depuis) {
            $premiere++;
        }

        if ($premiere > $apres) {
            return;
        }

        $this->record($item, $object, $playerId, $premiere - 1, $apres, UnitQueueProduction::finishInstantOf($debut, $fin, $quantite, $apres));
    }

    /**
     * Les unités qu'un demi-temps vient de livrer, toutes à l'instant où il s'applique.
     */
    public function recordHalving(UnitQueue $item, UnitObject $object, int $playerId, int $before, int $appliedAt): void
    {
        $apres = (int)$item->object_amount_progress;

        if ($apres <= $before) {
            return;
        }

        $this->record($item, $object, $playerId, $before, $apres, $appliedAt);
    }

    private function record(UnitQueue $item, UnitObject $object, int $playerId, int $from, int $to, int $occurredAt): void
    {
        $clef = 'build:' . (int)$item->id . ':' . $from . '-' . $to;

        try {
            $valeur = MilitaryValue::ofObject($object, $to - $from);
        } catch (UnknownMilitaryUnit $inconnue) {
            $this->recorder->defer($clef, $playerId, $occurredAt, 'unknown_unit_family', [
                'queue_id' => (int)$item->id,
                'object' => $inconnue->machineName,
                'from' => $from,
                'to' => $to,
            ]);

            return;
        }

        $this->recorder->credit($clef, $playerId, $occurredAt, built: $valeur);
    }
}
