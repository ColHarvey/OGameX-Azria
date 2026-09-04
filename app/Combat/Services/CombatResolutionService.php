<?php

namespace OGame\Combat\Services;

use Closure;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Application\CombatApplicationContext;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMessages\DebrisFieldHarvest;
use OGame\GameMessages\FleetLostContact;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Services\LootService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\DebrisFieldService;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\Npc\NpcDestructionService;
use OGame\Services\Npc\NpcThreatService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use RuntimeException;

/**
 * Applique le resultat d'un combat au jeu.
 *
 * Cette classe est le deplacement, sans aucune modification de comportement, de la seconde
 * moitie de `AttackMission::processArrival()` — une methode de 515 lignes qui faisait tout :
 * garder, collecter, simuler, puis appliquer. Seule l'application est ici.
 *
 * La frontiere est celle du deroulement reel : ce qui precede produit un `BattleResult`, ce
 * qui suit l'applique — butin, pertes, debris, champ d'epaves, lune, messages, retours. Cette
 * separation est ce qui rendra possible un combat qui dure : le resultat pourra etre calcule a
 * l'arrivee et applique plus tard, sans que rien d'autre ne bouge.
 *
 * `startReturn()` est `protected` dans la classe de base des missions. Plutot que d'elargir sa
 * visibilite, la mission passe une fermeture : l'ordre exact des operations est preserve — ce
 * qui est le seul critere pour un deplacement de ce genre — et la couture reste visible.
 */
class CombatResolutionService
{
    /**
     * Nombre de variantes de texte par motif de raid.
     *
     * Doit correspondre au nombre de cles presentes sous t_messages.npc_raid.<motif> dans
     * chaque fichier de langue. Augmenter ce nombre sans ajouter les traductions afficherait
     * une cle brute au joueur.
     */
    public const int NPC_MOTIVE_VARIATIONS = 5;

    /**
     * Get whether this attack was launched by a server-driven faction.
     *
     * Statique et publique : la mission s'en sert avant meme d'avoir un resultat a appliquer,
     * pour la garde du mode vacances, et le code deplace ici s'en sert deux fois. Une seule
     * definition vaut mieux que deux copies de cinq lignes.
     */
    public static function isNpcAttack(FleetMission $mission): bool
    {
        $attacker = User::find($mission->user_id);

        return $attacker !== null && $attacker->is_npc;
    }

    public function __construct(
        protected FleetMissionService $fleetMissionService,
        protected MessageService $messageService,
        protected PlanetServiceFactory $planetServiceFactory,
        protected PlayerServiceFactory $playerServiceFactory,
        protected SettingsService $settings,
    ) {
    }

