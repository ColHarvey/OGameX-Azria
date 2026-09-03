<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\AppliedLootShares;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\FrozenLootPotential;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Allocation\LootSettlement;
use OGame\Combat\Allocation\RemainingTargetStock;
use OGame\Combat\Allocation\SettledBattleResult;
use OGame\Combat\Allocation\SurvivingFleetCapacity;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use RuntimeException;

/**
 * Le reglement d'un combat durable : une transaction, sous les verrous, sur des nombres exacts.
 *
 * ## Ce que ce service fait, et ce qu'il ne refait pas
 *
 * Il ne recalcule rien et n'applique rien lui-meme. Le moteur a calcule le resultat a la cloture ;
 * la resolution existante sait deja retirer les unites perdues, deposer les debris, creer les
 * retours, ecrire le rapport et prevenir chacun. Ecrire une seconde application du resultat aurait
 * ete un second moteur de decision, avec ses propres ecarts a decouvrir un par un.
 *
 * Ce service fait donc la seule chose que le chemin instantane ne sait pas faire : **regler le butin
 * sur ce qui reste**, des heures apres la photographie. Il relit le resultat fige, gele le potentiel,
 * relit le restant sous verrou, en tire l'applique et sa repartition, **ecrit ces nombres avant tout
 * debit**, puis remet a la resolution une copie du resultat dont le butin est l'applique et dont
 * chaque part est celle qu'il a calculee. La resolution debite alors exactement l'applique, embarque
 * exactement les parts, et fige le rapport sur ces memes nombres — sans savoir qu'elle regle une
 * bataille ancienne.
 *
 * ## L'ordre des verrous
 *
 * Celui que la migration de barriere fixe : corps -> combat -> union -> missions par identifiant
 * croissant -> la cible, prise en dernier parce que c'est elle qu'on debite. Une jointure ou une
 * fermeture qui le suit ne peut pas nous attendre pendant que nous l'attendons.
 *
 * ## L'idempotence
 *
 * Tout est dans une transaction, et l'etat du combat est relu sous verrou avant d'agir. Un travail
 * relivre apres le commit retrouve `Resolved` et repart sans rien faire ; une panne avant le commit
 * ne laisse rien — ni potentiel, ni debit, ni retour, ni rapport — et la relivraison recommence
 * depuis un combat encore `Active`.
 *
 * ## Une borne dite
 *
 * Le potentiel et l'applique sont persistes en entiers exacts sur l'instance. La copie remise a la
 * resolution passe par `Resources`, qui porte des flottants : au-dela de 2^53, la cargaison d'un
 * retour ou le debit pourraient perdre l'unite. C'est la borne du moteur lui-meme, pas une
 * regression de ce service, et la frontiere de conversion la signale deja.
 */
final class CombatSettlementService
{
    public function __construct(
        private CombatResolutionService $resolution,
        private CombatRosterReader $roster = new CombatRosterReader(),
        private LootAllocatorRegistry|null $allocators = null,
    ) {
    }

