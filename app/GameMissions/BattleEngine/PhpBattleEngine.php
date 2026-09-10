<?php

namespace OGame\GameMissions\BattleEngine;

use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\GameObjects\Models\Units\UnitEntry;
use OGame\Hull\DamagedHulls;
use OGame\Services\CharacterClassService;
use OGame\Services\SettingsService;

/**
 * Class BattleEngine.
 *
 * This class is responsible for handling the battle logic in the game, used primarily
 * by the AttackMission class. This is the PHP version of the BattleEngine which is slower
 * and less memory efficientthan the Rust version but has less dependencies and is easier to debug.
 *
 * @package OGame\GameMissions\BattleEngine
 */
class PhpBattleEngine extends BattleEngine
{
    /**
     * La source des tirages des rounds : cible, explosion, tir rapide.
     *
     * Prise de `$this->draws->forRounds()` juste avant le premier round. Avec une graine, c'est
     * une suite neuve, celle que le moteur Rust commence au meme instant.
     */
    private BattleDraws $roundDraws;

    /**
     * Fight the battle in max 6 rounds.
     *
     * **La bataille se joue desormais en trois temps nommes** — ouvrir le champ, jouer des rounds,
     * fermer le champ — et cette methode les enchaine tels qu'ils l'ont toujours ete : une seule
     * etape de six rounds. Le comportement ne change pas d'un tir ; ce qui change est qu'on peut
     * maintenant s'arreter entre deux rounds, ce que le moteur progressif demandera.
     *
     * @param BattleResult $result
     * @return array<BattleResultRound>
     */
    protected function fightBattleRounds(BattleResult $result): array
    {
        $etat = $this->openTheField($result);

        $rounds = $this->playRounds($etat, self::MAX_ROUNDS);

        $this->closeTheField($result, $etat);

        return $rounds;
    }