    /**
     * Apply a simulated battle to the game.
     *
     * @param FleetMission $mission Mission initiatrice, deja arrivee.
     * @param BattleResult $battleResult Resultat simule, pas encore applique.
     * @param PlanetService $defenderPlanet
     * @param PlayerService $defenderPlayer
     * @param array<int, AttackerFleet> $attackerFleets Toutes les flottes attaquantes, union comprise.
     * @param PlayerService $attackerPlayer Proprietaire de la flotte initiatrice.
     * @param array<int, DefenderFleet> $defenders
     * @param int $originPlanetId
     * @param GameMission $missionDeJeu Porte le type de vitesse de flotte, qui determine la duree du retour.
     * @param Closure $creerRetour Cree une mission retour ; delegue a GameMission::startReturn().
     * @param FrozenLootAllocation $allocation L allocation gelee du combat durable ; le chemin
     *        instantane passe celle du debut de l operation, le durable celle qu il a gelee.
     * @param CombatApplicationContext $context Les faits dont l application depend encore — classes,
     *        chantier spatial, reglages et instant du champ d epaves. Le chemin instantane passe le
     *        monde courant, le combat durable la photographie prise a la cloture, sans quoi ce qui
     *        change pendant la bataille en changerait l issue. **Aucun repli** : chaque appelant
     *        nomme sa source, et l oublier est une erreur de type et non un comportement silencieux.
     * @return CombatResolutionOutcome Ce que l application du resultat a rencontre — distinct du
     *         resultat lui-meme, qui reste fige tel que le moteur l a calcule.
     */
    public function resolve(
        FleetMission $mission,
        BattleResult $battleResult,
        PlanetService $defenderPlanet,
        PlayerService $defenderPlayer,
        array $attackerFleets,
        PlayerService $attackerPlayer,
        array $defenders,
        int $originPlanetId,
        GameMission $missionDeJeu,
        Closure $creerRetour,
        FrozenLootAllocation $allocation,
        CombatApplicationContext $context,
    ): CombatResolutionOutcome {
        // Ce que l'application du resultat rencontre lui appartient : le `BattleResult` reste tel
        // que le moteur l'a fige.
        $diagnostics = ResourceNormalizationDiagnostics::none();

        // **La version de l allocateur se choisit ici, une fois, et vaut pour toute la
        // resolution.** Elle etait relue a chaque plafonnement : un deploiement survenu entre
        // deux appels aurait plafonne la premiere moitie de cette bataille sous une regle et la
        // seconde sous une autre, sans que rien ne le signale.
        //
        // C est la frontiere du chemin instantane. Le combat durable, lui, la lit dans ses faits
        // geles par `FrozenLootAllocation::fromFrozenSet()` et la passe en parametre : la
        // resolution ne choisit une version que si personne ne l a choisie avant elle.

        // **Les faits dont l'application depend encore.** Sur le chemin instantane, les lire
        // dans le monde courant est juste : quelques millisecondes separent le calcul de
        // l'application. Le combat durable, lui, en donne une photographie prise a la cloture —
        // un joueur qui change de classe ou monte son chantier spatial pendant la bataille ne
        // doit pas en changer l'issue.

        // Deduct loot from the target planet.
        $defenderPlanet->deductResources($battleResult->loot);

        // Process defender fleet results (planet owner + ACS defend fleets)
        foreach ($battleResult->defenderFleetResults as $fleetResult) {
            if ($fleetResult->fleetMissionId === 0) {
                // Planet owner's stationary forces - remove permanently lost units
                // Calculate permanently lost: lost units minus repaired defenses
                $permanentlyLostUnits = clone $fleetResult->unitsLost;

                // Safely subtract repaired defenses - only subtract units that actually exist in the lost units
                if ($battleResult->repairedDefenses->getAmount() > 0) {
                    foreach ($battleResult->repairedDefenses->units as $repairedUnit) {
                        // Only subtract if this unit type exists in our lost units
                        if ($permanentlyLostUnits->hasUnit($repairedUnit->unitObject)) {
                            $permanentlyLostUnits->removeUnit($repairedUnit->unitObject, $repairedUnit->amount, true);
                        }
                    }
                }

                // Only remove units if there are any to remove
                if ($permanentlyLostUnits->getAmount() > 0) {
                    $defenderPlanet->removeUnits($permanentlyLostUnits, false);
                }

                $defenderPlanet->save();
            } else {
                // ACS Defend fleet - handle return or destruction
                $defendMission = FleetMission::find($fleetResult->fleetMissionId);
                if ($defendMission) {
                    if ($fleetResult->completelyDestroyed) {
                        // Fleet was completely destroyed - no return mission
                        $defendMission->processed = 1;
                        $defendMission->save();

                        // Send fleet lost contact message to the fleet owner
                        $fleetOwner = $this->playerServiceFactory->make($fleetResult->ownerId);
                        $coordinates = '[coordinates]' . $defenderPlanet->getPlanetCoordinates()->asString() . '[/coordinates]';
                        $this->messageService->sendSystemMessageToPlayer($fleetOwner, FleetLostContact::class, [
                            'coordinates' => $coordinates,
                        ]);
                    } else {
                        // Fleet survived - update the outbound ACS defend mission with the
                        // post-battle unit counts and proportional resources. The fleet continues
                        // to hold at the target planet until its hold time expires, at which point
                        // AcsDefendMission::processArrival() creates the single return mission.
                        //
                        // We deliberately do NOT call startReturn() here because doing so would:
                        // 1. Show a second fleet slot in the widget immediately after battle.
                        // 2. Leave a dangling return mission if the player later recalls the fleet,
                        //    causing both missions to process and double the ships returned.
                        $originalUnits = $this->fleetMissionService->getFleetUnits($defendMission);

                        // **La proportion vient des capacites gelees a la cloture, pas du joueur vivant.**
                        //
                        // Les deux capacites etaient recalculees ici sur le proprietaire **vivant**,
                        // c'est-a-dire sur sa classe et son hyperespace du moment. Sur le chemin durable, des
                        // heures separent la cloture de l'echeance : un joueur qui monte sa recherche
                        // pendant la bataille changeait la proportion appliquee a une cargaison
                        // pourtant gelee. Les deux capacites bougent ensemble, mais pas du meme
                        // facteur — la survivante ne compte que les vaisseaux restants — donc le
                        // rapport change, et deux rejeux du meme combat rendaient deux cargaisons.
                        $originalCargoCapacity = $fleetResult->startingCargoCapacity;
                        $remainingCargoCapacity = $fleetResult->survivingCargoCapacity;

                        $survivalRate = $originalCargoCapacity > 0
                            ? $remainingCargoCapacity / $originalCargoCapacity
                            : 0;

                        // Zero out all ship columns first (covers ship types fully destroyed in battle)
                        foreach ($originalUnits->units as $unit) {
                            $defendMission->{$unit->unitObject->machine_name} = 0;
                        }

                        // Set surviving ship counts
                        foreach ($fleetResult->unitsResult->units as $unit) {
                            $defendMission->{$unit->unitObject->machine_name} = $unit->amount;
                        }

                        // **La cargaison de depart vient du contexte, pas de la ligne.**
                        //
                        // Elle etait relue sur la mission au moment de l'ecriture. Sur le chemin
                        // instantane c'est juste — quelques millisecondes separent le calcul de
                        // l'application. Sur le chemin durable, des heures les separent : la valeur
                        // lue n'est plus celle sur laquelle la bataille a ete calculee, et deux
                        // rejeux du meme combat ne rendraient pas la meme cargaison.
                        //
                        // Le contexte gele la porte depuis la cloture ; le contexte vivant la lit
                        // maintenant, comme avant. Un seul applicateur, deux sources de faits.
                        $cargaisonDeDepart = $context->heldFleetCargo((int)$defendMission->id);

                        $defendMission->metal = max(0, (int)($cargaisonDeDepart->metal->get() * $survivalRate));
                        $defendMission->crystal = max(0, (int)($cargaisonDeDepart->crystal->get() * $survivalRate));
                        $defendMission->deuterium = max(0, (int)($cargaisonDeDepart->deuterium->get() * $survivalRate));

                        $defendMission->save();
                    }
                }
            }
        }

        // Process attacker fleet results (handle multi-fleet ACS battles)
        // Only use this loop for multi-attacker battles (count > 1)
        // Single-attacker battles use the original logic below
        if (count($battleResult->attackerFleetResults) > 1) {
            // Multi-attacker battle - process each fleet separately
            foreach ($battleResult->attackerFleetResults as $fleetResult) {
                $fleetMission = FleetMission::find($fleetResult->fleetMissionId);
                if (!$fleetMission || $fleetMission->canceled) {
                    continue; // Fleet may have been recalled or deleted
                }

                if ($fleetResult->completelyDestroyed || !$fleetResult->hasSurvivors()) {
                    // Fleet completely destroyed - no return mission
                    $fleetMission->processed = 1;
                    $fleetMission->save();

                    // Send fleet lost contact message to the fleet owner
                    $fleetOwner = $this->playerServiceFactory->make($fleetResult->playerId);
                    $coordinates = '[coordinates]' . $defenderPlanet->getPlanetCoordinates()->asString() . '[/coordinates]';
                    $this->messageService->sendSystemMessageToPlayer($fleetOwner, FleetLostContact::class, [
                        'coordinates' => $coordinates,
                    ]);
                } else {
                    // Fleet survived - create return mission with survivors
                    $fleetOwner = $this->playerServiceFactory->make($fleetResult->playerId);

                    // TODO: Include Reaper-collected debris share for multi-attacker battles.
                    // Currently Reaper debris collection only works for single-attacker path.
                    $totalResources = new Resources(
                        $fleetResult->survivingCargo->metal->get() + $fleetResult->lootShare->metal->get(),
                        $fleetResult->survivingCargo->crystal->get() + $fleetResult->lootShare->crystal->get(),
                        $fleetResult->survivingCargo->deuterium->get() + $fleetResult->lootShare->deuterium->get(),
                        0
                    );

                    // Ensure total doesn't exceed surviving cargo capacity
                    // La capacite survivante de cette flotte, gelee a la cloture.
                    $remainingCargoCapacity = $fleetResult->survivingCargoCapacity;
                    if ($totalResources->sum() > $remainingCargoCapacity) {
                        $totalResources = $this->capAndCollect($totalResources, $remainingCargoCapacity, $allocation, $diagnostics, CombatResolutionOutcome::PHASE_RETURN_CAP, CombatParticipantKey::forFleet($fleetResult->fleetMissionId));
                    }

                    // Calculate natural return duration based on surviving ships and owner's tech.
                    // In original OGame, post-battle returns use each fleet's own natural speed,
                    // not the synced union speed. The origin planet provides correct tech levels
                    // for speed calculation, and distance is symmetric.
                    if ($fleetMission->planet_id_from === null) {
                        throw new RuntimeException('Attacking fleet mission has no origin planet.');
                    }
                    $originPlanet = $this->planetServiceFactory->makeForPlayer(
                        $fleetOwner,
                        $fleetMission->planet_id_from
                    );
                    $naturalReturnDuration = $this->fleetMissionService->calculateFleetMissionDuration(
                        $originPlanet,
                        $defenderPlanet->getPlanetCoordinates(),
                        $fleetResult->unitsResult,
                        $missionDeJeu,
                        10
                    );

                    // Calculate wreck field for General class attacker
                    // General perk: wreck field from attacker's lost ships is transported back with the return mission
                    $attackerWreckFieldData = null;
                    if ($context->isGeneral($fleetOwner)) {
                        $attackerWreckFieldData = $this->calculateAttackerWreckField($fleetResult->unitsLost, $fleetResult->unitsStart, $originPlanet, $context);
                    }

                    // Mark outbound mission as processed and create return mission with survivors
                    $fleetMission->processed = 1;
                    $fleetMission->save();
                    ($creerRetour)($fleetMission, $totalResources, $fleetResult->unitsResult, 0, $attackerWreckFieldData, $naturalReturnDuration);
                }
            }
        }

        // Create or append debris field.
        // TODO: we could change this debris field append logic to do everything in a single query to
        // prevent race conditions. Check this later when looking into reducing chance of race conditions occurring.
        $debrisFieldService = resolve(DebrisFieldService::class);
        $debrisFieldService->loadOrCreateForCoordinates($defenderPlanet->getPlanetCoordinates());

        // Check if attacker has Reaper ships for automatic debris collection (General class only)
        $attackerCollectedDebris = new Resources(0, 0, 0, 0);
        $defenderCollectedDebris = new Resources(0, 0, 0, 0);
        // Attacker Reaper collection
        $attackerDebrisCollectionPercentage = $context->reaperDebrisCollectionPercentage($attackerPlayer);
        if ($attackerDebrisCollectionPercentage > 0 && $battleResult->attackerUnitsResult->getAmountByMachineName('reaper') > 0) {
            // Calculate 30% of the debris to be collected automatically
            $collectionAmount = new Resources(
                (int)($battleResult->debris->metal->get() * $attackerDebrisCollectionPercentage),
                (int)($battleResult->debris->crystal->get() * $attackerDebrisCollectionPercentage),
                (int)($battleResult->debris->deuterium->get() * $attackerDebrisCollectionPercentage),
                0
            );

            // La capacite des Faucheurs attaquants survivants, gelee a la cloture : elle decide
            // combien de debris changent de proprietaire.
            $reaperCargoCapacity = $battleResult->attackerReaperCargoCapacity;

            // Limit collected debris to Reaper cargo capacity
            // (Can collect maximum 30% of debris OR Reaper capacity, whichever is lower)
            if ($collectionAmount->sum() <= $reaperCargoCapacity) {
                $attackerCollectedDebris = $collectionAmount;
            } else {
                // Distribute the 30% debris amount across Reaper capacity
                $attackerCollectedDebris = $this->capAndCollect($collectionAmount, $reaperCargoCapacity, $allocation, $diagnostics, CombatResolutionOutcome::PHASE_ATTACKER_REAPER);
            }
        }

        // For single-attacker battles, loot and carried resources already occupy part of the
        // surviving fleet cargo. Cap automatic Reaper collection to only the remaining free
        // space so excess debris stays in the debris field instead of disappearing.
        if (count($battleResult->attackerFleetResults) === 1 && $attackerCollectedDebris->sum() > 0) {
            $singleFleetResult = $battleResult->attackerFleetResults[0];
            $remainingCargoCapacity = $battleResult->attackerSurvivingCargoCapacity;
            $availableForCollectedDebris = max(
                0,
                (int)($remainingCargoCapacity - $singleFleetResult->survivingCargo->sum() - $singleFleetResult->lootShare->sum())
            );

            if ($attackerCollectedDebris->sum() > $availableForCollectedDebris) {
                $attackerCollectedDebris = $this->capAndCollect($attackerCollectedDebris, $availableForCollectedDebris, $allocation, $diagnostics, CombatResolutionOutcome::PHASE_ATTACKER_REAPER_ROOM, CombatParticipantKey::forFleet($singleFleetResult->fleetMissionId));
            }
        }

        // Calculate remaining debris after attacker collection
        $debrisAfterAttackerCollection = new Resources(
            $battleResult->debris->metal->get() - $attackerCollectedDebris->metal->get(),
            $battleResult->debris->crystal->get() - $attackerCollectedDebris->crystal->get(),
            $battleResult->debris->deuterium->get() - $attackerCollectedDebris->deuterium->get(),
            0
        );

        // Defender Reaper collection (from remaining debris after attacker collection)
        $defenderDebrisCollectionPercentage = $context->reaperDebrisCollectionPercentage($defenderPlayer);
        if ($defenderDebrisCollectionPercentage > 0 && $battleResult->defenderUnitsResult->getAmountByMachineName('reaper') > 0) {
            // Calculate 30% of the remaining debris
            $collectionAmount = new Resources(
                (int)($debrisAfterAttackerCollection->metal->get() * $defenderDebrisCollectionPercentage),
                (int)($debrisAfterAttackerCollection->crystal->get() * $defenderDebrisCollectionPercentage),
                (int)($debrisAfterAttackerCollection->deuterium->get() * $defenderDebrisCollectionPercentage),
                0
            );

            // La capacite des Faucheurs defenseurs survivants, gelee a la cloture.
            $defenderReaperCargoCapacity = $battleResult->defenderReaperCargoCapacity;

            // Limit collected debris to Reaper cargo capacity
            if ($collectionAmount->sum() <= $defenderReaperCargoCapacity) {
                $defenderCollectedDebris = $collectionAmount;
            } else {
                // Distribute the 30% debris amount across Reaper capacity
                $defenderCollectedDebris = $this->capAndCollect($collectionAmount, $defenderReaperCargoCapacity, $allocation, $diagnostics, CombatResolutionOutcome::PHASE_DEFENDER_REAPER);
            }

            // **Le credit du Faucheur defenseur s'ecrit en base, pas depuis le modele en memoire.**
            //
            // `addResources()` prenait le stock **tel qu'il avait ete lu** et reecrivait la somme.
            // Entre cette lecture et cette ecriture, la production du corps, un transport arrive ou
            // une construction terminee ont pu changer les memes colonnes — et aucune de ces
            // ecritures-la ne prend les verrous du combat, donc rien ne les retient. La somme
            // recalculee les effacait.
            //
            // Une addition faite par la base ne peut pas les perdre : elle lit la ligne qu'elle
            // ecrit, au moment ou elle l'ecrit. Et elle ne touche que les trois colonnes, au lieu de
            // flusher tout le modele de planete que la resolution a manipule par ailleurs.
            $defenderPlanet->addResourcesAtomic($defenderCollectedDebris);
        }

        // Total collected debris for battle report
        $collectedDebris = new Resources(
            $attackerCollectedDebris->metal->get() + $defenderCollectedDebris->metal->get(),
            $attackerCollectedDebris->crystal->get() + $defenderCollectedDebris->crystal->get(),
            $attackerCollectedDebris->deuterium->get() + $defenderCollectedDebris->deuterium->get(),
            0
        );

        // Add debris to the field (minus what was collected by both attacker and defender Reapers)
        $remainingDebris = new Resources(
            $debrisAfterAttackerCollection->metal->get() - $defenderCollectedDebris->metal->get(),
            $debrisAfterAttackerCollection->crystal->get() - $defenderCollectedDebris->crystal->get(),
            $debrisAfterAttackerCollection->deuterium->get() - $defenderCollectedDebris->deuterium->get(),
            0
        );
        $debrisFieldService->appendResources($remainingDebris);

        // Save the debris field
        $debrisFieldService->save();

        // Create or extend wreck field at defender's location if conditions are met
        // Note: If attacker is General class, a separate wreck field will be created at the attacker's
        // origin planet when the return mission arrives (see processReturn method).
        // IMPORTANT: If the battle is on a moon, the wreck field is created at the planet's coordinates
        // (not the moon's), and can only be interacted with from the planet.
        if (!empty($battleResult->wreckField) && $battleResult->wreckField['formed']) {
            // **Les faits d'epave viennent du contexte** : part de debris, duree de vie, instant.
            $wreckFieldService = new WreckFieldService(
                $defenderPlayer,
                $this->settings,
                $context->debrisFieldFromShips(),
                $context->wreckFieldLifetimeHours(),
                $context->applicationInstant()
            );

            // Determine the coordinates for the wreck field
            // If battle is on a moon, use the planet's coordinates. If on a planet, use its own coordinates.
            $wreckFieldCoordinates = $defenderPlanet->isMoon()
                ? $defenderPlanet->planet()->getPlanetCoordinates()
                : $defenderPlanet->getPlanetCoordinates();

            $wreckField = $wreckFieldService->createWreckField(
                $wreckFieldCoordinates,
                $battleResult->wreckField['ships'],
                $defenderPlayer->getId()
            );
        }

        // Create a moon for defender if result of battle indicates so and defender planet does not already have a moon.
        // Only create moon if defender is a planet (not already a moon).
        if ($defenderPlanet->isPlanet() && !$defenderPlanet->hasMoon() && $battleResult->moonCreated) {
            $debrisAmount = (int)$battleResult->debris->sum();
            $this->planetServiceFactory->createMoonForPlanet($defenderPlanet, $debrisAmount, $battleResult->moonChance);
        }

        // Check if attacker fleet was destroyed in first round
        $attackerDestroyedFirstRound = false;
        if (count($battleResult->rounds) > 0) {
            $firstRound = $battleResult->rounds[0];
            if ($firstRound->attackerShips->getAmount() === 0) {
                $attackerDestroyedFirstRound = true;
            }
        }

        // Chaque participant recoit l'issue du combat : tout proprietaire d'une flotte
        // attaquante (union ACS comprise) et tout proprietaire d'une flotte en defense
        // (le maitre de la planete, plus les allies venus en ACS Defend). Un joueur ayant
        // engage plusieurs flottes n'est prevenu qu'une seule fois.
        $reportId = $this->createBattleReport($attackerPlayer, $defenderPlanet, $battleResult, $collectedDebris, $attackerCollectedDebris, $defenderCollectedDebris, $context);

        // Le recit d'un raid de faction se depose ici, dans le rapport, et jamais avant
        // l'attaque : un raid pirate doit rester indiscernable d'une attaque humaine tant
        // qu'il n'a pas eu lieu. L'explication arrive une fois que tout est joue, la ou le
        // joueur va de toute facon analyser ce qui vient de se passer, et un recit qui
        // n'influence plus aucune decision ne desequilibre rien.
        $this->attachNpcMotive($reportId, $mission, $defenderPlayer, $context);

        if ($attackerDestroyedFirstRound) {
            // La force d'attaque a ete aneantie avant d'avoir pu transmettre quoi que ce soit :
            // ses proprietaires apprennent seulement la perte de contact, sans le detail des
            // flottes ni des technologies adverses.
            $coordinates = '[coordinates]' . $defenderPlanet->getPlanetCoordinates()->asString() . '[/coordinates]';

            foreach ($this->collectAttackingPlayers($attackerFleets) as $participant) {
                $this->messageService->sendSystemMessageToPlayer($participant, FleetLostContact::class, [
                    'coordinates' => $coordinates,
                ]);
            }
        } else {
            foreach ($this->collectAttackingPlayers($attackerFleets) as $participant) {
                $this->messageService->sendBattleReportMessageToPlayer($participant, $reportId);
            }
        }

        foreach ($this->collectDefendingPlayers($defenders, $defenderPlayer) as $participant) {
            $this->messageService->sendBattleReportMessageToPlayer($participant, $reportId);
        }

        // Send Reaper auto-collection message to attacker if debris was collected
        if ($attackerCollectedDebris->sum() > 0) {
            $reaperObject = ObjectService::getShipObjectByMachineName('reaper');
            $reaperCount = $battleResult->attackerUnitsResult->getAmountByMachineName('reaper');

            // L'avis annonce le plafond qui a servi, pas un plafond recalcule maintenant : sinon il
            // decrirait une recolte que le joueur ne peut pas retrouver dans ses ressources.
            $reaperCargoCapacity = $battleResult->attackerReaperCargoCapacity;

            $this->messageService->sendSystemMessageToPlayer($attackerPlayer, DebrisFieldHarvest::class, [
                'from' => '[planet]' . $mission->planet_id_from . '[/planet]',
                'to' => '[debrisfield]' . $defenderPlanet->getPlanetCoordinates()->asString(). '[/debrisfield]',
                'coordinates' => '[coordinates]' . $defenderPlanet->getPlanetCoordinates()->asString() . '[/coordinates]',
                'ship_name' => $reaperObject->title,
                'ship_amount' => $reaperCount,
                'storage_capacity' => $reaperCargoCapacity,
                'metal' => $battleResult->debris->metal->get(),
                'crystal' => $battleResult->debris->crystal->get(),
                'deuterium' => $battleResult->debris->deuterium->get(),
                'harvested_metal' => $attackerCollectedDebris->metal->get(),
                'harvested_crystal' => $attackerCollectedDebris->crystal->get(),
                'harvested_deuterium' => $attackerCollectedDebris->deuterium->get(),
            ]);
        }

        // Send Reaper auto-collection message to defender if debris was collected
        if ($defenderCollectedDebris->sum() > 0) {
            $reaperObject = ObjectService::getShipObjectByMachineName('reaper');
            $defenderReaperCount = $battleResult->defenderUnitsResult->getAmountByMachineName('reaper');
            $defenderReaperCargoCapacity = $battleResult->defenderReaperCargoCapacity;

            $this->messageService->sendSystemMessageToPlayer($defenderPlayer, DebrisFieldHarvest::class, [
                'from' => '[planet]' . $defenderPlanet->getPlanetId() . '[/planet]',
                'to' => '[debrisfield]' . $defenderPlanet->getPlanetCoordinates()->asString(). '[/debrisfield]',
                'coordinates' => '[coordinates]' . $defenderPlanet->getPlanetCoordinates()->asString() . '[/coordinates]',
                'ship_name' => $reaperObject->title,
                'ship_amount' => $defenderReaperCount,
                'storage_capacity' => $defenderReaperCargoCapacity,
                'metal' => $debrisAfterAttackerCollection->metal->get(),
                'crystal' => $debrisAfterAttackerCollection->crystal->get(),
                'deuterium' => $debrisAfterAttackerCollection->deuterium->get(),
                'harvested_metal' => $defenderCollectedDebris->metal->get(),
                'harvested_crystal' => $defenderCollectedDebris->crystal->get(),
                'harvested_deuterium' => $defenderCollectedDebris->deuterium->get(),
            ]);
        }

        // Consequences d'un combat contre une faction hostile : la rancune que les
        // attaquants viennent de s'attirer, et la chute eventuelle de la base.
        $this->applyNpcAftermath($mission, $defenderPlanet, $defenderPlayer, $attackerFleets, $battleResult);

        // Mark the arrival mission as processed and create return mission
        // Single-attacker battles: use original return processing
        // Multi-attacker battles (ACS): each fleet is handled individually above
        if (count($battleResult->attackerFleetResults) === 1) {
            // Single-attacker battle - mark as processed and create return
            $mission->processed = 1;
            $mission->save();

            // Create and start the return mission (if single attacker has remaining units).
            // La capacite survivante gelee : c'est elle qui plafonne ce qui rentre.
            $remainingCargoCapacity = $battleResult->attackerSurvivingCargoCapacity;
            $singleFleetResult = $battleResult->attackerFleetResults[0];

            // Total resources = remaining mission resources + remaining loot + collected debris (from attacker Reapers)
            $totalResources = new Resources(
                $singleFleetResult->survivingCargo->metal->get() + $singleFleetResult->lootShare->metal->get() + $attackerCollectedDebris->metal->get(),
                $singleFleetResult->survivingCargo->crystal->get() + $singleFleetResult->lootShare->crystal->get() + $attackerCollectedDebris->crystal->get(),
                $singleFleetResult->survivingCargo->deuterium->get() + $singleFleetResult->lootShare->deuterium->get() + $attackerCollectedDebris->deuterium->get(),
                0
            );

            // Defensive cap only: loot and carried cargo are already normalized before we reach
            // this point, so only edge-case rounding should ever hit this.
            if ($totalResources->sum() > $remainingCargoCapacity) {
                $totalResources = $this->capAndCollect($totalResources, $remainingCargoCapacity, $allocation, $diagnostics, CombatResolutionOutcome::PHASE_RETURN_CAP_FINAL, CombatParticipantKey::forFleet($singleFleetResult->fleetMissionId));
            }

            // Calculate wreck field for General class attacker
            // General perk: wreck field from attacker's lost ships is transported back with the return mission
            $attackerWreckFieldData = null;
            if ($context->isGeneral($attackerPlayer)) {
                // Calculate attacker's lost units (start - result = lost)
                $attackerUnitsLost = clone $battleResult->attackerUnitsStart;
                $attackerUnitsLost->subtractCollection($battleResult->attackerUnitsResult);
                $originPlanet = $this->planetServiceFactory->makeForPlayer($attackerPlayer, $originPlanetId);

                // Calculate wreck field data if conditions are met
                $attackerWreckFieldData = $this->calculateAttackerWreckField($attackerUnitsLost, $battleResult->attackerUnitsStart, $originPlanet, $context);
            }

            ($creerRetour)($mission, $totalResources, $battleResult->attackerUnitsResult, 0, $attackerWreckFieldData);
        }
        // End of single-attacker return processing
        // Note: For multi-attacker battles, each fleet is already processed above with its own return mission

        // Ce que l application a rencontre repart avec son propre resultat : le `BattleResult` reste
        // celui que le moteur a fige.
        return new CombatResolutionOutcome($diagnostics, $reportId);
    }

