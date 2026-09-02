<?php

namespace OGame\Combat\Enums;

/**
 * Les etats d'une reservation de butin, et les seuls passages permis entre eux.
 *
 *     OPEN ──→ SEALED ──→ SETTLED
 *       │
 *       └────→ CANCELLED
 *
 * **`CANCELLED` n'est accessible que depuis `OPEN`.** Une premiere version le placait apres
 * `SETTLED`, ce qui laissait entendre qu'une reservation deja reglee pouvait ensuite etre
 * annulee — le butin aurait ete preleve **puis** les fonds liberes, c'est-a-dire verses deux fois.
 *
 * Cela decoule d'une regle deja arretee : un combat ne s'annule que pendant le ralliement. Une
 * defaillance survenue apres le scellement **ne doit pas annuler le combat** ; elle doit conserver
 * le verrou et reprendre la resolution la ou elle s'est arretee.
 */
enum LootReservationState: string
{
    /**
     * Le ralliement court. La borne peut encore monter, jamais descendre.
     */
    case Open = 'open';

    /**
     * La photographie est prise. La borne est immuable.
     */
    case Sealed = 'sealed';

    /**
     * Le butin a ete preleve et le reliquat libere. Etat terminal.
     */
    case Settled = 'settled';

    /**
     * Le combat n'aura pas lieu : tout est libere, exactement une fois. Etat terminal.
     */
    case Cancelled = 'cancelled';

    /**
     * Les etats accessibles depuis celui-ci.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Sealed, self::Cancelled],
            self::Sealed => [self::Settled],
            self::Settled, self::Cancelled => [],
        };
    }

    /**
     * Si ce passage est permis.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Si rien ne sort de cet etat.
     */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Si la borne peut encore etre relevee.
     *
     * Uniquement pendant le ralliement : une fois la photographie prise, la borne est ce qu'elle
     * est.
     */
    public function acceptsARaise(): bool
    {
        return $this === self::Open;
    }
}
