<?php

namespace OGame\Combat\Allocation;

use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Models\Resources;

/**
 * L'allocateur de butin d'une operation, resolu depuis une version explicite.
 *
 * ## Le chemin de derive que cette facade ferme
 *
 * `LootService::distribute()` appelait `LootAllocatorRegistry::default()->current()` **au milieu du
 * calcul**. Chaque plafonnement d'une meme resolution redemandait donc « la version courante », et
 * un deploiement survenu entre deux appels aurait plafonne la premiere moitie d'une bataille sous
 * une regle et la seconde sous une autre. Personne n'aurait pu reproduire le resultat, et rien ne
 * l'aurait signale.
 *
 * La version se choisit desormais **une fois, au debut de l'operation**, et cet objet la transporte.
 * Un appelant ne peut plus plafonner sans dire sous quelle regle il le fait.
 *
 * ## Deux frontieres, et une seule est autoritaire pour un combat durable
 *
 * `fromFrozenSet()` sert le combat persistant : la version vient des faits ecrits avec l'instance,
 * a son ouverture.
 *
 * `atOperationStart()` sert le combat instantane, qui se resout a la seconde ou la flotte arrive.
 * Y lire la version courante est correct **parce que l'operation commence maintenant** — mais elle
 * est lue une fois, a l'entree, pas a chaque plafonnement. C'est la difference entre choisir une
 * regle et la subir.
 */
final readonly class FrozenLootAllocation
{
    private function __construct(
        public string $version,
        private LootAllocator $allocator,
    ) {
    }

    /**
     * L'allocateur d'un combat durable, resolu depuis ses faits geles.
     *
     * Une version que le registre ne connait plus leve : un rejeu vaut mieux arrete que menteur.
     */
    public static function fromFrozenSet(
        FrozenCombatVersionSet $versions,
        LootAllocatorRegistry|null $registry = null,
    ): self {
        $registre = $registry ?? LootAllocatorRegistry::default();

        return new self($versions->lootAllocator, $registre->forVersion($versions->lootAllocator));
    }

    /**
     * L'allocateur d'une operation instantanee, choisi a son entree.
     *
     * **La frontiere explicite du chemin instantane.** Elle existe pour que la lecture de la version
     * courante ait un endroit nomme, verifiable par la garde architecturale — au lieu d'etre dispersee
     * dans les services de calcul.
     */
    public static function atOperationStart(LootAllocatorRegistry|null $registry = null): self
    {
        $registre = $registry ?? LootAllocatorRegistry::default();

        return new self($registre->currentVersion(), $registre->current());
    }

    /**
     * Le plafonnement par capacite de fret, sous la version de cette operation.
     */
    public function capByCargo(
        Resources $loot,
        int $totalCargoCapacity,
        string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT,
        string $subject = '',
    ): CappedLoot {
        return $this->allocator->capByCargo($loot, $totalCargoCapacity, $phase, $subject);
    }

    /**
     * L'allocateur lui-meme, pour les usages qui en demandent plus qu'un plafonnement.
     */
    public function allocator(): LootAllocator
    {
        return $this->allocator;
    }
}