    /**
     * Calculate the wreck field for attacker's lost ships (General class perk).
     * Similar logic to defender wreck field but for attacker's ships.
     *
     * @param UnitCollection $attackerUnitsLost Units lost by the attacker.
     * @param UnitCollection $attackerUnitsStart Starting units of the attacker.
     * @param PlanetService $originPlanet The planet whose Space Dock determines repairable wreckage.
     * @return array<array{machine_name: string, quantity: int, repair_progress: int}>|null Wreck field data with ships array, or null if conditions not met.
     */

    /**
     * Plafonne une cargaison, et retient ce que la conversion a rencontre.
     *
     * **Une seule resolution passe ici cinq fois** — Faucheurs des deux camps, plafonnement de leur
     * place restante, et deux plafonds de cargaison de retour. Journaliser a chaque appel donnerait
     * cinq lignes pour une operation ; les diagnostics s'accumulent donc sur le resultat, et la
     * mission — le seul appelant qui voit l'operation entiere — ecrit une fois.
     *
     * **Les diagnostics n'entrent pas dans le `BattleResult`.** Celui-ci represente le resultat
     * calcule et fige a la photographie ; dans le cycle persistant, il sera serialise a l'ouverture
     * du combat et relu des heures plus tard. Y ecrire pendant son application melangerait deux
     * instants, et la relecture differerait de l'ecriture.
     *
     * @param Resources $resources
     * @param int $capacity
     * @param ResourceNormalizationDiagnostics $diagnostics Accumulateur local de la resolution.
     * @param string $phase Le moment fonctionnel, pour que deux incidents distincts le restent.
     * @param string $subject L identite stable de la flotte concernee, quand cette etape se repete.
     *                         **Sans elle, deux retours plafonnes dans la meme phase sur la meme
     *                         ressource porteraient la meme identite et fusionneraient en une seule
     *                         occurrence.** Les etapes qui n ont lieu qu une fois par resolution s en
     *                         passent : leur phase suffit a les distinguer.
     * @return Resources
     */
    private function capAndCollect(
        Resources $resources,
        int $capacity,
        FrozenLootAllocation $allocation,
        ResourceNormalizationDiagnostics &$diagnostics,
        string $phase,
        string $subject = '',
    ): Resources {
        $plafonne = LootService::distribute($resources, $capacity, $allocation, $phase, $subject);
        $diagnostics = $diagnostics->mergedWith($plafonne->diagnostics);

        return $plafonne->resources;
    }

