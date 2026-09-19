<?php

namespace OGame\Combat\Support;

use OGame\Enums\CharacterClass;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\Models\User;
use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Un joueur tel qu une bataille le voit : **vivant**, sauf ce que son admission a fixe.
 *
 * ## Pourquoi pas `FrozenCombatant`
 *
 * `FrozenCombatant` (combat progressif en espace libre) refuse toute lecture qu il ne porte pas, et son
 * utilisateur n est pas un compte. Une bataille contre un corps lit bien davantage sur le joueur de chaque
 * flotte : la technologie hyperespace pour le fret, le nom et le genre du compte pour les avis et le pillage.
 * Les refuser arreterait la bataille.
 *
 * ## Ce que l admission fixe
 *
 * - **Ce qui arme les tirs** : les niveaux d armes, de boucliers et de blindage, et le bonus de combat des
 *   classes (`FrozenCombatCharacteristics`).
 * - **La classe de personnage elle-meme** : `getUser()` rend un porteur detache (`FrozenClassCarrier`) qui la
 *   porte. Tout ce que le jeu decide depuis la classe repond ainsi depuis l admission — manoeuvre de Hamill,
 *   fret des transporteurs du Collecteur et des vaisseaux du General, part de pillage du Decouvreur, et ce
 *   que la cloture photographie sur ce combattant : champ d epaves du General, classe du rapport.
 *
 * Tout le reste est le compte, charge a neuf.
 *
 * ## Ce qui n est pas fixe ici, et le dire
 *
 * La technologie hyperespace, qui agrandit le fret, se lit sur le compte quand la bataille se calcule — comme le
 * fret des vaisseaux civils que les formes de vie ajoutent (Extension des soutes, Compresseur neuromodal : decision du
 * §157, point 10) —, et la duree du retour sur un compte vivant. Ce sont des points distincts, que ce combattant ne
 * tranche pas.
 *
 * ## Le bonus n est compte qu une fois
 *
 * Les niveaux rendus sont **bruts** ; le bonus reste a part, et ce sont les services de proprietes qui
 * l ajoutent. Le rapport lit la meme source.
 */
final class CombatantFrozenAtEntry extends PlayerService
{
    public function __construct(
        int $playerId,
        private readonly FrozenCombatCharacteristics $characteristics,
        private readonly CharacterClass|null $characterClass,
    ) {
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
     * Les bonus de formes de vie sur ses unites, tels que l admission les a geles : jamais une lecture
     * vivante pendant la bataille (journal §155.6).
     */
    public function getLifeformUnitStatsPercent(GameObject $object): float
    {
        return $this->characteristics->lifeformBonuses->unitStatsPercent($object);
    }

    /**
     * Le compte avec la classe de l admission : **un porteur neuf a chaque demande**.
     *
     * Neuf, parce qu un porteur garde d un appel a l autre survivrait a un rechargement du compte par
     * `load()`, et que deux lecteurs partageraient alors un meme objet. Le modele que ce combattant a charge,
     * lui, n est jamais modifie.
     */
    public function getUser(): User
    {
        return FrozenClassCarrier::carrying(parent::getUser(), $this->characterClass);
    }

    /**
     * Ce que ce combattant apporte a ses tirs — pour que le rapport annonce exactement cela.
     */
    public function characteristics(): FrozenCombatCharacteristics
    {
        return $this->characteristics;
    }

    /**
     * La classe de personnage que ce combattant avait a son admission.
     */
    public function characterClass(): CharacterClass|null
    {
        return $this->characterClass;
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
