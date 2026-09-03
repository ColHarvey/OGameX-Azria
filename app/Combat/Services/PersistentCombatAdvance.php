<?php

namespace OGame\Combat\Services;

/**
 * Ce qu'un passage du travail d'avancement a fait.
 *
 * Les combats mis de cote sont comptes a part : ce n'est pas un echec du passage, c'est une
 * attente d'exploitation. Un passage qui n'a rien fait et un passage qui bute sur dix combats
 * corrompus ne se racontent pas de la meme facon.
 */
final readonly class PersistentCombatAdvance
{
    /**
     * @param int $closed Ralliements fermes pendant ce passage.
     * @param int $settled Combats regles pendant ce passage.
     * @param array<int, string> $failures Les combats qui ont leve, par identifiant, avec leur raison.
     * @param int $quarantined Combats laisses de cote parce qu'ils ont deja trop echoue.
     */
    public function __construct(
        public int $closed,
        public int $settled,
        public array $failures,
        public int $quarantined,
    ) {
    }

    public function didSomething(): bool
    {
        return $this->closed > 0 || $this->settled > 0;
    }
}
