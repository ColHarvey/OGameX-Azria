<?php

namespace OGame\Combat\Policies;

use OGame\Combat\Support\LootPolicy;

/**
 * L'absence de droit de pillage, nommee comme une regle.
 *
 * ## Pourquoi ce n'est pas simplement un taux de zero
 *
 * Un zero calcule et un zero decide se ressemblent dans un resultat, et ne disent pas la meme
 * chose. Le premier signifie qu'il n'y avait rien a prendre ; le second, que ce combat n'avait pas
 * le droit de prendre. Un rapport, un audit ou une reservation doivent pouvoir les distinguer.
 *
 * Sans version dediee, un contexte sans pillage se rechargerait comme une politique de joueur dont
 * les faits auraient donne zero — et le jour ou la formule des joueurs changerait, ce combat-la
 * changerait avec elle, alors qu'aucune formule ne le concernait.
 *
 * La **raison** du refus — contre-espionnage, rencontre PNJ, banc d'essai — reste separee de la
 * version : elle repond a « pourquoi celui-ci », quand la version repond a « sous quelle regle ».
 */
final class NoLootV1 implements LootRateRule
{
    /**
     * L'identifiant persiste avec chaque combat.
     */
    public const string VERSION = 'no_loot_v1';

    /**
     * @inheritDoc
     */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @inheritDoc
     */
    public function rateInBasisPoints(LootPolicy $facts): int
    {
        return 0;
    }
}
