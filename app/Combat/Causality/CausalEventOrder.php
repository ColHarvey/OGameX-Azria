<?php

namespace OGame\Combat\Causality;

use OGame\Combat\Enums\CombatEventType;

/**
 * L'ordre des effets simultanes, dans une version donnee.
 *
 * ## Ce qui est versionne, et ce qui ne l'est pas
 *
 * Quatre regles gouvernent l'ordre des effets. **Une seule** varie d'une version a l'autre :
 *
 *     heure planifiee d'abord            -> invariant, toutes versions
 *     barriere avant tout evenement      -> invariant, toutes versions
 *     departage par identite persistee   -> invariant, toutes versions
 *     ordre entre genres                 -> **versionne**
 *
 * Les trois invariants sont proteges par des essais permanents. Les melanger au contrat versionne
 * laisserait croire qu'une v2 pourrait, par exemple, faire passer un effet avant sa propre
 * barriere — ce qui n'est pas une variation de regle mais une incoherence.
 *
 * ## Pourquoi la version voyage avec la cle
 *
 * Un combat ouvert sous v1 doit etre rejoue sous v1, meme des mois plus tard, meme si une v2 est
 * devenue courante. La version est donc fixee **a l'ouverture logique durable**, persistee avec
 * l'instance, et relue ensuite ; jamais choisie au reveil d'un worker en retard.
 *
 * Comparer une cle v1 a une cle v2 n'a aucun sens : les deux ne classent pas les memes genres dans
 * le meme ordre. `EffectOrderKey` le refuse plutot que de rendre un resultat plausible.
 */
interface CausalEventOrder
{
    /**
     * L'identifiant stable de cette version.
     */
    public function version(): string;

    /**
     * Le rang d'un genre d'evenement, a effet simultane.
     *
     * **Toujours strictement positif** : zero appartient aux barrieres, et un evenement qui le
     * porterait se placerait avant une fermeture tombant a la meme seconde, alors que la convention
     * veut l'inverse.
     *
     * @param CombatEventType $type
     * @return int
     */
    public function rankOf(CombatEventType $type): int;
}
