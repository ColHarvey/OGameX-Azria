<?php

namespace OGame\Combat\Policies;

use InvalidArgumentException;
use OGame\Combat\Exceptions\UnknownLootPolicyVersion;

/**
 * Les regles de taux connues, et celle qui sert aux nouveaux combats entre joueurs.
 *
 * Meme raisonnement que pour les regles de repartition : persister une version ne protege un
 * combat que si l'implementation correspondante reste disponible. Comparer la version persistee a
 * la version courante ne protege rien — le jour ou la courante change, tous les combats en cours
 * deviennent illisibles.
 *
 * **Immuable, et construit sur mesure par les tests.** Un registre global modifiable laisserait un
 * essai « faire devenir v2 la version courante » et le suivant en heriterait. Ici, chaque essai
 * construit son registre et demande explicitement la version qu'il veut.
 */
final readonly class LootPolicyRegistry
{
    /**
     * @param array<string, LootRateRule> $rules
     * @param string $defaultVersion
     */
    private function __construct(
        private array $rules,
        private string $defaultVersion,
    ) {
    }

    /**
     * Le registre du jeu.
     *
     * `cargo_weighted_v1` est la valeur par defaut : c'est la regle des combats entre joueurs, de
     * loin les plus nombreux. Les deux autres sont choisies par le selecteur, jamais heritees.
     *
     * @return self
     */
    public static function default(): self
    {
        return self::of(
            [new CargoWeightedV1(), new NpcBaseV1(), new NoLootV1()],
            CargoWeightedV1::VERSION
        );
    }

    /**
     * Un registre construit sur mesure.
     *
     * @param array<int, LootRateRule> $rules
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
                    'Deux regles se reclament de la version « ' . $version . ' » : une version ne peut designer '
                    . 'qu une seule formule, sans quoi elle ne dit plus rien de ce qui a ete calcule.'
                );
            }

            $connues[$version] = $rule;
        }

        if (!isset($connues[$defaultVersion])) {
            throw new InvalidArgumentException(
                'La version par defaut « ' . $defaultVersion . ' » ne figure pas parmi les regles reconnues : '
                . 'les nouveaux combats se reclameraient d une regle que rien ne sait appliquer.'
            );
        }

        return new self($connues, $defaultVersion);
    }

    /**
     * La regle qui servira aux nouveaux combats entre joueurs.
     *
     * @return LootRateRule
     */
    public function current(): LootRateRule
    {
        return $this->rules[$this->defaultVersion];
    }

    /**
     * La version inscrite sur les nouveaux combats entre joueurs.
     *
     * @return string
     */
    public function currentVersion(): string
    {
        return $this->defaultVersion;
    }

    /**
     * La regle d'une version persistee.
     *
     * **Jamais de repli sur la version courante.** Un combat calcule sous une regle doit etre relu
     * sous cette regle-la.
     *
     * @param string $version
     * @return LootRateRule
     */
    public function forVersion(string $version): LootRateRule
    {
        return $this->rules[$version]
            ?? throw UnknownLootPolicyVersion::because($version, array_keys($this->rules));
    }

    /**
     * Les versions que ce registre sait appliquer.
     *
     * @return array<int, string>
     */
    public function knownVersions(): array
    {
        return array_keys($this->rules);
    }
}
