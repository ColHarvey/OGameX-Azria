<?php

namespace OGame\Combat\Allocation;

use InvalidArgumentException;

/**
 * Trois montants de butin, en unites entieres exactes.
 *
 * ## Pourquoi ce type existe, alors que `LootEnvelope` semblait convenir
 *
 * `LootEnvelope` porte des flottants. Elle a ete ecrite pour la **reservation**, ou une borne
 * approchee ne fait perdre a personne une unite qui compte. Le reglement du butin n'a pas cette
 * indulgence : ce qu'il rend est **debite au defenseur, charge dans des soutes et ecrit dans un
 * rapport**, et ces trois nombres doivent etre le meme.
 *
 * Au-dela de deux puissance cinquante-trois, un `float` ne distingue plus un entier de son voisin.
 * Un stock de metal peut atteindre cet ordre de grandeur sur un serveur ancien : le butin
 * annoncerait alors un montant que le debit ne reproduirait pas, et l'ecart n'apparaitrait nulle
 * part.
 *
 * Tout le pipeline de pillage a ete construit pour convertir **une seule fois** la frontiere
 * vivante en unites entieres, puis rester exact. Rendre des flottants au reglement aurait defait ce
 * travail a son dernier maillon.
 *
 * ## Ce que ce type refuse
 *
 * Les valeurs negatives : un butin negatif rendrait des ressources au defenseur au lieu de lui en
 * prendre. Et sa frontiere publique n'accepte **aucun** flottant — c'est par la que la precision
 * s'etait perdue une premiere fois.
 *
 * ## Ce qu'il ne fait pas
 *
 * Il ne convertit rien. La conversion depuis un solde vivant appartient a `ResourceBoundary`, qui
 * rend ses diagnostics avec le montant ; les melanger ici reviendrait a remettre un `double`
 * normalise dans le domaine exact.
 */
final readonly class ExactLootAmounts
{
    /**
     * @param int $metal
     * @param int $crystal
     * @param int $deuterium
     *
     * @throws InvalidArgumentException Si l'une des composantes est negative. Ce n est pas une
     *         limite de plateforme — `UnrepresentableResourceAmount` dit cela — mais une faute
     *         d appelant : personne ne peut vouloir un butin negatif.
     */
    public function __construct(
        public int $metal = 0,
        public int $crystal = 0,
        public int $deuterium = 0,
    ) {
        foreach (['metal' => $metal, 'cristal' => $crystal, 'deuterium' => $deuterium] as $nom => $montant) {
            if ($montant < 0) {
                throw new InvalidArgumentException(
                    'Le ' . $nom . ' d un montant de butin ne peut pas etre negatif : un butin negatif '
                    . 'rendrait des ressources au defenseur au lieu de lui en prendre.'
                );
            }
        }
    }

    /**
     * Aucun butin.
     */
    public static function nothing(): self
    {
        return new self();
    }

    /**
     * Le plus petit des deux, composante par composante.
     *
     * **Jamais sur le total.** Metal, cristal et deuterium ne sont pas interchangeables : un
     * defenseur peut avoir vide son metal en gardant son deuterium, et un minimum calcule sur la
     * somme autoriserait a prendre le deuterium en echange du metal manquant.
     */
    public function cappedBy(self $ceiling): self
    {
        return new self(
            min($this->metal, $ceiling->metal),
            min($this->crystal, $ceiling->crystal),
            min($this->deuterium, $ceiling->deuterium),
        );
    }

    /**
     * Ce qui manque pour atteindre l'autre, composante par composante, jamais negatif.
     */
    public function shortfallTowards(self $target): self
    {
        return new self(
            max(0, $target->metal - $this->metal),
            max(0, $target->crystal - $this->crystal),
            max(0, $target->deuterium - $this->deuterium),
        );
    }

    /**
     * Si les trois composantes sont nulles.
     */
    public function isNothing(): bool
    {
        return $this->metal === 0 && $this->crystal === 0 && $this->deuterium === 0;
    }

    /**
     * Si ces montants sont exactement les autres.
     */
    public function equals(self $other): bool
    {
        return $this->metal === $other->metal
            && $this->crystal === $other->crystal
            && $this->deuterium === $other->deuterium;
    }

    /**
     * Ce qu'il faut ecrire, sous une forme comparable et persistable.
     *
     * @return array<string, int>
     */
    public function toStorage(): array
    {
        return [
            'metal' => $this->metal,
            'crystal' => $this->crystal,
            'deuterium' => $this->deuterium,
        ];
    }
}
