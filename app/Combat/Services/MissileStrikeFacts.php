<?php

namespace OGame\Combat\Services;

use OGame\Combat\Support\FrozenFact;

/**
 * Les faits d'une salve de missiles, releves au moment ou le monde l'applique.
 *
 * ## Pourquoi des faits, et pas un delta
 *
 * Le missile n'est pas lineaire : il detruit dans un tas qui contient aussi les defenses
 * inadmissibles, par priorite, apres interception. Le delta que le monde a subi ne dit pas ce que la
 * photographie admissible aurait perdu. La fermeture **projette** donc la meme salve sur la
 * photographie (`MissileStrikeProjection`), et pour cela il lui faut ce que la salve etait : combien
 * de missiles, quelle priorite, quelle technologie d'armes chez le lanceur, et combien d'antimissiles
 * la planete mere pouvait preter — ceux-la ne sont pas photographies, la planete mere n'est pas le
 * corps que le combat tient.
 *
 * Les antimissiles du corps vise, eux, viennent de la photographie : ceux de l'ouverture, plus les
 * seules files admissibles, moins ceux que les salves admissibles precedentes ont consommes.
 */
final readonly class MissileStrikeFacts
{
    public function __construct(
        public int $missiles,
        public int $priority,
        public int $weaponTech,
        public int $targetInterceptorsBefore,
        public int $parentInterceptorsBefore,
    ) {
    }

    /**
     * Les faits relus, ou un refus : une porte de confiance ne transtype pas.
     *
     * @param array<string, mixed> $facts
     */
    public static function fromFrozenFacts(array $facts): self
    {
        return new self(
            FrozenFact::int($facts, 'missiles'),
            FrozenFact::int($facts, 'priority'),
            FrozenFact::int($facts, 'weapon_tech'),
            FrozenFact::int($facts, 'target_interceptors_before'),
            FrozenFact::int($facts, 'parent_interceptors_before'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toFrozenFacts(): array
    {
        return [
            'missiles' => $this->missiles,
            'priority' => $this->priority,
            'weapon_tech' => $this->weaponTech,
            'target_interceptors_before' => $this->targetInterceptorsBefore,
            'parent_interceptors_before' => $this->parentInterceptorsBefore,
        ];
    }
}
