<?php

namespace OGame\Combat\Allocation;

use InvalidArgumentException;
use OGame\Combat\Exceptions\UnknownLootAllocatorVersion;

/**
 * Les regles de pillage connues, et celle qui sert aux nouveaux combats.
 *
 * ## Pourquoi un registre, et pas une constante
 *
 * Persister `exact_loot_pipeline_v1` ne protege un ancien combat que si cette version **existe
 * encore** le jour ou une v2 arrive. Comparer la version persistee a la version courante ne
 * protege rien : le jour ou la constante change, tous les combats en cours deviennent illisibles.
 *
 * Le registre separe donc deux choses que le mot « version » confondait :
 *
 * - **la version par defaut** : celle qui sera inscrite sur les nouveaux combats ;
 * - **les implementations reconnues** : celles qu'on sait encore appliquer.
 *
 * Changer la premiere ne touche pas une instance existante ; retirer une entree de la seconde est
 * une decision explicite, qui rend d'un coup illisibles les combats qui s'en reclamaient.
 *
 * ## Pourquoi immuable
 *
 * Un registre global modifiable permettrait a un test de « faire devenir v2 la version courante »,
 * et le suivant heriterait de cet etat. Ici, un test construit **son** registre — deux versions,
 * v2 par defaut — et demande explicitement la v1 : la demonstration ne depend d'aucune constante
 * globale et ne laisse aucune trace.
 */
final readonly class LootAllocatorRegistry
{
    /**
     * @param array<string, LootAllocator> $allocators Les implementations reconnues, par version.
     * @param string $defaultVersion La version inscrite sur les nouveaux combats.
     */
    private function __construct(
        private array $allocators,
        private string $defaultVersion,
    ) {
    }

    /**
     * Le registre du jeu.
     *
     * @return self
     */
    public static function default(): self
    {
        return self::of([new ExactLootAllocationV1()], ExactLootAllocationV1::VERSION);
    }

    /**
     * Un registre construit sur mesure.
     *
     * @param array<int, LootAllocator> $allocators
     * @param string $defaultVersion
     * @return self
     */
    public static function of(array $allocators, string $defaultVersion): self
    {
        $connues = [];

        foreach ($allocators as $allocator) {
            $version = $allocator->version();

            if (isset($connues[$version])) {
                throw new InvalidArgumentException(
                    'Deux implementations se reclament de la version « ' . $version . ' » : une version ne peut '
                    . 'designer qu une seule formule, sans quoi elle ne dit plus rien de ce qui a ete calcule.'
                );
            }

            $connues[$version] = $allocator;
        }

        if (!isset($connues[$defaultVersion])) {
            throw new InvalidArgumentException(
                'La version par defaut « ' . $defaultVersion . ' » ne figure pas parmi les implementations '
                . 'reconnues : les nouveaux combats se reclameraient d une regle que rien ne sait appliquer.'
            );
        }

        return new self($connues, $defaultVersion);
    }

    /**
     * L'implementation qui servira aux nouveaux combats.
     *
     * @return LootAllocator
     */
    public function current(): LootAllocator
    {
        return $this->allocators[$this->defaultVersion];
    }

    /**
     * La version inscrite sur les nouveaux combats.
     *
     * @return string
     */
    public function currentVersion(): string
    {
        return $this->defaultVersion;
    }

    /**
     * L'implementation d'une version persistee.
     *
     * **Jamais de repli sur la version courante.** Un combat calcule sous une regle doit etre
     * rejoue sous cette regle-la ; lui en appliquer une autre changerait son resultat sans que
     * personne ne l'ait demande.
     *
     * @param string $version
     * @return LootAllocator
     */
    public function forVersion(string $version): LootAllocator
    {
        return $this->allocators[$version]
            ?? throw UnknownLootAllocatorVersion::because($version, array_keys($this->allocators));
    }

    /**
     * Les versions que ce registre sait appliquer.
     *
     * @return array<int, string>
     */
    public function knownVersions(): array
    {
        return array_keys($this->allocators);
    }
}
