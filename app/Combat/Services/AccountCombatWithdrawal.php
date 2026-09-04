<?php

namespace OGame\Combat\Services;

use Illuminate\Database\Eloquent\Collection;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatState;
use OGame\GameMissions\AttackMission;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Services\SettingsService;

/**
 * Ce qu'un compte qui disparait fait de ses combats en cours.
 *
 * ## Le plan d'abord, les effets ensuite
 *
 * La suppression d'un compte efface ses missions. Une mission inscrite dans un combat actif ne
 * s'efface pas : la bataille a ete calculee avec elle, et l'inscription — la photographie — est
 * immuable. Le combat s'annule d'abord, avec la cause qui dit pourquoi ; les flottes des deux camps
 * rentrent, la cible l'apprend, et seulement ensuite les missions peuvent disparaitre.
 * L'inscription reste, son lien vers la mission devenu nul.
 *
 * **Tout se decide avant le premier effet.** Le retrait annulait combat par combat, et ne
 * decouvrait qu'en arrivant a sa ligne qu'un combat retenait la suppression : un compte engage dans
 * deux combats perdait le premier et gardait le compte. Le plan couvre maintenant tous les combats,
 * et un seul empechement suffit a n'en annuler aucun.
 *
 * ## Les trois positions d'un compte dans un combat
 *
 * - **attaquant** — ouvreur, vague inscrite, ou flotte retenue pendant le ralliement :
 *   `AttackerRemoved`, la cause faite pour cela ;
 * - **cible** — le corps attaque lui appartient : `TargetDisappeared`, le corps va disparaitre ;
 * - **renfort defensif** dans le combat d'un autre : la suppression **attend**. Retirer le seul
 *   renfort changerait une issue deja gelee ; annuler la bataille entiere changerait ce que voient
 *   plusieurs tiers. Regle arretee par Keven : le compte passe en suppression en attente, ne lance
 *   plus rien, et sa suppression reprend d'elle-meme des que ces combats sont finaux.
 *
 * Un combat **en cours d'application** retient de meme : des ecritures sont en train de partir, et
 * la machine d'etats refuse a juste titre de l'annuler.
 *
 * ## Pendant le ralliement, personne n'est encore inscrit
 *
 * La photographie est prise a la fermeture. Avant elle, c'est la colonne `combat_instance_id` de la
 * mission qui dit qui est retenu — et cette colonne n'a pas de cle etrangere qui aurait empeche la
 * suppression. Sans cette lecture, un combat en ralliement ouvert par le compte n'aurait ete trouve
 * par personne : ses missions effacees, son initiatrice disparue, et sa barriere tenant le corps
 * pour toujours.
 */
final class AccountCombatWithdrawal
{
    /**
     * Le plan complet, decide avant tout effet.
     *
     * @param array<int, int> $planetIds Les corps du compte, ceux en attente de purge compris.
     */
    public function planFor(int $userId, array $planetIds): AccountWithdrawalPlan
    {
        $aAnnuler = [];
        $empechements = [];

        foreach ($this->combatsStillRunningFor($userId, $planetIds) as $combat) {
            $identifiant = (int)$combat->id;

            // **Un combat en cours d'application ne s'annule pas** — la machine d'etats le refuse,
            // et elle a raison. Le dire ici, plutot que de laisser l'annulation echouer sur un
            // message qui parle d'etats a un administrateur venu effacer un compte.
            if ($combat->status === CombatState::Resolving) {
                $empechements[$identifiant] = 'il est en cours d application';

                continue;
            }

            $cause = $this->causeFor($combat, $userId, $planetIds);

            if ($cause === null) {
                $empechements[$identifiant] = 'le compte y renforce la defense d un autre joueur';

                continue;
            }

            $aAnnuler[$identifiant] = $cause;
        }

        return new AccountWithdrawalPlan($aAnnuler, $empechements, $this->fleetsThatCouldStillEngage($userId));
    }

