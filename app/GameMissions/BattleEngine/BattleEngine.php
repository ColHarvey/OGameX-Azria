<?php

namespace OGame\GameMissions\BattleEngine;

use InvalidArgumentException;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\LootAllocator;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Exceptions\IncoherentRoundAttribution;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameMissions\BattleEngine\Services\DefenseRepairService;
use OGame\GameMissions\BattleEngine\Services\TacticalRetreatService;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use RuntimeException;

/**
 * Abstract class BattleEngine.
 *
 * This abstract class provides the base battle simulation functionality, while leaving
 * the core battle round logic to be implemented by specific battle engine implementations such as
 * PhpBattleEngine and RustBattleEngine.
 *
 * @package OGame\GameMissions\BattleEngine
 */
abstract class BattleEngine
{
    /**
     * Le taux de pillage de ce combat, en points de base.
     *
     * **En centiemes de pour-cent, jamais en pour-cent entiers.** La ponderation par le fret
     * produit des taux comme 62,5 % : les tronquer a 62 ferait prendre moins que le taux
     * annonce, et l'ecart grandirait avec le butin.
     */
    protected int $lootRateInBasisPoints = CargoWeightedV1::BASE_RATE;

    /**
     * @var int Le meme taux en pour-cent entiers, pour le rapport de combat.
     *
     * Arrondi vers le bas : un rapport ne doit jamais annoncer plus que ce qui a ete pris.
     */
    protected int $lootPercentage = 50;

    /**
     * Ce que les conversions de ressources de ce combat ont rencontre.
     *
     * **Conserve, jamais journalise ici.** Un moteur qui journalise n est plus rejouable : rejouer
     * les memes faits geles produirait un second journal. La mission agrege et ecrit une fois.
     */
    protected ResourceNormalizationDiagnostics $resourceDiagnostics;

    /**
     * @var bool Whether the attacking initiator withdraws if the defender flees.
     */
    protected bool $retreatAfterDefenderRetreat = false;

    /**
     * BattleEngine constructor.
     *
     * @param array<AttackerFleet> $attackers All attacking fleets.
     * @param PlanetService $defenderPlanet The planet of the defender player (used for loot, moon calculation).
     * @param array<DefenderFleet> $defenders All defending fleets (planet owner + ACS defend fleets).
     * @param SettingsService $settings The settings service.
     * @param LootContext $lootContext Les faits de pillage, deja photographies.
     *
     * ## Pourquoi le contexte est obligatoire
     *
     * Le moteur construisait lui-meme sa politique en interrogeant les modeles vivants. C'est juste
     * pour une bataille instantanee, ou l'observation et le calcul se suivent d'un cheveu. Un combat
     * persistant dure jusqu'a deux heures : la cible peut s'y connecter, un attaquant changer de
     * classe, une recherche s'achever. Relire ces donnees a la resolution ferait dependre le butin de
     * ce qui s'est passe **pendant** le combat.
     *
     * Il n'y a donc pas de valeur par defaut : un contexte construit d'office rendrait au moteur la
     * decision qu'on vient de lui retirer, et accorderait au passage un droit de pillage aux combats
     * qui n'en ont aucun.
     */
    public function __construct(protected array $attackers, protected PlanetService $defenderPlanet, protected array $defenders, private SettingsService $settings, protected LootContext $lootContext)
    {
        if (($this->attackers[0] ?? null) === null) {
            throw new InvalidArgumentException('At least one attacker fleet is required');
        }

        // Le contexte doit avoir ete photographie pour **ces** flottes et **cette** cible. Sans ce
        // controle, rien n'empecherait d'appliquer le taux calcule sur le fret d'un combat aux
        // flottes d'un autre.
        $this->lootContext->ensureItBindsTo($this->attackers, CombatParticipantKey::forBody($this->defenderPlanet));

        $this->resourceDiagnostics = ResourceNormalizationDiagnostics::none();
        $this->lootRateInBasisPoints = $this->lootContext->rateInBasisPoints;
        $this->lootPercentage = intdiv($this->lootRateInBasisPoints, 100);
    }

    /**
     * Configure whether attackers withdraw without a fight when the defender flees.
     */
    public function setRetreatAfterDefenderRetreat(bool $retreatAfterDefenderRetreat): self
    {
        $this->retreatAfterDefenderRetreat = $retreatAfterDefenderRetreat;

        return $this;
    }

    /**
     * Get the primary attacker player (for backward compatibility).
     *
     * @return PlayerService
     */
    protected function getAttackerPlayer(): PlayerService
    {
        return $this->attackers[0]->player;
    }

    /**
     * Get the combined attacker fleet (for backward compatibility).
     *
     * @return UnitCollection
     */
    protected function getAttackerFleet(): UnitCollection
    {
        $combined = new UnitCollection();
        foreach ($this->attackers as $attacker) {
            $combined->addCollection($attacker->units);
        }
        return $combined;
    }

