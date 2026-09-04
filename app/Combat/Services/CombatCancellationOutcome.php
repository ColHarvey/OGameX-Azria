<?php

namespace OGame\Combat\Services;

/**
 * Ce qu'une annulation a fait, ou pourquoi elle n'a rien fait.
 *
 * Les compteurs distinguent les deux camps : une sortie d'exploitation qui annonce « toutes les
 * flottes rendues » doit pouvoir dire combien d'attaquantes et combien de renforts defensifs, et
 * combien etaient deja parties.
 */
final readonly class CombatCancellationOutcome
{
    public const string REASON_CANCELLED = 'cancelled';

    public const string REASON_ALREADY_OVER = 'already_over';

    public const string REASON_UNKNOWN_COMBAT = 'unknown_combat';

    private function __construct(
        public bool $cancelled,
        public string $reason,
        public int $fleetsSentHome,
        public int $fleetsAlreadyGone,
        public int $defendersSentHome,
    ) {
    }

    public static function cancelled(int $fleetsSentHome, int $fleetsAlreadyGone = 0, int $defendersSentHome = 0): self
    {
        return new self(true, self::REASON_CANCELLED, $fleetsSentHome, $fleetsAlreadyGone, $defendersSentHome);
    }

    public static function alreadyOver(): self
    {
        return new self(false, self::REASON_ALREADY_OVER, 0, 0, 0);
    }

    public static function unknownCombat(): self
    {
        return new self(false, self::REASON_UNKNOWN_COMBAT, 0, 0, 0);
    }
}
