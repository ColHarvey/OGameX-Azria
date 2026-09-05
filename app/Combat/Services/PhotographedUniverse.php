<?php

namespace OGame\Combat\Services;

use OGame\Combat\Support\FrozenFact;
use OGame\Services\SettingsService;

/**
 * Les reglages d'univers que la bataille consomme, fixes a l'ouverture du combat.
 *
 * ## Pourquoi a l'ouverture, et plus a la cloture
 *
 * `FrozenCombatApplicationContext` gele deja ce dont **l'application** depend, mais il le fait a la
 * cloture : entre l'ouverture et la fermeture, un ralliement dure, et un administrateur qui ajuste la
 * part d'epaves ou le seuil d'un champ change une bataille **deja engagee**. Les attaquants ont
 * decide de partir sous un univers ; ils se battent sous celui-la.
 *
 * ## Les sept faits, et pourquoi ceux-la
 *
 * Ce sont exactement les reglages que `BattleEngine` lit sur `SettingsService` pendant la simulation :
 *
 * | reglage | ce qu'il decide |
 * | --- | --- |
 * | `debris_field_from_ships` | la part des vaisseaux detruits qui devient champ de debris |
 * | `debris_field_from_defense` | la part des defenses detruites qui y va aussi |
 * | `debris_field_deuterium_on` | si le deuterium entre dans ce champ |
 * | `wreck_field_min_resources_loss` | le seuil de perte sous lequel aucun champ d'epaves ne nait |
 * | `wreck_field_min_fleet_percentage` | la part de flotte perdue qu'il faut pour qu'il naisse |
 * | `defense_repair_rate` | la part des defenses detruites que le corps repare |
 * | `maximum_moon_chance` | le plafond de la chance de lune |
 *
 * Les nommer un par un les rend verifiables : un huitieme reglage qui apparaitrait dans le moteur
 * devrait venir s'ajouter ici, et son absence se verrait. `SettingsSeenByTheEngineTest` compare cette
 * liste a ce que le moteur lit reellement, pour que l'oubli tombe au lieu de passer.
 *
 * ## Ce qui n'est pas ici
 *
 * Les reglages de **reparation d'un champ d'epaves** (`wreck_field_repair_max_hours`,
 * `wreck_field_repair_min_minutes`) ne s'appliquent qu'a une action future du joueur, bien apres la
 * bataille : ils n'appartiennent a aucun combat, et les geler reviendrait a promettre au joueur un
 * tarif que l'administration a change depuis.
 */
final readonly class PhotographedUniverse
{
    public function __construct(
        public int $debrisFieldFromShips,
        public int $debrisFieldFromDefense,
        public int $debrisFieldDeuteriumOn,
        public int $wreckFieldMinResourcesLoss,
        public int $wreckFieldMinFleetPercentage,
        public int $defenseRepairRate,
        public int $maximumMoonChance,
    ) {
    }

    /**
     * Les reglages tels qu'ils valent maintenant : lus une fois, dans la transaction d'ouverture.
     */
    public static function fromLiveSettings(SettingsService $settings): self
    {
        return new self(
            $settings->debrisFieldFromShips(),
            $settings->debrisFieldFromDefense(),
            $settings->debrisFieldDeuteriumOn(),
            $settings->wreckFieldMinResourcesLoss(),
            $settings->wreckFieldMinFleetPercentage(),
            $settings->defenseRepairRate(),
            $settings->maximumMoonChance(),
        );
    }

    /**
     * Les faits relus, ou un refus.
     *
     * **Une porte de confiance ne transtype pas** : `(int)` rend `'30'`, `30.9` et `true` egaux a un
     * entier plausible, et une bataille se jouerait sur une part que personne n'a ecrite.
     *
     * @param array<string, mixed> $facts
     */
    public static function fromFrozenFacts(array $facts): self
    {
        return new self(
            FrozenFact::int($facts, 'debris_field_from_ships'),
            FrozenFact::int($facts, 'debris_field_from_defense'),
            FrozenFact::int($facts, 'debris_field_deuterium_on'),
            FrozenFact::int($facts, 'wreck_field_min_resources_loss'),
            FrozenFact::int($facts, 'wreck_field_min_fleet_percentage'),
            FrozenFact::int($facts, 'defense_repair_rate'),
            FrozenFact::int($facts, 'maximum_moon_chance'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toFrozenFacts(): array
    {
        return [
            'debris_field_from_ships' => $this->debrisFieldFromShips,
            'debris_field_from_defense' => $this->debrisFieldFromDefense,
            'debris_field_deuterium_on' => $this->debrisFieldDeuteriumOn,
            'wreck_field_min_resources_loss' => $this->wreckFieldMinResourcesLoss,
            'wreck_field_min_fleet_percentage' => $this->wreckFieldMinFleetPercentage,
            'defense_repair_rate' => $this->defenseRepairRate,
            'maximum_moon_chance' => $this->maximumMoonChance,
        ];
    }
}
