<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;

/**
 * Une borne de butin, ressource par ressource.
 *
 * ⚠ **MECANISME EXPLORATOIRE — INACTIF EN PREMIERE VERSION.**
 *
 * Cette classe appartient au chantier de reservation, qui n'a aucun ecrivain ni lecteur dans le
 * chemin de jeu. Le reglement actif du butin passe par `ExactLootAmounts`, en **entiers exacts**.
 *
 * **Ses flottants ne sont pas anodins, meme dormants.** Une borne de reservation trop haute
 * immobiliserait une unite de trop ; trop basse, elle en protegerait une de moins. Si ce chantier
 * revenait un jour, il devrait lui aussi franchir une frontiere entiere exacte — l'approximation
 * n'est pas plus acceptable pour une reservation que pour un debit.
 *
 * **Un vecteur, jamais un total.** Metal, cristal et deuterium ne sont pas interchangeables : un
 * defenseur peut avoir vide son metal tout en gardant son deuterium, et une borne globale
 * autoriserait alors a prendre le deuterium en echange du metal manquant. Chaque composante a sa
 * propre limite, et le reglage les verifie une par une.
 *
 * **Immuable, et c'est indispensable.** `OGame\Models\Resources` existe deja, mais sa methode
 * `add()` modifie l'objet sur place : un appelant pourrait relever la borne d'une reservation
 * pourtant scellee. Ici, chaque operation rend une nouvelle valeur.
 *
 * Les montants sont des flottants, comme les colonnes de ressources du jeu depuis leur passage en
 * `double`. Ils ne peuvent etre ni negatifs, ni infinis, ni indefinis : une borne qui ne serait
 * pas un nombre fini rendrait toute comparaison ulterieure absurde, et le defaut ne se verrait
 * qu'au reglement.
 */
final readonly class LootEnvelope
{
    /**
     * @param float $metal
     * @param float $crystal
     * @param float $deuterium
     */
    public function __construct(
        public float $metal = 0.0,
        public float $crystal = 0.0,
        public float $deuterium = 0.0,
    ) {
        foreach (['metal' => $metal, 'cristal' => $crystal, 'deuterium' => $deuterium] as $nom => $montant) {
            if (!is_finite($montant)) {
                throw new InvalidArgumentException(
                    'Le ' . $nom . ' d une borne de butin doit etre un nombre fini : une valeur infinie ou indefinie rendrait toute comparaison ulterieure absurde.'
                );
            }

            if ($montant < 0.0) {
                throw new InvalidArgumentException(
                    'Le ' . $nom . ' d une borne de butin ne peut pas etre negatif : une borne negative autoriserait un butin negatif.'
                );
            }
        }
    }

    /**
     * Une borne nulle.
     */
    public static function nothing(): self
    {
        return new self();
    }

    /**
     * La plus grande des deux bornes, composante par composante.
     *
     * **Le maximum, pas la somme.** Une reservation ne s'accumule pas a chaque relecture : elle
     * retient la borne la plus haute qu'elle ait connue. Additionner ferait grossir la reserve a
     * chaque passage d'un worker, et immobiliserait des ressources sans raison.
     *
     * @param self $other
     * @return self
     */
    public function raisedTo(self $other): self
    {
        return new self(
            max($this->metal, $other->metal),
            max($this->crystal, $other->crystal),
            max($this->deuterium, $other->deuterium),
        );
    }

    /**
     * Si chaque composante de l'autre borne tient dans celle-ci.
     *
     * Composante par composante : un butin qui depasserait sur une seule ressource est refuse,
     * meme si son total reste inferieur.
     *
     * @param self $other
     * @return bool
     */
    public function covers(self $other): bool
    {
        return $other->metal <= $this->metal
            && $other->crystal <= $this->crystal
            && $other->deuterium <= $this->deuterium;
    }

    /**
     * Ce qui reste de cette borne une fois l'autre prelevee.
     *
     * @param self $taken
     * @return self
     */
    public function minus(self $taken): self
    {
        if (!$this->covers($taken)) {
            throw new InvalidArgumentException(
                'Impossible de prelever plus que la borne ne couvre : la reservation serait alors negative.'
            );
        }

        return new self(
            $this->metal - $taken->metal,
            $this->crystal - $taken->crystal,
            $this->deuterium - $taken->deuterium,
        );
    }

    /**
     * Si les deux bornes sont identiques sur les trois composantes.
     *
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->metal === $other->metal
            && $this->crystal === $other->crystal
            && $this->deuterium === $other->deuterium;
    }

    /**
     * Si la borne est nulle sur les trois composantes.
     */
    public function isNothing(): bool
    {
        return $this->metal === 0.0 && $this->crystal === 0.0 && $this->deuterium === 0.0;
    }
}