    private function calculateAttackerWreckField(UnitCollection $attackerUnitsLost, UnitCollection $attackerUnitsStart, PlanetService $originPlanet, CombatApplicationContext $context): array|null
    {
        // **Le niveau vient du contexte, pas du corps.** Un chantier spatial monte d'un niveau
        // pendant une bataille de deux heures en changerait la taille du champ d'epaves.
        $spaceDockLevel = $context->spaceDockLevelFor($originPlanet);
        $spaceDockPlanet = $originPlanet->isMoon() ? $originPlanet->planet() : $originPlanet;
        $spaceDockPlayer = $spaceDockPlanet->getPlayer();
        if ($spaceDockPlayer === null) {
            throw new RuntimeException('Space dock planet has no owner.');
        }
        $wreckFieldService = new WreckFieldService(
            $spaceDockPlayer,
            $this->settings,
            $context->debrisFieldFromShips(),
            $context->wreckFieldLifetimeHours(),
            $context->applicationInstant()
        );
        $wreckFieldData = $wreckFieldService->calculateShipsForWreckField($attackerUnitsLost, $spaceDockLevel);

        // Check if wreck field conditions are met
        $totalLostValue = $attackerUnitsLost->toResources()->metal->get() +
                         $attackerUnitsLost->toResources()->crystal->get() +
                         $attackerUnitsLost->toResources()->deuterium->get();
        $totalFleetValue = $attackerUnitsStart->toResources()->metal->get() +
                          $attackerUnitsStart->toResources()->crystal->get() +
                          $attackerUnitsStart->toResources()->deuterium->get();

        if ($totalFleetValue > 0) {
            $destroyedPercentage = ($totalLostValue / $totalFleetValue) * 100;
            $minResourcesRequired = $context->wreckFieldMinResourcesLoss();
            $minFleetPercentageRequired = $context->wreckFieldMinFleetPercentage();

            // Only return wreck field data if conditions are met and there are ships
            if ($totalLostValue >= $minResourcesRequired
                && $destroyedPercentage >= $minFleetPercentageRequired
                && !empty($wreckFieldData)) {
                return $wreckFieldData;
            }
        }

        return null;
    }

