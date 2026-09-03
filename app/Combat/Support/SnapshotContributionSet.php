<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Exceptions\CorruptedSnapshotInclusion;

/**
 * Ce qu'un evenement apporte a une photographie : un ensemble, jamais une valeur seule.
 *
 * ## Pourquoi un ensemble
 *
 * Les contributions **se cumulent**. Un retour charge apporte des vaisseaux *et* une cargaison ;
 * l'etat d'une cible apporte son solde, ses defenses *et* sa garnison. Une colonne a valeur unique
 * aurait force a ecrire ces evenements en plusieurs lignes — et la ligne serait alors devenue
 * l'unite d'unicite a la place de l'evenement.
 *
 * ## Pourquoi canonique
 *
 * Trie, sans doublon, non vide. Ces trois exigences ne sont pas de l'esthetique :
 *
 *     trie          deux ecritures du meme fait doivent produire le meme JSON, sinon le rejeu
 *                   ne reconnait pas ce qu'il a deja ecrit
 *     sans doublon  une contribution comptee deux fois compterait ses unites deux fois
 *     non vide      un evenement qui n'apporte rien n'a rien a faire dans une photographie
 *
 * ## Ce que cet objet ne juge pas
 *
 * Il ne verifie pas qu'une contribution est **permise pour sa provenance** — c'est le role de
 * `SnapshotContribution::allowedFor()`, et le dupliquer ici en ferait deux regles qui finiraient par
 * differer.
 */
final readonly class SnapshotContributionSet
{
    /**
     * @param array<int, SnapshotContribution> $contributions Triees, sans doublon, non vides.
     */
    private function __construct(
        private array $contributions,
    ) {
    }

    /**
     * L'ensemble canonique de ces contributions.
     *
     * @param array<int, SnapshotContribution> $contributions
     *
     * @throws CorruptedSnapshotInclusion Si l'ensemble est vide ou porte un doublon.
     */
    public static function of(array $contributions): self
    {
        if ($contributions === []) {
            throw new CorruptedSnapshotInclusion(
                'un evenement qui n apporte rien n a rien a faire dans une photographie',
                []
            );
        }

        $vues = [];

        foreach ($contributions as $contribution) {
            $valeur = $contribution->value;

            if (isset($vues[$valeur])) {
                // Un doublon ne se corrige pas en silence : il signale que l'appelant a compte deux
                // fois, et ses unites le seraient aussi.
                throw new CorruptedSnapshotInclusion(
                    'la contribution « ' . $valeur . ' » apparait deux fois',
                    array_map(static fn (SnapshotContribution $c): string => $c->value, $contributions)
                );
            }

            $vues[$valeur] = $contribution;
        }

        ksort($vues);

        return new self(array_values($vues));
    }

    /**
     * L'ensemble d'une seule contribution.
     */
    public static function ofOne(SnapshotContribution $contribution): self
    {
        return self::of([$contribution]);
    }

    /**
     * L'ensemble tel qu'il a ete persiste.
     *
     * @param mixed $stored La colonne JSON, telle que le modele la rend.
     *
     * @throws CorruptedSnapshotInclusion Si la structure lue n'est pas celle qui a ete ecrite.
     */
    public static function fromStorage(mixed $stored): self
    {
        if (!is_array($stored) || !array_is_list($stored)) {
            throw new CorruptedSnapshotInclusion('les contributions ne forment pas une liste', $stored);
        }

        $contributions = [];

        foreach ($stored as $valeur) {
            if (!is_string($valeur)) {
                throw new CorruptedSnapshotInclusion('une contribution n est pas une chaine', $stored);
            }

            $contribution = SnapshotContribution::tryFrom($valeur);

            if ($contribution === null) {
                throw new CorruptedSnapshotInclusion(
                    'la contribution « ' . $valeur . ' » n existe pas',
                    $stored
                );
            }

            $contributions[] = $contribution;
        }

        $canonique = self::of($contributions);

        // **La forme lue doit deja etre canonique.** Une permutation acceptee en silence rendrait
        // deux ecritures du meme fait indistinguables a l'oeil et differentes a l'octet.
        if ($canonique->toStorage() !== $stored) {
            throw new CorruptedSnapshotInclusion('les contributions ne sont pas dans l ordre canonique', $stored);
        }

        return $canonique;
    }

    /**
     * Ce qu'il faut ecrire avec l'inclusion.
     *
     * @return array<int, string>
     */
    public function toStorage(): array
    {
        return array_map(
            static fn (SnapshotContribution $contribution): string => $contribution->value,
            $this->contributions
        );
    }

    /**
     * Les contributions, dans l'ordre canonique.
     *
     * @return array<int, SnapshotContribution>
     */
    public function all(): array
    {
        return $this->contributions;
    }

    /**
     * Si cet ensemble est exactement l'autre.
     */
    public function equals(self $other): bool
    {
        return $this->toStorage() === $other->toStorage();
    }

    /**
     * Si cet ensemble apporte au moins une flotte qui se battra.
     */
    public function bringsAFightingFleet(): bool
    {
        foreach ($this->contributions as $contribution) {
            if ($contribution->isFightingFleet()) {
                return true;
            }
        }

        return false;
    }
}
