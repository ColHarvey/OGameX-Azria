<?php

namespace OGame\Combat\Services;

use OGame\Combat\Support\FrozenCombatCharacteristics;
use OGame\Combat\Support\FrozenFact;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;

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
    /**
     * Les bonus de formes de vie du corps et de son proprietaire a l ouverture (journal §155.6) ; aucun
     * pour un document ecrit sous une version anterieure — les formes de vie n existaient pas alors.
     */
    public FrozenLifeformCombatBonuses $lifeformBonuses;

    public function __construct(
        public int $weaponLevel,
        public int $shieldLevel,
        public int $armorLevel,
        public int $classCombatBonus,
        public int $spaceDockLevel,
        FrozenLifeformCombatBonuses|null $lifeformBonuses = null,
    ) {
        $this->lifeformBonuses = $lifeformBonuses ?? FrozenLifeformCombatBonuses::none();
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
            // **L absence est toleree ici**, comme pour les coques entamees : un document d avant la tranche 6
            // ne porte aucune forme de vie, et « aucune » est la valeur juste — jamais le corps vivant.
            array_key_exists('lifeform_bonuses', $facts) ? FrozenLifeformCombatBonuses::fromFrozenFacts(FrozenFact::array($facts, 'lifeform_bonuses')) : null,
        );
    }

    /**
     * @return array<string, int|array<string, mixed>>
     */
    public function toFrozenFacts(): array
    {
        return [
            'weapon_level' => $this->weaponLevel,
            'shield_level' => $this->shieldLevel,
            'armor_level' => $this->armorLevel,
            'class_combat_bonus' => $this->classCombatBonus,
            'space_dock_level' => $this->spaceDockLevel,
            'lifeform_bonuses' => $this->lifeformBonuses->toFrozenFacts(),
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
            'weapon_technology' => new self(max($this->weaponLevel, $level), $this->shieldLevel, $this->armorLevel, $this->classCombatBonus, $this->spaceDockLevel, $this->lifeformBonuses),
            'shielding_technology' => new self($this->weaponLevel, max($this->shieldLevel, $level), $this->armorLevel, $this->classCombatBonus, $this->spaceDockLevel, $this->lifeformBonuses),
            'armor_technology' => new self($this->weaponLevel, $this->shieldLevel, max($this->armorLevel, $level), $this->classCombatBonus, $this->spaceDockLevel, $this->lifeformBonuses),
            default => $this,
        };
    }

    public function withSpaceDockLevel(int $level): self
    {
        return new self($this->weaponLevel, $this->shieldLevel, $this->armorLevel, $this->classCombatBonus, max($this->spaceDockLevel, $level), $this->lifeformBonuses);
    }

    /**
     * Le meme defenseur, avec les quatre nombres qui arment ses tirs pris ailleurs.
     *
     * Sous le gel a l admission, ce sont ceux que le registre a inscrits a la barriere d ouverture, et le
     * rapport doit annoncer ceux-la. Le niveau du chantier spatial reste celui de la photographie : il ne
     * fixe aucun tir.
     */
    public function withCombatCharacteristics(FrozenCombatCharacteristics $faits): self
    {
        // Les unites viennent avec les tirs ; les faits du corps (population, lune, debris, epaves) restent au corps.
        return new self($faits->weaponLevel, $faits->shieldLevel, $faits->armorLevel, $faits->classCombatBonus, $this->spaceDockLevel, $this->lifeformBonuses->withUnitStatsOf($faits->lifeformBonuses));
    }
}
