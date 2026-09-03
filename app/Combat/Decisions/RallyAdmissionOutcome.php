<?php

namespace OGame\Combat\Decisions;

use LogicException;
use OGame\Combat\Admission\GroupAdmission;
use OGame\Combat\Enums\CombatReasonCode;

/**
 * Ce que le selecteur d'un camp a prononce pour **une** mission.
 *
 * ## Pourquoi ce type existe a cote de `GroupAdmission`
 *
 * Les selecteurs decident par groupe : une attaque ACS deja en vol est admise entiere ou renvoyee
 * entiere. Le consommateur d'arrivee, lui, traite une mission a la fois — c'est une flotte qui se
 * pose, pas un groupe.
 *
 * Il aurait ete plus court de passer directement un `GroupAdmission`. Ce serait aussi la porte
 * ouverte a une seconde verite : rien n'empecherait alors un appelant de fabriquer un
 * `AttackCandidateGroup` de circonstance pour repondre a la place du selecteur.
 * `fromGroupAdmission()` est donc **le seul pont**, et il ne fait que lire.
 */
final readonly class RallyAdmissionOutcome
{
    /**
     * @param bool $admitted
     * @param CombatReasonCode|null $refusal
     */
    private function __construct(
        public bool $admitted,
        public CombatReasonCode|null $refusal,
    ) {
        if ($admitted === ($refusal !== null)) {
            throw new LogicException(
                'Une admission porte une raison de refus, ou elle est admise : jamais les deux, jamais aucun '
                . 'des deux.'
            );
        }
    }

    /**
     * La mission rejoint son camp.
     */
    public static function admitted(): self
    {
        return new self(true, null);
    }

    /**
     * La mission est ecartee, et le joueur lira pourquoi.
     */
    public static function refused(CombatReasonCode $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * Le verdict du selecteur, tel qu'il l'a rendu pour le groupe de cette mission.
     */
    public static function fromGroupAdmission(GroupAdmission $admission): self
    {
        return $admission->admitted
            ? self::admitted()
            : self::refused($admission->refusal ?? CombatReasonCode::Undecided);
    }
}
