<?php

namespace OGame\Combat\Enums;

/**
 * Le systeme d'honneur, et ce que le statut du defenseur impose au pillage.
 *
 * ## Ce que cette enumeration disait avant, et pourquoi elle change
 *
 * Elle n'avait qu'un cas — `Disabled` — et disait en toutes lettres que le systeme n'existait pas
 * dans OGameX : `TacticalRetreatService` le constatait, `GalaxyController` gardait ses deux
 * verdicts en commentaire, et la vue generale affichait une constante zero. Plutot que de laisser
 * la question ouverte, l'etat avait ete **nomme et desactive**, avec la regle de composition ecrite
 * d'avance pour le jour ou il s'appliquerait.
 *
 * Ce jour est arrive (6 septembre 2026, decision de Keven, valeurs d'OGame officiel). La regle
 * annoncee alors n'a pas bouge :
 *
 *     taux effectif = max(taux de classe, taux d honneur)
 *
 * **Par maximum, jamais par addition.** Un bandit attaque par un Decouvreur reste a 100 %, pas a
 * 175 %.
 *
 * ## Pourquoi `Disabled` survit a l'arrivee du systeme
 *
 * L'interrupteur `honor_system_enabled` vaut non par defaut : un univers deja en cours recoit le
 * code sans que le pillage change d'un point. `Disabled` est cet etat-la, et il rend zero — donc il
 * ne gagne jamais le maximum. `Neutral` en est distinct : le systeme fonctionne, mais ce
 * defenseur-la n'a merite ni faveur ni sanction.
 */
enum HonorPolicy: string
{
    /**
     * Le systeme est eteint sur cet univers : il n'ajoute rien au taux.
     */
    case Disabled = 'disabled';

    /**
     * Le systeme tourne, et ce defenseur n'a rien de particulier : ni honorable, ni bandit.
     */
    case Neutral = 'neutral';

    /**
     * Le defenseur a un honneur positif : on ne le depouille pas entierement.
     */
    case HonorableTarget = 'honorable_target';

    /**
     * Le defenseur est tombe sous le seuil : son stock est pris en entier.
     */
    case Outlaw = 'outlaw';

    /**
     * Le taux que l'honneur impose, en points de base.
     *
     * Zero pour les deux etats qui n'imposent rien — ils ne gagnent alors jamais le maximum, et le
     * taux de classe decide seul.
     *
     * @return int
     */
    public function minimumRateInBasisPoints(): int
    {
        return match ($this) {
            self::Disabled, self::Neutral => 0,
            self::HonorableTarget => 7500,
            self::Outlaw => 10000,
        };
    }
}
