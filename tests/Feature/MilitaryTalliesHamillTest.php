<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Enums\CharacterClass;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Military\BattleTallyEvaluation;
use OGame\Military\BattleTallyFacts;
use OGame\Military\HamillPendingConversion;
use OGame\Military\MilitaryTallyPublisher;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryTallyReplay;
use OGame\Military\MilitaryValue;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use ReflectionProperty;
use stdClass;
use Tests\Feature\Combat\OpensAPersistentAcsBattle;
use Tests\FleetDispatchTestCase;
use Tests\RecordsClassHistory;

/**
 * La manoeuvre de Hamill dans les cumuls : l Etoile prise, nommee par le moteur a l instant du retrait, est comptee
 * **une fois** en perdus chez la victime et creditee en detruits **a l auteur seul**, par un evenement nomme qui vit
 * dans le meme groupe que la bataille — ecrit, attendu, repris et converti avec elle, jamais l un sans l autre.
 */
final class MilitaryTalliesHamillTest extends FleetDispatchTestCase
{
    use OpensAPersistentAcsBattle;
    use RecordsClassHistory;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private int $chance = 1_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chance = resolve(SettingsService::class)->hamillManoeuvreChance();
    }

    protected function basicSetup(): void
    {
        $this->basicSetupForAnAcsBattle();
        // Des croiseurs pour le scenario ou l initiatrice n a aucun chasseur.
        $this->planetAddUnit('cruiser', 30);
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
        DB::table('military_tally_events')->where('event_key', 'like', 'battle:%')->orWhere('event_key', 'like', 'hamill:%')->delete();
        DB::table('military_tallies')->where('player_id', $this->currentUserId)->delete();
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        resolve(SettingsService::class)->set('hamill_manoeuvre_chance', $this->chance);
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);

        parent::tearDown();
    }

    /**
     * **Le temoin d effet de la source « manoeuvre-de-hamill ».**
     */
    public function testADurableBattleUnderTheManoeuvreCreditsTheStarOnceToTheGeneralConsulted(): void
    {
        [$combat, $cible, $initiatrice, $alliee, $resultat] = $this->uneBatailleDurableSousHamill(
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 60],
            ['rocket_launcher' => 60, 'light_fighter' => 80, 'deathstar' => 1]
        );

        $clefInitiatrice = CombatParticipantKey::forFleet((int)$initiatrice->id);
        $clefGarnison = CombatParticipantKey::forPlanet($cible);
        $etoile = MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('deathstar'), 1);

        $this->assertSame($clefInitiatrice, $resultat->hamill->author, 'Premisse : l auteur enregistre est l initiatrice, premiere dans l ordre canonique.');
        $this->assertSame($clefGarnison, $resultat->hamill->victim, 'Premisse : la victime enregistree est la garnison.');

        foreach ($resultat->rounds as $rang => $round) {
            $this->assertSame(0, ($round->lossesInRoundByParticipant[$clefGarnison] ?? null)?->getAmountByMachineName('deathstar') ?? 0, 'Le round ' . ($rang + 1) . ' porte l Etoile prise par la manoeuvre.');
        }

        $faits = BattleTallyFacts::fromBattleResult($resultat, $clefGarnison, BattleTallyFacts::SPACE_COMBAT, (int)$combat->id, (int)$combat->ends_at, []);
        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);
        $this->assertFalse($issue->isPending(), 'Premisse : la bataille s evalue (' . $issue->detail . ').');

        $evenements = $this->evenements('battle:combat:' . $combat->id . ':') + $this->evenements('hamill:combat:' . $combat->id . ':');
        $clefHamill = $faits->hamillEventKeyFor($clefInitiatrice);

        $this->assertArrayHasKey($clefHamill, $evenements, 'L evenement nomme de la manoeuvre manque.');
        $this->assertSame([MilitaryTallyRecorder::APPLIED, $this->currentUserId, 0, $etoile, 0], [
            $evenements[$clefHamill]->status,
            (int)$evenements[$clefHamill]->player_id,
            (int)$evenements[$clefHamill]->built_value,
            (int)$evenements[$clefHamill]->destroyed_value,
            (int)$evenements[$clefHamill]->lost_value,
        ], 'L evenement nomme ne porte pas la valeur de l Etoile pour l auteur.');

        $garnison = $evenements[$faits->eventKeyFor($clefGarnison)];
        $attaquantes = (int)$evenements[$faits->eventKeyFor($clefInitiatrice)]->destroyed_value + (int)$evenements[$faits->eventKeyFor(CombatParticipantKey::forFleet((int)$alliee->id))]->destroyed_value;

        $this->assertSame(1, $this->laFlotteDefensive($resultat, 0)->unitsLost->getAmountByMachineName('deathstar'), 'La garnison n a pas perdu exactement une Etoile.');
        $this->assertSame($attaquantes + $etoile, (int)$garnison->lost_value, 'Les perdus de la garnison ne sont pas le partage des rounds plus l Etoile nommee, une fois.');
        $this->assertSame($issue->computed[$clefGarnison]['lost'] - $etoile, $attaquantes, 'Le partage des rounds porte une part de l Etoile.');
        $this->assertCount(4, $evenements, 'Le groupe n est pas exactement trois evenements de bataille et un evenement nomme.');
    }

    /**
     * **Convention Azria : l auteur est la flotte dont le General a ete consulte**, meme quand seul un allie porte les
     * chasseurs. Le declenchement ne change pas.
     */
    public function testWhenOnlyTheAllyCarriesTheFightersTheStarIsStillCreditedToTheFleetWhoseGeneralWasConsulted(): void
    {
        [$combat, , $initiatrice, $alliee, $resultat] = $this->uneBatailleDurableSousHamill(
            ['cruiser' => 30, 'recycler' => 1],
            ['light_fighter' => 60],
            ['rocket_launcher' => 40, 'deathstar' => 1]
        );

        $this->assertSame(0, $resultat->attackerFleetResults[0]->unitsStart->getAmountByMachineName('light_fighter'), 'Premisse : la premiere flotte n a aucun chasseur.');
        $this->assertSame(CombatParticipantKey::forFleet((int)$initiatrice->id), $resultat->hamill->author);

        $hamill = $this->evenements('hamill:combat:' . $combat->id . ':');
        $this->assertCount(1, $hamill);
        $this->assertSame($this->currentUserId, (int)array_values($hamill)[0]->player_id, 'L Etoile n est pas creditee au General consulte.');
        $this->assertStringEndsWith(':' . CombatParticipantKey::forFleet((int)$initiatrice->id), array_keys($hamill)[0]);
        $this->assertNotSame((int)$alliee->user_id, (int)array_values($hamill)[0]->player_id);
    }

    /**
     * **La reprise du groupe est tout ou rien** : la bataille et sa manoeuvre attendent ensemble, se reprennent
     * ensemble ; un membre illisible ou manquant retient tout le groupe, et rien n est applique deux fois.
     */
    public function testTheBattleAndItsManoeuvreAreReplayedTogetherOrNotAtAll(): void
    {
        $this->amputerLaTable('light_fighter');
        [$combat, $cible, $initiatrice, , $resultat] = $this->uneBatailleDurableSousHamill(
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 60],
            ['rocket_launcher' => 60, 'light_fighter' => 80, 'deathstar' => 1]
        );

        $groupe = fn (): array => $this->evenements('battle:combat:' . $combat->id . ':') + $this->evenements('hamill:combat:' . $combat->id . ':');
        $this->assertCount(4, $groupe(), 'Premisse : quatre attentes.');

        foreach ($groupe() as $clef => $ligne) {
            $this->assertSame(MilitaryTallyRecorder::PENDING, $ligne->status, "$clef n attend pas.");
            $this->assertSame(BattleTallyEvaluation::UNKNOWN_UNIT_FAMILY, $ligne->reason);
        }

        $clefHamill = 'hamill:combat:' . $combat->id . ':' . CombatParticipantKey::forFleet((int)$initiatrice->id);
        $this->assertArrayHasKey($clefHamill, $groupe(), 'L evenement nomme n attend pas avec la bataille.');

        // Un membre illisible retient tout le groupe, meme le catalogue retrouve.
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);
        $sain = (string)$groupe()[$clefHamill]->payload;
        DB::table('military_tally_events')->where('event_key', $clefHamill)->update(['payload' => json_encode(['kind' => 'hamill', 'participant' => 'x', 'facts' => ['schema' => 99]])]);
        $bilan = (new MilitaryTallyReplay())->replay();
        $this->assertSame(0, $bilan['replayed'], 'Un groupe dont un membre est illisible a ete repris en partie.');
        $this->assertSame([], array_filter($groupe(), static fn (stdClass $l): bool => $l->status !== MilitaryTallyRecorder::PENDING), 'Un membre a ete applique sans les autres.');

        // Un membre manquant aussi : le credit nomme n a plus d attente, la bataille ne s applique pas sans lui.
        DB::table('military_tally_events')->where('event_key', $clefHamill)->delete();
        $bilan = (new MilitaryTallyReplay())->replay();
        $this->assertSame(0, $bilan['replayed'], 'La bataille a ete reprise sans son evenement nomme.');

        // Le membre revient : le groupe entier s applique en un passage, une fois.
        DB::table('military_tally_events')->insert([
            'event_key' => $clefHamill,
            'player_id' => $this->currentUserId,
            'status' => MilitaryTallyRecorder::PENDING,
            'reason' => BattleTallyEvaluation::UNKNOWN_UNIT_FAMILY,
            'payload' => $sain,
            'weighting_version' => MilitaryValue::WEIGHTING_VERSION,
            'built_value' => 0,
            'destroyed_value' => 0,
            'lost_value' => 0,
            'recorded_at' => (int)Date::now()->timestamp,
            'aggregated_at' => null,
            'resolved_at' => null,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
        $bilan = (new MilitaryTallyReplay())->replay();
        $this->assertSame([1, 0, 3], [$bilan['replayed'], $bilan['pending'], $bilan['skipped']], 'Le groupe ne s est pas applique d un bloc : un repris, trois deja repris par lui.');

        $faits = BattleTallyFacts::fromBattleResult($resultat, CombatParticipantKey::forPlanet($cible), BattleTallyFacts::SPACE_COMBAT, (int)$combat->id, (int)$combat->ends_at, []);
        $attendu = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        foreach ($groupe() as $clef => $ligne) {
            $this->assertSame(MilitaryTallyRecorder::APPLIED, $ligne->status, "$clef attend encore.");
        }

        $hamill = $attendu->hamillCredit();
        $this->assertNotNull($hamill);
        $this->assertSame($hamill['destroyed'], (int)$groupe()[$clefHamill]->destroyed_value);
        $this->assertSame([1, 0, 4], [count(array_filter($groupe(), static fn (stdClass $l): bool => $l->resolved_at !== null)) > 0 ? 1 : 0, (new MilitaryTallyReplay())->replay()['replayed'], count($groupe())], 'Un second passage a repris quelque chose.');
    }

    /**
     * **La conversion retrouve exactement ce que l enregistrement vivant avait ecrit**, puis la reprise applique le
     * groupe converti, une fois. Les attentes que les faits conserves n etablissent pas restent non resolues.
     */
    public function testTheConversionOfAnOldPendingGroupReproducesTheLiveRecordExactly(): void
    {
        $this->amputerLaTable('light_fighter');
        [$combat, $cible, $initiatrice, , $resultat] = $this->uneBatailleDurableSousHamill(
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 60],
            ['rocket_launcher' => 60, 'light_fighter' => 80, 'deathstar' => 1]
        );
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);

        $prefixe = 'battle:combat:' . $combat->id . ':';
        $clefHamill = 'hamill:combat:' . $combat->id . ':' . CombatParticipantKey::forFleet((int)$initiatrice->id);
        $vivants = BattleTallyFacts::fromStorage(json_decode((string)array_values($this->evenements($prefixe))[0]->payload, true)['facts']);
        $this->assertNotNull($vivants);
        $this->assertNotNull($vivants->hamill, 'Premisse : les faits vivants nomment la manoeuvre.');

        // Le monde d avant : des faits du schema 1, sans manoeuvre nommee, en attente pour cette raison, sans evenement nomme.
        DB::table('military_tally_events')->where('event_key', $clefHamill)->delete();
        foreach ($this->evenements($prefixe) as $clef => $ligne) {
            $charge = json_decode((string)$ligne->payload, true);
            $charge['facts'] = ['schema' => 1] + array_diff_key($charge['facts'], ['hamill' => true, 'schema' => true]);
            $charge['facts']['pre_round_losses'] = [];
            DB::table('military_tally_events')->where('event_key', $clef)->update(['reason' => BattleTallyEvaluation::HAMILL_VICTIM_UNNAMED, 'payload' => json_encode($charge)]);
        }

        // Le resultat gele du combat ne nomme pas non plus la manoeuvre : c est la difference exacte qui doit l etablir.
        $gele = CombatInstance::query()->findOrFail($combat->id)->battle_result;
        $this->assertIsArray($gele);
        $gele['schema'] = 5;
        unset($gele['hamill']);
        CombatInstance::query()->whereKey($combat->id)->update(['battle_result' => json_encode($gele)]);

        $this->assertSame(0, (new MilitaryTallyReplay())->replay()['replayed'], 'Premisse : sans conversion, rien ne se reprend.');

        $aBlanc = resolve(HamillPendingConversion::class)->convert(false);
        $this->assertSame([1, 0], [$aBlanc['converted'], $aBlanc['unresolved']], 'Le passage a blanc ne voit pas un groupe convertible : ' . json_encode($aBlanc['groups']));
        $this->assertSame(HamillPendingConversion::CONVERTIBLE, $aBlanc['groups'][0]['outcome']);
        $this->assertSame([], $this->evenements($clefHamill), 'Le passage a blanc a ecrit.');

        $ecrit = resolve(HamillPendingConversion::class)->convert(true);
        $this->assertSame([1, 0], [$ecrit['converted'], $ecrit['unresolved']]);

        foreach ($this->evenements($prefixe) as $clef => $ligne) {
            $charge = json_decode((string)$ligne->payload, true);
            $convertis = BattleTallyFacts::fromStorage($charge['facts']);
            $this->assertNotNull($convertis, "$clef : faits convertis illisibles.");
            $this->assertEquals($vivants, $convertis, "$clef : la conversion ne retrouve pas exactement les faits de l enregistrement vivant.");
            $this->assertSame(HamillPendingConversion::CONVERTED, $ligne->reason);
            $this->assertSame(HamillPendingConversion::RULE, $charge['conversion']['rule'] ?? null, "$clef : la conversion n est pas auditee.");
            $this->assertSame(MilitaryTallyRecorder::PENDING, $ligne->status, "$clef : la conversion a credite.");
        }

        $insere = $this->evenements($clefHamill);
        $this->assertCount(1, $insere, 'L evenement nomme n a pas ete ajoute au groupe converti.');
        $this->assertSame([MilitaryTallyRecorder::PENDING, $this->currentUserId], [array_values($insere)[0]->status, (int)array_values($insere)[0]->player_id]);

        $bilan = (new MilitaryTallyReplay())->replay();
        $this->assertSame([1, 0, 3], [$bilan['replayed'], $bilan['pending'], $bilan['skipped']]);

        $attendu = (new BattleTallyEvaluation())->evaluate($vivants, MilitaryValue::WEIGHTING_VERSION);
        $hamill = $attendu->hamillCredit();
        $this->assertNotNull($hamill);
        $this->assertSame($hamill['destroyed'], (int)array_values($this->evenements($clefHamill))[0]->destroyed_value);
        $this->assertSame($attendu->credits()[CombatParticipantKey::forPlanet($cible)]['lost'], (int)$this->evenements($prefixe)[$prefixe . CombatParticipantKey::forPlanet($cible)]->lost_value);

        // Une seconde conversion ne trouve plus rien, et une seconde reprise n applique rien.
        $this->assertSame([0, 0], [resolve(HamillPendingConversion::class)->convert(true)['converted'], (new MilitaryTallyReplay())->replay()['replayed']]);
    }

    public function testAnOldPendingGroupWhoseFactsDoNotEstablishTheManoeuvreStaysExplicitlyUnresolved(): void
    {
        $this->amputerLaTable('light_fighter');
        [$combat, , $initiatrice] = $this->uneBatailleDurableSousHamill(
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 60],
            ['rocket_launcher' => 60, 'light_fighter' => 80, 'deathstar' => 1]
        );
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);

        $prefixe = 'battle:combat:' . $combat->id . ':';
        DB::table('military_tally_events')->where('event_key', 'hamill:combat:' . $combat->id . ':' . CombatParticipantKey::forFleet((int)$initiatrice->id))->delete();

        // Le resultat gele ne nomme pas la manoeuvre (schema 5) : un resultat qui la nomme la donnerait tel quel, et
        // c est le chemin par difference exacte que ces cas eprouvent.
        $gele = CombatInstance::query()->findOrFail($combat->id)->battle_result;
        $this->assertIsArray($gele);
        $gele['schema'] = 5;
        unset($gele['hamill']);
        CombatInstance::query()->whereKey($combat->id)->update(['battle_result' => json_encode($gele)]);

        // Chaque cas repart des faits vivants, jamais du cas precedent.
        $vivants = [];
        foreach ($this->evenements($prefixe) as $clef => $ligne) {
            $vivants[$clef] = json_decode((string)$ligne->payload, true);
        }

        $retrograder = function (callable $deformer) use ($vivants): void {
            foreach ($vivants as $clef => $charge) {
                $charge['facts'] = ['schema' => 1] + array_diff_key($charge['facts'], ['hamill' => true, 'schema' => true]);
                $charge['facts']['pre_round_losses'] = [];
                $charge['facts'] = $deformer($charge['facts']);
                unset($charge['conversion'], $charge['unresolved']);
                DB::table('military_tally_events')->where('event_key', $clef)->update(['reason' => BattleTallyEvaluation::HAMILL_VICTIM_UNNAMED, 'payload' => json_encode($charge)]);
            }
        };

        // Regle v1 sur l instance : l Etoile n est retiree d aucune flotte.
        CombatInstance::query()->whereKey($combat->id)->update(['hamill_rule_version' => 'v1']);
        $retrograder(static fn (array $f): array => $f);
        $bilan = resolve(HamillPendingConversion::class)->convert(true);
        $this->assertSame([0, 1], [$bilan['converted'], $bilan['unresolved']], 'Une attente sous la regle v1 a ete convertie : ' . json_encode($bilan['groups']));
        $this->assertStringContainsString('regle v1', $bilan['groups'][0]['detail']);
        $this->assertSame([HamillPendingConversion::UNRESOLVED], array_values(array_unique(array_map(static fn (stdClass $l): string => (string)$l->reason, array_values($this->evenements($prefixe))))));
        CombatInstance::query()->whereKey($combat->id)->update(['hamill_rule_version' => 'v3']);

        // Espace mission : la regle de l epoque n est pas conservee.
        $retrograder(static fn (array $f): array => ['space' => 'mission'] + $f);
        $bilan = resolve(HamillPendingConversion::class)->convert(true);
        $this->assertSame([0, 1], [$bilan['converted'], $bilan['unresolved']]);
        $this->assertStringContainsString('instantanee', $bilan['groups'][0]['detail']);

        // Deux flottes defensives presentent l ecart d une Etoile : la victime est ambigue.
        $retrograder(static function (array $f): array {
            $f['participants'][] = ['key' => CombatParticipantKey::forFleet(999_999), 'side' => 'defenseur', 'owner' => 1, 'npc' => false, 'start' => ['deathstar' => 1], 'lost' => ['deathstar' => 1]];

            return $f;
        });
        $bilan = resolve(HamillPendingConversion::class)->convert(true);
        $this->assertSame([0, 1], [$bilan['converted'], $bilan['unresolved']]);
        $this->assertStringContainsString('plusieurs flottes', $bilan['groups'][0]['detail']);

        // L ordre canonique ne se retrouve pas : la premiere attaquante des faits n est pas celle du resultat gele.
        $retrograder(static function (array $f): array {
            $f['participants'] = array_reverse($f['participants']);

            return $f;
        });
        $bilan = resolve(HamillPendingConversion::class)->convert(true);
        $this->assertSame([0, 1], [$bilan['converted'], $bilan['unresolved']]);
        $this->assertStringContainsString('ordre canonique', $bilan['groups'][0]['detail']);

        $this->assertSame(0, (new MilitaryTallyReplay())->replay()['replayed'], 'Une attente non resolue a ete reprise.');
    }

    /**
     * @return array{0: CombatInstance, 1: int, 2: FleetMission, 3: FleetMission, 4: BattleResult}
     */
    private function uneBatailleDurableSousHamill(array $initiateur, array $allie, array $cible): array
    {
        $this->activer($this->maintenant());
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        resolve(SettingsService::class)->set('hamill_manoeuvre_chance', 1);

        [$combat, $cibleId, $initiatrice, $alliee] = $this->anAcsBattleReadyToSettle($initiateur, $allie, $cible);

        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);
        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'Premisse : le combat a ete regle.');

        $resultat = BattleResultCodec::fromStorage($combat->battle_result);
        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'Premisse : la manoeuvre a eu lieu.');
        $this->assertTrue($resultat->hamill->isNamed(), 'Premisse : le moteur a nomme la manoeuvre.');

        return [$combat, $cibleId, $initiatrice, $alliee, $resultat];
    }

    private function laFlotteDefensive(BattleResult $resultat, int $fleetMissionId): \OGame\GameMissions\BattleEngine\Models\DefenderFleetResult
    {
        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte;
            }
        }

        $this->fail('La flotte defensive ' . $fleetMissionId . ' manque au resultat.');
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
     * @return array<string, stdClass>
     */
    private function evenements(string $prefixe): array
    {
        $lignes = [];

        foreach (DB::table('military_tally_events')->where('event_key', 'like', $prefixe . '%')->orderBy('event_key')->get() as $ligne) {
            $lignes[(string)$ligne->event_key] = $ligne;
        }

        return $lignes;
    }

    private function amputerLaTable(string $nom): void
    {
        MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);

        $poids = new ReflectionProperty(MilitaryValue::class, 'poids');
        $table = (array)$poids->getValue();
        unset($table[$nom]);
        $poids->setValue(null, $table);
    }
}
