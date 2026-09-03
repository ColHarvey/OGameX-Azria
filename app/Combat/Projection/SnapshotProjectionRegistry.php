<?php

namespace OGame\Combat\Projection;

use InvalidArgumentException;
use OGame\Combat\Exceptions\UnknownSnapshotProjection;

/**
 * Les projections connues, et celle qui sert aux nouveaux combats.
 *
 * ## Le meme regime que les quatre autres regles
 *
 * La projection etait portee par une constante de classe et une liste de versions connues. Cela
 * suffisait a refuser une version inconnue, mais laissait un **second mecanisme de gel** a cote de
 * `FrozenCombatVersionSet` : deux facons de choisir une version, deux facons de la relire, deux
 * facons de la faire entrer dans une empreinte.
 *
 * Or une projection modifie la photographie, donc l'idempotence, donc le resultat persistant du
 * combat. Elle merite exactement le meme traitement que l'ordre causal ou l'allocateur de butin —
 * ni plus, ni moins.
 *
 * ## Pourquoi immuable
 *
 * Un registre global modifiable permettrait a un essai de faire devenir v2 la version courante, et
 * le suivant heriterait de cet etat. Ici, un essai construit **son** registre et demande
 * explicitement la version qu'il veut : la demonstration ne laisse aucune trace.
 */
final readonly class SnapshotProjectionRegistry
{
    /**
     * @param array<string, SnapshotProjectionRule> $rules Les projections reconnues, par version.
     * @param string $defaultVersion La version inscrite sur les nouveaux combats.
     */
    private function __construct(
        private array $rules,
        private string $defaultVersion,
    ) {
    }

    /**
     * Le registre du jeu.
     */
    public static function default(): self
    {
        return self::of([new SnapshotProjectionV1()], SnapshotProjectionV1::VERSION);
    }

    /**
     * Un registre construit sur mesure.
     *
     * @param array<int, SnapshotProjectionRule> $rules
     * @param string $defaultVersion
     * @return self
     */
    public static function of(array $rules, string $defaultVersion): self
    {
        $connues = [];

        foreach ($rules as $rule) {
            $version = $rule->version();

            if (isset($connues[$version])) {
                throw new InvalidArgumentException(
                    'Deux projections se reclament de la version « ' . $version . ' » : une version ne peut '
                    . 'designer qu une seule facon de lire une photographie.'
                );
            }

            $connues[$version] = $rule;
        }

        if (!isset($connues[$defaultVersion])) {
            throw new InvalidArgumentException(
                'La version par defaut « ' . $defaultVersion . ' » ne figure pas parmi les projections '
                . 'reconnues : les nouveaux combats se reclameraient d une lecture que rien ne sait faire.'
            );
        }

        return new self($connues, $defaultVersion);
    }

    /**
     * La projection qui servira aux nouveaux combats.
     */
    public function current(): SnapshotProjectionRule
    {
        return $this->rules[$this->defaultVersion];
    }

    /**
     * La version inscrite sur les nouveaux combats.
     */
    public function currentVersion(): string
    {
        return $this->defaultVersion;
    }

    /**
     * La projection d'une version persistee.
     *
     * **Jamais de repli sur la version courante.** Une photographie ecrite sous une projection doit
     * etre relue sous celle-la ; en appliquer une autre donnerait une lecture plausible et fausse,
     * et personne ne le verrait. Les projections se ressemblent d'une version a l'autre : c'est
     * precisement ce qui rend le repli dangereux.
     */
    public function forVersion(string $version): SnapshotProjectionRule
    {
        return $this->rules[$version]
            ?? throw new UnknownSnapshotProjection($version, $this->knownVersions());
    }

    /**
     * Les versions que ce registre sait lire.
     *
     * @return array<int, string>
     */
    public function knownVersions(): array
    {
        return array_keys($this->rules);
    }
}
