<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Enums\FleetMissionStatus;
use OGame\Services\PlanetService;
use Tests\AccountTestCase;
use Tests\Feature\Lifeforms\LifeformPagesTest;

/**
 * **L'alarme d'attaque du bandeau dit ce que la boite d'evenements compte** (demande de Keven, journal §156).
 *
 * L'icone rouge qui bat (`#attack_alert.soon`, feuille officielle) etait rendue une fois, au chargement de la page,
 * sur une regle a elle : ni les missiles que la boite d'evenements comptait hostiles, ni les missions rappelees
 * qu'elle excluait. Depuis, une seule regle de camp (`FleetMissionStatus::ofMissionType()`) sert l'alarme, la boite
 * et la Galaxie ; le bandeau des ressources porte l'alarme dans l'objet qu'il resynchronise (annonce Echo d'un
 * mouvement de flotte, ou veille de trente secondes, hors `globalgame`), et la page la tient a jour sans recharger.
 */
final class AttackAlertTest extends AccountTestCase
{
    private PlanetService $etrangere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->etrangere = $this->getNearbyForeignPlanet();
        DB::table('fleet_missions')->where('planet_id_to', $this->planetService->getPlanetId())->delete();
    }

    protected function tearDown(): void
    {
        DB::table('fleet_missions')->where('planet_id_to', $this->planetService->getPlanetId())->delete();
        parent::tearDown();
    }

    public function testTheRuleOfSidesIsOneForEveryReader(): void
    {
        $this->assertSame([1, 2, 6, 9, 10], FleetMissionStatus::HOSTILE_MISSION_TYPES, 'Attaque, attaque groupee, espionnage, destruction de lune, missiles.');
        foreach (FleetMissionStatus::HOSTILE_MISSION_TYPES as $genre) {
            $this->assertSame(FleetMissionStatus::Hostile, FleetMissionStatus::ofMissionType($genre, false));
            $this->assertSame(FleetMissionStatus::Friendly, FleetMissionStatus::ofMissionType($genre, true), 'Sa propre mission n est jamais hostile.');
        }
        $this->assertSame(FleetMissionStatus::Neutral, FleetMissionStatus::ofMissionType(3, false));
        $this->assertSame(FleetMissionStatus::Neutral, FleetMissionStatus::ofMissionType(5, false));
        $this->assertSame(FleetMissionStatus::Friendly, FleetMissionStatus::ofMissionType(4, false), 'Un genre qui ne vise personne est ami.');
    }

    public function testTheAlarmLightsForEveryHostileKindTheEventBoxCountsAndForNothingElse(): void
    {
        $this->assertAlarm(false, 0, 'Sans mission, rien.');

        foreach (FleetMissionStatus::HOSTILE_MISSION_TYPES as $genre) {
            $id = $this->uneMissionEtrangereVersMoi(['mission_type' => $genre]);
            $this->assertAlarm(true, 1, "Genre $genre : l alarme s allume et la boite compte un vol hostile.");
            DB::table('fleet_missions')->where('id', $id)->delete();
        }

        // Un transport d'un autre joueur : neutre pour la boite, rien pour l'alarme.
        $id = $this->uneMissionEtrangereVersMoi(['mission_type' => 3]);
        $this->assertAlarm(false, 0, 'Un transport etranger n est pas une attaque.');
        $this->assertSame(1, $this->getJson(route('fleet.eventbox.fetch'))->json('neutral'));
        DB::table('fleet_missions')->where('id', $id)->delete();

        // Sa propre attaque vers l'etranger : amie.
        $cible = $this->etrangere->getPlanetCoordinates();
        $depart = $this->planetService->getPlanetCoordinates();
        $id = (int)DB::table('fleet_missions')->insertGetId([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'galaxy_from' => $depart->galaxy, 'system_from' => $depart->system, 'position_from' => $depart->position,
            'planet_id_to' => $this->etrangere->getPlanetId(),
            'galaxy_to' => $cible->galaxy, 'system_to' => $cible->system, 'position_to' => $cible->position,
            'type_from' => 1, 'type_to' => 1, 'mission_type' => 1,
            'time_departure' => time(), 'time_arrival' => time() + 3600, 'light_fighter' => 10,
            'processed' => 0, 'canceled' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertAlarm(false, 0, 'Attaquer n allume pas sa propre alarme.');
        DB::table('fleet_missions')->where('id', $id)->delete();
    }

    public function testARecalledOrSettledAttackPutsTheAlarmOut(): void
    {
        $id = $this->uneMissionEtrangereVersMoi(['mission_type' => 1]);
        $this->assertAlarm(true, 1);

        DB::table('fleet_missions')->where('id', $id)->update(['canceled' => 1]);
        $this->assertAlarm(false, 0, 'Une attaque rappelee ne menace plus : la boite l exclut, l alarme aussi.');

        DB::table('fleet_missions')->where('id', $id)->update(['canceled' => 0, 'processed' => 1]);
        $this->assertAlarm(false, 0, 'Une attaque reglee non plus.');
    }

    /**
     * La page tient l'alarme a jour sans se recharger : l'ancre est toujours la, le script lit les deux flux.
     */
    public function testThePageCarriesTheAlarmAndTheHookThatKeepsItCurrent(): void
    {
        $sans = (string)$this->get(route('overview.index'))->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('#<div id="attack_alert" class="\s*noAttack\s*"#', $sans));
        $this->assertSame(1, preg_match('#<a href="javascript:void\(0\);" id="attackAlertLink" class="tooltipHTML js_hideTipOnMobile" title="' . preg_quote(e(__('t_ingame.layout.under_attack')), '#') . '" onclick="toggleEvents\(\); return false;" style="display: none;"></a>#', $sans), 'L ancre existe, cachee, et son clic ouvre la liste des evenements.');
        $this->assertStringContainsString("document.addEventListener('ogamex:resourcebox'", $sans, 'Le bandeau des ressources tient l alarme.');
        $this->assertStringNotContainsString("options.url.indexOf('/ajax/fleet/eventbox/fetch')", $sans, 'Pas la boite d evenements : son compte vaut zero pendant une bataille durable.');
        $this->assertStringContainsString('poserAlarme(lu.attack.hostile)', $sans);
        $this->assertStringNotContainsString('#TODO_componentOnly', $sans, 'Plus d adresse a faire.');
        LifeformPagesTest::assertScriptsCarryNoHtmlEntity($sans);

        $this->uneMissionEtrangereVersMoi(['mission_type' => 1]);
        $avec = (string)$this->get(route('overview.index'))->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('#<div id="attack_alert" class="\s*soon\s*"\s*title="' . preg_quote(e(__('t_ingame.layout.under_attack')), '#') . '"\s*>#', $avec), 'Sous attaque, la boite porte son infobulle.');
        $this->assertSame(1, preg_match('#id="attackAlertLink" class="tooltipHTML js_hideTipOnMobile" title="[^"]*" onclick="toggleEvents\(\); return false;"></a>#', $avec), 'Sous attaque, l ancre est visible.');
    }

    /**
     * @param array<string, mixed> $colonnes
     */
    private function uneMissionEtrangereVersMoi(array $colonnes): int
    {
        $cible = $this->planetService->getPlanetCoordinates();
        $depart = $this->etrangere->getPlanetCoordinates();
        $proprietaire = $this->etrangere->getPlayer();
        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId(), 'Premisse : la planete etrangere est a un autre joueur.');

        return (int)DB::table('fleet_missions')->insertGetId($colonnes + [
            'user_id' => $proprietaire->getId(),
            'planet_id_from' => $this->etrangere->getPlanetId(),
            'galaxy_from' => $depart->galaxy, 'system_from' => $depart->system, 'position_from' => $depart->position,
            'planet_id_to' => $this->planetService->getPlanetId(),
            'galaxy_to' => $cible->galaxy, 'system_to' => $cible->system, 'position_to' => $cible->position,
            'type_from' => 1, 'type_to' => 1, 'mission_type' => 1,
            'time_departure' => time(), 'time_arrival' => time() + 3600, 'light_fighter' => 10,
            'processed' => 0, 'canceled' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Les trois lecteurs, d'un coup : la page, le bandeau resynchronise, la boite d'evenements.
     */
    private function assertAlarm(bool $attendue, int $hostiles, string $message = ''): void
    {
        $page = (string)$this->get(route('overview.index'))->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('#<div id="attack_alert" class="\s*' . ($attendue ? 'soon' : 'noAttack') . '\s*"#', $page), $message . ' (page)');
        $this->assertSame($attendue, $this->getJson(route('resourcebox.ajax'))->assertStatus(200)->json('attack.hostile'), $message . ' (bandeau)');
        $this->assertSame($hostiles, $this->getJson(route('fleet.eventbox.fetch'))->assertStatus(200)->json('hostile'), $message . ' (boite)');
    }
}
