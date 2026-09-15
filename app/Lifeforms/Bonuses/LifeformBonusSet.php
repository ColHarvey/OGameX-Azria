<?php

namespace OGame\Lifeforms\Bonuses;

/**
 * Un jeu de bonus de formes de vie resolus : une fraction par effet (et par cible quand l effet en a une).
 *
 * `fraction('metal_production')` rend 0,2 pour +20 % ; `fraction('ship_stats', 'cruiser')` la part qui vise
 * le croiseur seul. `multiplier()` et `reduction()` additionnent la part generale et la part ciblee d un
 * meme effet : c est ainsi que « −1 % sur toute recherche » et « −2 % sur l Espionnage » se cumulent.
 *
 * **Neutre a zero** : un jeu vide rend 0, 1,0 et 0 — les points d application ne testent rien de plus.
 */
final readonly class LifeformBonusSet
{
    /**
     * @param array<string, float> $fractions clef `code` ou `code:cible`, valeur en fraction
     */
    public function __construct(private array $fractions)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function key(string $code, string|null $target): string
    {
        return $target === null ? $code : $code . ':' . $target;
    }

    public function fraction(string $code, string|null $target = null): float
    {
        return $this->fractions[self::key($code, $target)] ?? 0.0;
    }

    /**
     * 1 + (part generale + part ciblee).
     */
    public function multiplier(string $code, string|null $target = null): float
    {
        return 1.0 + max(0.0, $this->fraction($code) + ($target === null ? 0.0 : $this->fraction($code, $target)));
    }

    /**
     * La reduction a appliquer (part generale + part ciblee), jamais au-dela de 99 %.
     */
    public function reduction(string $code, string|null $target = null): float
    {
        return min(0.99, max(0.0, $this->fraction($code) + ($target === null ? 0.0 : $this->fraction($code, $target))));
    }

    public function isEmpty(): bool
    {
        return $this->fractions === [];
    }

    /**
     * @return array<string, float>
     */
    public function all(): array
    {
        return $this->fractions;
    }

    /**
     * L addition de deux jeux, clef par clef (les plafonds ont deja ete appliques par le resolveur).
     */
    public function merge(self $other): self
    {
        $somme = $this->fractions;
        foreach ($other->fractions as $clef => $part) {
            $somme[$clef] = ($somme[$clef] ?? 0.0) + $part;
        }

        return new self($somme);
    }
}
