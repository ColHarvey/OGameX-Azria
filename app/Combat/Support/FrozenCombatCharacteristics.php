<?php

namespace OGame\Combat\Support;

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
 * ## Aucune lecture du compte vivant ici
 *
 * Une premiere version offrait « ce que le compte porte maintenant ». Elle n est plus : les niveaux et le
 * bonus d un participant — une flotte, ou la garnison du corps vise — sont ceux de **son instant
 * d admission**, que seul le registre sait etablir, par l historique des files de recherche et celui des
 * classes. Un raccourci vers le compte vivant, ou vers la photographie du defenseur prise au traitement
 * de l ouverture, reintroduirait precisement ce que le gel ferme.
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