    /**
     * Le champ a son ouverture : les unites des deux camps, la manoeuvre de Hamill, la bande neuve.
     *
     * **L'ordre canonique est pris ici et nulle part ailleurs** : flottes par identifiant de
     * mission, unites par identifiant d'objet. Une cible se choisit par sa position parmi les
     * unites restantes ; deux moteurs nourris des memes tirages ne visent la meme unite que s'ils
     * les ont rangees pareil.
     */
    protected function openTheField(BattleResult $result): BattleFieldState
    {
        // Convert attacker units to BattleUnit objects to keep track of hull plating and shields.
        // Each attacker fleet uses its own player's tech levels.
        // **Ordre canonique** : flottes par identifiant de mission, unites par identifiant d'objet.
        // Une cible se choisit par sa position parmi les unites restantes ; deux moteurs nourris
        // des memes tirages ne visent la meme unite que s'ils les ont rangees pareil.
        $attackerUnits = [];
        foreach (self::inCanonicalOrder($this->attackers) as $attackerFleet) {
            foreach (self::unitsInCanonicalOrder($attackerFleet->units) as $unit) {
                // Create new object for each unique unit in this fleet.
                // Use THIS fleet owner's tech levels for calculations
                $structuralIntegrity = $unit->unitObject->properties->structural_integrity->calculate($attackerFleet->player)->totalValue;
                $shieldPoints = $unit->unitObject->properties->shield->calculate($attackerFleet->player)->totalValue;
                $attackPower = $unit->unitObject->properties->attack->calculate($attackerFleet->player)->totalValue;
                $unitObject = new BattleUnit($unit->unitObject, $structuralIntegrity, $shieldPoints, $attackPower, $attackerFleet->fleetMissionId, $attackerFleet->ownerId);

                // **Les degats que cette flotte apporte**, un niveau par unite, les plus intactes
                // d abord. Une flotte qui n a jamais combattu rend une suite de zeros, et la boucle
                // se comporte exactement comme avant.
                $degatsPortes = $attackerFleet->damagedHulls()
                    ->damageSequenceFor($unit->unitObject->machine_name, $unit->amount);

                for ($i = 0; $i < $unit->amount; $i++) {
                    // Clone the unit object for each individual entry of this ship add it to the array.
                    $exemplaire = clone $unitObject;
                    $degats = $degatsPortes[$i] ?? 0;

                    if ($degats > 0) {
                        // `originalHullPlating` ne bouge pas : c est la coque **pleine** de l unite,
                        // et c est elle qui decide du risque d explosion. Une unite qui arrive
                        // entamee est donc plus fragile qu une unite neuve — c est voulu.
                        $exemplaire->currentHullPlating = DamagedHulls::hullFromDamage(
                            $exemplaire->originalHullPlating,
                            $degats
                        );
                    }

                    $attackerUnits[] = $exemplaire;
                }
            }
        }

        $defenderUnits = [];
        // Create BattleUnits for each defending fleet separately to preserve ownership and tech levels
        foreach (self::inCanonicalOrder($this->defenders) as $defenderFleet) {
            foreach (self::unitsInCanonicalOrder($defenderFleet->units) as $unit) {
                // Create new object for each unique unit type in this fleet
                // Use THIS fleet owner's tech levels for calculations
                $structuralIntegrity = $unit->unitObject->properties->structural_integrity->calculate($defenderFleet->player)->totalValue;
                $shieldPoints = $unit->unitObject->properties->shield->calculate($defenderFleet->player)->totalValue;
                $attackPower = $unit->unitObject->properties->attack->calculate($defenderFleet->player)->totalValue;

                $unitObject = new BattleUnit(
                    $unit->unitObject,
                    $structuralIntegrity,
                    $shieldPoints,
                    $attackPower,
                    $defenderFleet->fleetMissionId,  // Track which fleet this unit belongs to
                    $defenderFleet->ownerId          // Track which player owns this unit
                );

                // Les degats de cette flotte defensive, meme regle qu au-dessus : les plus intactes
                // d abord, et une suite de zeros pour qui n a jamais ete touche.
                $degatsPortes = $defenderFleet->damagedHulls()
                    ->damageSequenceFor($unit->unitObject->machine_name, $unit->amount);

                // Create individual BattleUnit for each ship
                for ($i = 0; $i < $unit->amount; $i++) {
                    $exemplaire = clone $unitObject;
                    $degats = $degatsPortes[$i] ?? 0;

                    if ($degats > 0) {
                        $exemplaire->currentHullPlating = DamagedHulls::hullFromDamage(
                            $exemplaire->originalHullPlating,
                            $degats
                        );
                    }

                    $defenderUnits[] = $exemplaire;
                }
            }
        }

        // Hamill Manoeuvre: General class Light Fighters have a chance to destroy one Deathstar before battle
        $this->checkHamillManoeuvre($result, $attackerUnits, $defenderUnits);
        $attackerUnits = array_values($attackerUnits);
        $defenderUnits = array_values($defenderUnits);

        // **Les rounds tirent d'une suite neuve**, celle que le moteur Rust recoit avec la graine :
        // Hamill a deja tire de la source de la bataille, et les deux moteurs doivent commencer
        // leurs rounds au meme point.
        $this->roundDraws = $this->draws->forRounds();

        $attackerRemainingShips = clone $result->attackerUnitsStart;
        $defenderRemainingShips = clone $result->defenderUnitsStart;

        // Initialize per-fleet tracking for multi-attacker battles
        $attackerLossesPerFleet = [];
        $attackerShipsPerFleet = [];
        foreach ($this->attackers as $attackerFleet) {
            $attackerLossesPerFleet[$attackerFleet->fleetMissionId] = new UnitCollection();
            $attackerShipsPerFleet[$attackerFleet->fleetMissionId] = clone $attackerFleet->units;
        }

        // Les deux tableaux sont deja reindexes : la manoeuvre de Hamill vient de le faire.
        // **Les deux bandes voyagent** : celle des rounds, et celle de la bataille dont elle est
        // nee — Hamill y a deja puise, le tirage de lune y puisera apres les rounds.
        return new BattleFieldState(
            $attackerUnits,
            $defenderUnits,
            $this->roundDraws,
            $this->draws,
            0,
            $attackerRemainingShips,
            $defenderRemainingShips,
            new UnitCollection(),
            new UnitCollection(),
            $attackerLossesPerFleet,
            $attackerShipsPerFleet,
        );
    }

