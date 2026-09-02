<?php

namespace OGame\Combat\Decisions;

use OGame\Combat\Enums\CombatReasonCode;

/**
 * Ce que le combat repond a une tentative de lancement.
 *
 * Deux issues seulement : la mission part, ou elle ne part pas. Rien ne se reporte et rien ne se
 * photographie a ce moment-la — la flotte n'existe pas encore.
 *
 * **Le refus doit vivre ici, dans le domaine**, et non dans une route ou une vue. Un joueur qui
 * contourne l'interface, un appel d'API, un script d'administration : tous passent par le service
 * d'envoi, et c'est lui qui doit consulter cette decision.
 */
final readonly class LaunchDecision implements CombatDecision
{
    /**
     * @param bool $allowed
     * @param CombatReasonCode $reason
     * @param string|null $openQuestion
     */
    private function __construct(
        public bool $allowed,
        private CombatReasonCode $reason,
        private string|null $openQuestion = null,
    ) {
    }

    /**
     * Le combat ne s'oppose pas a ce lancement.
     */
    public static function allow(): self
    {
        return new self(true, CombatReasonCode::NoCombatEffect);
    }

    /**
     * Le lancement est refuse, et la raison est nommee.
     *
     * @param CombatReasonCode $reason
     * @return self
     */
    public static function refuse(CombatReasonCode $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * La regle de cette case n'est pas arretee.
     *
     * @param string $question
     * @return self
     */
    public static function unresolved(string $question): self
    {
        return new self(false, CombatReasonCode::Undecided, $question);
    }

    /**
     * Si le lancement est autorise.
     *
     * **Leve si la case n'est pas tranchee**, plutot que de rendre un booleen que l'appelant
     * interpreterait comme une autorisation.
     */
    public function isAllowed(): bool
    {
        if ($this->openQuestion !== null) {
            throw new UnresolvedCombatDecision($this->openQuestion);
        }

        return $this->allowed;
    }

    public function reason(): CombatReasonCode
    {
        return $this->reason;
    }

    public function isResolved(): bool
    {
        return $this->openQuestion === null;
    }

    public function openQuestion(): string|null
    {
        return $this->openQuestion;
    }
}
