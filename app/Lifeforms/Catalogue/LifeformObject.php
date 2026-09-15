<?php

namespace OGame\Lifeforms\Catalogue;

use InvalidArgumentException;
use OGame\Lifeforms\Species;

/**
 * Une fiche du catalogue des formes de vie : un batiment ou une technologie d une espece.
 *
 * L identifiant est **l identifiant officiel** (11101 pour le Secteur residentiel, 14218 pour la
 * derniere technologie Kaelesh). Ces identifiants ne recoupent aucun objet du jeu (`ObjectService`
 * s arrete a 503) : le catalogue des formes de vie est un catalogue a part, et il le reste.
 *
 * Les couts et durees suivent les formules de `LifeformFormulas` ; ici ne vivent que les bases et
 * les facteurs, tels que le fichier maitre les donne.
 *
 * `populationBase` et `populationFactor` ne sont poses que sur les deux batiments de palier de
 * chaque espece : la population que la planete doit porter pour construire un niveau.
 *
 * @param array<int, int> $requirements niveau exige par identifiant de batiment de la meme espece
 * @param array<int, LifeformBonus> $bonuses
 */
final readonly class LifeformObject
{
    public function __construct(
        public int $id,
        public Species $species,
        public LifeformKind $kind,
        public int $index,
        public string $machineName,
        public int $metal,
        public int $crystal,
        public int $deuterium,
        public int $energy,
        public float $costFactor,
        public float $energyFactor,
        public int $durationBase,
        public float $durationFactor,
        public array $requirements,
        public float|null $populationBase,
        public float|null $populationFactor,
        public array $bonuses,
    ) {
        $attendu = $species->idPrefix() * 1000 + $kind->idDigit() * 100 + $index;
        if ($id !== $attendu) {
            throw new InvalidArgumentException("Identifiant $id incoherent avec l espece, le genre et l index ($attendu attendu).");
        }
        if ($kind === LifeformKind::Building && ($index < 1 || $index > 12)) {
            throw new InvalidArgumentException("Un batiment porte un index de 1 a 12, pas $index.");
        }
        if ($kind === LifeformKind::Technology && ($index < 1 || $index > 18)) {
            throw new InvalidArgumentException("Une technologie porte un index de 1 a 18, pas $index.");
        }
        if (($populationBase === null) !== ($populationFactor === null)) {
            throw new InvalidArgumentException("La population exigee de $id demande une base et un facteur, ou ni l un ni l autre.");
        }
    }

    /**
     * Le palier d une technologie : 1 (index 1 a 6), 2 (7 a 12) ou 3 (13 a 18).
     */
    public function tier(): int
    {
        if ($this->kind !== LifeformKind::Technology) {
            throw new InvalidArgumentException("Un batiment n a pas de palier ($this->machineName).");
        }

        return intdiv($this->index - 1, 6) + 1;
    }

    /**
     * L effet portant ce code (et cette cible), ou null.
     */
    public function bonus(string $code, string|null $target = null): LifeformBonus|null
    {
        foreach ($this->bonuses as $bonus) {
            if ($bonus->code === $code && $bonus->target === $target) {
                return $bonus;
            }
        }

        return null;
    }

    /**
     * Le batiment demande-t-il une population minimale pour etre construit ?
     */
    public function requiresPopulation(): bool
    {
        return $this->populationBase !== null;
    }
}