    /**
     * Joue au plus ce nombre de rounds sur ce champ, sans jamais depasser le plafond du jeu.
     *
     * ## Ce que « depuis cet etat » veut dire
     *
     * L'etat d'entree est l'etat de sortie du dernier round joue : coques entamees comprises. Le
     * corps de la boucle n'a pas bouge d'une ligne — il travaille sur des variables locales
     * deballees a l'entree et remballees a la sortie. C'etait la condition pour que cette couture
     * ne change aucun comportement, et pour que la suite entiere en soit le temoin.
     *
     * **La bande des rounds est celle de l'etat**, pas une nouvelle : c'est ce qui rend la suite de
     * tirages independante du decoupage. `$this->roundDraws` la recoit parce que les deux aides du
     * round — `attackUnit()` et `cleanupRound()` — la lisent la ; une seule source, posee ici.
     *
     * @return array<BattleResultRound> Les rounds joues **par cet appel**, pas depuis le debut.
     */
    protected function playRounds(BattleFieldState $etat, int $howMany): array
    {
        $this->roundDraws = $etat->roundDraws;

        $rounds = [];

        $attackerUnits = $etat->attackerUnits;
        $defenderUnits = $etat->defenderUnits;
        $attackerRemainingShips = $etat->attackerRemainingShips;
        $defenderRemainingShips = $etat->defenderRemainingShips;
        $attackerLosses = $etat->attackerLosses;
        $defenderLosses = $etat->defenderLosses;
        $attackerLossesPerFleet = $etat->attackerLossesPerFleet;
        $attackerShipsPerFleet = $etat->attackerShipsPerFleet;

        $jusqua = min(self::MAX_ROUNDS, $etat->roundsPlayed + max(0, $howMany));

        while ($etat->roundsPlayed < $jusqua && count($attackerUnits) > 0 && count($defenderUnits) > 0) {
            $etat->roundsPlayed++;
            $round = new BattleResultRound();
            $round->defenderLossesInRound = new UnitCollection();
            $round->attackerLossesInRound = new UnitCollection();
            $round->absorbedDamageAttacker = 0;
            $round->absorbedDamageDefender = 0;

            // Initialize per-fleet tracking for this round
            $round->attackerLossesInRoundPerFleet = [];
            $round->attackerShipsPerFleet = [];
            $round->hitsPerAttackerFleet = [];
            $round->damagePerAttackerFleet = [];
            foreach ($this->attackers as $attackerFleet) {
                $round->attackerLossesInRoundPerFleet[$attackerFleet->fleetMissionId] = new UnitCollection();
                $round->attackerShipsPerFleet[$attackerFleet->fleetMissionId] = new UnitCollection();
                $round->hitsPerAttackerFleet[$attackerFleet->fleetMissionId] = 0;
                $round->damagePerAttackerFleet[$attackerFleet->fleetMissionId] = 0;
            }

            // **Le camp defenseur est suivi flotte par flotte lui aussi** : la garnison sous zero,
            // chaque renfort sous sa mission. Sans cela, un defenseur accompagne ne saurait pas de
            // quelle flotte vient chaque perte de sa bataille.
            $round->defenderLossesInRoundPerFleet = [];
            foreach ($this->defenders as $defenderFleet) {
                $round->defenderLossesInRoundPerFleet[$defenderFleet->fleetMissionId] = new UnitCollection();
            }

            // Let the attacker attack the defender.
            foreach ($attackerUnits as $unit) {
                // Every single unit attacks a random unit from the defender's units.
                // If the attacker has rapidfire against the defender and successfully rolled a dice,
                // the attacker can attack a random unit again.
                do {
                    $targetUnit = $defenderUnits[$this->roundDraws->targetIndex(count($defenderUnits))];

                    $rapidfire = $this->attackUnit(true, $round, $unit, $targetUnit);
                } while ($rapidfire);
            }

            // Let the defender attack the attacker.
            foreach ($defenderUnits as $unit) {
                // If the attacker has rapidfire against the defender and successfully rolled a dice,
                // the attacker can attack a random unit again.
                do {
                    $targetUnit = $attackerUnits[$this->roundDraws->targetIndex(count($attackerUnits))];

                    $rapidfire = $this->attackUnit(false, $round, $unit, $targetUnit);
                } while ($rapidfire);
            }

            // After all units have attacked each other, clean up the round. This removes destroyed units
            // and applies shield regeneration.
            $this->cleanupRound($round, $attackerUnits, $defenderUnits);
            $attackerUnits = array_values($attackerUnits);
            $defenderUnits = array_values($defenderUnits);

            // Subtract losses from the attacker and defender units.
            $attackerRemainingShips->subtractCollection($round->attackerLossesInRound);
            $defenderRemainingShips->subtractCollection($round->defenderLossesInRound);

            // Update per-fleet tracking
            foreach ($this->attackers as $attackerFleet) {
                $fleetId = $attackerFleet->fleetMissionId;
                $attackerShipsPerFleet[$fleetId]->subtractCollection($round->attackerLossesInRoundPerFleet[$fleetId]);
                $attackerLossesPerFleet[$fleetId]->addCollection($round->attackerLossesInRoundPerFleet[$fleetId]);

                $round->attackerShipsPerFleet[$fleetId] = clone $attackerShipsPerFleet[$fleetId];
                $round->attackerLossesPerFleet[$fleetId] = clone $attackerLossesPerFleet[$fleetId];
            }

            // Update the total losses for the attacker and defender.
            $attackerLosses->addCollection($round->attackerLossesInRound);
            $defenderLosses->addCollection($round->defenderLossesInRound);

            // Clone the losses to the round object to keep track of the total losses at round point-in-time.
            $round->attackerLosses = clone $attackerLosses;
            $round->defenderLosses = clone $defenderLosses;

            // Update the ships remaining at the end of this round.
            $round->attackerShips = clone $attackerRemainingShips;
            $round->defenderShips = clone $defenderRemainingShips;

            // Add the round to the list of rounds.
            $rounds[] = $round;
        }

        // **Remballage.** Les collections sont des objets, donc l'etat suivait deja ; les tableaux
        // d'unites, eux, ont ete reindexes par `array_values()` a chaque round et doivent revenir.
        $etat->attackerUnits = $attackerUnits;
        $etat->defenderUnits = $defenderUnits;
        $etat->attackerRemainingShips = $attackerRemainingShips;
        $etat->defenderRemainingShips = $defenderRemainingShips;
        $etat->attackerLosses = $attackerLosses;
        $etat->defenderLosses = $defenderLosses;
        $etat->attackerLossesPerFleet = $attackerLossesPerFleet;
        $etat->attackerShipsPerFleet = $attackerShipsPerFleet;

        return $rounds;
    }

