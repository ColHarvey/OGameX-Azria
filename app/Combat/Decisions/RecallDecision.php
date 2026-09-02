<?php

namespace OGame\Combat\Decisions;

use OGame\Combat\Enums\CombatReasonCode;

/**
 * Ce que le combat repond a une demande de rappel.
 *
 * Trois issues, et elles ne se ressemblent pas :
 *
 * - la flotte etait **candidate et pas encore arrivee** : elle est retiree, et l'echeance du
 *   ralliement peut s'en trouver raccourcie ;
 * - la flotte est **deja engagee** : trop tard, elle se battra ;
 * - la flotte **n'a rien a voir** avec un combat : le rappel suit son cours ordinaire.
 *
 * Aucune de ces issues ne se reporte ni ne photographie quoi que ce soit. Un rappel n'ajoute
 * jamais rien a une bataille — il ne peut qu'en retirer.
 */
final readonly class RecallDecision implements CombatDecision
{
    /**
     * @param bool $withdrawsCandidate Si le retrait doit etre applique et l'echeance recalculee.
     * @param bool $recallAllowed Si le rappel lui-meme est accepte.
     * @param CombatReasonCode $reason
     * @param string|null $openQuestion
     */
    private function __construct(
        public bool $withdrawsCandidate,
        public bool $recallAllowed,
        private CombatReasonCode $reason,
        private string|null $openQuestion = null,
    ) {
    }

    /**
     * La flotte est retiree des candidates, et l'echeance recalculee sous le meme verrou.
     *
     * Le recalcul ne peut que **raccourcir** la fenetre — voir
     * `CombatRallyWindow::closesAfterWithdrawal()`.
     */
    public static function allowAndWithdrawCandidate(): self
    {
        return new self(true, true, CombatReasonCode::NoCombatEffect);
    }

    /**
     * La flotte est deja arrivee et engagee : elle ne se rappelle plus.
     */
    public static function rejectAlreadyEngaged(): self
    {
        return new self(false, false, CombatReasonCode::AlreadyEngaged);
    }

    /**
     * Le rappel est accepte et ne concerne aucun combat.
     */
    public static function allowRecallWithoutCombatEffect(): self
    {
        return new self(false, true, CombatReasonCode::NoCombatEffect);
    }

    /**
     * La regle de cette case n'est pas arretee.
     *
     * @param string $question
     * @return self
     */
    public static function unresolved(string $question): self
    {
        return new self(false, false, CombatReasonCode::Undecided, $question);
    }

    /**
     * Si le rappel est accepte. Leve si la case n'est pas tranchee.
     */
    public function isRecallAllowed(): bool
    {
        if ($this->openQuestion !== null) {
            throw new UnresolvedCombatDecision($this->openQuestion);
        }

        return $this->recallAllowed;
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
