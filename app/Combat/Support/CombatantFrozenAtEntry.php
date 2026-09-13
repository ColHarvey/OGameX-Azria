<?php

namespace OGame\Combat\Support;

use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Un joueur tel qu une bataille durable le voit : **vivant**, sauf ce qui arme ses tirs.
 *
 * ## Pourquoi pas `FrozenCombatant`
 *
 * `FrozenCombatant` (espace libre) refuse toute lecture qu il ne porte pas, et son utilisateur n est
 * pas un compte. Une bataille contre un corps lit bien davantage sur le joueur de chaque flotte : la
 * capacite de fret des Faucheurs et des survivants (`BattleEngine`, technologie hyperespace), la classe
 * pour la manoeuvre de Hamill et le fret des transporteurs, le nom du compte pour les avis. Ces lectures
 * ont leur propre photographie a la cloture ; les refuser arreterait la bataille, les geler ici
 * changerait des regles que la decision de Keven ne touche pas.
 *
 * Cette classe ne remplace donc que **quatre** lectures : les niveaux d armes, de boucliers et de
 * blindage, et le bonus de combat des classes. Tout le reste est le compte, charge a neuf.
 *
 * ## Le bonus n est compte qu une fois
 *
 * Les niveaux rendus sont **bruts** ; le bonus reste a part, et ce sont les services de proprietes qui
 * l ajoutent. Le rapport lit la meme source.
 */
final class CombatantFrozenAtEntry extends PlayerService
{
    public function __construct(int $playerId, private readonly FrozenCombatCharacteristics $characteristics)
    {
        parent::__construct($playerId);
    }

    public function getResearchLevel(string $machine_name): int
    {
        return match ($machine_name) {
            'weapon_technology' => $this->characteristics->weaponLevel,
            'shielding_technology' => $this->characteristics->shieldLevel,
            'armor_technology' => $this->characteristics->armorLevel,
            default => parent::getResearchLevel($machine_name),
        };
    }

    public function getCombatResearchBonusLevels(): int
    {
        return $this->characteristics->classCombatBonus;
    }

    /**
     * Ce que ce combattant apporte a ses tirs — pour que le rapport annonce exactement cela.
     */
    public function characteristics(): FrozenCombatCharacteristics
    {
        return $this->characteristics;
    }

    /**
     * Une bataille ne modifie pas le compte qu elle lit.
     */
    public function setResearchLevel(string $machine_name, int $level, bool $save_to_db = true): void
    {
        throw new RuntimeException(
            'A combatant frozen at its entry was asked to change its « ' . $machine_name . ' » level: a '
            . 'battle reads an account, it never writes to it.'
        );
    }
}