    /**
     * Le champ a sa fermeture : ce que chaque flotte a perdu, et ce que la bande a consomme.
     *
     * Rien ici ne depend du decoupage : les resultats par flotte se lisent sur les **survivants**,
     * et le journal des tirages sur la bande de l'etat — la meme, qu'elle ait servi en une etape ou
     * en six.
     */
    protected function closeTheField(BattleResult $result, BattleFieldState $etat): void
    {
        $attackerUnits = $etat->attackerUnits;
        $defenderUnits = $etat->defenderUnits;

        // Ce que la source des rounds a tire, pour le banc de parite ; nul en jeu.
        $journal = $etat->roundDraws->journal();
        $result->drawsConsumed = $journal === null ? null : ['count' => $journal->count(), 'raw' => $journal->rawCount(), 'digest' => $journal->digest()];

        // Populate per-fleet attacker results by scanning surviving units
        foreach ($result->attackerFleetResults as $fleetResult) {
            // **C est ici que la coque existe encore, et c est ici qu elle se perdait.**
            //
            // Cette boucle ne gardait que le compte : trente croiseurs survivants devenaient le
            // nombre 30, et l etat de chacun disparaissait avec l objet. On en derive desormais
            // l histogramme des degats, seul endroit du jeu ou l information soit disponible.
            $degatsBruts = [];

            // Count surviving units for this fleet
            foreach ($attackerUnits as $battleUnit) {
                if ($battleUnit->fleetMissionId === $fleetResult->fleetMissionId) {
                    $fleetResult->unitsResult->addUnit($battleUnit->unitObject, 1);

                    $degats = DamagedHulls::damageFromHull(
                        $battleUnit->currentHullPlating,
                        $battleUnit->originalHullPlating
                    );

                    // **Les vaisseaux seulement.** Les defenses ont deja leur propre reparation,
                    // automatique et gratuite (`DefenseRepairService`) : leur ajouter une coque
                    // persistante creerait deux mecanismes concurrents sur le meme objet, et la
                    // consigne demande de ne pas toucher a ce qui existe.
                    if ($degats > 0 && $battleUnit->unitObject->type === GameObjectType::Ship) {
                        // Accumule a plat : reconstruire l histogramme a chaque unite le trierait
                        // des milliers de fois pour rien. Il se construit une seule fois, apres.
                        $degatsBruts[$battleUnit->unitObject->machine_name][$degats] =
                            ($degatsBruts[$battleUnit->unitObject->machine_name][$degats] ?? 0) + 1;
                    }
                }
            }

            $fleetResult->survivorHulls = DamagedHulls::of($degatsBruts);

            // Calculate losses for this fleet
            $fleetResult->unitsLost = clone $fleetResult->unitsStart;
            $fleetResult->unitsLost->subtractCollection($fleetResult->unitsResult);

            // Calculate resource loss
            $fleetResult->calculateResourceLoss();

            // Check if completely destroyed
            $fleetResult->completelyDestroyed = $fleetResult->unitsResult->getAmount() === 0;
        }

        // Populate per-fleet defender results by scanning surviving units
        foreach ($result->defenderFleetResults as $fleetResult) {
            // Meme derivation que cote attaquant : la garnison comme les renforts gardent leurs
            // coques entamees, et la flotte d identifiant zero est celle du corps lui-meme.
            $degatsBruts = [];

            // Count surviving units for this fleet
            foreach ($defenderUnits as $battleUnit) {
                if ($battleUnit->fleetMissionId === $fleetResult->fleetMissionId) {
                    $fleetResult->unitsResult->addUnit($battleUnit->unitObject, 1);

                    $degats = DamagedHulls::damageFromHull(
                        $battleUnit->currentHullPlating,
                        $battleUnit->originalHullPlating
                    );

                    // **Les vaisseaux seulement.** Les defenses ont deja leur propre reparation,
                    // automatique et gratuite (`DefenseRepairService`) : leur ajouter une coque
                    // persistante creerait deux mecanismes concurrents sur le meme objet, et la
                    // consigne demande de ne pas toucher a ce qui existe.
                    if ($degats > 0 && $battleUnit->unitObject->type === GameObjectType::Ship) {
                        // Accumule a plat : reconstruire l histogramme a chaque unite le trierait
                        // des milliers de fois pour rien. Il se construit une seule fois, apres.
                        $degatsBruts[$battleUnit->unitObject->machine_name][$degats] =
                            ($degatsBruts[$battleUnit->unitObject->machine_name][$degats] ?? 0) + 1;
                    }
                }
            }

            $fleetResult->survivorHulls = DamagedHulls::of($degatsBruts);

            // Calculate losses for this fleet
            $fleetResult->unitsLost = clone $fleetResult->unitsStart;
            $fleetResult->unitsLost->subtractCollection($fleetResult->unitsResult);

            // Check if completely destroyed
            $fleetResult->completelyDestroyed = $fleetResult->unitsResult->getAmount() === 0;
        }
    }