    /**
     * Creates a battle report for the given battle result.
     *
     * @param PlayerService $attackPlayer The player who initiated the attack.
     * @param PlanetService $defenderPlanet The planet that was attacked.
     * @param BattleResult $battleResult The result of the battle.
     * @param Resources $collectedDebris Total debris collected automatically by Reaper ships (attacker + defender).
     * @param Resources $attackerCollectedDebris Debris collected by attacker's Reaper ships.
     * @param Resources $defenderCollectedDebris Debris collected by defender's Reaper ships.
     * @return int
     */
    private function createBattleReport(PlayerService $attackPlayer, PlanetService $defenderPlanet, BattleResult $battleResult, Resources $collectedDebris, Resources $attackerCollectedDebris, Resources $defenderCollectedDebris, CombatApplicationContext $context): int
    {
        $defenderPlayer = $defenderPlanet->getPlayer();
        if ($defenderPlayer === null) {
            throw new RuntimeException('Battle report defender planet has no owner.');
        }

        // Create new battle report record.
        $report = new BattleReport();
        $report->planet_galaxy = $defenderPlanet->getPlanetCoordinates()->galaxy;
        $report->planet_system = $defenderPlanet->getPlanetCoordinates()->system;
        $report->planet_position = $defenderPlanet->getPlanetCoordinates()->position;
        $report->planet_type = $defenderPlanet->getPlanetType()->value;

        $report->planet_user_id = $defenderPlayer->getId();

        $report->general = [
            'moon_existed' => $battleResult->moonExisted,
            'moon_chance' => $battleResult->moonChance,
            'moon_created' => $battleResult->moonCreated,
            'hamill_manoeuvre_triggered' => $battleResult->hamillManoeuvreTriggered,
            'tactical_retreat' => [
                'ratio' => $battleResult->tacticalRetreatRatio,
                'attacker_points' => $battleResult->tacticalRetreatAttackerPoints,
                'defender_points' => $battleResult->tacticalRetreatDefenderPoints,
                'defender_fled' => $battleResult->tacticalRetreatDefenderFled,
                'attacker_also_retreated' => $battleResult->tacticalRetreatAttackerAlsoRetreated,
                'deuterium_cost' => $battleResult->tacticalRetreatDeuteriumCost,
                'by' => $battleResult->tacticalRetreatDefenderFled
                    ? ($battleResult->tacticalRetreatAttackerAlsoRetreated ? 'both' : 'defender')
                    : 'none',
                'supremacy' => $battleResult->tacticalRetreatAttackerPoints,
            ],
        ];

        $attackerCharacterClass = $context->characterClassOf($attackPlayer);
        $defenderCharacterClass = $context->characterClassOf($defenderPlayer);

        $report->attacker = [
            'player_id' => $attackPlayer->getId(),
            'resource_loss' => $battleResult->attackerResourceLoss->sum(),
            'units' => $battleResult->attackerUnitsStart->toArray(),
            'weapon_technology' => $battleResult->attackerWeaponLevel,
            'shielding_technology' => $battleResult->attackerShieldLevel,
            'armor_technology' => $battleResult->attackerArmorLevel,
            'planet_id' => $battleResult->attackerPlanetId,
            'character_class' => $attackerCharacterClass?->getName(),
        ];

        // TODO: Enhance battle reports to show individual participating fleets/defenders
        // Currently shows aggregated defender data (combined units, planet owner's tech, single player_id)
        // Should show:
        // - Combined fleet totals (current behavior)
        // - Dropdown/expandable sections for each participating fleet:
        //   - Planet owner's stationary forces (ships + defenses with their tech levels)
        //   - Each ACS Defend fleet (units, owner, tech levels)
        // - Per-fleet losses and survivors
        // Data available in: $battleResult->defenderFleetResults
        $report->defender = [
            'player_id' => $defenderPlayer->getId(),
            'resource_loss' => $battleResult->defenderResourceLoss->sum(),
            'units' => $battleResult->defenderUnitsStart->toArray(),
            'weapon_technology' => $battleResult->defenderWeaponLevel,
            'shielding_technology' => $battleResult->defenderShieldLevel,
            'armor_technology' => $battleResult->defenderArmorLevel,
            'character_class' => $defenderCharacterClass?->getName(),
        ];

        $report->loot = [
            'percentage' => $battleResult->lootPercentage,
            'metal' => (int)$battleResult->loot->metal->get(),
            'crystal' => (int)$battleResult->loot->crystal->get(),
            'deuterium' => (int)$battleResult->loot->deuterium->get(),
        ];

        $report->debris = [
            'metal' => $battleResult->debris->metal->get(),
            'crystal' => $battleResult->debris->crystal->get(),
            'deuterium' => $battleResult->debris->deuterium->get(),
            'collected_metal' => $collectedDebris->metal->get(),
            'collected_crystal' => $collectedDebris->crystal->get(),
            'collected_deuterium' => $collectedDebris->deuterium->get(),
            'attacker_collected_metal' => $attackerCollectedDebris->metal->get(),
            'attacker_collected_crystal' => $attackerCollectedDebris->crystal->get(),
            'attacker_collected_deuterium' => $attackerCollectedDebris->deuterium->get(),
            'defender_collected_metal' => $defenderCollectedDebris->metal->get(),
            'defender_collected_crystal' => $defenderCollectedDebris->crystal->get(),
            'defender_collected_deuterium' => $defenderCollectedDebris->deuterium->get(),
        ];

        $report->repaired_defenses = $battleResult->repairedDefenses->toArray();

        // Save defender's wreck field data (ships recoverable via Space Dock)
        if (!empty($battleResult->wreckField) && ($battleResult->wreckField['formed'] ?? false)) {
            $wreckageData = [];
            foreach ($battleResult->wreckField['ships'] as $ship) {
                $wreckageData[$ship['machine_name']] = $ship['quantity'];
            }
            $report->wreckage = $wreckageData;
        }

        // Save General class attacker's wreck field in the report.
        // Shown only if the attacker is General class AND at least one ship survived
        // (survivor condition ensures there is a return mission to transport the wreckage back).
        if ($context->isGeneral($attackPlayer)
            && $battleResult->attackerUnitsResult->getAmount() > 0) {
            $attackerUnitsLost = clone $battleResult->attackerUnitsStart;
            $attackerUnitsLost->subtractCollection($battleResult->attackerUnitsResult);
            $originPlanet = $this->planetServiceFactory->makeForPlayer($attackPlayer, $battleResult->attackerPlanetId);
            $generalWreckData = $this->calculateAttackerWreckField($attackerUnitsLost, $battleResult->attackerUnitsStart, $originPlanet, $context);

            if ($generalWreckData !== null) {
                $generalWreckForReport = [];
                foreach ($generalWreckData as $ship) {
                    $generalWreckForReport[$ship['machine_name']] = $ship['quantity'];
                }
                $general = $report->general;
                $general['attacker_wreckage'] = $generalWreckForReport;
                $report->general = $general;
            }
        }

        $rounds = [];
        foreach ($battleResult->rounds as $round) {
            $rounds[] = [
                'attacker_ships' => $round->attackerShips->toArray(),
                'defender_ships' => $round->defenderShips->toArray(),
                'attacker_losses' => $round->attackerLosses->toArray(),
                'defender_losses' => $round->defenderLosses->toArray(),
                'attacker_losses_in_this_round' => $round->attackerLossesInRound->toArray(),
                'defender_losses_in_this_round' => $round->defenderLossesInRound->toArray(),
                'absorbed_damage_attacker' => $round->absorbedDamageAttacker,
                'absorbed_damage_defender' => $round->absorbedDamageDefender,
                'full_strength_attacker' => $round->fullStrengthAttacker,
                'full_strength_defender' => $round->fullStrengthDefender,
                'hits_attacker' => $round->hitsAttacker,
                'hits_defender' => $round->hitsDefender,
            ];
        }

        $report->rounds = $rounds;
        $report->save();

        return $report->id;
    }

