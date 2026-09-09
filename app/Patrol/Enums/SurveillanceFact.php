<?php

namespace OGame\Patrol\Enums;

/**
 * Les faits qu un contact de surveillance peut porter, et le palier a partir duquel chacun voyage.
 *
 * ## Une liste fermee, et ce qui n y est pas
 *
 * Ce que cette enumeration ne nomme pas ne voyage jamais, quel que soit le niveau du reseau : la
 * **composition** d une flotte, sa **reserve de carburant**, sa **cargaison**. Ces trois-la n ont
 * pas de palier parce qu ils n en auront pas — les rendre ferait de la surveillance un espionnage
 * sans sonde et sans risque. L absence est donc une decision, pas un oubli, et c est ici qu elle se
 * lit.
 *
 * ## Le palier vit sur le fait, pas sur le niveau
 *
 * Chaque fait nomme le palier a partir duquel il est revele, et `SurveillanceTier::reveals()`
 * compare. L inverse — une liste de faits par niveau — se serait desynchronisee au premier fait
 * ajoute, et rien ne l aurait signale.
 */
enum SurveillanceFact: string
{
    /**
     * La presence d une patrouille et sa position dans le systeme.
     */
    case Position = 'position';

    /**
     * Le proprietaire de la patrouille.
     */
    case Owner = 'owner';

    /**
     * Sa direction a l interieur du systeme.
     */
    case Heading = 'heading';

    /**
     * Un ordre de grandeur de sa taille, jamais un effectif exact.
     */
    case SizeEstimate = 'size_estimate';

    /**
     * L effectif exact.
     */
    case ExactStrength = 'exact_strength';

    /**
     * Le palier a partir duquel ce fait voyage.
     */
    public function fromTier(): SurveillanceTier
    {
        return match ($this) {
            self::Position => SurveillanceTier::Contact,
            self::Owner => SurveillanceTier::Identity,
            self::Heading => SurveillanceTier::Heading,
            self::SizeEstimate => SurveillanceTier::Estimate,
            self::ExactStrength => SurveillanceTier::Strength,
        };
    }
}
