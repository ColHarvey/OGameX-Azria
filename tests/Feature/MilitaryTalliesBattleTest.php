<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\BattleTallyEvaluation;
use OGame\Military\BattleTallyFacts;
use OGame\Military\MilitaryBattleTally;
use OGame\Military\MilitaryTallyPublisher;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryTallyReplay;
use OGame\Military\MilitaryValue;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use ReflectionProperty;
use stdClass;
use Tests\Feature\Combat\OpensAPersistentAcsBattle;
use Tests\FleetDispatchTestCase;

/**
 * Le raccordement des cumuls militaires a la bataille : un evenement final par participant classe, calcule round par
 * round sur le resultat gele, dans l espace de clefs du combat durable ou de la mission instantanee ; une attente entiere
 * quand l evaluation ne conclut pas, reprise plus tard sur les memes faits.
 */
final class MilitaryTalliesBattleTest extends FleetDispatchTestCase
{
    use OpensAPersistentAcsBattle;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /** @var list<int> */
    private array $comptes = [];

    protected function basicSetup(): void
    {
        $this->basicSetupForAnAcsBattle();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        DB::table('settings')->whereIn('key', [MilitaryTallyRecorder::SINCE_KEY, MilitaryTallyPublisher::PUBLISHED_KEY])->delete();
        DB::table('military_tally_events')->where('event_key', 'like', 'battle:%')->delete();
        DB::table('military_tallies')->whereIn('player_id', [$this->currentUserId, ...$this->comptes])->delete();
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);

