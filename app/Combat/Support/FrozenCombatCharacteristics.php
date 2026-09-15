<?php

namespace OGame\Combat\Support;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;

/**
 * Ce qu un participant apporte a ses tirs : trois niveaux de recherche et le bonus de ses classes.
 *
 * ## Pourquoi ces quatre nombres, et eux seuls
 *
 * Ce sont les seules lectures du joueur qui fixent la puissance de feu, le bouclier et la coque d une
 * unite (`AttackPropertyService`, `ShieldPropertyService`, `StructuralIntegrityPropertyService`). La
 * **classe elle-meme** — manoeuvre de Hamill, fret, champ d epaves, part du Decouvreur, rapport — se gele a
 * la meme admission, a cote de ces nombres (`CombatantFrozenAtEntry`) ; la duree du retour a sa propre
 * photographie a la cloture.
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
    /**
     * Les bonus de formes de vie sur les unites de ce combattant (journal §155.6) ; aucun pour une ligne
     * ecrite avant qu ils existent.
     */
    public FrozenLifeformCombatBonuses $lifeformBonuses;

    public function __construct(
        public int $weaponLevel,
        public int $shieldLevel,
        public int $armorLevel,
        public int $classCombatBonus,
        FrozenLifeformCombatBonuses|null $lifeformBonuses = null,
    ) {
        $this->lifeformBonuses = $lifeformBonuses ?? FrozenLifeformCombatBonuses::none();
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
            self::lifeformBonusesOf($row),
        );
    }

    /**
     * La colonne JSON des bonus de formes de vie : absente ou nulle pour une ligne d avant, relue depuis
     * la base comme un texte JSON, ou deja une structure quand elle vient de `toStorage()`.
     *
     * @param array<string, mixed> $row
     */
    private static function lifeformBonusesOf(array $row): FrozenLifeformCombatBonuses
    {
        $valeur = $row['lifeform_bonuses'] ?? null;
        if ($valeur === null) {
            return FrozenLifeformCombatBonuses::none();
        }
        if (is_string($valeur)) {
            $valeur = json_decode($valeur, true);
        }
        if (!is_array($valeur)) {
            throw new CorruptedFrozenMoonPlan('le fait « lifeform_bonuses » n est ni nul, ni une structure, ni un document JSON lisible', $row);
        }

        return FrozenLifeformCombatBonuses::fromFrozenFacts($valeur);
    }

    /**
     * @return array{weapon_level: int, shield_level: int, armor_level: int, class_combat_bonus: int, lifeform_bonuses: array<string, mixed>}
     */
    public function toStorage(): array
    {
        return [
            'weapon_level' => $this->weaponLevel,
            'shield_level' => $this->shieldLevel,
            'armor_level' => $this->armorLevel,
            'class_combat_bonus' => $this->classCombatBonus,
            'lifeform_bonuses' => $this->lifeformBonuses->toFrozenFacts(),
        ];
    }
}