    /**
     * Simulate a battle between two players.
     *
     * @return BattleResult Information about the battle result.
     */
    public function simulateBattle(): BattleResult
    {
        $result = new BattleResult();

        // Initialize the battle result object with the attacker and defender information.
        $result->lootPercentage = $this->lootPercentage;
        $result->lootRateInBasisPoints = $this->lootRateInBasisPoints;

        // Tout ce qu'il faudra pour expliquer ce butin sans le recalculer. Un rapport differe et un
        // retour de flotte lisent ces valeurs ; ils ne repartissent rien de nouveau a l'echeance.
        $result->lootPolicyVersion = $this->lootContext->policyVersion;
        $result->lootAllocatorVersion = $this->lootContext->allocatorVersion;
        $result->lootFrozenFacts = $this->lootContext->toFrozenFacts();
        $result->lootSnapshotFingerprint = $this->lootContext->snapshotFingerprint;
        $result->resourceDiagnostics = $this->resourceDiagnostics;

        // Use primary attacker for tech levels (first attacker in array)
        $primaryAttacker = $this->attackers[0];
        $attackerPlayer = $primaryAttacker->player;

        // Get base research levels
        $attackerWeaponBase = $attackerPlayer->getResearchLevel('weapon_technology');
        $attackerShieldBase = $attackerPlayer->getResearchLevel('shielding_technology');
        $attackerArmorBase = $attackerPlayer->getResearchLevel('armor_technology');

        $defenderPlayer = $this->defenderPlanet->getPlayer();
        if ($defenderPlayer === null) {
            throw new RuntimeException('Battle defender planet has no owner.');
        }
        $defenderWeaponBase = $defenderPlayer->getResearchLevel('weapon_technology');
        $defenderShieldBase = $defenderPlayer->getResearchLevel('shielding_technology');
        $defenderArmorBase = $defenderPlayer->getResearchLevel('armor_technology');

        // Apply General class combat research bonus (+2 levels)
        $characterClassService = app(CharacterClassService::class);
        $attackerCombatBonus = $characterClassService->getAdditionalCombatResearchLevels($attackerPlayer->getUser());
        $defenderCombatBonus = $characterClassService->getAdditionalCombatResearchLevels($defenderPlayer->getUser());

        $result->attackerWeaponLevel = $attackerWeaponBase + $attackerCombatBonus;
        $result->attackerShieldLevel = $attackerShieldBase + $attackerCombatBonus;
        $result->attackerArmorLevel = $attackerArmorBase + $attackerCombatBonus;

        $result->defenderWeaponLevel = $defenderWeaponBase + $defenderCombatBonus;
        $result->defenderShieldLevel = $defenderShieldBase + $defenderCombatBonus;
        $result->defenderArmorLevel = $defenderArmorBase + $defenderCombatBonus;

        // Combine all attacker fleets for backward-compatible units tracking
        $result->attackerUnitsStart = new UnitCollection();
        foreach ($this->attackers as $attacker) {
            $result->attackerUnitsStart->addCollection($attacker->units);
        }
        $result->attackerUnitsResult = clone $result->attackerUnitsStart;

        // Initialize per-attacker fleet results
        foreach ($this->attackers as $attacker) {
            $fleetResult = new AttackerFleetResult(
                $attacker->fleetMissionId,
                $attacker->ownerId,
                $attacker->units
            );
            $result->attackerFleetResults[] = $fleetResult;
        }

        $result->defenderUnitsStart = new UnitCollection();

        // Collect units from all defending fleets and initialize per-fleet results
        foreach ($this->defenders as $defenderFleet) {
            $result->defenderUnitsStart->addCollection($defenderFleet->units);

            // Initialize result tracking for this fleet
            $fleetResult = new DefenderFleetResult(
                $defenderFleet->fleetMissionId,
                $defenderFleet->ownerId,
                $defenderFleet->units
            );
            $result->defenderFleetResults[] = $fleetResult;
        }

        $result->defenderUnitsResult = clone $result->defenderUnitsStart;

        // Evaluate tactical retreat before combat rounds (shared by PHP and Rust engines).
        $this->applyTacticalRetreat($result);

        if ($result->tacticalRetreatAttackerAlsoRetreated) {
            // Both sides withdraw — no rounds, no losses, no loot from combat.
            $result->rounds = [];
            $result->attackerUnitsResult = clone $result->attackerUnitsStart;
            $result->defenderUnitsResult = clone $result->defenderUnitsStart;
            foreach ($result->attackerFleetResults as $fleetResult) {
                $fleetResult->unitsResult = clone $fleetResult->unitsStart;
                $fleetResult->unitsLost = new UnitCollection();
                $fleetResult->completelyDestroyed = false;
                $fleetResult->calculateResourceLoss();
            }
            foreach ($result->defenderFleetResults as $fleetResult) {
                $fleetResult->unitsResult = clone $fleetResult->unitsStart;
                $fleetResult->unitsLost = new UnitCollection();
                $fleetResult->completelyDestroyed = false;
            }
        } else {
            // Execute the battle rounds, this will handle the actual combat logic.
            $result->rounds = $this->fightBattleRounds($result);

            // Sanitize the round array to make sure that the remaining attacker and defender units
            // for every round contain the starting unit types, even if there are no units of that type left.
            // This is important for the battle report to show all units that were part of the battle on
            // every round.
            $result->rounds = $this->sanitizeRoundArray($result->rounds);

            // **Chaque perte porte le nom de qui l'a subie**, des deux camps, sous une clef typee. Une
            // attribution qui ne recouvre pas les pertes du camp arrete le moteur ici.
            $this->keyRoundLossesByParticipant($result->rounds);

            // Get the result of the battle.
            if (count($result->rounds) > 0) {
                // Take the remaining ships in the last round as the result.
                $round = end($result->rounds);
                $result->attackerUnitsResult = $round->attackerShips;
                $result->defenderUnitsResult = $round->defenderShips;
            } else {
                // If no rounds were fought, the result is the same as the start.
                $result->attackerUnitsResult = $result->attackerUnitsStart;
                $result->defenderUnitsResult = $result->defenderUnitsStart;
            }
        }

        // Calculate the resources lost by the attacker and defender.
        // Deduct defender's lost units from the defenders planet.
        // Only subtract unit types present in the start collection — sanitizeRoundArray may
        // still add zero-amount unit types that participated in combat but were wiped out.
        $result->attackerUnitsLost = clone $result->attackerUnitsStart;
        foreach ($result->attackerUnitsResult->units as $entry) {
            if ($result->attackerUnitsLost->hasUnit($entry->unitObject)) {
                $result->attackerUnitsLost->removeUnit($entry->unitObject, $entry->amount);
            }
        }
        $result->attackerResourceLoss = $result->attackerUnitsLost->toResources();

        $result->defenderUnitsLost = clone $result->defenderUnitsStart;
        foreach ($result->defenderUnitsResult->units as $entry) {
            if ($result->defenderUnitsLost->hasUnit($entry->unitObject)) {
                $result->defenderUnitsLost->removeUnit($entry->unitObject, $entry->amount);
            }
        }

        // Add Hamill Manoeuvre Deathstar loss if it was triggered
        if ($result->hamillManoeuvreTriggered) {
            $deathstarObject = ObjectService::getShipObjectByMachineName('deathstar');
            $result->defenderUnitsLost->addUnit($deathstarObject, 1);
        }

        $result->defenderResourceLoss = $result->defenderUnitsLost->toResources();

        // Calculate repaired defenses (only defense units, not ships).
        // According to game rules, approximately 70% of destroyed defenses are repaired after battle.
        // Ingenieur : la part reconstruite passe de 70 % a 85 %. Cette methode appartient
        // a la classe de base partagee, le bonus vaut donc pour les deux moteurs de combat.
        $defenseRepairRate = $defenderPlayer->hasEngineer() ? 85 : $this->settings->defenseRepairRate();
        $defenseRepairService = new DefenseRepairService($defenseRepairRate);
        $result->repairedDefenses = $defenseRepairService->calculateRepairedDefenses($result->defenderUnitsLost);

        // Determine winner of battle.
        // Attacker withdrawal after defender flee is not a combat win — never grant loot.
        if ($result->tacticalRetreatAttackerAlsoRetreated) {
            $result->loot = new Resources(0, 0, 0, 0);
        } elseif ($result->defenderUnitsResult->getAmount() === 0) {
            // ---
            // [WIN] - If attacker wins:
            // ---
            // Check if the attacker has enough cargo capacity to carry the loot.
            // If not, reduce the loot to the cargo capacity.
            $result->loot = $this->calculateLootCapacityConstrained();
        } else {
            $result->loot = new Resources(0, 0, 0, 0);
        }

        // Distribute loot and surviving cargo proportionally among each attacker fleet.
        $this->distributeResources($result);

        // **Toutes les capacites de fret sont gelees ici, pas relues au reglement.**
        //
        // Elles decident ce qui est transfere : ce qu'une flotte rapporte, ce qu'un renfort garde de
        // sa cargaison, combien de debris un Faucheur ramasse. Chacune depend de la classe et de
        // l'hyperespace de son proprietaire, et le reglement les recalculait a l'echeance — des
        // heures apres la bataille sur le chemin durable. Un changement survenu **pendant** la
        // bataille changeait donc une ressource transferee, et deux rejeux du meme combat ne
        // rendaient pas le meme nombre.
        $this->freezeCargoCapacities($result);

        // Calculate debris.
        // Only permanently lost defenses contribute to debris (destroyed - repaired).
        $permanentlyLostDefenderUnits = clone $result->defenderUnitsLost;
        $permanentlyLostDefenderUnits->subtractCollection($result->repairedDefenses);

        // Calculate wreck field and debris
        $result->wreckField = $this->calculateWreckField($result->defenderUnitsLost, $result->defenderUnitsStart);
        $result->debris = $this->calculateDebris($result->attackerUnitsLost, $permanentlyLostDefenderUnits);

        // Determine if a moon already exists for defender's planet.
        // If defender is a moon, moonExisted should be true (the moon itself exists).
        $result->moonExisted = $this->defenderPlanet->isMoon() || $this->defenderPlanet->hasMoon();

        // Calculate moon percentage if a moon does not exist yet.
        if ($result->moonExisted) {
            $result->moonChance = 0;
            $result->moonCreated = false;
        } else {
            $result->moonChance = $this->calculateMoonChance($result->debris);
            $result->moonCreated = $this->rollMoonCreation($result->moonChance);
        }

        return $result;
    }