    /**
     * Les flottes par identifiant de mission croissant : la garnison (zero) d'abord.
     *
     * @template T of AttackerFleet|DefenderFleet
     * @param array<T> $fleets
     * @return array<T>
     */
    private static function inCanonicalOrder(array $fleets): array
    {
        $rangees = array_values($fleets);
        usort($rangees, static fn (AttackerFleet|DefenderFleet $a, AttackerFleet|DefenderFleet $b): int => $a->fleetMissionId <=> $b->fleetMissionId);

        return $rangees;
    }

    /**
     * Les unites par identifiant d'objet croissant.
     *
     * @return array<UnitEntry>
     */
    private static function unitsInCanonicalOrder(UnitCollection $units): array
    {
        $rangees = array_values($units->units);
        usort($rangees, static fn ($a, $b): int => $a->unitObject->id <=> $b->unitObject->id);

        return $rangees;
    }

    /**
     * Let one unit attack another unit and apply the damage to the defending unit.
     *
     * @param bool $isAttacker True if the attacker is attacking, false if the defender is attacking. This is used
     * to determine which statistics to update.
     * @param BattleResultRound $round
     * @param BattleUnit $attacker
     * @param BattleUnit $defender
     *
     * @return bool True if the attacker has rapidfire against the defender and can attack again, false otherwise.
     */
    private function attackUnit(bool $isAttacker, BattleResultRound $round, BattleUnit $attacker, BattleUnit $defender): bool
    {
        // Calculate the damage dealt by the attacker to the defender.
        $damage = $attacker->attackPower;
        $shieldAbsorption = 0;

        if ($damage < (0.01 * $defender->originalShieldPoints)) {
            // If the damage is less than 1% of the shield points, the attack is bounced and no damage is dealt.
            return false;
        }

        if ($defender->currentShieldPoints > 0 && $damage <= $defender->currentShieldPoints) {
            // If the defender has a shield, first apply damage to the shield.
            $shieldAbsorption = $damage;
            $defender->currentShieldPoints -= $damage;
        } elseif ($defender->currentShieldPoints > 0 && $damage > $defender->currentShieldPoints) {
            // If the shield is destroyed, apply the remaining damage to the hull plating.
            $shieldAbsorption = $defender->currentShieldPoints;
            $defender->currentHullPlating -= $damage - $defender->currentShieldPoints;
            $defender->currentShieldPoints = 0;
        } else {
            // No shield, apply damage directly to the hull plating.
            $defender->currentHullPlating -= $damage;
        }

        // If the defender's hull integrity is less than 70%, the unit can explode randomly.
        if ($defender->damagedHullExplosion($this->roundDraws)) {
            // Hull was damaged and dice roll was successful, destroy the unit.
            $defender->currentShieldPoints = 0;
            $defender->currentHullPlating = 0;
        }

        if ($isAttacker) {
            $round->hitsAttacker += 1;
            $round->fullStrengthAttacker += $damage;
            $round->absorbedDamageDefender += $shieldAbsorption;

            // Track per-fleet statistics for multi-attacker battles
            if (isset($round->hitsPerAttackerFleet[$attacker->fleetMissionId])) {
                $round->hitsPerAttackerFleet[$attacker->fleetMissionId] += 1;
                $round->damagePerAttackerFleet[$attacker->fleetMissionId] += $damage;
            }
        } else {
            $round->hitsDefender += 1;
            $round->fullStrengthDefender += $damage;
            $round->absorbedDamageAttacker += $shieldAbsorption;
        }

        // Rapidfire: if the attacker has a rapidfire bonus against the defender, roll a dice to see if the
        // attacker can attack again.
        if ($attacker->unitObject->didSuccessfulRapidfire($defender->unitObject, $this->roundDraws)) {
            // Rapidfire was successful, return true to indicate that the attacker can attack again.
            return true;
        }

        return false;
    }

