<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Models\CombatFleetDisposition;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;

/**
 * Le registre des mouvements que le combat impose, et de ceux qui restent a faire.
 *
 * ## Ecrire la decision, pas seulement l'annoncer
 *
 * Une flotte refusee a l'admission doit repartir. Jusqu'ici, seul un avis etait ecrit, et le
 * mouvement etait rededuit plus tard a partir de la barriere du corps — qui disparait au reglement.
 * Une flotte touchee apres la fin du combat ne retrouvait donc plus la raison de son demi-tour.
 *
 * Ce registre ecrit la decision dans la transaction qui la prend, et la conserve jusqu'a ce qu'elle
 * soit executee. Le combat peut etre termine, la barriere levee, le travailleur en retard de
 * plusieurs heures : la flotte sait toujours ce qu'elle doit faire, et pourquoi.
 *
 * ## Consommee une fois, sous verrou
 *
 * `consume()` relit la ligne sous verrou, refuse si elle a deja ete consommee, puis execute le
 * mouvement dans la meme transaction que le marquage. Deux travailleurs simultanes ne peuvent donc
 * pas creer deux retours pour une meme flotte : le second trouve la ligne consommee.
 */
final class FleetDispositionRegistry
{
    /**
     * Ecrit le mouvement qu'une flotte devra faire — ou laisse celui qui existe deja.
     *
     * **Le mouvement se nomme, il ne se suppose pas.** Une valeur par defaut ferait qu aucun
     * appelant ne dise ce qu il decide, et le jour ou un second mouvement existerait, tous les
     * appels anciens continueraient a signifier le premier sans que personne l ait choisi.
     *
     * **Idempotent.** Une fermeture rejouee ne remplace pas une decision prise : la premiere raison
     * prononcee est celle que le joueur lira, et une seconde ecriture n'aurait aucune raison d'etre
     * plus juste que la premiere.
     */
    public function record(
        CombatInstance $combat,
        int $fleetMissionId,
        CombatReasonCode $reason,
        int $decidedAt,
        FleetDispositionKind $movement,
    ): void {
        CombatFleetDisposition::query()->firstOrCreate(
            ['fleet_mission_id' => $fleetMissionId],
            [
                'combat_instance_id' => $combat->id,
                'movement' => $movement->value,
                'reason' => $reason->value,
                'decided_at' => $decidedAt,
            ]
        );
    }

    /**
     * Le mouvement qui reste a faire pour cette mission, s'il y en a un.
     */
    public function pendingFor(FleetMission $mission): CombatFleetDisposition|null
    {
        return CombatFleetDisposition::query()
            ->where('fleet_mission_id', $mission->id)
            ->whereNull('consumed_at')
            ->first();
    }

    /**
     * Execute le mouvement une seule fois, sous verrou.
     *
     * @param Closure(CombatFleetDisposition): void $faire Ce que le mouvement veut dire, cote jeu.
     * @return bool Vrai si ce passage a execute le mouvement ; faux s'il ne restait rien a faire.
     */
    public function consume(CombatFleetDisposition $disposition, int $consumedAt, Closure $faire): bool
    {
        return DB::transaction(function () use ($disposition, $consumedAt, $faire): bool {
            $ligne = CombatFleetDisposition::query()->whereKey($disposition->id)->lockForUpdate()->first();

            if (!$ligne instanceof CombatFleetDisposition || !$ligne->isPending()) {
                // Un autre passage l'a faite entre-temps. Rien a refaire : c'est exactement ce que
                // la colonne existe pour dire.
                return false;
            }

            $ligne->consumed_at = $consumedAt;
            $ligne->save();

            ($faire)($ligne);

            return true;
        });
    }
}