    /**
     * Regle le combat, ou explique pourquoi il n'y avait rien a regler.
     *
     * @param int $combatInstanceId
     * @param GameMission $missionDeJeu Porte le type de vitesse qui determine la duree des retours.
     * @param Closure $creerRetour Cree une mission retour ; delegue a GameMission::startReturn().
     * @param int $now L'instant du reglement, ecrit sur l'instance.
     */
    public function settle(int $combatInstanceId, GameMission $missionDeJeu, Closure $creerRetour, int $now): CombatSettlementOutcome
    {
        return DB::transaction(function () use ($combatInstanceId, $missionDeJeu, $creerRetour, $now): CombatSettlementOutcome {
            // 1. La barriere, par l'identifiant de combat. Elle peut manquer — un combat purge —
            // et ce n'est pas a elle de dire si le combat existe : c'est l'instance qui le dit.
            CelestialBodyCombatBarrier::query()
                ->where('combat_instance_id', $combatInstanceId)
                ->lockForUpdate()
                ->first();

            // 2. L'instance, et son etat relu sous verrou : c'est lui qui rend le reglement idempotent.
            $combat = CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()->first();

            if ($combat === null) {
                return CombatSettlementOutcome::unknownCombat();
            }

            switch ($combat->status) {
                case CombatState::Resolved:
                    return CombatSettlementOutcome::alreadySettled();
                case CombatState::Cancelled:
                    return CombatSettlementOutcome::cancelled();
                case CombatState::Rallying:
                    return CombatSettlementOutcome::stillRallying();
                default:
                    break;
            }

            // **Le combat dure jusqu'a son echeance.** La regler avant la couperait court : le
            // defenseur perdrait le temps qu'on lui avait promis pour depenser ou renforcer.
            if ($combat->ends_at === null || $now < $combat->ends_at) {
                return CombatSettlementOutcome::stillFighting();
            }

            if ($combat->battle_result === null) {
                throw new RuntimeException('Le combat ' . $combat->id . ' est actif sans resultat : il n a jamais ete engage.');
            }

            // 3. L'union, puis les missions par identifiant croissant.
            if ($combat->union_id !== null) {
                FleetUnion::query()->whereKey($combat->union_id)->lockForUpdate()->first();
            }

            $this->lockMissionsOf($combat);

            // 4. La cible, en dernier : c'est elle qu'on debite.
            $ligneCible = Planet::query()->whereKey($combat->target_planet_id)->lockForUpdate()->first();

            if ($ligneCible === null) {
                throw new RuntimeException('Le combat ' . $combat->id . ' vise un corps ' . $combat->target_planet_id . ' qui n existe plus.');
            }

            // **Apres le verrou, jamais avant.** Le lecteur charge le corps a neuf : un service
            // charge avant le verrou porterait un solde d'avant une depense concurrente, et la
            // resolution le sauverait tel quel apres le debit — rendant au defenseur ce qu il a paye.
            $effectif = $this->roster->forCombat($combat);
            $result = BattleResultCodec::fromStorage($combat->battle_result);

            // **Tout vient du combat**, jamais des courantes : un reglement sous une autre version
            // que celle de l'ouverture serait une autre bataille.
            $versions = FrozenCombatVersionSet::fromInstance($combat);

            // Le resultat d'abord : c'est lui qui est verifie contre le combat. Un resultat calcule
            // sous d'autres versions est refuse avant qu'on cherche l'allocateur qu'il faudrait.
            $potentiel = FrozenLootPotential::frozenFrom($result, $versions);
            $allocation = FrozenLootAllocation::fromFrozenSet($versions, $this->allocators);
            $restant = RemainingTargetStock::readFrom($ligneCible, CombatParticipantKey::forPlanet($ligneCible->id));
            $reglement = LootSettlement::of($potentiel->amounts, $restant->amounts);

            $capacites = array_map(
                static fn (AttackerFleetResult $flotte): SurvivingFleetCapacity => SurvivingFleetCapacity::fromFleetResult($flotte),
                $result->attackerFleetResults
            );
            $parts = AppliedLootShares::of($reglement->applied, $capacites, $combat->mission_id, $allocation);

            // **Les nombres avant le debit.** Si tout ce qui suit echoue, la transaction les efface
            // avec le reste ; s'il reussit, ils sont ce sur quoi tout a ete ecrit, et la relecture
            // du combat les trouve a cote du rapport.
            $this->moveTo($combat, CombatState::Resolving);
            $combat->fill($potentiel->toColumns($now));
            $combat->applied_loot_metal = $reglement->applied->metal;
            $combat->applied_loot_crystal = $reglement->applied->crystal;
            $combat->applied_loot_deuterium = $reglement->applied->deuterium;
            $combat->save();

            $issue = $this->resolution->resolve(
                $effectif->initiator,
                SettledBattleResult::of($result, $reglement->applied, $parts),
                $effectif->target,
                $effectif->targetOwner,
                $effectif->attackers,
                $effectif->initiatorOwner,
                $effectif->defenders,
                (int)$effectif->initiator->planet_id_from,
                $missionDeJeu,
                $creerRetour,
                $allocation,
            );

            $this->moveTo($combat, CombatState::Resolved);
            $combat->loot_settled_at = $now;
            $combat->battle_report_id = $issue->battleReportId;
            $combat->save();

            return CombatSettlementOutcome::settled(
                $reglement,
                $parts,
                $issue->battleReportId,
                $potentiel->diagnostics->mergedWith($restant->diagnostics)->mergedWith($issue->diagnostics)
            );
        });
    }

    /**
     * Verrouille les missions du combat par identifiant croissant.
     *
     * L'initiatrice en fait partie : c'est elle que la resolution marque traitee et dont elle cree
     * le retour. Le lecteur d'effectif la trouve inscrite parmi les attaquants.
     */
    private function lockMissionsOf(CombatInstance $combat): void
    {
        $identifiants = array_merge(
            $this->roster->missionIdsOf($combat, CombatParticipant::SIDE_ATTACKER),
            $this->roster->missionIdsOf($combat, CombatParticipant::SIDE_DEFENDER)
        );

        sort($identifiants);

        FleetMission::query()
            ->whereIn('id', array_values(array_unique($identifiants)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Fait passer le combat a l'etat vise, si la machine d'etats le permet.
     */
    private function moveTo(CombatInstance $combat, CombatState $target): void
    {
        if (!$combat->status->canTransitionTo($target)) {
            throw new RuntimeException(
                'Le combat ' . $combat->id . ' ne peut pas passer de « ' . $combat->status->value
                . ' » a « ' . $target->value . ' ».'
            );
        }

        $combat->status = $target;
    }
}
