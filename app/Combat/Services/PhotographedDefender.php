<?php

namespace OGame\Combat\Services;

use OGame\Combat\Support\FrozenFact;

/**
 * Ce que le defenseur apporte a la bataille, fixe par la photographie.
 *
 * ## Pourquoi ces quatre valeurs, et pas le joueur
 *
 * Le moteur lit les niveaux d'armes, de boucliers et de blindage du proprietaire du corps, son bonus
 * de classe, et le niveau du chantier spatial qui decide la part d'epave recuperable. Relus vivants a
 * la fermeture, ils feraient dependre le resultat de ce que le joueur a termine pendant le ralliement
 * — une recherche engagee **apres** l'ouverture renforcerait une defense deja engagee dans un combat.
 *
 * Photographier le joueur entier n'aurait aucun sens : ce sont ces quatre faits, et eux seuls, que la
 * bataille consomme. Les nommer les rend verifiables ; un cinquieme fait qui apparaitrait dans le
 * moteur devrait venir s'ajouter ici, et son absence se verrait.
 *
 * Le bonus de classe est photographie **tel qu'il vaut**, pas la classe : un changement de classe
 * pendant le ralliement ne doit pas changer une bataille deja engagee, et la valeur derivee est ce
 * que le moteur additionne.
 */
final readonly class PhotographedDefender
{
    public function __construct(
        public int $weaponLevel,
        public int $shieldLevel,
        public int $armorLevel,
        public int $classCombatBonus,
        public int $spaceDockLevel,
    ) {
    }

    /**
     * Les faits relus, ou un refus.
     *
     * **Une porte de confiance ne transtype pas.** `(int)` accepte `'4'`, `4.7` et `true`, et les
     * rend tous egaux a 4 : un document abime passerait pour un document valide, et la bataille se
     * jouerait sur des niveaux que personne n'a ecrits. `FrozenFact::int()` exige un entier.
     *
     * @param array<string, mixed> $facts
     */
    public static function fromFrozenFacts(array $facts): self
    {
        return new self(
            FrozenFact::int($facts, 'weapon_level'),
            FrozenFact::int($facts, 'shield_level'),
            FrozenFact::int($facts, 'armor_level'),
            FrozenFact::int($facts, 'class_combat_bonus'),
            FrozenFact::int($facts, 'space_dock_level'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toFrozenFacts(): array
    {
        return [
            'weapon_level' => $this->weaponLevel,
            'shield_level' => $this->shieldLevel,
            'armor_level' => $this->armorLevel,
            'class_combat_bonus' => $this->classCombatBonus,
            'space_dock_level' => $this->spaceDockLevel,
        ];
    }

    /**
     * Le meme defenseur, avec un niveau de recherche releve par un effet admissible.
     *
     * Le niveau ne peut que monter : une recherche achevee **atteint** un niveau, elle ne le rend pas.
     * Prendre le maximum protege d'un achevement lu deux fois ou d'un niveau deja atteint a l'ouverture.
     */
    public function withResearchLevel(string $machineName, int $level): self
    {
        return match ($machineName) {
            'weapon_technology' => new self(max($this->weaponLevel, $level), $this->shieldLevel, $this->armorLevel, $this->classCombatBonus, $this->spaceDockLevel),
            'shielding_technology' => new self($this->weaponLevel, max($this->shieldLevel, $level), $this->armorLevel, $this->classCombatBonus, $this->spaceDockLevel),
            'armor_technology' => new self($this->weaponLevel, $this->shieldLevel, max($this->armorLevel, $level), $this->classCombatBonus, $this->spaceDockLevel),
            default => $this,
        };
    }

    public function withSpaceDockLevel(int $level): self
    {
        return new self($this->weaponLevel, $this->shieldLevel, $this->armorLevel, $this->classCombatBonus, max($this->spaceDockLevel, $level));
    }
}