    /**
     * Record in the battle report why a hostile faction came, when one did.
     *
     * La colonne general du rapport est un tableau JSON libre, et AttackMission la relit
     * deja pour y ajouter le champ d'epave : deposer le motif suit ce precedent et ne
     * demande aucune migration. La cle n'est ecrite que pour un raid de faction, donc le
     * gabarit reste muet pour tous les combats entre joueurs.
     */
    private function attachNpcMotive(int $reportId, FleetMission $mission, PlayerService $defenderPlayer, CombatApplicationContext $context): void
    {
        if (!self::isNpcAttack($mission)) {
            return;
        }

        $attacker = User::find($mission->user_id);

        if ($attacker === null) {
            return;
        }

        $report = BattleReport::find($reportId);

        if ($report === null) {
            return;
        }

        // **Le motif vient du contexte.** Sur le chemin instantane, c'est celui de l'arrivee — il
        // ne peut avoir change depuis le depart que si le joueur a de nouveau provoque la
        // faction pendant le vol, auquel cas le recent est le plus juste. Sur le chemin durable,
        // c'est celui de la cloture : un motif inscrit **pendant** la bataille expliquerait un
        // raid decide avant lui.
        $motive = $context->npcMotiveAgainst($defenderPlayer);

        $general = $report->general ?? [];
        $general['npc_motive'] = $motive ?? 'first_contact';
        $general['npc_faction'] = $attacker->npc_type;
        $general['npc_crew'] = $attacker->username;
        // La variante est tiree une seule fois et conservee avec le rapport. La tirer a
        // l'affichage donnerait un texte different a chaque ouverture du meme rapport, et un
        // joueur qui relit un combat verrait son histoire changer sous ses yeux. Sur le chemin
        // durable, elle est tiree a la cloture, avec le reste de ce qui decide du rapport.
        $general['npc_variation'] = $context->npcNarrativeVariation(self::NPC_MOTIVE_VARIATIONS);
        $report->general = $general;
        $report->save();
    }

