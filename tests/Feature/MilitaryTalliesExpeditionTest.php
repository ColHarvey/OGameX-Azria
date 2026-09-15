<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\Models\ExpeditionOutcomeType;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Models\BattleReport;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * **Les cumuls militaires lisent une bataille d expedition.**
 *
 * Le joueur est credite de ce qu il a perdu (« perdus ») et de ce qu il a abattu (« detruits ») ; les pirates et les
 * aliens n ont pas de compte — identifiants −1 et −2 — et ne recoivent rien. Le rapport de bataille de l expedition,
 * ecrit par le meme calcul, sert de temoin de ce qui a ete perdu de chaque cote : le cumul doit dire la meme chose.
 *
 * Le rapport d expedition presente le PNJ comme attaquant : ses pertes sont sous `attacker_losses_in_this_round`,
 * celles du joueur sous `defender_losses_in_this_round`.
 */
final class MilitaryTalliesExpeditionTest extends FleetDispatchTestCase
{
    use ReadsMilitaryTallies;

    protected int $missionType = 15;

    protected string $missionName = 'Expedition';

    protected function basicSetup(): void
    {
        $this->planetAddUnit('battlecruiser', 50);
        $this->planetAddUnit('cruiser', 100);
        $this->playerSetResearchLevel('weapon_technology', 10);
        $this->playerSetResearchLevel('shielding_technology', 10);
        $this->playerSetResearchLevel('armor_technology', 10);
        $this->playerSetResearchLevel('astrophysics', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 1);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $this->planetAddResources(new Resources(0, 0, 100000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        $this->desactiverLesCumuls();

        parent::tearDown();
    }

    public function testAnExpeditionBattleCreditsThePlayerWithWhatHeLostAndWhatHeShotDown(): void
    {
        $this->basicSetup();
        $this->activerLesCumulsDepuis((int)Date::now()->timestamp - 1);
        $this->onlyThisOutcome(ExpeditionOutcomeType::BattlePirates);

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('battlecruiser'), 50);
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 100);
        $this->sendMissionToPosition16($flotte, new Resources(1, 1, 0, 0));

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 15)->orderByDesc('id')->firstOrFail();

        $this->travel(10)->hours();
        $this->get('/overview')->assertStatus(200);

        $rapport = BattleReport::query()->where('planet_user_id', $this->currentUserId)->orderByDesc('id')->first();
        $this->assertNotNull($rapport, 'Premisse : la bataille d expedition a eu lieu et son rapport existe.');
        $this->assertSame('pirate', $rapport->general['npc_type'] ?? '', 'Premisse : l adversaire est bien le PNJ pirate.');

        $pertesDuJoueur = $this->pertesCumuleesDesRounds((array)$rapport->rounds, 'defender_losses_in_this_round');
        $pertesDesPirates = $this->pertesCumuleesDesRounds((array)$rapport->rounds, 'attacker_losses_in_this_round');
        $this->assertNotSame([], $pertesDuJoueur + $pertesDesPirates, 'Premisse : au moins un camp a perdu quelque chose, sinon le temoin ne mesure rien.');

        $evenements = $this->evenementsSous('battle:expedition:' . $mission->id . ':');
        $this->assertCount(1, $evenements, 'Une bataille d expedition doit produire exactement un evenement : celui du joueur, jamais celui du PNJ.');

        $evenement = $evenements['battle:expedition:' . $mission->id . ':' . CombatParticipantKey::forFleet((int)$mission->id)] ?? null;
        $this->assertNotNull($evenement, 'L evenement du joueur ne porte pas la clef de sa flotte.');
        $this->assertSame($this->currentUserId, (int)$evenement->player_id);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $evenement->status, 'La bataille est restee en attente : ' . (string)$evenement->reason);
        $this->assertSame($this->valeurMilitaireDe($pertesDuJoueur), (int)$evenement->lost_value, 'Les « perdus » du joueur ne sont pas la valeur de ses pertes.');
        $this->assertSame($this->valeurMilitaireDe($pertesDesPirates), (int)$evenement->destroyed_value, 'Les « detruits » du joueur ne sont pas la valeur des pirates abattus.');
        $this->assertSame(0, (int)$evenement->built_value);
    }

    /**
     * **L instant du fait est l arrivee de l expedition, jamais l heure du traitement** : une collecte ouverte apres
     * l arrivee, mais avant que le travailleur ne traite la mission, ne voit pas cette bataille.
     */
    public function testAnExpeditionBattleIsCollectedByItsArrivalNeverByItsProcessingTime(): void
    {
        $this->basicSetup();
        $this->onlyThisOutcome(ExpeditionOutcomeType::BattlePirates);

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('battlecruiser'), 50);
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 100);
        $this->sendMissionToPosition16($flotte, new Resources(1, 1, 0, 0));

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 15)->orderByDesc('id')->firstOrFail();
        $this->activerLesCumulsDepuis((int)$mission->time_arrival + 1);

        $this->travel(10)->hours();
        $this->get('/overview')->assertStatus(200);

        $this->assertNotNull(BattleReport::query()->where('planet_user_id', $this->currentUserId)->orderByDesc('id')->first(), 'Premisse : la bataille a eu lieu.');
        $this->assertSame([], $this->evenementsSous('battle:expedition:' . $mission->id . ':'), 'Une bataille arrivee avant l ouverture de la collecte a ete comptee sur l heure de son traitement.');
    }

    /**
     * Ne laisse a l expedition qu une seule issue possible.
     */
    private function onlyThisOutcome(ExpeditionOutcomeType $issue): void
    {
        $reglages = resolve(SettingsService::class);

        if (!$reglages->get('bonus_expedition_slots')) {
            $reglages->set('bonus_expedition_slots', 0);
        }

        foreach (['resources', 'ships', 'dark_matter', 'items'] as $multiplicateur) {
            if (!$reglages->get('expedition_reward_multiplier_' . $multiplicateur)) {
                $reglages->set('expedition_reward_multiplier_' . $multiplicateur, '1.0');
            }
        }

        $poids = [
            ExpeditionOutcomeType::GainDarkMatter->value => 'expedition_weight_dark_matter',
            ExpeditionOutcomeType::GainShips->value => 'expedition_weight_ships',
            ExpeditionOutcomeType::GainResources->value => 'expedition_weight_resources',
            ExpeditionOutcomeType::FailedAndDelay->value => 'expedition_weight_delay',
            ExpeditionOutcomeType::FailedAndSpeedup->value => 'expedition_weight_speedup',
            ExpeditionOutcomeType::Failed->value => 'expedition_weight_nothing',
            ExpeditionOutcomeType::LossOfFleet->value => 'expedition_weight_black_hole',
            ExpeditionOutcomeType::GainMerchantTrade->value => 'expedition_weight_merchant',
            ExpeditionOutcomeType::GainItems->value => 'expedition_weight_items',
            ExpeditionOutcomeType::BattlePirates->value => 'expedition_weight_pirates',
            ExpeditionOutcomeType::BattleAliens->value => 'expedition_weight_aliens',
        ];

        foreach ($poids as $valeur => $clef) {
            $reglages->set($clef, $valeur === $issue->value ? '100' : '0');
        }
    }
}
