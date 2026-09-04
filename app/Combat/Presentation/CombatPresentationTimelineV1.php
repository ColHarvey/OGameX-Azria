<?php

namespace OGame\Combat\Presentation;

use InvalidArgumentException;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CombatParticipant;

/**
 * La premiere regle de presentation : les pertes de chaque round deviennent visibles a la fin de la
 * periode du round, par participant et par type de vaisseau.
 *
 * ## Deterministe, et de quoi
 *
 * De trois choses, toutes gelees a la cloture : le resultat de la bataille (les pertes de chaque
 * round par participant), le calendrier des rounds (les secondes de chacun, reparties par
 * l'estimateur de duree) et l'instant ou la bataille commence. Ni les modeles vivants, ni l'heure
 * de lecture n'entrent ici : un rejeu du meme resultat rend exactement les memes evenements, avec
 * les memes numeros de sequence.
 *
 * ## L'ordre, et pourquoi il est fixe
 *
 * Round croissant, puis camp (attaquant avant defenseur), puis clef de participant, puis nom
 * d'unite — tous tries, jamais l'ordre d'iteration d'une carte. Le numero de sequence est le rang
 * dans cet ordre, de un a n. Deux evenements du meme instant restent distingues par leur rang.
 *
 * ## Ce que l'interface ne verra pas
 *
 * Aucun numero de round. Les evenements sont repartis dans la duree autoritative du combat ; le
 * round n'est qu'un moyen de les dater.
 */
final class CombatPresentationTimelineV1
{
    public const string VERSION = 'v1';

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * Les evenements du fil, dans l'ordre, depuis le resultat gele.
     *
     * @param array<int, int> $secondsPerRound Les secondes de chaque round, dans l'ordre des rounds.
     * @param int $startsAt L'instant ou la bataille commence, en secondes.
     * @return array<int, PresentationEvent>
     */
    public function project(BattleResult $result, array $secondsPerRound, int $startsAt): array
    {
        if (count($secondsPerRound) !== count($result->rounds)) {
            throw new InvalidArgumentException(sprintf(
                'Le calendrier compte %d round(s) et le resultat %d : ils ne decrivent pas la meme bataille.',
                count($secondsPerRound),
                count($result->rounds)
            ));
        }

        $attaquantes = [CombatParticipantKey::EPHEMERAL_ATTACKER => true];
        foreach ($result->attackerFleetResults as $flotte) {
            if ($flotte->fleetMissionId > 0) {
                $attaquantes[CombatParticipantKey::forFleet($flotte->fleetMissionId)] = true;
            }
        }

        $evenements = [];
        $sequence = 0;
        $instant = $startsAt;

        foreach ($result->rounds as $rang => $round) {
            $secondes = $secondsPerRound[$rang] ?? null;

            if (!is_int($secondes) || $secondes < 0) {
                throw new InvalidArgumentException('Le round ' . ($rang + 1) . ' n a pas de duree entiere positive dans le calendrier.');
            }

            // **La perte devient visible a la fin de la periode du round** qui l'a produite : la
            // montrer au debut annoncerait l'issue d'un echange qui n'a pas encore eu lieu.
            $instant += $secondes;

            $pertes = [];

            foreach ($round->lossesInRoundByParticipant as $participant => $unites) {
                $camp = isset($attaquantes[$participant]) ? CombatParticipant::SIDE_ATTACKER : CombatParticipant::SIDE_DEFENDER;

                foreach ($unites->units as $entree) {
                    if ($entree->amount <= 0) {
                        continue;
                    }

                    $pertes[] = [$camp === CombatParticipant::SIDE_ATTACKER ? 0 : 1, (string)$participant, $entree->unitObject->machine_name, $entree->amount, $camp];
                }
            }

            usort($pertes, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

            foreach ($pertes as [, $participant, $unite, $montant, $camp]) {
                $evenements[] = new PresentationEvent(++$sequence, $instant, $participant, $camp, $unite, $montant);
            }
        }

        return $evenements;
    }
}
