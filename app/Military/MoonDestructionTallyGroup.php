<?php

namespace OGame\Military;

/**
 * Le groupe d une destruction de lune : l evenement de la lune et celui de chaque tentative, sur les memes faits.
 */
final class MoonDestructionTallyGroup implements GroupedTallyKind
{
    public function __construct(
        private MoonDestructionTallyEvaluation $evaluation = new MoonDestructionTallyEvaluation(),
    ) {
    }

    public function kinds(): array
    {
        return [MilitaryMoonDestructionTally::KIND];
    }

    public function readFacts(mixed $facts): object|null
    {
        return MoonDestructionTallyFacts::fromStorage($facts);
    }

    public function memberPrefixes(object $facts): array
    {
        return $facts instanceof MoonDestructionTallyFacts ? [$facts->eventKeyPrefix()] : [];
    }

    public function expectedEvents(object $facts, string $version): array|null
    {
        if (!$facts instanceof MoonDestructionTallyFacts) {
            return null;
        }

        $issue = $this->evaluation->evaluate($facts, $version);

        if ($issue->isPending()) {
            return null;
        }

        $attendus = [];

        foreach ($issue->classedOutcomes() as $participant => $valeurs) {
            $attendus[$facts->eventKeyFor($participant)] = ['kind' => MilitaryMoonDestructionTally::KIND, 'owner' => $valeurs['owner'], 'built' => 0, 'destroyed' => $valeurs['destroyed'], 'lost' => $valeurs['lost']];
        }

        return $attendus;
    }

    public function storageOf(object $facts): array
    {
        return $facts instanceof MoonDestructionTallyFacts ? $facts->toStorage() : [];
    }
}