    /**
     * Apply what a battle against a hostile faction changes beyond the battle itself.
     *
     * Deux consequences, et une seule condition commune : que le defenseur soit une base
     * pilotee par le serveur. Un combat entre joueurs ne passe jamais par ici.
     *
     * @param array<int, AttackerFleet> $attackerFleets
     */
    private function applyNpcAftermath(
        FleetMission $mission,
        PlanetService $defenderPlanet,
        PlayerService $defenderPlayer,
        array $attackerFleets,
        BattleResult $battleResult
    ): void {
        if (!$defenderPlayer->getUser()->is_npc) {
            return;
        }

        // Une faction qui en attaque une autre ne s'attire aucune rancune : la menace est
        // une notion propre aux joueurs humains.
        if (self::isNpcAttack($mission)) {
            return;
        }

        $destruction = resolve(NpcDestructionService::class);

        $fleetWiped = $battleResult->defenderUnitsResult->getAmount() === 0;
        $attackerSurvived = $battleResult->attackerUnitsResult->getAmount() > 0;

        // La regle de destruction d'une base, ses conditions et ses consequences vivent
        // entierement dans NpcDestructionService — y compris la raison pour laquelle les
        // ruines d'une base vaincue ne se reparent pas. Ne pas la dupliquer ici.
        $baseDestroyed = $destruction->isDefeatedInBattle($battleResult);

        $reason = match (true) {
            $baseDestroyed => 'base_destroyed',
            $fleetWiped => 'fleet_wiped',
            $attackerSurvived => 'attack_won',
            default => 'attack_lost',
        };

        $threatService = resolve(NpcThreatService::class);
        $coordinate = $defenderPlanet->getPlanetCoordinates();

        foreach ($this->collectAttackingPlayers($attackerFleets) as $participant) {
            $threatService->add($participant, $reason, $coordinate);
        }

        $destruction->settleBattle($defenderPlanet, $battleResult);
    }