    /**
     * Nomme, dans chaque round, le participant qui a subi chaque perte — des deux camps.
     *
     * ## Ce que les moteurs fournissent, et ce que cette methode en fait
     *
     * Les deux moteurs attribuent chaque vaisseau detruit a sa flotte, par identifiant de mission :
     * `attackerLossesInRoundPerFleet` et `defenderLossesInRoundPerFleet`, la garnison sous `0`. Ces
     * cartes disent d'ou vient une perte, mais leur clef ne nomme personne : `0` est un identifiant
     * de mission que rien ne distingue d'une flotte sans identifiant.
     *
     * La carte derivee ici porte les clefs des inscriptions au combat : la garnison est le corps,
     * chaque flotte sa mission. C'est elle que la chronologie d'un defenseur lira, et elle que le
     * banc de parite comparera entre les deux moteurs.
     *
     * ## Pourquoi un refus
     *
     * Si les attributions d'un camp ne font pas exactement ses pertes du round, le moteur a perdu
     * des vaisseaux sans dire de qui. Completer en versant le reste a la garnison rendrait ce defaut
     * invisible — c'est precisement ce qu'un moteur qui ne suit pas les flottes produirait. Le
     * resultat ne se produit pas.
     *
     * @param array<BattleResultRound> $rounds
     */
    protected function keyRoundLossesByParticipant(array $rounds): void
    {
        $garnison = CombatParticipantKey::forBody($this->defenderPlanet);

        foreach ($rounds as $rang => $round) {
            $parParticipant = [];

            // Une attaquante sans mission — la sonde ephemere du contre-espionnage, ou un banc —
            // porte le nom reserve : lui inventer un identifiant nommerait une flotte qui n'existe pas.
            foreach ($round->attackerLossesInRoundPerFleet as $mission => $pertes) {
                $parParticipant[$mission === 0 ? CombatParticipantKey::EPHEMERAL_ATTACKER : CombatParticipantKey::forFleet($mission)] = clone $pertes;
            }

            foreach ($round->defenderLossesInRoundPerFleet as $mission => $pertes) {
                $parParticipant[$mission === 0 ? $garnison : CombatParticipantKey::forFleet($mission)] = clone $pertes;
            }

            $this->refuseIfTheAttributionDoesNotCoverTheLosses($rang + 1, 'attaquant', $round->attackerLossesInRoundPerFleet, $round->attackerLossesInRound);
            $this->refuseIfTheAttributionDoesNotCoverTheLosses($rang + 1, 'defenseur', $round->defenderLossesInRoundPerFleet, $round->defenderLossesInRound);

            ksort($parParticipant);
            $round->lossesInRoundByParticipant = $parParticipant;
        }
    }

