<?php

namespace OGame\Highscore;

use InvalidArgumentException;

/**
 * Ce qu une ligne du classement dit de son mouvement, et rien de plus.
 *
 * Cinq etats, exclusifs :
 *
 * - `up` / `down` : le sujet etait deja dans la reference et son rang a change. `places` porte le
 *   nombre de places, toujours positif ; le sens est dans l etat.
 * - `stable` : il y etait, au meme rang.
 * - `new` : la reference existe pour cette categorie, et il n y figurait pas. Une vraie entree neuve.
 * - `unavailable` : **aucune reference n a encore ete publiee pour cette categorie**. C est un aveu
 *   d ignorance, pas un mouvement nul — et surtout pas une entree neuve : un classement entier
 *   d « Nouv. » le premier jour serait faux.
 *
 * La variation se lit sur les rangs PUBLIES : `reference - courant`. Un ancien rang 5 devenu 3 rend
 * `up` et deux places. Les regles d exclusion et de departage n ont pas a etre rejouees ici, elles
 * sont deja dans le rang que la tache a ecrit.
 */
final class RankMovement
{
    public const STATE_UP = 'up';
    public const STATE_DOWN = 'down';
    public const STATE_STABLE = 'stable';
    public const STATE_NEW = 'new';
    public const STATE_UNAVAILABLE = 'unavailable';

    /**
     * @param string $state Un des cinq etats ci-dessus.
     * @param int $places Nombre de places, toujours positif ou nul. Nul hors `up` et `down`.
     * @param int|null $referenceAt Instant de la reference, nul seulement quand elle n existe pas.
     */
    private function __construct(
        public readonly string $state,
        public readonly int $places,
        public readonly int|null $referenceAt,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(self::STATE_UNAVAILABLE, 0, null);
    }

    public static function newEntry(int $referenceAt): self
    {
        return new self(self::STATE_NEW, 0, self::anInstant($referenceAt));
    }

    /**
     * Le mouvement entre deux rangs publies.
     *
     * Les deux rangs sont exiges strictement positifs : un rang nul est la marque d un sujet hors
     * classement (compte systeme, PNJ, administrateur cache), et une variation calculee dessus serait
     * une invention. Les appelants filtrent deja, cette garde ferme le cas ou l un d eux oublierait.
     */
    public static function between(int $referenceRank, int $currentRank, int $referenceAt): self
    {
        if ($referenceRank < 1 || $currentRank < 1) {
            throw new InvalidArgumentException('Un mouvement se calcule entre deux rangs classes.');
        }

        $variation = $referenceRank - $currentRank;

        if ($variation > 0) {
            return new self(self::STATE_UP, $variation, self::anInstant($referenceAt));
        }

        if ($variation < 0) {
            return new self(self::STATE_DOWN, -$variation, self::anInstant($referenceAt));
        }

        return new self(self::STATE_STABLE, 0, self::anInstant($referenceAt));
    }

    /**
     * La forme que la vue consomme. Un tableau, pas l objet : la page est mise en cache cinq minutes,
     * et un cache doit porter des donnees, pas des instances.
     *
     * @return array{state: string, places: int, reference_at: int|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'places' => $this->places,
            'reference_at' => $this->referenceAt,
        ];
    }

    private static function anInstant(int $referenceAt): int
    {
        if ($referenceAt < 1) {
            throw new InvalidArgumentException('Une reference publiee porte un instant.');
        }

        return $referenceAt;
    }
}
