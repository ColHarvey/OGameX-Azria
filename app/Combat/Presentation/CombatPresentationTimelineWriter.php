<?php

namespace OGame\Combat\Presentation;

use OGame\Combat\Exceptions\ContradictoryPresentationTimeline;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CombatInstance;
use OGame\Models\CombatPresentationEvent;

/**
 * Ecrit la chronologie de presentation d'un combat, dans la transaction de sa cloture.
 *
 * ## Une seule fois, et la meme
 *
 * L'ecrivain projette le resultat gele, puis regarde ce que la base porte deja pour ce combat et
 * cette version. Rien : il ecrit tout. Exactement la projection : il ne fait rien — une cloture
 * rejouee ne produit pas deux fils. Autre chose : il refuse (`ContradictoryPresentationTimeline`),
 * parce qu'un resultat gele ne change pas et qu'une regle de presentation est deterministe ; garder
 * la premiere version ou ecraser la seconde cacherait le defaut qui a produit la difference.
 *
 * La contrainte d'unicite combat / version / sequence tient le meme contrat cote base, pour le cas
 * ou deux clotures concurrentes passeraient la comparaison en meme temps.
 *
 * ## Ce que l'ecrivain ne fait pas
 *
 * Il ne sauve pas l'instance : l'appelant tient la transaction et sauvera avec le reste. Il pose
 * seulement la version sur l'instance, pour que le lecteur sache sous quelle regle lire.
 */
final class CombatPresentationTimelineWriter
{
    public function __construct(private readonly CombatPresentationTimelineV1 $rule = new CombatPresentationTimelineV1())
    {
    }

    /**
     * Ecrit — ou retrouve — le fil, et rend le nombre d'evenements qu'il compte.
     *
     * @param array<int, int> $secondsPerRound Les secondes de chaque round, dans l'ordre.
     */
    public function write(CombatInstance $combat, BattleResult $result, array $secondsPerRound, int $startsAt): int
    {
        $evenements = $this->rule->project($result, $secondsPerRound, $startsAt);
        $version = $this->rule->version();

        $existants = CombatPresentationEvent::query()
            ->where('combat_instance_id', $combat->id)
            ->where('version', $version)
            ->orderBy('sequence')
            ->get();

        if ($existants->isNotEmpty()) {
            $this->refuseIfDifferent($combat, $version, $existants->all(), $evenements);
            $combat->presentation_version = $version;

            return count($evenements);
        }

        $maintenant = now();
        $lignes = [];

        foreach ($evenements as $evenement) {
            $lignes[] = $evenement->toRow() + [
                'combat_instance_id' => $combat->id,
                'version' => $version,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }

        if ($lignes !== []) {
            CombatPresentationEvent::query()->insert($lignes);
        }

        $combat->presentation_version = $version;

        return count($evenements);
    }

    /**
     * @param array<int, CombatPresentationEvent> $existants
     * @param array<int, PresentationEvent> $projetes
     */
    private function refuseIfDifferent(CombatInstance $combat, string $version, array $existants, array $projetes): void
    {
        if (count($existants) !== count($projetes)) {
            throw ContradictoryPresentationTimeline::forCombat($combat->id, $version, count($existants) . ' evenement(s) en base, ' . count($projetes) . ' dans la projection');
        }

        foreach ($projetes as $rang => $projete) {
            $existant = $existants[$rang];
            $ligne = [
                'sequence' => (int)$existant->sequence,
                'visible_at' => (int)$existant->visible_at,
                'participant_key' => (string)$existant->participant_key,
                'side' => (string)$existant->side,
                'unit' => (string)$existant->unit,
                'amount' => (int)$existant->amount,
            ];

            foreach ($projete->toRow() as $champ => $valeur) {
                if ($ligne[$champ] !== $valeur) {
                    throw ContradictoryPresentationTimeline::forCombat(
                        $combat->id,
                        $version,
                        'sequence ' . $projete->sequence . ', champ ' . $champ . ' : en base ' . var_export($ligne[$champ], true) . ', projete ' . var_export($valeur, true)
                    );
                }
            }
        }
    }
}