    /**
     * Get every distinct player who committed an attacking fleet to this battle.
     *
     * Une union ACS peut compter plusieurs flottes appartenant au meme joueur : le
     * dedoublonnage se fait sur l'identifiant du proprietaire, afin que personne ne
     * recoive deux fois le meme rapport.
     *
     * @param array<int, AttackerFleet> $attackerFleets
     * @return array<int, PlayerService>
     */
    private function collectAttackingPlayers(array $attackerFleets): array
    {
        $players = [];

        foreach ($attackerFleets as $fleet) {
            $players[$fleet->ownerId] = $fleet->player;
        }

        return array_values($players);
    }

    /**
     * Get every distinct player who took part in the defense of the target planet.
     *
     * Le maitre de la planete est ajoute en premier et de facon inconditionnelle : il est
     * defenseur meme sans une seule unite en orbite, et il doit recevoir le rapport quoi
     * qu'il arrive. Les flottes venues en ACS Defend s'y ajoutent ensuite.
     *
     * @param array<int, DefenderFleet> $defenderFleets
     * @return array<int, PlayerService>
     */
    private function collectDefendingPlayers(array $defenderFleets, PlayerService $planetOwner): array
    {
        $players = [$planetOwner->getId() => $planetOwner];

        foreach ($defenderFleets as $fleet) {
            $players[$fleet->ownerId] = $fleet->player;
        }

        return array_values($players);
    }
}