    /**
     * Clean up the round after all units have attacked each other.
     *
     * This method handles:
     * - Removing destroyed units from the attacker and defender unit arrays.
     * - Rolling a dice for hull integrity < 70% of original if the unit is also destroyed.
     * - Applying shield regeneration.
     * - Calculate the total damage dealt by the attacker and defender and calculate shield absorption stats.
     *
     * @param BattleResultRound $round.
     * @param array<BattleUnit> $attackerUnits
     * @param array<BattleUnit> $defenderUnits
     * @return void
     */
    private function cleanupRound(BattleResultRound $round, array &$attackerUnits, array &$defenderUnits): void
    {
        // Cleanup attacker units.
        foreach ($attackerUnits as $key => $unit) {
            if ($unit->currentHullPlating <= 0) {
                // Remove destroyed units from the array.
                $round->attackerLossesInRound->addUnit($unit->unitObject, 1);

                // Track per-fleet losses for multi-attacker battles
                if (isset($round->attackerLossesInRoundPerFleet[$unit->fleetMissionId])) {
                    $round->attackerLossesInRoundPerFleet[$unit->fleetMissionId]->addUnit($unit->unitObject, 1);
                }

                unset($attackerUnits[$key]);
            } else {
                // Apply shield regeneration.
                $unit->currentShieldPoints = $unit->originalShieldPoints;
            }
        }

        // Cleanup defender units.
        foreach ($defenderUnits as $key => $unit) {
            if ($unit->currentHullPlating <= 0) {
                // Remove destroyed units from the array.
                $round->defenderLossesInRound->addUnit($unit->unitObject, 1);

                // La perte est attribuee a la flotte qui portait l'unite, garnison comprise.
                if (isset($round->defenderLossesInRoundPerFleet[$unit->fleetMissionId])) {
                    $round->defenderLossesInRoundPerFleet[$unit->fleetMissionId]->addUnit($unit->unitObject, 1);
                }

                unset($defenderUnits[$key]);
            } else {
                // Apply shield regeneration.
                $unit->currentShieldPoints = $unit->originalShieldPoints;
            }
        }
    }

