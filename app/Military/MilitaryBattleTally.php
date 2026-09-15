<?php

namespace OGame\Military;

use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\FleetMission;

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

    /**
     * L evenement nomme de la manoeuvre de Hamill : l Etoile prise, creditee en « detruits » a l auteur. Il vit dans
     * le meme groupe que les evenements de bataille de son fait — ecrits ensemble, en attente ensemble, repris
     * ensemble, jamais l un sans l autre.
     */
    public const string KIND_HAMILL = 'hamill';

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
        $combat = $initiator->combat_instance_id;
        [$espace, $identifiant] = $combat === null
            ? [BattleTallyFacts::SPACE_MISSION, (int)$initiator->id]
            : [BattleTallyFacts::SPACE_COMBAT, (int)$combat];

        return $this->recordFor($result, $bodyKey, $espace, $identifiant, $echeance);
    }

    /**
     * Une bataille que le moteur a jouee hors du reglement d une attaque — expedition, contre-espionnage, espace
     * libre —, dans son propre espace de clefs. Memes regles, memes faits, meme evaluation.
     *
     * @param string $space Un espace de `BattleTallyFacts::SPACES`.
     * @param int $id L identifiant qui, dans cet espace, designe ce fait et lui seul.
     * @param list<int> $unrankedOwners Les proprietaires que l appelant declare PNJ en plus des comptes PNJ — les acteurs
     *        ephemeres du serveur, aux identifiants non positifs : calcules, jamais credites.
     */
    public function recordFor(BattleResult $result, string $bodyKey, string $space, int $id, int $echeance, array $unrankedOwners = []): BattleTallyOutcome|null
    {
        $depuis = $this->recorder->collectingSince();

        if ($depuis === null || $echeance < $depuis) {
            return null;
        }

        $npc = array_values(array_unique(array_merge($this->npcAmong($result), $unrankedOwners)));
        $faits = BattleTallyFacts::fromBattleResult($result, $bodyKey, $space, $id, $echeance, $npc);
        $issue = $this->evaluation->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        if ($issue->isPending()) {
            $charge = ['kind' => self::KIND, 'participant' => '', 'detail' => $issue->detail, 'facts' => $faits->toStorage()];

            foreach ($faits->participants as $participant) {
                if ($participant['owner'] === null || $participant['npc']) {
                    continue;
                }

                $this->recorder->defer($faits->eventKeyFor($participant['key']), $participant['owner'], $echeance, (string)$issue->reason, ['participant' => $participant['key']] + $charge);
            }

            // L evenement nomme attend avec la bataille, quand la manoeuvre est nommee et son auteur classe.
            $auteur = $this->classedAuthorOf($faits);

            if ($auteur !== null) {
                $this->recorder->defer($faits->hamillEventKeyFor($auteur['key']), $auteur['owner'], $echeance, (string)$issue->reason, ['kind' => self::KIND_HAMILL, 'participant' => $auteur['key']] + $charge);
            }

            return $issue;
        }

        foreach ($issue->credits() as $clef => $credit) {
            $this->recorder->credit($faits->eventKeyFor($clef), $credit['owner'], $echeance, 0, $credit['destroyed'], $credit['lost']);
        }

        $hamill = $issue->hamillCredit();

        if ($hamill !== null) {
            $this->recorder->credit($faits->hamillEventKeyFor($hamill['author']), $hamill['owner'], $echeance, 0, $hamill['destroyed'], 0);
        }

        return $issue;
    }

    /**
     * L auteur de la manoeuvre nommee, s il est un compte classe.
     *
     * @return array{key: string, owner: int}|null
     */
    private function classedAuthorOf(BattleTallyFacts $faits): array|null
    {
        if ($faits->hamill === null) {
            return null;
        }

        foreach ($faits->participants as $participant) {
            if ($participant['key'] === $faits->hamill['author'] && $participant['owner'] !== null && !$participant['npc']) {
                return ['key' => $participant['key'], 'owner' => $participant['owner']];
            }
        }

        return null;
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

        return NpcAccounts::among(array_map(static fn (mixed $id): int => (int)$id, array_keys($proprietaires)));
    }
}
