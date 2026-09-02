<?php

namespace OGame\Combat\Support;

/**
 * Ce que l'instance de combat dit de son initiateur, relu en base.
 *
 * **La source de verite, et la seule.** Un worker ne transporte que l'identite d'un evenement ;
 * il ne transporte jamais la qualite d'initiateur, ni un jeton qui l'attesterait. C'est cette
 * ligne, relue sous le verrou de la cible, qui tranche.
 *
 * Sans cela, un payload de tache forge ou simplement perime pourrait faire passer une seconde
 * flotte pour l'initiatrice, et lui faire franchir toutes les barrieres du verrou causal.
 */
final readonly class PersistedCombatOpener
{
    /**
     * @param int $combatInstanceId L'instance concernee.
     * @param EffectOrderKey $openerEventKey L'evenement qui a ouvert ce combat. Unique par
     *                                       instance : la meme mission ne peut jamais en ouvrir
     *                                       deux.
     * @param string $targetBodyKey Le corps celeste verrouille. Une planete et sa lune sont deux
     *                              cibles distinctes, donc deux cles distinctes.
     * @param int $plannedArrival Heure planifiee de l'arrivee initiatrice, telle qu'enregistree.
     * @param int $openingDecisionOrder Rang de l'ouverture dans l'ordre des decisions.
     */
    public function __construct(
        public int $combatInstanceId,
        public EffectOrderKey $openerEventKey,
        public string $targetBodyKey,
        public int $plannedArrival,
        public int $openingDecisionOrder,
    ) {
    }
}
