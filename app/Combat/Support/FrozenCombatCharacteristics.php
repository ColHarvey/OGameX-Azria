<?php

namespace OGame\Combat\Support;

use OGame\Combat\Services\PhotographedDefender;
use OGame\Services\PlayerService;

/**
 * Ce qu un participant apporte a ses tirs : trois niveaux de recherche et le bonus de ses classes.
 *
 * ## Pourquoi ces quatre nombres, et eux seuls
 *
 * Ce sont les seules lectures du joueur qui fixent la puissance de feu, le bouclier et la coque d une
 * unite (`AttackPropertyService`, `ShieldPropertyService`, `StructuralIntegrityPropertyService`). Le
 * reste de ce que le moteur lit sur un joueur — capacite de fret, classe pour la manoeuvre de Hamill,
 * duree de retour — a sa propre photographie a la cloture, et n appartient pas a cette decision.
 *
 * ## Le bonus est porte tel qu il vaut
 *
 * La somme des deux classes, prise a l entree. La relire a la cloture ferait dependre des tirs d une
 * classe achetee ou d une alliance quittee apres l arrivee de la flotte.
 */
final readonly class FrozenCombatCharacteristics
{
    public function __construct(
        public int $weaponLevel,
        public int $shieldLevel,
        public int $armorLevel,
        public int $classCombatBonus,
    ) {
    }

    /**
     * Ce que le compte porte **maintenant**. Le registre le ramene ensuite a l instant d admission : le
     * compte peut deja porter une recherche achevee apres lui.
     */
    public static function ofLivePlayer(PlayerService $player): self
    {
        return new self(
            $player->getResearchLevel('weapon_technology'),
            $player->getResearchLevel('shielding_technology'),
            $player->getResearchLevel('armor_technology'),
            $player->getCombatResearchBonusLevels(),
        );
    }

    /**
     * Ce que la garnison apporte : la photographie d ouverture, relevee par les seuls effets admissibles.
     */
    public static function ofPhotographedDefender(PhotographedDefender $defender): self
    {
        return new self($defender->weaponLevel, $defender->shieldLevel, $defender->armorLevel, $defender->classCombatBonus);
    }

    /**
     * Les faits relus d une ligne, ou un refus.
     *
     * **Une porte de confiance ne transtype pas.** `(int)` rendrait `'7'`, `7.4` et `true` egaux a un
     * niveau : une ligne abimee passerait pour valide, et la flotte tirerait sur un niveau que personne
     * n a ecrit.
     *
     * @param array<string, mixed> $row
     */
    public static function fromStorage(array $row): self
    {
        return new self(
            FrozenFact::int($row, 'weapon_level'),
            FrozenFact::int($row, 'shield_level'),
            FrozenFact::int($row, 'armor_level'),
            FrozenFact::int($row, 'class_combat_bonus'),
        );
    }

    /**
     * @return array{weapon_level: int, shield_level: int, armor_level: int, class_combat_bonus: int}
     */
    public function toStorage(): array
    {
        return [
            'weapon_level' => $this->weaponLevel,
            'shield_level' => $this->shieldLevel,
            'armor_level' => $this->armorLevel,
            'class_combat_bonus' => $this->classCombatBonus,
        ];
    }
}
