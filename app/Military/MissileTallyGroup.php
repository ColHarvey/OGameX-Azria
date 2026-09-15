<?php

namespace OGame\Military;

/**
 * Le groupe d une frappe de missiles : l evenement de l attaquant et celui du defenseur, sur les memes faits.
 */
final class MissileTallyGroup implements GroupedTallyKind
{
    public function __construct(
        private MissileTallyEvaluation $evaluation = new MissileTallyEvaluation(),
    ) {
    }

    public function kinds(): array
    {
        return [MilitaryMissileTally::KIND];
    }

    public function readFacts(mixed $facts): object|null
    {
        return MissileTallyFacts::fromStorage($facts);
    }

    public function memberPrefixes(object $facts): array
    {
        return $facts instanceof MissileTallyFacts ? [$facts->eventKeyPrefix()] : [];
    }

    public function expectedEvents(object $facts, string $version): array|null
    {
        if (!$facts instanceof MissileTallyFacts) {
            return null;
        }

        $issue = $this->evaluation->evaluate($facts, $version);

        if ($issue->isPending()) {
            return null;
        }

        $attendus = [];

        foreach ($issue->classedOutcomes() as $participant => $valeurs) {
            $attendus[$facts->eventKeyFor($participant)] = ['kind' => MilitaryMissileTally::KIND, 'owner' => $valeurs['owner'], 'built' => 0, 'destroyed' => $valeurs['destroyed'], 'lost' => $valeurs['lost']];
        }

        return $attendus;
    }

    public function storageOf(object $facts): array
    {
        return $facts instanceof MissileTallyFacts ? $facts->toStorage() : [];
    }
}
