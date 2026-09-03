<?php

namespace OGame\Combat\Support;

use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Exceptions\CorruptedRuleVersionSet;
use OGame\Combat\Exceptions\MismatchedRuleVersionSet;
use OGame\Combat\MoonDestruction\MoonDestructionRuleRegistry;
use OGame\Combat\Policies\LootPolicyRegistry;

/**
 * Les quatre versions de regle qui gouvernent un combat, choisies une fois et jamais relues.
 *
 * ## La frontiere autoritaire, et elle est unique
 *
 * Quatre mecanismes sont versionnes : l'ordre causal des evenements, l'allocateur de butin, la
 * politique de taux, et la regle de destruction de lune. Chacun peut evoluer entre l'ouverture d'un
 * combat et sa resolution, deux heures plus tard.
 *
 * **`chosenAtOpening()` est le seul endroit du chemin persistant qui a le droit de demander la
 * version courante.** Le nom le dit : ailleurs, il n'y a pas de « courante », il y a celle du
 * combat. Une garde architecturale verifie que personne d'autre n'appelle `current()` ni
 * `currentVersion()` sur ces registres.
 *
 * La distinction n'est pas theorique. Un service qui lirait « la version courante » au milieu d'une
 * resolution ferait deriver retroactivement un combat deja engage : la bataille aurait ete
 * photographiee sous une regle et reglee sous une autre, et personne ne pourrait reproduire le
 * resultat.
 *
 * ## Une version inconnue arrete le rejeu
 *
 * `forVersion()` leve deja sur chaque registre. C'est voulu : se rabattre sur la version courante
 * quand la version persistee a disparu produirait un resultat different de celui qui avait ete
 * calcule, sous le meme identifiant de combat. Mieux vaut un rejeu qui s'arrete qu'un rejeu qui
 * ment.
 *
 * ## Deux ensembles differents ne se comparent pas
 *
 * `ensureSameAs()` leve au lieu de rendre `false`. Comparer un combat V1 a un combat V2 n'a pas de
 * reponse juste : ce ne sont pas deux valeurs d'une meme chose, ce sont deux choses. Rendre `false`
 * laisserait un appelant conclure « ils different » et continuer.
 */
final readonly class CombatRuleVersionSet
{
    /**
     * Les quatre clefs, et rien d'autre.
     *
     * @var array<int, string>
     */
    private const array KEYS = ['causal_order', 'loot_allocator', 'loot_policy', 'moon_destruction'];

    /**
     * @param string $causalOrder La version de l'ordre causal des evenements.
     * @param string $lootAllocator La version de l'allocateur exact de butin.
     * @param string $lootPolicy La version de la politique de taux.
     * @param string $moonDestruction La version de la regle de destruction de lune.
     */
    private function __construct(
        public string $causalOrder,
        public string $lootAllocator,
        public string $lootPolicy,
        public string $moonDestruction,
    ) {
    }

    /**
     * Les versions courantes, choisies **a l'ouverture durable et nulle part ailleurs**.
     *
     * Les registres sont injectables pour que les essais puissent installer des versions factices
     * sans toucher a un etat global.
     */
    public static function chosenAtOpening(
        CausalEventOrderRegistry|null $causal = null,
        LootAllocatorRegistry|null $allocators = null,
        LootPolicyRegistry|null $policies = null,
        MoonDestructionRuleRegistry|null $moons = null,
    ): self {
        // **Par `of()`, pas par le constructeur.** Un registre qui rendrait une version vide
        // ecrirait sinon cette chaine vide dans l empreinte du combat, et le defaut ne se verrait
        // qu a la relecture, bien plus tard.
        return self::of(
            ($causal ?? CausalEventOrderRegistry::default())->currentVersion(),
            ($allocators ?? LootAllocatorRegistry::default())->currentVersion(),
            ($policies ?? LootPolicyRegistry::default())->currentVersion(),
            ($moons ?? MoonDestructionRuleRegistry::default())->currentVersion(),
        );
    }

    /**
     * L'ensemble tel qu'il a ete persiste avec le combat.
     *
     * **Il refuse plutot que de completer.** Une clef absente devenait une chaine vide, qui ne
     * resout aucun registre : le combat se serait rejoue sous une regle indeterminee, et cette
     * chaine vide serait entree dans son empreinte.
     *
     * @param array<string, mixed> $stored
     *
     * @throws CorruptedRuleVersionSet Si la structure lue n'est pas celle qui a ete ecrite.
     */
    public static function fromStorage(array $stored): self
    {
        $inconnues = array_diff(array_keys($stored), self::KEYS);

        if ($inconnues !== []) {
            throw new CorruptedRuleVersionSet(
                'la structure porte des clefs inconnues (' . implode(', ', $inconnues) . ')',
                $stored
            );
        }

        $versions = [];

        foreach (self::KEYS as $clef) {
            if (!array_key_exists($clef, $stored)) {
                throw new CorruptedRuleVersionSet('la clef « ' . $clef . ' » manque', $stored);
            }

            $valeur = $stored[$clef];

            if (!is_string($valeur)) {
                throw new CorruptedRuleVersionSet(
                    'la version de « ' . $clef . ' » n est pas une chaine',
                    $stored
                );
            }

            $versions[] = $valeur;
        }

        return self::of(...$versions);
    }

    /**
     * Un ensemble explicite, pour les essais et les rejeux.
     *
     * **La meme exigence que la relecture.** Une version vide passee ici serait persistee telle
     * quelle, et le defaut ne se verrait qu'a la relecture d'un combat deja ouvert — trop tard.
     *
     * @throws CorruptedRuleVersionSet Si l'une des quatre versions est vide.
     */
    public static function of(
        string $causalOrder,
        string $lootAllocator,
        string $lootPolicy,
        string $moonDestruction,
    ): self {
        $fournies = [
            'causal_order' => $causalOrder,
            'loot_allocator' => $lootAllocator,
            'loot_policy' => $lootPolicy,
            'moon_destruction' => $moonDestruction,
        ];

        foreach ($fournies as $clef => $version) {
            if ($version === '') {
                throw new CorruptedRuleVersionSet(
                    'la version de « ' . $clef . ' » est vide',
                    $fournies
                );
            }
        }

        return new self($causalOrder, $lootAllocator, $lootPolicy, $moonDestruction);
    }

    /**
     * Ce qu'il faut ecrire avec le combat.
     *
     * @return array<string, string>
     */
    public function toStorage(): array
    {
        return [
            'causal_order' => $this->causalOrder,
            'loot_allocator' => $this->lootAllocator,
            'loot_policy' => $this->lootPolicy,
            'moon_destruction' => $this->moonDestruction,
        ];
    }

    /**
     * Ce que l'empreinte et les cles d'idempotence doivent porter.
     *
     * Les versions **font partie de l'identite du resultat**. Sans elles, deux calculs sous deux
     * regles differentes partageraient une empreinte, et un rejeu passerait pour un doublon.
     *
     * @return array<string, string>
     */
    public function fingerprintFacts(): array
    {
        return $this->toStorage();
    }

    /**
     * Exige que l'autre ensemble soit exactement celui-ci.
     *
     * Leve plutot que de rendre un booleen : deux combats sous deux ensembles differents ne se
     * comparent pas, ils s'excluent.
     */
    public function ensureSameAs(self $other): void
    {
        if ($this->toStorage() === $other->toStorage()) {
            return;
        }

        throw new MismatchedRuleVersionSet($this->toStorage(), $other->toStorage());
    }
}
