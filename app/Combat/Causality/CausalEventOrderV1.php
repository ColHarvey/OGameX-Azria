<?php

namespace OGame\Combat\Causality;

use OGame\Combat\Enums\CombatEventType;

/**
 * L'ordre des effets simultanes, premiere version.
 *
 * ## Ce que cet ordre decide, concretement
 *
 *     recherche  <  chantier  <  missile  <  arrivee
 *
 * - **une recherche achevee a la meme seconde qu'un chantier** : l'unite construite se bat avec la
 *   technologie nouvelle, pas avec l'ancienne ;
 * - **un chantier acheve a la meme seconde qu'un impact** : la defense existe deja, et **le missile
 *   peut donc la detruire**. C'est la decision de jeu qui a fixe cet ordre ;
 * - **un missile a la meme seconde qu'une arrivee** : la cible est modifiee avant que la flotte
 *   n'arrive, et l'arrivee voit l'etat d'apres.
 *
 * ## Pourquoi cet ordre a remplace le precedent
 *
 * `CombatEventType::rank()` portait l'ordre **inverse** — arrivee, missile, chantier — ecrit avant
 * que la question ne soit posee. Le domaine n'etant branche nulle part et aucun combat persistant
 * n'existant, c'etait le moment de choisir l'ordre coherent plutot que de preserver une
 * compatibilite avec rien.
 *
 * Le rang est desormais **ici** et non sur l'enumeration : une v2 vivra dans sa propre classe, et
 * les deux coexisteront sans qu'aucune ne doive deloger l'autre.
 */
final readonly class CausalEventOrderV1 implements CausalEventOrder
{
    /**
     * L'identifiant stable de cette version.
     */
    public const string VERSION = 'causal_event_order_v1';

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * Le rang d'un genre, a effet simultane.
     *
     * Le `match` est exhaustif et sans branche par defaut : un genre ajoute plus tard devra recevoir
     * son rang explicitement. Lui laisser celui d'un autre rendrait l'ordre non deterministe entre
     * les deux, et cela ne se verrait qu'en production, sur deux evenements de la meme seconde.
     */
    public function rankOf(CombatEventType $type): int
    {
        return match ($type) {
            CombatEventType::ResearchCompletion => 1,
            CombatEventType::QueueCompletion => 2,
            CombatEventType::MissileImpact => 3,
            CombatEventType::FleetArrival => 4,
        };
    }
}
