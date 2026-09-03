<?php

namespace OGame\Combat\Allocation;

use OGame\Combat\Support\LootEnvelope;

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
 *   l'ouverture. Un combat de deux heures produit des ressources ; elles appartiennent au defenseur,
 *   et le butin a ete calcule sans elles ;
 * - **le plafond par le restant** laisse le defenseur sauver ce qu'il a eu le temps de depenser.
 *   C'est une decision de jeu, pas une precaution technique : elle recompense la reactivite plutot
 *   que de punir l'inattention.
 *
 * C'est ce qui distingue cette regle d'un compromis. Une reservation aurait ferme le second abus en
 * supprimant le premier choix ; un butin non plafonne aurait fait l'inverse.
 *
 * ## Composante par composante, jamais en total
 *
 * Metal, cristal et deuterium ne sont pas interchangeables. Un defenseur peut avoir vide son metal
 * en gardant son deuterium : un minimum calcule sur la somme autoriserait a prendre le deuterium en
 * echange du metal manquant.
 *
 * ## L'ecart se constate, il ne se recupere pas
 *
 * `shortfall()` est ce que l'attaquant n'aura pas eu — la difference entre le dû et le pris. Il
 * existe pour l'audit et pour le rapport, **jamais pour etre preleve ailleurs**. Le compenser sur
 * une autre ressource, sur une autre planete ou sur un combat suivant reviendrait a nier la
 * decision.
 */
final readonly class LootSettlement
{
    /**
     * @param LootEnvelope $potential Ce que le combat avait gele, depuis la photographie.
     * @param LootEnvelope $remaining Ce que la cible portait encore, relu sous verrou.
     * @param LootEnvelope $applied Ce qui est effectivement pris.
     */
    private function __construct(
        public LootEnvelope $potential,
        public LootEnvelope $remaining,
        public LootEnvelope $applied,
    ) {
    }

    /**
     * Le reglement de ce potentiel contre ce restant.
     *
     * **Aucune des deux bornes n'est recalculee ici.** Le potentiel vient des faits geles, le
     * restant d'une lecture sous verrou ; cette classe ne fait que les confronter. Lui faire lire
     * quoi que ce soit rendrait le reglement dependant du moment ou il s'execute.
     */
    public static function of(LootEnvelope $potential, LootEnvelope $remaining): self
    {
        $applique = new LootEnvelope(
            min($potential->metal, $remaining->metal),
            min($potential->crystal, $remaining->crystal),
            min($potential->deuterium, $remaining->deuterium),
        );

        return new self($potential, $remaining, $applique);
    }

    /**
     * Ce que l'attaquant n'aura pas eu, composante par composante.
     *
     * Nul quand la cible avait tout ce qu'elle devait. **Pour l'audit et le rapport uniquement** :
     * un manque ne se prend pas ailleurs.
     */
    public function shortfall(): LootEnvelope
    {
        return new LootEnvelope(
            $this->potential->metal - $this->applied->metal,
            $this->potential->crystal - $this->applied->crystal,
            $this->potential->deuterium - $this->applied->deuterium,
        );
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
