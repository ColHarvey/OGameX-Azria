<?php

namespace OGame\Combat\MoonDestruction;

use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMessages\MoonDestroyed;
use OGame\GameMessages\MoonDestructionCatastrophic;
use OGame\GameMessages\MoonDestructionFailure;
use OGame\GameMessages\MoonDestructionRepelled;
use OGame\GameMessages\MoonDestructionSuccess;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\BattleTallyFacts;
use OGame\Military\MilitaryMoonDestructionTally;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Ce qu'un plan de destruction gele fait au monde, a l'echeance d'un combat durable.
 *
 * ## Rien n'est retire, rien n'est relu
 *
 * Le plan a ete gele a la cloture avec ses tirages (`FrozenMoonDestructionPlan`). Ici, on **relit** :
 * chaque tentative dit deja son issue et ce qu'elle coute. Les etoiles de la mort perdues par un
 * echec catastrophique ont ete retirees du retour par la resolution, avant que le retour existe ;
 * cette classe ne touche qu'a la lune et aux messages.
 *
 * ## L'ordre
 *
 * Les messages de chaque tentative, dans l'ordre du plan ; puis, si une tentative a detruit la lune,
 * les missions encore en vol vers elle sont redirigees vers la planete mere (ou perdent leur lien),
 * et la lune est supprimee — apres que la barriere du combat a ete levee, dans la meme transaction.
 * Le chemin instantane (`MoonDestructionMission`) fait la meme chose a l'arrivee, sans plan.
 */
final class MoonDestructionSettlement
{
    public function __construct(
        private PlayerServiceFactory $players,
        private PlanetServiceFactory $planets,
    ) {
    }

    /**
     * @param int $echeance L echeance du combat : l instant logique de ce que la tentative a fait.
     */
    public function apply(FrozenMoonDestructionPlan $plan, PlanetService $moon, int $echeance): void
    {
        if (!$moon->isMoon() || $moon->getPlanetId() !== $plan->moon->moonId) {
            throw new RuntimeException('Le plan de destruction du combat ' . $plan->combatInstanceId . ' vise la lune ' . $plan->moon->moonId . ', pas le corps ' . $moon->getPlanetId() . '.');
        }
        $proprietaire = $moon->getPlayer();
        if ($proprietaire === null) {
            throw new RuntimeException('La lune ' . $moon->getPlanetId() . ' n a pas de proprietaire : le plan de destruction ne peut pas etre applique.');
        }

        foreach ($plan->attempts as $tentative) {
            $this->notify($tentative, $plan, $proprietaire);
        }

        // **Les cumuls militaires lisent le plan** avant que la lune disparaisse : chaque tentative qui a consomme un
        // tirage, ses Etoiles perdues, celle qui a detruit la lune, et ce que la lune emportait a cet instant — la
        // bataille, elle, a ete comptee par le reglement.
        $tentatives = [];

        foreach ($plan->attempts as $tentative) {
            if (!$tentative->outcome->consumedADraw()) {
                continue;
            }

            $mission = FleetMission::query()->whereKey($tentative->fleetMissionId)->first();

            if ($mission instanceof FleetMission) {
                $tentatives[] = ['mission' => $mission, 'deathstars_lost' => $tentative->extraDeathstarLosses, 'destroyed_the_moon' => $tentative->destroyedTheMoon()];
            }
        }

        resolve(MilitaryMoonDestructionTally::class)->record(
            BattleTallyFacts::SPACE_COMBAT,
            $plan->combatInstanceId,
            $echeance,
            $moon,
            $tentatives,
            $plan->destroysTheMoon() ? MilitaryMoonDestructionTally::unitsOn($moon) : new UnitCollection(),
        );

        if ($plan->destroysTheMoon()) {
            $this->redirectFleetsFromMoon($moon);
            $moon->permanentlyDeletePlanet();
        }
    }

    private function notify(FrozenMoonDestructionAttempt $tentative, FrozenMoonDestructionPlan $plan, PlayerService $proprietaire): void
    {
        $mission = FleetMission::query()->whereKey($tentative->fleetMissionId)->first();
        if (!$mission instanceof FleetMission) {
            throw new RuntimeException('La tentative de la mission ' . $tentative->fleetMissionId . ' ne peut pas etre annoncee : la mission n existe plus.');
        }
        $attaquant = $this->players->make((int)$mission->user_id, true);
        $coordonnees = '[coordinates]' . $plan->moon->coordinates . '[/coordinates]';
        $chances = [
            'moon_name' => $plan->moon->name,
            'moon_coords' => $coordonnees,
            'destruction_chance' => number_format($tentative->destructionChance, 2) . '%',
            'loss_chance' => number_format($tentative->deathstarLossChance, 2) . '%',
        ];

        switch ($tentative->outcome) {
            case MoonDestructionOutcome::MoonDestroyed:
                $this->messagesFor($attaquant)->sendSystemMessageToPlayer($attaquant, MoonDestructionSuccess::class, $chances);
                $this->messagesFor($proprietaire)->sendSystemMessageToPlayer($proprietaire, MoonDestroyed::class, [
                    'moon_name' => $plan->moon->name,
                    'moon_coords' => $coordonnees,
                    'attacker_name' => $attaquant->getUsername(),
                ]);

                return;
            case MoonDestructionOutcome::AttemptFailed:
                if ($tentative->extraDeathstarLosses > 0) {
                    $this->messagesFor($attaquant)->sendSystemMessageToPlayer($attaquant, MoonDestructionCatastrophic::class, $chances);
                } else {
                    $this->messagesFor($attaquant)->sendSystemMessageToPlayer($attaquant, MoonDestructionFailure::class, $chances);
                }
                $this->messagesFor($proprietaire)->sendSystemMessageToPlayer($proprietaire, MoonDestructionRepelled::class, [
                    'moon_name' => $plan->moon->name,
                    'moon_coords' => $coordonnees,
                    'attacker_name' => $attaquant->getUsername(),
                ]);

                return;
            case MoonDestructionOutcome::TargetAlreadyDestroyed:
            case MoonDestructionOutcome::NoSurvivingDeathstar:
            case MoonDestructionOutcome::AttackSideDidNotWin:
                // Aucune tentative n'a eu lieu : le rapport de bataille commun dit deja ce qui s'est passe.
                return;
        }
    }

    /**
     * Les missions encore en vol vers la lune visent desormais la planete mere — ou plus rien.
     */
    private function redirectFleetsFromMoon(PlanetService $moon): void
    {
        $coordonnees = $moon->getPlanetCoordinates();
        $mere = $this->planets->makePlanetForCoordinate($coordonnees);

        $enVol = FleetMission::query()
            ->where('galaxy_to', $coordonnees->galaxy)
            ->where('system_to', $coordonnees->system)
            ->where('position_to', $coordonnees->position)
            ->where('type_to', PlanetType::Moon->value)
            ->where('processed', 0)
            ->get();
        foreach ($enVol as $mission) {
            $mission->type_to = PlanetType::Planet->value;
            $mission->planet_id_to = $mere?->getPlanetId();
            $mission->save();
        }
    }

    private function messagesFor(PlayerService $joueur): MessageService
    {
        return resolve(MessageService::class, ['player' => $joueur]);
    }
}