    /**
     * Les flottes du compte encore en vol qui peuvent ouvrir ou rejoindre un combat.
     *
     * ## Ce que le drapeau ne ferme pas
     *
     * Le drapeau de suppression empeche les **nouveaux** lancements ; il ne dit rien d'une flotte
     * deja partie. Celle-la peut atteindre le travailleur apres l'inventaire, ouvrir un combat, et
     * voir ensuite ses missions effacees : le combat garderait une initiatrice qui n'existe plus, et
     * sa barriere tiendrait un corps pour toujours — la colonne du lien n'a pas de cle etrangere qui
     * l'aurait arrete.
     *
     * La suppression attend donc qu'elles soient finales. L'ensemble ne peut que retrecir : le
     * compte ne lance plus rien, et chaque flotte finit par arriver puis rentrer.
     *
     * **Seulement quand le combat durable est arme** : sans lui aucune barriere n'existe, aucune de
     * ces arrivees ne peut ouvrir de combat, et faire attendre une suppression pour une course
     * impossible immobiliserait des comptes sans rien protéger.
     *
     * @param int $userId
     * @return array<int, int>
     */
    private function fleetsThatCouldStillEngage(int $userId): array
    {
        if (!resolve(SettingsService::class)->persistentCombatEnabled()) {
            return [];
        }

        $genres = [];

        foreach (CombatMissionKind::byMissionType() as $type => $genre) {
            if ($genre->opensCombat() || $genre->reinforcesTheDefence()) {
                $genres[] = $type;
            }
        }

        $candidates = FleetMission::query()
            ->where('user_id', $userId)
            ->whereNull('parent_id')
            ->where('processed', 0)
            ->whereIn('mission_type', $genres)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        // **Deja engagee ne veut pas dire « colonne posee ».** Une flotte rattachee a un combat
        // figure dans l'inventaire : son combat a recu sa cause, et elle ne peut plus en ouvrir un
        // second. Mais l'engagement a **deux preuves**, et la colonne n'est que la premiere : un
        // renfort defensif n'est lie que par son inscription, et une attaque groupee non ouvreuse
        // porte l'inscription avant que son travailleur ne pose la colonne.
        //
        // Ne regarder que la colonne produisait un plan contradictoire : le meme attaquant recevait
        // sa cause d'annulation **et** figurait parmi les flottes qui retiennent tout. Rien n'etait
        // annule, et la suppression attendait la fin naturelle d'un combat qu'elle aurait du
        // arreter. La definition vient donc d'`EngagedFleetCheck`, la seule du depot.
        $engagees = resolve(EngagedFleetCheck::class)->engagedAmong($candidates);

        return array_values(array_diff($candidates, $engagees));
    }

    /**
     * Applique un plan qui ne retient rien, et rend le nombre de combats annules.
     *
     * @param int $userId
     * @param AccountWithdrawalPlan $plan
     * @param int $now
     * @return int
     */
    public function apply(int $userId, AccountWithdrawalPlan $plan, int $now): int
    {
        if ($plan->deferred()) {
            // Un plan qui retient quelque chose n'a aucun effet : c'est tout son objet.
            return 0;
        }

        $annules = 0;

        foreach ($plan->aAnnuler as $combat => $cause) {
            $issue = resolve(AttackMission::class)->cancelPersistentCombat(
                $combat,
                $cause,
                'suppression du compte ' . $userId,
                $now
            );

            if ($issue->cancelled) {
                $annules++;
            }
        }

        return $annules;
    }

    /**
     * Les combats non finaux ou ce compte figure, par identifiant croissant.
     *
     * @param array<int, int> $planetIds
     * @return Collection<int, CombatInstance>
     */
    private function combatsStillRunningFor(int $userId, array $planetIds): Collection
    {
        return CombatInstance::query()
            ->whereIn('status', array_map(static fn (CombatState $etat): string => $etat->value, self::statesStillRunning()))
            ->where(function ($requete) use ($userId, $planetIds): void {
                // **Les deux liens, pas seulement l'inscription.** Avant la fermeture personne n'est
                // inscrit : un combat en ralliement ouvert par ce compte ne serait trouve par
                // aucune des deux premieres conditions.
                $requete
                    ->whereIn('target_planet_id', $planetIds)
                    ->orWhereIn('id', CombatParticipant::query()->select('combat_instance_id')->where('player_id', $userId))
                    ->orWhereIn('id', FleetMission::query()->select('combat_instance_id')->whereNotNull('combat_instance_id')->where('user_id', $userId));
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * La cause qui s'applique a ce combat, ou `null` si le compte n'y est que renfort defensif.
     *
     * @param array<int, int> $planetIds
     */
    private function causeFor(CombatInstance $combat, int $userId, array $planetIds): CombatCancellationCause|null
    {
        if (in_array((int)$combat->target_planet_id, $planetIds, true)) {
            return CombatCancellationCause::TargetDisappeared;
        }

        $attaquant = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('player_id', $userId)
            ->where('side', CombatParticipant::SIDE_ATTACKER)
            ->exists();

        if ($attaquant) {
            return CombatCancellationCause::AttackerRemoved;
        }

        // **Pendant le ralliement, le camp se lit du genre de la mission retenue.** La photographie
        // n'est pas encore prise ; c'est la meme lecture que fait l'effectif de l'annulation, et la
        // meme enumeration exhaustive qui la porte.
        $retenues = FleetMission::query()
            ->where('combat_instance_id', $combat->id)
            ->where('user_id', $userId)
            ->pluck('mission_type')
            ->map(static fn (mixed $type): CombatMissionKind => CombatMissionKind::fromMissionType((int)$type));

        if ($retenues->isNotEmpty() && $retenues->every(static fn (CombatMissionKind $genre): bool => !$genre->reinforcesTheDefence())) {
            return CombatCancellationCause::AttackerRemoved;
        }

        return null;
    }

    /**
     * @return array<int, CombatState>
     */
    private static function statesStillRunning(): array
    {
        return array_values(array_filter(CombatState::cases(), static fn (CombatState $etat): bool => !$etat->isFinal()));
    }
}
