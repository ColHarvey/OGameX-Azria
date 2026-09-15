<?php

namespace OGame\Military;

/**
 * Le groupe d une bataille : un evenement par participant classe, plus l evenement nomme de la manoeuvre de Hamill
 * quand son auteur est classe.
 *
 * **Zeros compris.** L attente differe chaque participant classe sans connaitre ses valeurs ; la reprise attend donc
 * chacun d eux, y compris celui qui n a rien perdu ni rien detruit, et le clot a zero. Un membre qui n aurait aucun
 * attendu laisserait le groupe en attente pour toujours.
 */
final class BattleTallyGroup implements GroupedTallyKind
{
    public function __construct(
        private BattleTallyEvaluation $evaluation = new BattleTallyEvaluation(),
    ) {
    }

    public function kinds(): array
    {
        return [MilitaryBattleTally::KIND, MilitaryBattleTally::KIND_HAMILL];
    }

    public function readFacts(mixed $facts): object|null
    {
        return BattleTallyFacts::fromStorage($facts);
    }

    public function memberPrefixes(object $facts): array
    {
        return $facts instanceof BattleTallyFacts ? [$facts->eventKeyPrefix(), $facts->hamillEventKeyPrefix()] : [];
    }

    public function expectedEvents(object $facts, string $version): array|null
    {
        if (!$facts instanceof BattleTallyFacts) {
            return null;
        }

        $issue = $this->evaluation->evaluate($facts, $version);

        if ($issue->isPending()) {
            return null;
        }

        $attendus = [];

        foreach ($issue->classedOutcomes() as $participant => $valeurs) {
            $attendus[$facts->eventKeyFor($participant)] = ['kind' => MilitaryBattleTally::KIND, 'owner' => $valeurs['owner'], 'built' => 0, 'destroyed' => $valeurs['destroyed'], 'lost' => $valeurs['lost']];
        }

        $hamill = $issue->hamillClassed();

        if ($hamill !== null) {
            $attendus[$facts->hamillEventKeyFor($hamill['author'])] = ['kind' => MilitaryBattleTally::KIND_HAMILL, 'owner' => $hamill['owner'], 'built' => 0, 'destroyed' => $hamill['destroyed'], 'lost' => 0];
        }

        return $attendus;
    }

    public function storageOf(object $facts): array
    {
        return $facts instanceof BattleTallyFacts ? $facts->toStorage() : [];
    }
}
