<?php

namespace OGame\Highscore;

/**
 * La reference telle qu une page la consulte : couverte ou non, son instant, et les rangs des seuls
 * sujets affiches.
 *
 * Une lecture ne modifie jamais la reference — c est une exigence de Keven, et c est structurel ici :
 * cet objet n a aucun ecrivain.
 */
final class RankReferenceView
{
    /**
     * @param bool $covered La categorie a-t-elle deja recu une reference publiee.
     * @param int|null $publishedAt Instant reel de cette publication.
     * @param array<int, int> $ranks Rang de reference, par identifiant de sujet.
     */
    private function __construct(
        public readonly bool $covered,
        public readonly int|null $publishedAt,
        private readonly array $ranks,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, null, []);
    }

    /**
     * @param array<int, int> $ranks
     */
    public static function published(int $publishedAt, array $ranks): self
    {
        return new self(true, $publishedAt, $ranks);
    }

    /**
     * Le mouvement d un sujet, decide ici pour que les deux classements — joueurs et alliances — ne
     * puissent pas en donner deux lectures differentes.
     *
     * Un rang courant absent ou nul ne produit aucun mouvement : c est une ligne qui n est pas
     * classee (ligne de faction, compte exclu), et Keven a demande qu elle n en porte pas.
     */
    public function movementOf(int $subjectId, int|null $currentRank): RankMovement|null
    {
        if ($currentRank === null || $currentRank < 1) {
            return null;
        }

        if (!$this->covered || $this->publishedAt === null) {
            return RankMovement::unavailable();
        }

        if (!isset($this->ranks[$subjectId])) {
            return RankMovement::newEntry($this->publishedAt);
        }

        return RankMovement::between($this->ranks[$subjectId], $currentRank, $this->publishedAt);
    }
}
