<?php

namespace OGame\Combat\Services;

use OGame\Combat\Allocation\AppliedLootShares;
use OGame\Combat\Allocation\LootSettlement;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;

/**
 * Ce que le reglement d'un combat durable a fait — ou pourquoi il n'a rien fait.
 *
 * Ne rien faire n'est pas une faute. Un travail relivre retrouve un combat deja regle ; un combat
 * annule n'a rien a regler ; un combat inconnu a ete purge. Chaque cas porte sa raison pour que
 * l'appelant la journalise au lieu de la deviner, et aucun ne leve : lever transformerait une
 * relivraison normale en incident.
 */
final readonly class CombatSettlementOutcome
{
    public const string REASON_SETTLED = 'settled';

    public const string REASON_ALREADY_SETTLED = 'already_settled';

    public const string REASON_CANCELLED = 'cancelled';

    public const string REASON_STILL_RALLYING = 'still_rallying';

    public const string REASON_STILL_FIGHTING = 'still_fighting';

    public const string REASON_UNKNOWN_COMBAT = 'unknown_combat';

    private function __construct(
        public bool $settled,
        public string $reason,
        public LootSettlement|null $loot = null,
        public AppliedLootShares|null $shares = null,
        public int|null $battleReportId = null,
        public ResourceNormalizationDiagnostics|null $diagnostics = null,
    ) {
    }

    /**
     * Le combat est regle : voici les nombres exacts sur lesquels tout a ete ecrit.
     */
    public static function settled(
        LootSettlement $loot,
        AppliedLootShares $shares,
        int $battleReportId,
        ResourceNormalizationDiagnostics $diagnostics,
    ): self {
        return new self(true, self::REASON_SETTLED, $loot, $shares, $battleReportId, $diagnostics);
    }

    /**
     * Un travail relivre : le combat avait deja ete regle, et rien n'a ete refait.
     */
    public static function alreadySettled(): self
    {
        return new self(false, self::REASON_ALREADY_SETTLED);
    }

    public static function cancelled(): self
    {
        return new self(false, self::REASON_CANCELLED);
    }

    /**
     * Le rassemblement n'est pas clos : on ne regle pas une bataille dont les rangs ne sont pas figes.
     */
    public static function stillRallying(): self
    {
        return new self(false, self::REASON_STILL_RALLYING);
    }

    /**
     * L'echeance n'est pas atteinte : le combat dure encore, et le regler le couperait court.
     */
    public static function stillFighting(): self
    {
        return new self(false, self::REASON_STILL_FIGHTING);
    }

    public static function unknownCombat(): self
    {
        return new self(false, self::REASON_UNKNOWN_COMBAT);
    }
}
