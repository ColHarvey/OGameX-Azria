<?php

namespace OGame\Combat\Models;

/**
 * Le travail calcule pour un round, et les grandeurs qui l'ont produit.
 *
 * Tout est conserve, pas seulement le resultat : c'est ce detail qui permet de calibrer le
 * coefficient de rythme en regardant *pourquoi* un combat dure, au lieu de deviner.
 */
class CombatRoundWork
{
    /**
     * @param int $number Numero du round, a partir de 1.
     * @param int $hitsAttacker Tirs portes par l'attaquant, tirs rapides compris.
     * @param int $hitsDefender Tirs portes par le defenseur, tirs rapides compris.
     * @param float $exchanges Echanges bilateraux : le plus petit des deux nombres de tirs.
     * @param float $resistance Resistance reellement opposee : la plus faible des deux forces.
     * @param float $balance Equilibre des forces, entre 0 et 1.
     * @param float $shieldPressure Degats absorbes par les boucliers des deux cotes.
     * @param float $work Produit des quatre grandeurs ci-dessus.
     * @param int $seconds Part de la duree totale revenant a ce round.
     */
    public function __construct(
        public readonly int $number,
        public readonly int $hitsAttacker,
        public readonly int $hitsDefender,
        public readonly float $exchanges,
        public readonly float $resistance,
        public readonly float $balance,
        public readonly float $shieldPressure,
        public readonly float $work,
        public readonly int $seconds,
    ) {
    }
}
