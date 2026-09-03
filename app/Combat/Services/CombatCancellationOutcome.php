<?php

namespace OGame\Combat\Services;

/**
 * Ce qu'une annulation a fait — ou pourquoi elle n'a rien fait.
 */
final readonly class CombatCancellationOutcome
{
    public const string REASON_CANCELLED = 'cancelled';

    public const string REASON_ALREADY_OVER = 'already_over';

    public const string REASON_UNKNOWN_COMBAT = 'unknown_combat';

    /**
     * @param bool $cancelled Vrai si ce passage a annule le combat.
     * @param string $reason Ce qui s'est passe, nomme.
     * @param int $fleetsSentHome Les flottes attaquantes renvoyees par ce passage.
     * @param int $fleetsAlreadyGone Les flottes inscrites qui etaient deja traitees : laissees telles
     *                               quelles, sans second retour — et dites, parce qu'un tel etat
     *                               n'existe que par corruption ou reparation manuelle.
     */
    private function __construct(
        public bool $cancelled,
        public string $reason,
        public int $fleetsSentHome,
        public int $fleetsAlreadyGone,
    ) {
    }

    public static function cancelled(int $fleetsSentHome, int $fleetsAlreadyGone = 0): self
    {
        return new self(true, self::REASON_CANCELLED, $fleetsSentHome, $fleetsAlreadyGone);
    }

    /**
     * Le combat est deja termine — regle, ou annule avant : rien ne se defait, rien ne se refait.
     */
    public static function alreadyOver(): self
    {
        return new self(false, self::REASON_ALREADY_OVER, 0, 0);
    }

    public static function unknownCombat(): self
    {
        return new self(false, self::REASON_UNKNOWN_COMBAT, 0, 0);
    }
}