    /**
     * @param array<int, UnitCollection> $parFlotte
     */
    private function refuseIfTheAttributionDoesNotCoverTheLosses(int $round, string $camp, array $parFlotte, UnitCollection $pertesDuCamp): void
    {
        $attribue = [];

        foreach ($parFlotte as $pertes) {
            foreach ($pertes->units as $entree) {
                if ($entree->amount === 0) {
                    continue;
                }

                $attribue[$entree->unitObject->machine_name] = ($attribue[$entree->unitObject->machine_name] ?? 0) + $entree->amount;
            }
        }

        $perdu = [];

        foreach ($pertesDuCamp->units as $entree) {
            if ($entree->amount === 0) {
                continue;
            }

            $perdu[$entree->unitObject->machine_name] = $entree->amount;
        }

        ksort($attribue);
        ksort($perdu);

        if ($attribue !== $perdu) {
            throw IncoherentRoundAttribution::inRound($round, $camp, $attribue, $perdu);
        }
    }

    /**
     * Gele toutes les capacites de fret dont l'application aura besoin.
     *
     * ## Pourquoi ici, et une fois
     *
     * Une capacite de fret depend de la classe du joueur et de sa recherche d'hyperespace. Sur le
     * chemin instantane, la relire a l'application est sans danger : quelques millisecondes separent
     * les deux. Sur le chemin durable, **des heures** les separent, et le joueur peut avoir change
     * de classe ou monte sa recherche entre-temps.
     *
     * Chacune de ces capacites decide d'un transfert reel : la cargaison qu'un renfort garde, ce
     * qu'une flotte peut rapporter, combien de debris un Faucheur ramasse. Les relire a l'echeance
     * faisait donc dependre une ressource transferee d'un bonus acquis **apres** le calcul de la
     * bataille — et deux rejeux du meme resultat gele ne rendaient pas le meme nombre.
     *
     * Elles sont donc toutes prises ici, a l'instant ou la bataille est calculee, et le reglement ne
     * fait plus que les lire.
     */
    protected function freezeCargoCapacities(BattleResult $result): void
    {
        $initiatrice = $this->attackers[0] ?? null;
        $garnison = null;

        foreach ($this->defenders as $defenseur) {
            if ($defenseur->fleetMissionId === 0) {
                $garnison = $defenseur;
                break;
            }
        }

        // **Le camp attaquant, vu comme le reglement le voyait** : la totalite des unites
        // survivantes, mesuree avec le joueur de l'initiatrice. La mesure ne change pas ; seul
        // l'instant ou elle est prise change.
        if ($initiatrice !== null) {
            $result->attackerSurvivingCargoCapacity = $result->attackerUnitsResult->getTotalCargoCapacity($initiatrice->player);

            $faucheur = ObjectService::getShipObjectByMachineName('reaper');
            $faucheursAttaquants = $result->attackerUnitsResult->getAmountByMachineName('reaper');
            $result->attackerReaperCargoCapacity = (int)($faucheur->properties->capacity->calculate($initiatrice->player)->totalValue * $faucheursAttaquants);
        }

        if ($garnison !== null) {
            $faucheur = ObjectService::getShipObjectByMachineName('reaper');
            $faucheursDefenseurs = $result->defenderUnitsResult->getAmountByMachineName('reaper');
            $result->defenderReaperCargoCapacity = (int)($faucheur->properties->capacity->calculate($garnison->player)->totalValue * $faucheursDefenseurs);
        }

        // **Chaque renfort defensif porte les deux capacites qui fixent sa proportion.** La garnison
        // n'a pas de cargaison a rendre : ses deux valeurs restent nulles, et rien ne les lit.
        foreach ($result->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === 0) {
                continue;
            }

            foreach ($this->defenders as $defenseur) {
                if ($defenseur->fleetMissionId !== $flotte->fleetMissionId) {
                    continue;
                }

                $flotte->startingCargoCapacity = $defenseur->units->getTotalCargoCapacity($defenseur->player);
                $flotte->survivingCargoCapacity = $flotte->unitsResult->getTotalCargoCapacity($defenseur->player);

                break;
            }
        }
    }

    /**
     * Evaluate and apply tactical retreat before combat rounds.
     * Fleeing ships are removed from combat but remain on the planet.
     */
    protected function applyTacticalRetreat(BattleResult $result): void
    {
        $service = new TacticalRetreatService();
        $decision = $service->evaluate(
            $this->defenderPlanet,
            $this->attackers,
            $this->defenders,
            $this->retreatAfterDefenderRetreat,
        );

        $result->tacticalRetreatRatio = $decision->ratio;
        $result->tacticalRetreatAttackerPoints = $decision->attackerPoints;
        $result->tacticalRetreatDefenderPoints = $decision->defenderPoints;
        $result->tacticalRetreatDeuteriumCost = $decision->deuteriumCost;
        $result->tacticalRetreatFleeingUnits = $decision->fleeingUnits;
        $result->tacticalRetreatDefenderFled = $decision->defenderFled;
        $result->tacticalRetreatAttackerAlsoRetreated = $decision->attackerAlsoRetreated;

        if (!$decision->defenderFled) {
            return;
        }

        // Deduct flee deuterium before loot calculation so cargo theft uses updated stocks.
        if ($decision->deuteriumCost > 0) {
            $this->defenderPlanet->deductResources(new Resources(0, 0, $decision->deuteriumCost, 0));
        }

        // Strip fleeing ships from the planet-owner defender fleet (ACS defend fleets stay).
        foreach ($this->defenders as $defenderFleet) {
            if ($defenderFleet->fleetMissionId !== 0) {
                continue;
            }

            foreach ($decision->fleeingUnits->units as $entry) {
                if ($defenderFleet->units->hasUnit($entry->unitObject)) {
                    $defenderFleet->units->removeUnit($entry->unitObject, $entry->amount, true);
                }
            }
        }

        // Rebuild aggregated defender start units and planet-owner fleet result start.
        $result->defenderUnitsStart = new UnitCollection();
        foreach ($this->defenders as $defenderFleet) {
            $result->defenderUnitsStart->addCollection($defenderFleet->units);
        }
        $result->defenderUnitsResult = clone $result->defenderUnitsStart;

        foreach ($result->defenderFleetResults as $fleetResult) {
            if ($fleetResult->fleetMissionId !== 0) {
                continue;
            }
            foreach ($this->defenders as $defenderFleet) {
                if ($defenderFleet->fleetMissionId === 0) {
                    $fleetResult->unitsStart = clone $defenderFleet->units;
                    break;
                }
            }
        }
    }

    /**
     * Fight the battle rounds according to the specific battle engine implementation.
     *
     * @param BattleResult $result
     * @return array<BattleResultRound>
     */
    abstract protected function fightBattleRounds(BattleResult $result): array;

    /**
     * Calculate the battle loot constrained by the total cargo capacity of all attackers.
     *
     * For ACS battles, cargo capacity must be summed using each fleet owner's own research
     * and class modifiers instead of evaluating a combined fleet against the initiator only.
     *
     * @return Resources
     */
    protected function calculateLootCapacityConstrained(): Resources
    {
        $resources = $this->defenderPlanet->getResources();
        $loot = new Resources(
            $this->lootableAmount($resources->metal->get()),
            $this->lootableAmount($resources->crystal->get()),
            $this->lootableAmount($resources->deuterium->get()),
            0
        );

        // **Le moteur appelle la regle directement, sans passer par la facade.** Un combat traverse
        // deja la facade cinq fois pendant sa resolution ; y ajouter le butin melangerait deux
        // proprietaires pour une meme operation.
        //
        // Il ne journalise pas non plus : les diagnostics sont conserves sur le resultat, et la
        // mission — le seul appelant qui voit l operation entiere — ecrira une fois.
        $plafonne = $this->lootAllocator()->capByCargo($loot, $this->getTotalAttackerCargoCapacity());
        $this->resourceDiagnostics = $this->resourceDiagnostics->mergedWith($plafonne->diagnostics);

        return $plafonne->resources;
    }

    /**
     * Ce qu'un stock permet de piller, au taux de ce combat.
     *
     * **Le stock est arrondi vers le bas avant le taux** : on ne peut pas prendre une fraction
     * d'unite qui n'existe pas encore. La borne reservee, elle, arrondit vers le haut — c'est ce
     * qui garantit qu'elle couvre toujours ce calcul-ci.
     *
     * @param float $inStock
     * @return int
     */
    private function lootableAmount(float $inStock): int
    {
        $diagnostics = $this->resourceDiagnostics;
        $montant = $this->lootAllocator()->lootableAmount($inStock, $this->lootRateInBasisPoints, ExactLootAllocationV1::PHASE_TARGET_LOOT, $diagnostics);
        $this->resourceDiagnostics = $diagnostics;

        return $montant;
    }

    /**
     * La regle de pillage sous laquelle ce combat est calcule.
     *
     * **La version vient du contexte, jamais du registre.** Le registre dit quelle regle sert aux
     * nouveaux combats ; celui-ci se reclame de la sienne, et changer la valeur par defaut ne doit
     * rien changer a une instance deja ouverte.
     *
     * @return LootAllocator
     */
    protected function lootAllocator(): LootAllocator
    {
        return LootAllocatorRegistry::default()->forVersion($this->lootContext->allocatorVersion);
    }

    /**
     * Sum the cargo capacity of all attacking fleets using each fleet owner's modifiers.
     *
     * @return int
     */
    private function getTotalAttackerCargoCapacity(): int
    {
        $totalCapacity = 0;
        foreach ($this->attackers as $attacker) {
            $totalCapacity += $attacker->units->getTotalCargoCapacity($attacker->player);
        }

        return $totalCapacity;
    }

    /**
     * Populate survivingCargo and lootShare for each attacker fleet result.
     *
     * For multi-attacker battles, loot is allocated proportionally to each fleet's
     * surviving cargo capacity. Carried resources survive at the same rate as cargo
     * capacity (proportional to the fraction of ships that survived). Each fleet's
     * loot share is further capped by the space remaining after surviving cargo is
     * accounted for.
     *
     * @param BattleResult $result
     * @return void
     */
    protected function distributeResources(BattleResult $result): void
    {
        // Index attacker fleets by fleet mission ID for efficient lookup.
        $attackersByMissionId = [];
        foreach ($this->attackers as $attacker) {
            $attackersByMissionId[$attacker->fleetMissionId] = $attacker;
        }

        // Step 1: Compute per-fleet surviving cargo capacity and surviving carried resources.
        $survivingCapacityByFleet = []; // fleetMissionId => int
        $totalSurvivingCapacity = 0;

        foreach ($result->attackerFleetResults as $fleetResult) {
            $attacker = $attackersByMissionId[$fleetResult->fleetMissionId] ?? null;
            if ($attacker === null || $fleetResult->completelyDestroyed) {
                $survivingCapacityByFleet[$fleetResult->fleetMissionId] = 0;
                $fleetResult->survivingCargo = new Resources(0, 0, 0, 0);
                continue;
            }

            $originalCapacity = $attacker->getSurvivingCargoCapacity($attacker->units);
            $survivingCapacity = $attacker->getSurvivingCargoCapacity($fleetResult->unitsResult);

            $survivingCapacityByFleet[$fleetResult->fleetMissionId] = $survivingCapacity;
            $fleetResult->startingCargoCapacity = $originalCapacity;
            $fleetResult->survivingCargoCapacity = $survivingCapacity;
            $totalSurvivingCapacity += $survivingCapacity;

            // Carried resources survive at the same rate as cargo capacity.
            $survivalRate = $originalCapacity > 0 ? $survivingCapacity / $originalCapacity : 0.0;
            $fleetResult->survivingCargo = new Resources(
                max(0, (int)($attacker->cargoResources->metal->get() * $survivalRate)),
                max(0, (int)($attacker->cargoResources->crystal->get() * $survivalRate)),
                max(0, (int)($attacker->cargoResources->deuterium->get() * $survivalRate)),
                0
            );

            $fleetResult->lootShare = new Resources(0, 0, 0, 0);
        }

        // Step 2: Distribute loot proportionally by surviving cargo capacity.
        if ($totalSurvivingCapacity <= 0 || $result->loot->sum() <= 0) {
            $result->loot = $this->sumLootShares($result);
            return;
        }

        $remainingLootCapacityByFleet = [];
        foreach ($result->attackerFleetResults as $fleetResult) {
            $survivingCapacity = $survivingCapacityByFleet[$fleetResult->fleetMissionId] ?? 0;
            $remainingLootCapacityByFleet[$fleetResult->fleetMissionId] = max(
                0,
                (int)($survivingCapacity - $fleetResult->survivingCargo->sum())
            );
        }

        foreach (['metal', 'crystal', 'deuterium'] as $resourceName) {
            $this->distributeLootResource(
                (int)$result->loot->{$resourceName}->get(),
                $resourceName,
                $result,
                $survivingCapacityByFleet,
                $remainingLootCapacityByFleet
            );
        }

        // Normalize the battle loot to what was actually assigned to surviving fleets so any
        // excess that did not fit remains on the defender planet instead of disappearing.
        $result->loot = $this->sumLootShares($result);
    }

    /**
     * Applique aux flottes ce que la regle versionnee leur attribue pour une ressource.
     *
     * Le moteur ne decide plus de la repartition : il fournit les poids et la place restante, et
     * inscrit le resultat. La regle — plus forts restes, priorite de l'initiateur, plafonnement par
     * le fret — appartient a `ExactLootAllocationV1`, sous une version persistee avec le combat.
     *
     * @param int $resourceAmount
     * @param string $resourceName
     * @param BattleResult $result
     * @param array<int, int> $survivingCapacityByFleet
     * @param array<int, int> $remainingLootCapacityByFleet
     * @return void
     */
    private function distributeLootResource(
        int $resourceAmount,
        string $resourceName,
        BattleResult $result,
        array $survivingCapacityByFleet,
        array &$remainingLootCapacityByFleet
    ): void {
        if ($resourceAmount <= 0) {
            return;
        }

        $poids = [];

        foreach ($result->attackerFleetResults as $fleetResult) {
            $poids[$fleetResult->fleetMissionId] = $survivingCapacityByFleet[$fleetResult->fleetMissionId] ?? 0;
        }

        $parts = $this->lootAllocator()->shareBetweenFleets(
            $resourceAmount,
            $poids,
            $remainingLootCapacityByFleet,
            $this->getInitiatorFleetMissionId()
        );

        foreach ($parts as $fleetMissionId => $part) {
            if ($part <= 0) {
                continue;
            }

            $this->addLootShareToFleet($result, $fleetMissionId, $resourceName, $part);
            $remainingLootCapacityByFleet[$fleetMissionId] -= $part;
        }
    }

    /**
     * Get the fleet mission ID of the ACS initiator.
     *
     * @return int
     */
    private function getInitiatorFleetMissionId(): int
    {
        foreach ($this->attackers as $attacker) {
            if ($attacker->isInitiator) {
                return $attacker->fleetMissionId;
            }
        }

        return $this->attackers[0]->fleetMissionId;
    }

    /**
     * Add an allocated loot amount to the matching fleet result.
     *
     * @param BattleResult $result
     * @param int $fleetMissionId
     * @param string $resourceName
     * @param int $amount
     * @return void
     */
    private function addLootShareToFleet(BattleResult $result, int $fleetMissionId, string $resourceName, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        foreach ($result->attackerFleetResults as $fleetResult) {
            if ($fleetResult->fleetMissionId !== $fleetMissionId) {
                continue;
            }

            $fleetResult->lootShare->{$resourceName}->set(
                $fleetResult->lootShare->{$resourceName}->get() + $amount
            );

            return;
        }
    }

    /**
     * Sum the loot actually assigned across all attacker fleets.
     *
     * @param BattleResult $result
     * @return Resources
     */
    private function sumLootShares(BattleResult $result): Resources
    {
        $totalLoot = new Resources(0, 0, 0, 0);

        foreach ($result->attackerFleetResults as $fleetResult) {
            $totalLoot->add($fleetResult->lootShare);
        }

        return $totalLoot;
    }

    /**
     * Calculate the debris field based on the units lost in the battle.
     *
     * @param UnitCollection $attackerUnitsLost
     * @param UnitCollection $defenderUnitsLost
     * @return Resources
     */
    protected function calculateDebris(UnitCollection $attackerUnitsLost, UnitCollection $defenderUnitsLost): Resources
    {
        $metal = 0;
        $crystal = 0;
        $deuterium = 0;

        // Calculate actual debris percentage after accounting for wreck fields
        $shipsToDebrisPercentage = $this->settings->debrisFieldFromShips();
        $defenseToDebrisPercentage = $this->settings->debrisFieldFromDefense();
        $deuteriumOn = $this->settings->debrisFieldDeuteriumOn();

        // Combine the attacker and defender losses to calculate the debris.
        $allUnitsLost = new UnitCollection();
        $allUnitsLost->addCollection($attackerUnitsLost);
        $allUnitsLost->addCollection($defenderUnitsLost);

        // Handle attacker losses.
        foreach ($allUnitsLost->units as $unit) {
            if ($unit->unitObject->type === GameObjectType::Ship) {
                if ($shipsToDebrisPercentage > 0) {
                    $metal += floor(($unit->unitObject->price->resources->metal->get() * $unit->amount) * ($shipsToDebrisPercentage / 100));
                    $crystal += floor(($unit->unitObject->price->resources->crystal->get() * $unit->amount) * ($shipsToDebrisPercentage / 100));
                    if ($deuteriumOn) {
                        $deuterium += floor(($unit->unitObject->price->resources->deuterium->get() * $unit->amount) * ($shipsToDebrisPercentage / 100));
                    }
                }
            } elseif ($unit->unitObject->type === GameObjectType::Defense) {
                if ($defenseToDebrisPercentage > 0) {
                    $metal += floor(($unit->unitObject->price->resources->metal->get() * $unit->amount) * ($defenseToDebrisPercentage / 100));
                    $crystal += floor(($unit->unitObject->price->resources->crystal->get() * $unit->amount) * ($defenseToDebrisPercentage / 100));
                    if ($deuteriumOn) {
                        $deuterium += floor(($unit->unitObject->price->resources->deuterium->get() * $unit->amount) * ($defenseToDebrisPercentage / 100));
                    }
                }
            }
        }

        return new Resources($metal, $crystal, $deuterium, 0);
    }

    /**
     * Calculate the wreck field based on the defender's ships lost in the battle.
     * Only defender's ships can form wreck fields, not attacker's ships.
     *
     * @param UnitCollection $defenderUnitsLost
     * @param UnitCollection $defenderUnitsStart
     * @return array
     */
    protected function calculateWreckField(UnitCollection $defenderUnitsLost, UnitCollection $defenderUnitsStart): array
    {
        $spaceDockPlanet = $this->defenderPlanet->isMoon() ? $this->defenderPlanet->planet() : $this->defenderPlanet;
        $spaceDockLevel = max(1, $spaceDockPlanet->getObjectLevel('space_dock'));
        $spaceDockPlayer = $spaceDockPlanet->getPlayer();
        if ($spaceDockPlayer === null) {
            throw new RuntimeException('Wreck field calculation planet has no owner.');
        }
        $wreckFieldService = new WreckFieldService($spaceDockPlayer, $this->settings);
        $wreckFieldPercentage = $wreckFieldService->getRecoverableWreckFieldPercentage($spaceDockLevel) / 100;
        $wreckFieldData = $wreckFieldService->calculateShipsForWreckField($defenderUnitsLost, $spaceDockLevel);

        // Check if wreck field conditions are met
        $totalLostValue = $defenderUnitsLost->toResources()->metal->get() +
                         $defenderUnitsLost->toResources()->crystal->get() +
                         $defenderUnitsLost->toResources()->deuterium->get();
        $totalFleetValue = $defenderUnitsStart->toResources()->metal->get() +
                          $defenderUnitsStart->toResources()->crystal->get() +
                          $defenderUnitsStart->toResources()->deuterium->get();

        if ($totalFleetValue > 0) {
            $destroyedPercentage = ($totalLostValue / $totalFleetValue) * 100;
            $minResourcesRequired = $this->settings->wreckFieldMinResourcesLoss();
            $minFleetPercentageRequired = $this->settings->wreckFieldMinFleetPercentage();

            // Only return wreck field data if conditions are met
            if ($totalLostValue >= $minResourcesRequired && $destroyedPercentage >= $minFleetPercentageRequired) {
                return [
                    'formed' => true,
                    'conditions_met' => true,
                    'ships' => $wreckFieldData,
                    'total_value' => $totalLostValue * $wreckFieldPercentage,
                    'fleet_percentage' => $destroyedPercentage,
                    'resources_lost' => $totalLostValue,
                ];
            }
        }

        return [
            'formed' => false,
            'conditions_met' => false,
            'ships' => [],
            'total_value' => 0,
            'fleet_percentage' => 0,
            'resources_lost' => 0,
        ];
    }

    /**
     * Sanitizes the round array to make sure that the remaining attacker and defender units
     * for every round contain all the starting unit types.
     *
     * @param array<BattleResultRound> $rounds
     * @return array<BattleResultRound>
     */
    protected function sanitizeRoundArray(array $rounds): array
    {
        $combinedAttackerFleet = $this->getAttackerFleet();

        // Use post-retreat combat fleets so ships that fled are not padded into rounds
        // as zero-amount "destroyed" units (they remain on the planet outside combat).
        $combatDefenderUnits = new UnitCollection();
        foreach ($this->defenders as $defenderFleet) {
            $combatDefenderUnits->addCollection($defenderFleet->units);
        }

        foreach ($rounds as $round) {
            // Ensure all attacker units are present in the round
            foreach ($combinedAttackerFleet->units as $unit) {
                if (!$round->attackerShips->hasUnit($unit->unitObject)) {
                    $round->attackerShips->addUnit($unit->unitObject, 0);
                }
            }

            // Ensure all combat defender units are present in the round
            foreach ($combatDefenderUnits->units as $unit) {
                if (!$round->defenderShips->hasUnit($unit->unitObject)) {
                    $round->defenderShips->addUnit($unit->unitObject, 0);
                }
            }
        }

        return $rounds;
    }

    /**
     * Calculate moon chance based on the debris field.
     *
     * @param Resources $debris
     * @return int
     */
    protected function calculateMoonChance(Resources $debris): int
    {
        $max_moon_chance = $this->settings->maximumMoonChance();

        // Every 100k debris results in 1% moon chance, up to a maximum
        // of max moon chance configured in server settings.
        $moon_chance = floor(($debris->sum()) / 100000);
        if ($moon_chance > $max_moon_chance) {
            $moon_chance = $max_moon_chance;
        }

        return (int)$moon_chance;
    }

    /**
     * Roll the dice to see if a moon is created based on the moon chance.
     *
     * @param int $moonChance
     * @return bool
     */
    protected function rollMoonCreation($moonChance): bool
    {
        $dice = random_int(1, 100);
        return $dice <= $moonChance;
    }
}
