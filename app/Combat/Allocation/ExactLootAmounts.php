<?php

namespace OGame\Combat\Allocation;

use InvalidArgumentException;
use OGame\Combat\Exceptions\CorruptedFrozenLootAmounts;

/**
 * Trois montants de butin, en unites entieres exactes.
 *
 * ## Pourquoi ce type existe
 *
 * Ce qu'il porte est **debite au defenseur, charge dans des soutes et ecrit dans un rapport**, et
 * ces trois nombres doivent etre le meme, a l'unite pres. Au-dela de deux puissance cinquante-trois,
 * un `float` ne distingue plus un entier de son voisin : un stock de metal peut atteindre cet ordre
 * de grandeur sur un serveur ancien, et le butin annonce ne serait alors pas celui debite.
 *
 * Tout le pipeline de pillage a ete construit pour convertir **une seule fois** la frontiere vivante
 * en unites entieres, puis rester exact. `LootEnvelope` appartient au chantier exploratoire de
 * reservation, non raccorde, et porte des flottants ; elle n'a pas sa place ici — et si ce chantier
 * revenait un jour, il devrait lui aussi franchir une frontiere entiere exacte.
 *
 * ## Pourquoi une fabrique, et non un constructeur typé
 *
 * `public function __construct(int $metal, ...)` **ne refuse pas les flottants**. Aucun fichier du
 * depot ne declare `strict_types`, et cette regle se decide au **site d'appel** : en mode coercitif,
 * `1.0`, `'1'` et `true` traversent la frontiere et deviennent `1`. Pire, `1.5` la traverse aussi —
 * avec un simple avertissement de perte de precision, que rien n'arrete.
 *
 * La promesse « aucun flottant a la frontiere publique » etait donc fausse tant qu'elle reposait sur
 * la signature. Le constructeur est prive ; `of()` prend des `mixed` et verifie `is_int()`.
 *
 * ## Ce que ce type refuse
 *
 * Tout ce qui n'est pas un entier — flottant, chaine numerique, booleen, nul, tableau, objet — et
 * tout entier negatif : un butin negatif rendrait des ressources au defenseur au lieu de lui en
 * prendre.
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
     * Les trois clefs, et rien d'autre.
     *
     * @var array<int, string>
     */
    private const array KEYS = ['metal', 'crystal', 'deuterium'];

    /**
     * **Prive.** Passer par `of()` : c'est elle qui refuse ce qu'une signature `int` laisserait
     * convertir.
     */
    private function __construct(
        public int $metal,
        public int $crystal,
        public int $deuterium,
    ) {
    }

    /**
     * Trois montants entiers, verifies un par un.
     *
     * @param mixed $metal
     * @param mixed $crystal
     * @param mixed $deuterium
     *
     * @throws InvalidArgumentException Si l'un n'est pas un entier, ou s'il est negatif.
     */
    public static function of(mixed $metal, mixed $crystal, mixed $deuterium): self
    {
        $verifies = [];

        foreach (array_combine(self::KEYS, [$metal, $crystal, $deuterium]) as $nom => $montant) {
            if (!is_int($montant)) {
                throw new InvalidArgumentException(
                    'Le montant « ' . $nom . ' » doit etre un entier, et « ' . get_debug_type($montant)
                    . ' » n en est pas un. Une signature typee l aurait converti en silence : le mode '
                    . 'coercitif de PHP accepte 1.0, la chaine « 1 » et true, et laisse meme passer 1.5 '
                    . 'avec un simple avertissement de perte de precision.'
                );
            }

            if ($montant < 0) {
                throw new InvalidArgumentException(
                    'Le montant « ' . $nom . ' » ne peut pas etre negatif : un butin negatif rendrait '
                    . 'des ressources au defenseur au lieu de lui en prendre.'
                );
            }

            $verifies[] = $montant;
        }

        return new self(...$verifies);
    }

    /**
     * Aucun butin.
     */
    public static function nothing(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * Les montants tels qu'ils ont ete persistes.
     *
     * **Jamais d'hydratation coercitive.** Une chaine numerique lue depuis une colonne, un flottant
     * rendu par un pilote de base : les accepter en les convertissant ferait dependre le butin du
     * pilote plutot que de ce qui a ete ecrit.
     *
     * @param mixed $stored
     *
     * @throws CorruptedFrozenLootAmounts Si la structure lue n'est pas celle qui a ete ecrite.
     */
    public static function fromStorage(mixed $stored): self
    {
        if (!is_array($stored)) {
            throw new CorruptedFrozenLootAmounts('les montants ne forment pas une structure', $stored);
        }

        $inconnues = array_diff(array_keys($stored), self::KEYS);

        if ($inconnues !== []) {
            throw new CorruptedFrozenLootAmounts(
                'la structure porte des clefs inconnues (' . implode(', ', $inconnues) . ')',
                $stored
            );
        }

        $montants = [];

        foreach (self::KEYS as $clef) {
            if (!array_key_exists($clef, $stored)) {
                throw new CorruptedFrozenLootAmounts('la clef « ' . $clef . ' » manque', $stored);
            }

            $valeur = $stored[$clef];

            if (!is_int($valeur)) {
                throw new CorruptedFrozenLootAmounts(
                    'le montant « ' . $clef . ' » est un ' . get_debug_type($valeur) . ' et non un entier',
                    $stored
                );
            }

            if ($valeur < 0) {
                throw new CorruptedFrozenLootAmounts(
                    'le montant « ' . $clef . ' » est negatif',
                    $stored
                );
            }

            $montants[] = $valeur;
        }

        return new self(...$montants);
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
     * Les colonnes qui la recevront doivent porter des entiers sans conversion flottante.
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
