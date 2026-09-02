<?php

namespace OGame\Combat\Admission;

use LogicException;
use OGame\Combat\Enums\CombatReasonCode;

/**
 * Ce que le selecteur decide d'un groupe candidat.
 *
 * La raison accompagne toujours le refus, et elle est **finale** : elle sera lue par un joueur dans
 * sa vue Flotte. Un refus sans raison precise obligerait l'interface a en inventer une.
 */
final readonly class GroupAdmission
{
    /**
     * @param AttackCandidateGroup $group Le groupe concerne.
     * @param bool $admitted S'il rejoint le combat, entierement.
     * @param CombatReasonCode|null $refusal La raison du refus, ou null s'il est admis.
     */
    private function __construct(
        public AttackCandidateGroup $group,
        public bool $admitted,
        public CombatReasonCode|null $refusal,
    ) {
        if ($admitted === ($refusal !== null)) {
            throw new LogicException(
                'Une admission porte une raison de refus, ou elle est admise : jamais les deux, jamais aucun '
                . 'des deux. Un refus sans raison obligerait l interface a en inventer une.'
            );
        }
    }

    /**
     * Le groupe rejoint le combat, entierement.
     */
    public static function admit(AttackCandidateGroup $group): self
    {
        return new self($group, true, null);
    }

    /**
     * Le groupe est renvoye, entierement.
     */
    public static function refuse(AttackCandidateGroup $group, CombatReasonCode $reason): self
    {
        return new self($group, false, $reason);
    }

    /**
     * L'admission, sous une forme lisible dans un message d'essai.
     */
    public function describe(): string
    {
        return $this->group->groupIdentity . ' | ' . ($this->refusal?->value ?? 'admis');
    }
}
