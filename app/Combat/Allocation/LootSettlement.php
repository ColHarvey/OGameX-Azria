<?php

namespace OGame\Combat\Allocation;

/**
 * Le reglement du butin : ce qui etait dû, ce qui reste, ce qui est pris.
 *
 * ## La regle, et les deux abus qu'elle ferme en meme temps
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Elle se lit d'un trait, et chacune de ses deux moities existe pour une raison distincte :
 *
 * - **le plafond par le potentiel** empeche l'attaquant de prendre la production arrivee apres
 *   l'ouverture. Un combat qui dure produit des ressources ; elles appartiennent au defenseur, et le
 *   butin a ete calcule sans elles ;
 * - **le plafond par le restant** laisse le defenseur sauver ce qu'il a eu le temps de depenser.
 *   C'est une decision de jeu, pas une precaution technique : elle recompense la reactivite plutot
 *   que de punir l'inattention.
 *
 * C'est ce qui distingue cette regle d'un compromis. Une reservation aurait ferme le second abus en
 * supprimant le premier choix ; un butin non plafonne aurait fait l'inverse.
 *
 * ## Exact, du debut a la fin
 *
 * Les trois montants sont des **entiers**, jamais des flottants. Ce que ce reglement rend est
 * debite au defenseur, charge dans des soutes et ecrit dans un rapport : ces trois nombres doivent
 * etre le meme, a l'unite pres.
 *
 * Cette classe a d'abord ete ecrite sur `LootEnvelope`, dont les composantes sont des `float`.
 * C'etait une regression silencieuse : au-dela de deux puissance cinquante-trois, un `float` ne
 * distingue plus un entier de son voisin, et tout le pipeline de pillage existe precisement pour
 * convertir **une seule fois** la frontiere vivante en unites entieres puis rester exact.
 *
 * ## L'ecart se constate, il ne se recupere pas
 *
 * `shortfall()` est ce que l'attaquant n'aura pas eu. Il existe pour l'audit et pour le rapport,
 * **jamais pour etre preleve ailleurs** — ni sur une autre ressource, ni sur une autre planete, ni
 * sur un combat suivant. Le compenser reviendrait a nier la decision.
 *
 * ## Ce que cette classe ne fait pas
 *
 * Elle ne lit aucun modele, aucun registre courant, aucune horloge et n'ecrit aucun journal. Le
 * potentiel vient des faits geles, le restant d'une lecture sous verrou ; elle ne fait que les
 * confronter. Lui faire lire quoi que ce soit rendrait le reglement dependant du moment ou il
 * s'execute.
 */
final readonly class LootSettlement
{
    /**
     * @param ExactLootAmounts $potential Ce que le combat avait gele, depuis la photographie.
     * @param ExactLootAmounts $remaining Ce que la cible portait encore, relu sous verrou.
     * @param ExactLootAmounts $applied Ce qui est effectivement pris.
     */
    private function __construct(
        public ExactLootAmounts $potential,
        public ExactLootAmounts $remaining,
        public ExactLootAmounts $applied,
    ) {
    }

    /**
     * Le reglement de ce potentiel contre ce restant.
     */
    public static function of(ExactLootAmounts $potential, ExactLootAmounts $remaining): self
    {
        return new self($potential, $remaining, $potential->cappedBy($remaining));
    }

    /**
     * Ce que l'attaquant n'aura pas eu, composante par composante.
     *
     * Nul quand la cible avait tout ce qu'elle devait. **Pour l'audit et le rapport uniquement.**
     */
    public function shortfall(): ExactLootAmounts
    {
        return $this->applied->shortfallTowards($this->potential);
    }

    /**
     * Si la cible avait de quoi payer entierement.
     *
     * Sert au rapport : distinguer « le butin annonce a ete pris » de « la cible n'avait plus tout »
     * evite qu'un joueur croie a une erreur de calcul.
     */
    public function wasPaidInFull(): bool
    {
        return $this->shortfall()->isNothing();
    }
}
