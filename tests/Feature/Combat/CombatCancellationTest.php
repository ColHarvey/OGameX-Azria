<?php

namespace Tests\Feature\Combat;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatCancellationOutcome;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\PersistentCombatAdvance;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * La sortie d'exploitation d'un combat qui ne se reglera jamais : annuler, rendre, liberer.
 *
 * ## Ce qui doit tenir
 *
 * Rien n'est applique — le defenseur ne perd rien, l'attaquant ne prend rien, aucun rapport
 * n'existe. Chaque flotte rentre une seule fois, avec ce qu'elle portait et un message qui donne
 * la cause. Le corps redevient attaquable. Et une fois termine, un combat ne se defait plus.
 */
class CombatCancellationTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('fleet_missions')->whereNotNull('combat_instance_id')->update(['combat_instance_id' => null]);

        foreach ([
            'combat_snapshot_inclusions',
            'combat_outbox',
            'combat_participants',
            'combat_effect_receipts',
            'combat_loot_reservations',
            'celestial_body_combat_barriers',
            'combat_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 200);
        $this->planetAddUnit('light_fighter', 900);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);

        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('fleet_speed_holding', 1);
        $settingsService->set('fleet_speed_peaceful', 1);
        $settingsService->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * Annuler un combat actif rend la flotte, libere le corps, et n'applique rien.
     */
    public function testCancellingAnActiveCombatSendsTheFleetHomeAndAppliesNothing(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();

        $stock = $this->stockOf($cible);
        $defenses = (int)Planet::query()->whereKey($cible->getPlanetId())->value('rocket_launcher');
        $rapports = BattleReport::query()->count();

        $issue = $this->cancel($combat, CombatCancellationCause::InconsistentSnapshot);

        $this->assertTrue($issue->cancelled, 'The cancellation did nothing: ' . $issue->reason);
        $this->assertSame(1, $issue->fleetsSentHome);

        $combat->refresh();
        $this->assertSame(CombatState::Cancelled, $combat->status);
        $this->assertSame(CombatCancellationCause::InconsistentSnapshot, $combat->cancellation_cause, 'The cause was not persisted.');
        $this->assertNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body is still held by a cancelled combat.');

        // Rien n'est applique.
        $this->assertSame($stock, $this->stockOf($cible), 'A cancelled combat looted the target.');
        $this->assertSame($defenses, (int)Planet::query()->whereKey($cible->getPlanetId())->value('rocket_launcher'), 'A cancelled combat destroyed defences.');
        $this->assertSame($rapports, BattleReport::query()->count(), 'A cancelled combat wrote a report.');

        // La flotte rentre, une fois, avec ce qu'elle portait et toutes ses unites.
        $mission->refresh();
        $this->assertSame(1, (int)$mission->processed);
        $retours = FleetMission::query()->where('parent_id', $mission->id)->get();
        $this->assertCount(1, $retours, 'The fleet did not come home exactly once.');
        $retour = $retours->first();

        if ($retour === null) {
            $this->fail('The fleet has no return.');
        }

        $this->assertSame(350, (int)$retour->light_fighter, 'The fleet lost ships in a battle that was never applied.');
        $this->assertSame(0, (int)$retour->metal + (int)$retour->crystal, 'The fleet brought back loot from a cancelled combat.');

        // Et le joueur apprend pourquoi.
        $avis = CombatOutboxMessage::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($mission->id))
            ->first();
        $this->assertNotNull($avis, 'The fleet went home without being told why.');
        $this->assertSame(CombatOutboxKind::CombatCancelled->value, $avis->kind);
        $this->assertSame(CombatCancellationCause::InconsistentSnapshot->value, $avis->payload['cause'] ?? null);
    }

    /**
     * Annuler deux fois ne rend pas la flotte deux fois.
     */
    public function testCancellingTwiceDoesNotSendTheFleetHomeTwice(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        $this->assertTrue($this->cancel($combat, CombatCancellationCause::AdministrativeDecision)->cancelled);

        $seconde = $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);

        $this->assertFalse($seconde->cancelled);
        $this->assertSame(CombatCancellationOutcome::REASON_ALREADY_OVER, $seconde->reason);
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'A second cancellation created a second return.');
    }

    /**
     * Un combat regle ne se defait plus : ce qui a ete ecrit l'a ete.
     */
    public function testASettledCombatCannotBeCancelled(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();

        $this->advanceAt(new PersistentCombatAdvancer(), (int)$combat->ends_at);
        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'The combat did not settle: nothing would be irreversible.');

        $stock = $this->stockOf($cible);
        $retours = FleetMission::query()->where('parent_id', $mission->id)->count();

        $issue = $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);

        $this->assertFalse($issue->cancelled);
        $this->assertSame(CombatCancellationOutcome::REASON_ALREADY_OVER, $issue->reason);

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'A settled combat was erased.');
        $this->assertSame($stock, $this->stockOf($cible));
        $this->assertSame($retours, FleetMission::query()->where('parent_id', $mission->id)->count());
    }

    /**
     * Une fois annule, le corps peut etre attaque de nouveau.
     */
    public function testAfterACancellationTheBodyCanHostANewCombat(): void
    {
        [$combat, , $cible] = $this->anActiveCombat();
        $this->assertTrue($this->cancel($combat, CombatCancellationCause::AdministrativeDecision)->cancelled);

        $seconde = $this->aSecondAttackAgainst($cible);
        $nouveau = (new CombatOpeningService())->openOrJoin($seconde, $cible->getPlanetId(), (int)$seconde->time_arrival);

        $this->assertNotSame($combat->id, $nouveau->id, 'The new attack joined the cancelled combat instead of opening its own.');
        $this->assertSame(CombatState::Active, $nouveau->status);
    }

    /**
     * Un combat mis de cote sort de la quarantaine par l'annulation, et par la reprise.
     */
    public function testAQuarantinedCombatLeavesTheQuarantineByCancellationOrResumption(): void
    {
        [$combat] = $this->anActiveCombat();
        $avanceur = new PersistentCombatAdvancer();

        DB::table('combat_instances')->where('id', $combat->id)->update(['battle_result' => '{"schema":99}']);
        for ($essai = 1; $essai <= PersistentCombatAdvancer::MAX_ATTEMPTS; $essai++) {
            $this->advanceAt($avanceur, (int)$combat->ends_at);
        }
        $this->assertSame(1, $this->advanceAt($avanceur, (int)$combat->ends_at)->quarantined, 'The combat was not set aside.');

        // La reprise remet le compteur a zero, et le passage suivant reessaie.
        $this->assertSame(Command::SUCCESS, Artisan::call('ogamex:combat:reprendre', ['combat' => $combat->id]));
        $combat->refresh();
        $this->assertSame(0, $combat->advance_attempts);
        $this->assertNull($combat->advance_last_error);
        $this->assertArrayHasKey($combat->id, $this->advanceAt($avanceur, (int)$combat->ends_at)->failures, 'A resumed combat was not attempted again.');

        // L'annulation le sort de la quarantaine pour de bon.
        $this->assertSame(Command::SUCCESS, Artisan::call('ogamex:combat:annuler', ['combat' => $combat->id, '--cause' => 'inconsistent_snapshot']));
        $combat->refresh();
        $this->assertSame(CombatState::Cancelled, $combat->status);
        $this->assertSame(0, $this->advanceAt($avanceur, (int)$combat->ends_at)->quarantined, 'A cancelled combat is still counted as waiting for an operator.');
    }

    /**
     * La commande refuse une cause inconnue et un combat inconnu, bruyamment.
     */
    public function testTheCommandRefusesAnUnknownCauseAndAnUnknownCombat(): void
    {
        [$combat] = $this->anActiveCombat();

        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:annuler', ['combat' => $combat->id, '--cause' => 'parce_que']));
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'An unknown cause cancelled the combat anyway.');

        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:annuler', ['combat' => 999_999]));
        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:reprendre', ['combat' => 999_999]));
    }

    /**
     * Le retour d'une flotte annulee part de l'instant d'annulation, et dure le trajet aller.
     *
     * ## Le defaut que cet essai ferme
     *
     * `startReturn()` calcule le depart du retour depuis `time_arrival`. Le service marquait la
     * mission traitee sans remplacer cette heure : une flotte annulee deux heures apres son arrivee
     * repartait donc **de son arrivee initiale**, arrivait dans le passe, et son retour etait traite
     * aussitot. Le rappel ordinaire (`GameMission::cancel()`) ne s'y trompe pas ; l'annulation
     * empruntait la meme mecanique sans la meme precaution.
     */
    public function testTheReturnOfACancelledFleetLeavesAtTheCancellationInstant(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        $depart = (int)$mission->time_departure;
        $arriveeInitiale = (int)$mission->time_arrival;
        $trajet = $arriveeInitiale - $depart;
        $this->assertGreaterThan(0, $trajet, 'The outbound trip took no time: nothing would be compared.');

        // Deux heures de combat, puis une annulation.
        $annulation = $arriveeInitiale + 7_200;
        $issue = resolve(AttackMission::class)->cancelPersistentCombat($combat->id, CombatCancellationCause::AdministrativeDecision, $annulation);
        $this->assertTrue($issue->cancelled, 'The cancellation did nothing: ' . $issue->reason);

        $retour = FleetMission::query()->where('parent_id', $mission->id)->first();

        if ($retour === null) {
            $this->fail('The fleet has no return.');
        }

        $this->assertSame($annulation, (int)$retour->time_departure, 'The return leaves from the original arrival instead of the cancellation.');
        $this->assertSame($annulation + $trajet, (int)$retour->time_arrival, 'The return does not take the time the outbound trip took.');
        $this->assertSame(0, (int)$retour->processed, 'The return was already processed: it was created in the past.');
    }

    /**
     * Une inscrite deja traitee n'est pas rendue une seconde fois — et l'annulation le dit.
     *
     * Cet etat ne s'atteint pas par le jeu : l'arrivee ne traite pas, le rappel d'une engagee est
     * refuse, et le reglement ne traite qu'en rendant le combat final. Il n'existe que par corruption
     * ou reparation manuelle — precisement ce qu'une sortie d'exploitation rencontre. L'essai le
     * construit donc a la main, et le nomme.
     */
    public function testAParticipantThatAlreadyLeftIsNotSentHomeASecondTime(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        DB::table('fleet_missions')->where('id', $mission->id)->update(['processed' => 1]);

        $issue = $this->cancel($combat, CombatCancellationCause::InconsistentSnapshot);

        $this->assertTrue($issue->cancelled, 'A combat with a fleet already gone could not be cancelled: its body would stay locked.');
        $this->assertSame(0, $issue->fleetsSentHome, 'A fleet that had already left was sent home again: its ships now exist twice.');
        $this->assertSame(1, $issue->fleetsAlreadyGone, 'The cancellation did not say that a fleet had already left.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count(), 'A second return was created for a fleet already gone.');

        $combat->refresh();
        $this->assertSame(CombatState::Cancelled, $combat->status);
        $this->assertNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body is still held.');
    }

    /**
     * Un combat actif, ouvert par une vraie attaque — et deja engage, puisque personne n'est attendu.
     *
     * @return array{0: CombatInstance, 1: FleetMission, 2: PlanetService}
     */
    private function anActiveCombat(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 500_000,
            'crystal' => 300_000,
            'deuterium' => 100_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        $combat = (new CombatOpeningService())->openOrJoin($mission, $cible->getPlanetId(), (int)$mission->time_arrival);
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'A lone attacker did not close its own rally.');

        return [$combat, $mission, $cible];
    }

    private function aSecondAttackAgainst(PlanetService $cible): FleetMission
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);

        $this->sendMissionToOtherPlayerPlanet($unites, new Resources(0, 0, 0, 0));

        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->where('planet_id_to', $cible->getPlanetId())
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('The second attack was not sent against the same body.');
        }

        return $mission;
    }

    private function cancel(CombatInstance $combat, CombatCancellationCause $cause): CombatCancellationOutcome
    {
        return resolve(AttackMission::class)->cancelPersistentCombat($combat->id, $cause, (int)$combat->ends_at);
    }

    /**
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function stockOf(PlanetService $cible): array
    {
        $ligne = Planet::query()->whereKey($cible->getPlanetId())->first();

        if ($ligne === null) {
            $this->fail('The target planet vanished.');
        }

        return ['metal' => (int)$ligne->metal, 'crystal' => (int)$ligne->crystal, 'deuterium' => (int)$ligne->deuterium];
    }

    /**
     * Avance le passage a cet instant, horloge comprise.
     *
     * La frontiere du reglement lit **sa propre horloge** : l'heure donnee au passage ne sert qu'a
     * choisir les combats dus, et l'horloge doit dire la meme chose — comme en production, ou le
     * passage planifie prend l'une et l'autre au meme moment.
     */
    private function advanceAt(PersistentCombatAdvancer $avanceur, int $now): PersistentCombatAdvance
    {
        $this->travelTo(Date::createFromTimestamp($now));

        return $avanceur->advance($now);
    }
}