        parent::tearDown();
    }

    /**
     * **Le temoin d effet de la source « bataille ».** Une attaque groupee durable — deux attaquantes, une garnison —
     * reglee par le jeu : chaque participant classe recoit un evenement, une fois, dans l espace du combat, dont les
     * valeurs sont celles que l evaluation tire du resultat gele, et les deux camps se conservent.
     */
    public function testADurableGroupBattleCreditsEachRankedParticipantOnceFromItsRounds(): void
    {
        $this->activer($this->maintenant());

        [$combat, $cible, $initiatrice, $alliee] = $this->anAcsBattleReadyToSettle(
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 60],
            ['rocket_launcher' => 60, 'light_fighter' => 80]
        );

        $this->settle($combat);
        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'Premisse : le combat a ete regle.');

        $resultat = BattleResultCodec::fromStorage($combat->battle_result);
        $faits = BattleTallyFacts::fromBattleResult($resultat, CombatParticipantKey::forPlanet($cible), BattleTallyFacts::SPACE_COMBAT, (int)$combat->id, (int)$combat->ends_at, []);
        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), 'Premisse : la bataille s evalue (' . $issue->detail . ').');
        $credits = $issue->credits();
        $proprietaire = (int)DB::table('planets')->where('id', $cible)->value('user_id');

        $participants = [CombatParticipantKey::forFleet((int)$alliee->id), CombatParticipantKey::forFleet((int)$initiatrice->id), CombatParticipantKey::forPlanet($cible)];
        sort($participants);
        $this->assertSame($participants, array_keys($credits), 'Premisse : les trois participants ont quelque chose a compter.');
        $this->assertGreaterThan(0, $credits[CombatParticipantKey::forFleet((int)$alliee->id)]['destroyed'], 'Premisse : l alliee a detruit quelque chose.');
        $this->assertNotSame(
            $credits[CombatParticipantKey::forFleet((int)$alliee->id)]['destroyed'],
            $credits[CombatParticipantKey::forFleet((int)$initiatrice->id)]['destroyed'],
            'Premisse : les deux attaquantes n ont pas les memes forces, le prorata doit se voir.'
        );

        $evenements = $this->evenements('battle:combat:' . $combat->id . ':');
        $this->assertSame(array_map(fn (string $clef): string => $faits->eventKeyFor($clef), array_keys($credits)), array_keys($evenements), 'Les evenements ne sont pas un par participant classe, dans l espace du combat.');

        foreach ($credits as $clef => $credit) {
            $ligne = $evenements[$faits->eventKeyFor($clef)];
            $this->assertSame(MilitaryTallyRecorder::APPLIED, $ligne->status);
            $this->assertSame([$credit['owner'], 0, $credit['destroyed'], $credit['lost']], [(int)$ligne->player_id, (int)$ligne->built_value, (int)$ligne->destroyed_value, (int)$ligne->lost_value], 'L evenement de ' . $clef . ' ne porte pas les valeurs evaluees.');
        }

        $this->assertSame($this->currentUserId, (int)$evenements[$faits->eventKeyFor(CombatParticipantKey::forFleet((int)$initiatrice->id))]->player_id);
        $this->assertSame((int)$this->acsAllyUser()->id, (int)$evenements[$faits->eventKeyFor(CombatParticipantKey::forFleet((int)$alliee->id))]->player_id);
        $this->assertSame($proprietaire, (int)$evenements[$faits->eventKeyFor(CombatParticipantKey::forPlanet($cible))]->player_id);

        $this->assertSame($issue->computed[CombatParticipantKey::forPlanet($cible)]['lost'], $credits[CombatParticipantKey::forFleet((int)$alliee->id)]['destroyed'] + $credits[CombatParticipantKey::forFleet((int)$initiatrice->id)]['destroyed'], 'Conservation : les detruits des attaquantes ne font pas les perdus de la garnison.');
        $this->assertSame($credits[CombatParticipantKey::forFleet((int)$alliee->id)]['lost'] + $credits[CombatParticipantKey::forFleet((int)$initiatrice->id)]['lost'], $issue->computed[CombatParticipantKey::forPlanet($cible)]['destroyed'], 'Conservation : les detruits de la garnison ne font pas les perdus des attaquantes.');
    }

    /**
     * **L instant du fait est l echeance, jamais l heure du traitement.** Une bataille dont l echeance precede la
     * collecte n entre pas, meme reglee apres ; une echeance a la seconde de l activation entre.
     */
    public function testTheDeadlineDecidesWhetherABattleIsCollectedNeverTheSettlementTime(): void
    {
        [$resultat, $mission, $corps] = $this->uneBatailleFabriquee();
        $echeance = $this->maintenant() - 3_600;

        $this->activer($echeance + 1);
        $avant = resolve(MilitaryBattleTally::class)->record($resultat, $corps, $mission, $echeance);
        $this->assertNull($avant, 'Une bataille anterieure a la collecte a ete evaluee.');
        $this->assertSame([], $this->evenements('battle:'), 'Un reglement tardif a rendu admissible une bataille anterieure a la collecte.');

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->update(['value' => (string)$echeance]);
        $apres = resolve(MilitaryBattleTally::class)->record($resultat, $corps, $mission, $echeance);

        $this->assertNotNull($apres, 'Une echeance a la seconde de l activation n est pas entree.');
        $this->assertCount(3, $this->evenements('battle:mission:' . $mission->id . ':'), 'Une echeance a la seconde de l activation n est pas entree.');
    }

    /**
     * **Le chemin instantane ecrit dans l espace de la mission.** Sans combat durable, l attaque se regle a l arrivee et
     * ses evenements portent `battle:mission:<id>`.
     */
    public function testTheInstantPathWritesInTheMissionKeySpace(): void
    {
        $this->activer($this->maintenant());
        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 120);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        DB::table('users')->where('id', $cible->getPlayer()?->getId())->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cible->getPlanetId())->update(['rocket_launcher' => 40, 'light_fighter' => 30]);

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 1)->where('processed', 0)->orderByDesc('id')->firstOrFail();
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 1));
        $this->get('/overview')->assertStatus(200);

        $this->assertSame(1, (int)FleetMission::query()->findOrFail($mission->id)->processed, 'Premisse : l attaque a ete reglee a l arrivee.');
        $this->assertNull(FleetMission::query()->findOrFail($mission->id)->combat_instance_id, 'Premisse : aucun combat durable n a ete ouvert.');

        $evenements = $this->evenements('battle:mission:' . $mission->id . ':');
        $this->assertSame(
            ['battle:mission:' . $mission->id . ':' . CombatParticipantKey::forFleet((int)$mission->id), 'battle:mission:' . $mission->id . ':' . CombatParticipantKey::forPlanet($cible->getPlanetId())],
            array_keys($evenements),
            'Le chemin instantane n ecrit pas un evenement par participant dans l espace de la mission.'
        );
        $this->assertSame([], $this->evenements('battle:combat:'), 'Le chemin instantane a ecrit dans l espace du combat durable.');
        $this->assertGreaterThan(0, (int)$evenements['battle:mission:' . $mission->id . ':' . CombatParticipantKey::forFleet((int)$mission->id)]->destroyed_value);
    }

    /**
     * **Un compte PNJ est calcule, jamais credite** : ses pertes font les detruits de ses adversaires, et il ne recoit
     * aucun evenement.
     */
    public function testAnNpcGarrisonIsComputedButReceivesNoEvent(): void
    {
        $this->activer($this->maintenant());
        [$resultat, $mission, $corps, $comptes] = $this->uneBatailleFabriquee(garnisonNpc: true);

        $issue = resolve(MilitaryBattleTally::class)->record($resultat, $corps, $mission, $this->maintenant());

        $this->assertNotNull($issue);
        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        $this->assertTrue($issue->computed[$corps]['npc']);

        $evenements = $this->evenements('battle:mission:' . $mission->id . ':');
        $this->assertCount(2, $evenements, 'La garnison PNJ a recu un evenement.');
        $this->assertSame([$comptes['premiere'], $comptes['seconde']], array_map(static fn (stdClass $e): int => (int)$e->player_id, array_values($evenements)), 'Les deux attaquantes ne sont pas les seules creditees.');
        $this->assertNotContains($comptes['garnison'], array_map(static fn (stdClass $e): int => (int)$e->player_id, array_values($evenements)), 'Le PNJ a ete credite.');
        $this->assertSame(
            $issue->computed[$corps]['lost'],
            array_sum(array_map(static fn (stdClass $e): int => (int)$e->destroyed_value, array_values($evenements))),
            'Les pertes du PNJ ne sont plus creditees a ses adversaires.'
        );
    }

    /**
     * **La meme bataille inscrite deux fois n ecrit rien de plus** : la clef de l evenement est celle du fait.
     */
    public function testRecordingTheSameBattleTwiceWritesNothingMore(): void
    {
        $this->activer($this->maintenant());
        [$resultat, $mission, $corps] = $this->uneBatailleFabriquee();

        resolve(MilitaryBattleTally::class)->record($resultat, $corps, $mission, $this->maintenant());
        $premiers = $this->evenements('battle:mission:' . $mission->id . ':');
        $this->assertCount(3, $premiers, 'Premisse : trois participants classes.');

        resolve(MilitaryBattleTally::class)->record($resultat, $corps, $mission, $this->maintenant());

        $this->assertEquals($premiers, $this->evenements('battle:mission:' . $mission->id . ':'), 'Un second passage a ecrit ou change quelque chose.');
    }

    /**
     * **Une unite hors catalogue laisse toute la bataille en attente, et la reprise l applique sur ses faits gardes.**
     */
    public function testAnUnknownUnitLeavesTheWholeBattlePendingAndTheReplayAppliesItLater(): void
    {
        $this->activer($this->maintenant());
        [$resultat, $mission, $corps] = $this->uneBatailleFabriquee();

        $this->amputerLaTable('light_fighter');
        $issue = resolve(MilitaryBattleTally::class)->record($resultat, $corps, $mission, $this->maintenant());

        $this->assertNotNull($issue);
        $this->assertSame(BattleTallyEvaluation::UNKNOWN_UNIT_FAMILY, $issue->reason);

        $enAttente = $this->evenements('battle:mission:' . $mission->id . ':');
        $this->assertCount(3, $enAttente, 'Chaque participant classe n a pas son attente.');

        foreach ($enAttente as $clef => $ligne) {
            $this->assertSame(MilitaryTallyRecorder::PENDING, $ligne->status, "L evenement $clef n attend pas.");
            $this->assertSame(BattleTallyEvaluation::UNKNOWN_UNIT_FAMILY, $ligne->reason);
            $this->assertSame([0, 0, 0], [(int)$ligne->built_value, (int)$ligne->destroyed_value, (int)$ligne->lost_value], "L evenement $clef porte un credit partiel.");
            $charge = json_decode((string)$ligne->payload, true);
            $this->assertSame(MilitaryBattleTally::KIND, $charge['kind'] ?? null);
            $this->assertStringContainsString('light_fighter', (string)($charge['detail'] ?? ''));
            $this->assertNotNull(BattleTallyFacts::fromStorage($charge['facts'] ?? null), "Les faits de $clef ne se relisent pas.");
        }

        // Le catalogue retrouve l unite : la reprise applique ce que l evaluation donne sur les faits gardes.
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);
        $bilan = (new MilitaryTallyReplay())->replay();
        $this->assertSame(3, $bilan['replayed'], 'La reprise n a pas repris les trois attentes.');

        $faits = BattleTallyFacts::fromStorage(json_decode((string)array_values($enAttente)[0]->payload, true)['facts']);
        $this->assertNotNull($faits);
        $attendus = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION)->credits();

        foreach ($this->evenements('battle:mission:' . $mission->id . ':') as $clef => $ligne) {
            $participant = substr($clef, strlen('battle:mission:' . $mission->id . ':'));
            $this->assertSame(MilitaryTallyRecorder::APPLIED, $ligne->status, "L evenement $clef attend encore.");
            $this->assertSame([$attendus[$participant]['destroyed'], $attendus[$participant]['lost']], [(int)$ligne->destroyed_value, (int)$ligne->lost_value], "La reprise de $clef ne donne pas ce que les faits gardes donnent.");
            $this->assertNotNull($ligne->resolved_at);
        }
    }

    /**
     * **Une manoeuvre de Hamill sans victime nommee laisse toute la bataille en attente, et la reprise l y laisse.**
     */
    public function testAHamillManoeuvreLeavesTheWholeBattlePendingAndStaysPendingOnReplay(): void
    {
        $this->activer($this->maintenant());
        [$resultat, $mission, $corps] = $this->uneBatailleFabriquee();
        $resultat->hamillManoeuvreTriggered = true;

        $issue = resolve(MilitaryBattleTally::class)->record($resultat, $corps, $mission, $this->maintenant());

        $this->assertNotNull($issue);
        $this->assertSame(BattleTallyEvaluation::HAMILL_VICTIM_UNNAMED, $issue->reason);
        $enAttente = $this->evenements('battle:mission:' . $mission->id . ':');
        $this->assertCount(3, $enAttente);

        $bilan = (new MilitaryTallyReplay())->replay();

        $this->assertSame(0, $bilan['replayed'], 'Une bataille sous Hamill a ete reprise sans que le moteur nomme la victime.');
        $this->assertSame(3, $bilan['pending']);

        foreach ($this->evenements('battle:mission:' . $mission->id . ':') as $ligne) {
            $this->assertSame(MilitaryTallyRecorder::PENDING, $ligne->status);
            $this->assertSame([0, 0, 0], [(int)$ligne->built_value, (int)$ligne->destroyed_value, (int)$ligne->lost_value]);
        }
    }

    /**
     * **Des faits illisibles laissent l evenement en attente** : la reprise n evalue rien sur un document deforme.
     */
    public function testAnUnreadableBattlePayloadStaysPending(): void
    {
        $this->activer($this->maintenant());
        $clef = 'battle:mission:999999:fleet:1';

        DB::table('military_tally_events')->insert([
            'event_key' => $clef,
            'player_id' => $this->currentUserId,
            'status' => MilitaryTallyRecorder::PENDING,
            'reason' => BattleTallyEvaluation::INCOHERENT_BATTLE,
            'payload' => json_encode(['kind' => MilitaryBattleTally::KIND, 'participant' => CombatParticipantKey::forFleet(1), 'facts' => ['schema' => 99]]),
            'weighting_version' => MilitaryValue::WEIGHTING_VERSION,
            'built_value' => 0,
            'destroyed_value' => 0,
            'lost_value' => 0,
            'recorded_at' => $this->maintenant(),
            'aggregated_at' => null,
            'resolved_at' => null,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        $bilan = (new MilitaryTallyReplay())->replay();

        $this->assertSame(0, $bilan['replayed']);
        $this->assertSame(MilitaryTallyRecorder::PENDING, $this->evenements($clef)[$clef]->status);
    }

    private function settle(CombatInstance $combat): void
    {
        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);
    }

    private function maintenant(): int
    {
        return (int)Date::now()->timestamp;
    }

    private function activer(int $instant): void
    {
        DB::table('settings')->insert([
            'key' => MilitaryTallyRecorder::SINCE_KEY,
            'value' => (string)$instant,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }

    /**
     * @return array<string, stdClass> Les evenements dont la clef commence ainsi, par clef.
     */
    private function evenements(string $prefixe): array
    {
        $lignes = [];

        foreach (DB::table('military_tally_events')->where('event_key', 'like', $prefixe . '%')->orderBy('event_key')->get() as $ligne) {
            $lignes[(string)$ligne->event_key] = $ligne;
        }

        return $lignes;
    }

    /**
     * Deux attaquantes (10 et 30 chasseurs) contre une garnison de 20 lance-missiles, deux rounds, cinq lance-missiles
     * repares : la bataille des temoins unitaires, avec de vrais comptes et une vraie mission initiatrice.
     *
     * @return array{0: BattleResult, 1: FleetMission, 2: string, 3: array{premiere: int, seconde: int, garnison: int}}
     */
    private function uneBatailleFabriquee(bool $garnisonNpc = false): array
    {
        $premiere = $this->unCompte();
        $seconde = $this->unCompte();
        $garnison = $this->unCompte($garnisonNpc);

        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');

        $mission = FleetMission::forceCreate([
            'user_id' => $premiere,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'type_from' => 1,
            'galaxy_from' => 1,
            'system_from' => 1,
            'position_from' => 1,
            'planet_id_to' => $this->planetService->getPlanetId(),
            'type_to' => 1,
            'galaxy_to' => 1,
            'system_to' => 2,
            'position_to' => 2,
            'mission_type' => 1,
            'time_departure' => $this->maintenant() - 600,
            'time_arrival' => $this->maintenant(),
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 1,
        ]);
        $seconde_mission = (int)$mission->id + 1;

        $resultat = new BattleResult();
        $resultat->hamillManoeuvreTriggered = false;
        $resultat->repairedDefenses = $this->collection([$lanceur, 5]);

        $flotte1 = new AttackerFleetResult((int)$mission->id, $premiere, $this->collection([$chasseur, 10]));
        $flotte1->unitsLost = $this->collection([$chasseur, 10]);
        $flotte2 = new AttackerFleetResult($seconde_mission, $seconde, $this->collection([$chasseur, 30]));
        $flotte2->unitsLost = $this->collection([$chasseur, 10]);
        $resultat->attackerFleetResults = [$flotte1, $flotte2];

        $corps = new DefenderFleetResult(0, $garnison, $this->collection([$lanceur, 20]));
        $corps->unitsLost = $this->collection([$lanceur, 15]);
        $resultat->defenderFleetResults = [$corps];

        $clefCorps = CombatParticipantKey::forPlanet(7);
        $clef1 = CombatParticipantKey::forFleet((int)$mission->id);
        $clef2 = CombatParticipantKey::forFleet($seconde_mission);

        $round1 = new BattleResultRound();
        $round1->lossesInRoundByParticipant = [$clef1 => $this->collection([$chasseur, 4]), $clef2 => $this->collection([$chasseur, 6]), $clefCorps => $this->collection([$lanceur, 6])];
        $round1->attackerShipsPerFleet = [(int)$mission->id => $this->collection([$chasseur, 6]), $seconde_mission => $this->collection([$chasseur, 24])];
        $round2 = new BattleResultRound();
        $round2->lossesInRoundByParticipant = [$clef1 => $this->collection([$chasseur, 6]), $clef2 => $this->collection([$chasseur, 4]), $clefCorps => $this->collection([$lanceur, 9])];
        $round2->attackerShipsPerFleet = [(int)$mission->id => new UnitCollection(), $seconde_mission => $this->collection([$chasseur, 20])];
        $resultat->rounds = [$round1, $round2];

        return [$resultat, $mission, $clefCorps, ['premiere' => $premiere, 'seconde' => $seconde, 'garnison' => $garnison]];
    }

    private function unCompte(bool $npc = false): int
    {
        $compte = User::factory()->create(['username' => 'bataille_' . bin2hex(random_bytes(5)), 'is_npc' => $npc]);
        $this->comptes[] = (int)$compte->id;

        return (int)$compte->id;
    }

    /**
     * @param array{0: \OGame\GameObjects\Models\UnitObject, 1: int} ...$entrees
     */
    private function collection(array ...$entrees): UnitCollection
    {
        $collection = new UnitCollection();

        foreach ($entrees as [$objet, $nombre]) {
            $collection->addUnit($objet, $nombre);
        }

        return $collection;
    }

    /**
     * Retire une unite de la table des poids, comme si le catalogue ne la connaissait pas.
     */
    private function amputerLaTable(string $nom): void
    {
        MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);

        $poids = new ReflectionProperty(MilitaryValue::class, 'poids');
        $table = (array)$poids->getValue();
        unset($table[$nom]);
        $poids->setValue(null, $table);
    }
}