    /**
     * Check and execute the Hamill Manoeuvre special ability.
     * General class Light Fighters have a small chance to instantly destroy one Deathstar before battle.
     *
     * @param BattleResult $result
     * @param array<BattleUnit> $attackerUnits
     * @param array<BattleUnit> $defenderUnits
     * @return void
     */
    private function checkHamillManoeuvre(BattleResult $result, array &$attackerUnits, array &$defenderUnits): void
    {
        // Check if attacker is General class
        $attackerPlayer = $this->getAttackerPlayer();
        $characterClassService = app(CharacterClassService::class);
        if (!$characterClassService->isGeneral($attackerPlayer->getUser())) {
            return;
        }

        // Check if attacker has at least one Light Fighter
        $hasLightFighter = false;
        foreach ($attackerUnits as $unit) {
            if ($unit->unitObject->machine_name === 'light_fighter') {
                $hasLightFighter = true;
                break;
            }
        }

        if (!$hasLightFighter) {
            return;
        }

        // Check if defender has at least one Deathstar
        $deathstarKey = null;
        foreach ($defenderUnits as $key => $unit) {
            if ($unit->unitObject->machine_name === 'deathstar') {
                $deathstarKey = $key;
                break;
            }
        }

        if ($deathstarKey === null) {
            return;
        }

        // Roll the dice for Hamill Manoeuvre
        $settings = app(SettingsService::class);
        $probability = $settings->hamillManoeuvreChance();

        // Une chance sur `$probability`, tiree de la source de la bataille.
        if ($this->draws->chanceOutOf($probability) === 1) {
            // Hamill Manoeuvre triggered! Destroy one Deathstar
            $result->hamillManoeuvreTriggered = true;

            // Remove the Deathstar from defender units array (battle simulation)
            // This prevents it from participating in battle rounds
            unset($defenderUnits[$deathstarKey]);

            // NOTE: We do NOT remove it from defenderUnitsStart or defenderUnitsResult here.
            // The loss will be properly added to defenderUnitsLost in BattleEngine::simulateBattle() (line 142-145).
        }
    }
}
