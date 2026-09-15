<?php

namespace OGame\Military;

use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\FleetMission;
use OGame\Models\User;

/**
 * Le raccordement des cumuls militaires a une bataille reglee d un bloc.
 *
 * ## Ou, et quand
 *
 * Appele par `CombatResolutionService::resolve()` — le chemin unique des deux moteurs, du combat durable comme de
 * l attaque instantanee — une fois toutes les pertes definitives appliquees, **dans la transaction de l appelant** : le
 * registre est la derniere ecriture, jamais separee de l effet par une panne.
 *
 * ## L instant du fait
 *
 * L echeance logique de la bataille, jamais l heure du traitement : une bataille calculee avant l activation ne compte
 * que si son echeance tombe dans la periode de collecte, et un reglement tardif ne la rend jamais admissible. Cette
 * convention vaut pour la bataille reglee d un bloc ; un combat progressif, dont les pertes deviendraient effectives par
 * etape, demandera sa propre decision.
 *
 * ## Un evenement final par participant, dans un espace de clefs sans collision
 *
 * `battle:combat:<instance>:<participant>` pour le durable, `battle:mission:<mission>:<participant>` pour
 * l instantane. Le partage est calcule round par round, l evenement est unique. Une attente — Hamill, unite inconnue,
 * incoherence — s inscrit pour chaque participant classe, avec les faits entiers, et le reglement du combat suit son
 * cours normal.
 */
final class MilitaryBattleTally
{
    public const string KIND = 'battle';

    public function __construct(
        private MilitaryTallyRecorder $recorder,
        private BattleTallyEvaluation $evaluation,
    ) {
    }

    /**
     * @return BattleTallyOutcome|null Ce que l evaluation a conclu, ou `null` quand la collecte ne couvre pas ce fait.
     */
    public function record(BattleResult $result, string $bodyKey, FleetMission $initiator, int $echeance): BattleTallyOutcome|null
    {
        $depuis = $this->recorder->collectingSince();

        if ($depuis === null || $echeance < $depuis) {
            return null;
        }

        $combat = $initiator->combat_instance_id;
        [$espace, $identifiant] = $combat === null
            ? [BattleTallyFacts::SPACE_MISSION, (int)$initiator->id]
            : [BattleTallyFacts::SPACE_COMBAT, (int)$combat];

        $faits = BattleTallyFacts::fromBattleResult($result, $bodyKey, $espace, $identifiant, $echeance, $this->npcAmong($result));
        $issue = $this->evaluation->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        if ($issue->isPending()) {
            foreach ($faits->participants as $participant) {
                if ($participant['owner'] === null || $participant['npc']) {
                    continue;
                }

                $this->recorder->defer($faits->eventKeyFor($participant['key']), $participant['owner'], $echeance, (string)$issue->reason, [
                    'kind' => self::KIND,
                    'participant' => $participant['key'],
                    'detail' => $issue->detail,
                    'facts' => $faits->toStorage(),
                ]);
            }

            return $issue;
        }

        foreach ($issue->credits() as $clef => $credit) {
            $this->recorder->credit($faits->eventKeyFor($clef), $credit['owner'], $echeance, 0, $credit['destroyed'], $credit['lost']);
        }

        return $issue;
    }

    /**
     * Les comptes PNJ parmi les proprietaires : leurs pertes se calculent, rien ne leur est credite.
     *
     * @return list<int>
     */
    private function npcAmong(BattleResult $result): array
    {
        $proprietaires = [];

        foreach ($result->attackerFleetResults as $flotte) {
            $proprietaires[$flotte->playerId] = true;
        }

        foreach ($result->defenderFleetResults as $flotte) {
            $proprietaires[$flotte->ownerId] = true;
        }

        $identifiants = array_values(array_filter(array_keys($proprietaires), static fn (int $id): bool => $id > 0));

        if ($identifiants === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int)$id,
            User::query()->whereIn('id', $identifiants)->where('is_npc', true)->pluck('id')->all()
        ));
    }
}
