<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Mockery\MockInterface;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Models\BattleReport;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\CounterEspionageService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * **Les cumuls militaires lisent une bataille de contre-espionnage.**
 *
 * L espion est l attaquante ephemere du moteur — aucune mission de flotte dans la bataille, mais un proprietaire
 * venu du contexte, jamais invente, jamais perdu. Ses sondes perdues sont ses « perdus » et les « detruits » du
 * defenseur ; les pertes du defenseur, s il y en a, sont symetriques. Le rapport de bataille du defenseur, ecrit par
 * le meme calcul, dit ce que chaque camp a perdu.
 */
final class MilitaryTalliesEspionageTest extends FleetDispatchTestCase
{
    use ReadsMilitaryTallies;

    protected int $missionType = 6;

    protected string $missionName = 'Espionage';

    protected function basicSetup(): void
    {
        $this->planetAddUnit('espionage_probe', 10);
        $this->planetAddResources(new Resources(0, 0, 100000, 0));
        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
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

    public function testACounterEspionageBattleCreditsTheSpyAndTheDefenderSymmetrically(): void
    {
        $this->basicSetup();
        $this->activerLesCumulsDepuis((int)Date::now()->timestamp - 1);

        $cible = $this->getNearbyForeignCleanPlanet();
        $cible->addUnit('light_fighter', 500);
        $cible->save();
        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire, 'Premisse : la cible a un proprietaire.');

        $sondes = new UnitCollection();
        $sondes->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 3);
        $this->dispatchFleet($cible->getPlanetCoordinates(), $sondes, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 6)->orderByDesc('id')->firstOrFail();

        // **Le tirage est force apres l envoi** : l envoi reconstruit le conteneur, un faux pose avant serait efface.
        $this->partialMock(CounterEspionageService::class, static function (MockInterface $faux): void {
            $faux->shouldReceive('rollCounterEspionage')->andReturn(true);
        });

        $this->travel(10)->hours();
        $this->get('/overview')->assertStatus(200);

        $rapport = BattleReport::query()->where('planet_user_id', $proprietaire->getId())->orderByDesc('id')->first();
        $this->assertNotNull($rapport, 'Premisse : la bataille de contre-espionnage a eu lieu et son rapport existe.');

        $sondesPerdues = $this->pertesCumuleesDesRounds((array)$rapport->rounds, 'attacker_losses_in_this_round');
        $pertesDuDefenseur = $this->pertesCumuleesDesRounds((array)$rapport->rounds, 'defender_losses_in_this_round');
        $this->assertGreaterThan(0, $sondesPerdues['espionage_probe'] ?? 0, 'Premisse : au moins une sonde est tombee, sinon le temoin ne mesure rien.');

        $prefixe = 'battle:espionage:' . $mission->id . ':';
        $evenements = $this->evenementsSous($prefixe);
        $this->assertCount(2, $evenements, 'Une bataille de contre-espionnage doit produire un evenement par camp classe : ' . implode(', ', array_keys($evenements)));

        $espion = $evenements[$prefixe . CombatParticipantKey::EPHEMERAL_ATTACKER] ?? null;
        $this->assertNotNull($espion, 'L espion, attaquante ephemere, n a pas son evenement.');
        $this->assertSame($this->currentUserId, (int)$espion->player_id, 'Le proprietaire de l attaquante ephemere n est pas l espion.');
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $espion->status, 'La bataille est restee en attente : ' . (string)$espion->reason);
        $this->assertSame($this->valeurMilitaireDe($sondesPerdues), (int)$espion->lost_value, 'Les « perdus » de l espion ne sont pas la valeur de ses sondes.');
        $this->assertSame($this->valeurMilitaireDe($pertesDuDefenseur), (int)$espion->destroyed_value);

        $defenseur = $evenements[$prefixe . CombatParticipantKey::forBody($cible)] ?? null;
        $this->assertNotNull($defenseur, 'Le defenseur n a pas son evenement sous la clef de son corps.');
        $this->assertSame($proprietaire->getId(), (int)$defenseur->player_id);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $defenseur->status);
        $this->assertSame($this->valeurMilitaireDe($sondesPerdues), (int)$defenseur->destroyed_value, 'Les « detruits » du defenseur ne sont pas la valeur des sondes abattues.');
        $this->assertSame($this->valeurMilitaireDe($pertesDuDefenseur), (int)$defenseur->lost_value);
    }

    /**
     * **L instant du fait est l arrivee des sondes, jamais l heure du traitement** : une collecte ouverte apres
     * l arrivee, mais avant que le travailleur ne traite la mission, ne voit pas cette bataille.
     */
    public function testACounterEspionageBattleIsCollectedByItsArrivalNeverByItsProcessingTime(): void
    {
        $this->basicSetup();

        $cible = $this->getNearbyForeignCleanPlanet();
        $cible->addUnit('light_fighter', 500);
        $cible->save();
        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire, 'Premisse : la cible a un proprietaire.');

        $sondes = new UnitCollection();
        $sondes->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 3);
        $this->dispatchFleet($cible->getPlanetCoordinates(), $sondes, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 6)->orderByDesc('id')->firstOrFail();
        $this->activerLesCumulsDepuis((int)$mission->time_arrival + 1);
        $this->partialMock(CounterEspionageService::class, static function (MockInterface $faux): void {
            $faux->shouldReceive('rollCounterEspionage')->andReturn(true);
        });

        $this->travel(10)->hours();
        $this->get('/overview')->assertStatus(200);

        $this->assertNotNull(BattleReport::query()->where('planet_user_id', $proprietaire->getId())->orderByDesc('id')->first(), 'Premisse : la bataille a eu lieu.');
        $this->assertSame([], $this->evenementsSous('battle:espionage:' . $mission->id . ':'), 'Une bataille arrivee avant l ouverture de la collecte a ete comptee sur l heure de son traitement.');
    }
}
