<?php

namespace Tests\Feature\Combat;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Exceptions\IncoherentCombatEnrolment;
use OGame\Combat\Exceptions\ReturnDestinationMoved;
use OGame\Combat\Exceptions\UnreturnableFleet;
use OGame\Combat\Services\AccountCombatWithdrawal;
use OGame\Combat\Services\AccountWithdrawalPlan;
use OGame\Combat\Services\CombatCancellationOutcome;
use OGame\Combat\Services\CombatCancellationService;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\PersistentCombatAdvance;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Services\ReturnDestinationResolver;
use OGame\Combat\Services\ReturnPlanner;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\ReturnPlan;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use ReflectionClass;
use RuntimeException;
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
        $this->assertSame(Command::SUCCESS, Artisan::call('ogamex:combat:annuler', ['combat' => $combat->id, '--cause' => 'inconsistent_snapshot', '--note' => 'quarantaine sans issue apres cinq echecs']));
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

        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:annuler', ['combat' => $combat->id, '--cause' => 'parce_que', '--note' => 'n importe']));
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'An unknown cause cancelled the combat anyway.');

        // **Ni cause ni note par defaut.** Une cause implicite ferait annuler sans dire pourquoi ;
        // une note absente, sans dire ce qui a ete vu.
        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:annuler', ['combat' => $combat->id, '--note' => 'sans cause']));
        $this->assertSame(CombatState::Active, $combat->refresh()->status, 'A cancellation without a cause went through.');

        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:annuler', ['combat' => $combat->id, '--cause' => 'administrative_decision']));
        $this->assertSame(CombatState::Active, $combat->refresh()->status, 'A cancellation without a note went through.');

        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:annuler', ['combat' => $combat->id, '--cause' => 'administrative_decision', '--note' => '   ']));
        $this->assertSame(CombatState::Active, $combat->refresh()->status, 'A blank note counted as a note.');

        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:annuler', ['combat' => 999_999, '--cause' => 'administrative_decision', '--note' => 'inconnu']));
        $this->assertSame(Command::FAILURE, Artisan::call('ogamex:combat:reprendre', ['combat' => 999_999]));
    }

    /**
     * L'annulation laisse une trace complete : cause, note, instant, empreinte abandonnee, avis a la cible.
     *
     * Une annulation qu'on ne retrouve pas apres coup n'est pas une sortie d'exploitation, c'est une
     * disparition. La note est ce que l'administrateur a vu ; l'empreinte relie l'annulation aux
     * faits geles qu'elle ecarte ; la cible apprend que le corps est libre, et pourquoi.
     */
    public function testACancellationLeavesItsFullAuditTrail(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();
        $empreinte = $combat->frozen_facts_fingerprint;
        $this->assertNotNull($empreinte, 'The combat has no frozen facts fingerprint: nothing would be linked.');

        $instant = (int)$combat->ends_at;
        $issue = resolve(AttackMission::class)->cancelPersistentCombat($combat->id, CombatCancellationCause::InconsistentSnapshot, '  photographie sans effectif, vue au journal  ', $instant);
        $this->assertTrue($issue->cancelled);

        $combat->refresh();
        $this->assertSame(CombatCancellationCause::InconsistentSnapshot, $combat->cancellation_cause);
        $this->assertSame('photographie sans effectif, vue au journal', $combat->cancellation_note, 'The note was not persisted trimmed.');
        $this->assertSame($instant, (int)$combat->cancelled_at);

        foreach ([
            'la flotte' => CombatParticipantKey::forFleet($mission->id),
            'la cible' => CombatParticipantKey::forPlanet($cible->getPlanetId()),
        ] as $qui => $cle) {
            $avis = CombatOutboxMessage::query()
                ->where('combat_instance_id', $combat->id)
                ->where('participant_key', $cle)
                ->where('kind', CombatOutboxKind::CombatCancelled->value)
                ->first();

            $this->assertNotNull($avis, "No cancellation notice for {$qui}.");
            $charge = $avis->payload ?? [];
            $this->assertSame(CombatCancellationCause::InconsistentSnapshot->value, $charge['cause'] ?? null, "The notice for {$qui} does not carry the cause.");
            $this->assertSame('photographie sans effectif, vue au journal', $charge['note'] ?? null, "The notice for {$qui} does not carry the note.");
            $this->assertSame($instant, $charge['cancelled_at'] ?? null, "The notice for {$qui} does not carry the instant.");
            $this->assertSame($empreinte, $charge['abandoned_fingerprint'] ?? null, "The notice for {$qui} does not link the abandoned fingerprint.");
        }
    }

    /**
     * Une annulation sans note ne se fait pas.
     */
    public function testACancellationWithoutANoteIsRefused(): void
    {
        [$combat] = $this->anActiveCombat();

        try {
            resolve(AttackMission::class)->cancelPersistentCombat($combat->id, CombatCancellationCause::AdministrativeDecision, '   ', (int)$combat->ends_at);
            $this->fail('A cancellation without a note went through.');
        } catch (RuntimeException $refus) {
            $this->assertStringContainsString('note', $refus->getMessage());
        }

        $this->assertSame(CombatState::Active, $combat->refresh()->status);
    }

    /**
     * Les renforts defensifs inscrits rentrent aussi, chacun par son genre de mission, et sont avises.
     *
     * La bataille etait calculee avec eux : les laisser stationner sur un corps qui ne tient plus
     * de combat serait les oublier a moitie. Le retour d'une Defense ACS est celui de son genre —
     * un retour de type 5 —, et son avis porte la meme cause que ceux des attaquantes.
     */
    public function testEnrolledDefensiveReinforcementsAreSentHomeAndTold(): void
    {
        $renfort = null;
        [$combat, $mission] = $this->anActiveCombat(function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            // Pose sur le corps avant l'ouverture, pour longtemps : retenu a l'ouverture, admis a la
            // fermeture, inscrit dans la photographie.
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture - 10, 100_000, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue(
            $combat->participants()->where('fleet_mission_id', $renfort->id)->where('side', 'defender')->exists(),
            'The reinforcement was not enrolled as a defender: nothing would be proved.'
        );

        $instant = (int)$combat->ends_at;
        $issue = $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);

        $this->assertTrue($issue->cancelled);
        $this->assertSame(1, $issue->fleetsSentHome, 'The attacking fleet count is wrong.');
        $this->assertSame(1, $issue->defendersSentHome, 'The enrolled reinforcement was not counted as sent home.');

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'The enrolled reinforcement stayed on a body that no longer holds a combat.');
        $retour = FleetMission::query()->where('parent_id', $renfort->id)->first();
        $this->assertNotNull($retour, 'The enrolled reinforcement was not sent home.');
        $this->assertSame(5, (int)$retour->mission_type, 'The reinforcement return is not of its own kind.');
        $this->assertSame($instant, (int)$retour->time_departure);
        $this->assertSame((int)$renfort->planet_id_from, (int)$retour->planet_id_to);

        $avis = CombatOutboxMessage::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($renfort->id))
            ->where('kind', CombatOutboxKind::CombatCancelled->value)
            ->first();
        $this->assertNotNull($avis, 'The enrolled reinforcement was not told.');

        unset($mission);
    }

    /**
     * Une flotte disparue de l'effectif arrete l'annulation, quel que soit son camp.
     *
     * ## Ce que la cle etrangere ne disait pas
     *
     * L'inscription pointe vers une mission, et la base impose que ce pointeur soit valide. Elle
     * n'impose pas qu'il en reste un : une mission effacee laisse son inscription avec un lien vide.
     * La lecture ecartait ces lignes-la, donc un effectif ampute — une attaquante secondaire, un
     * renfort defensif — passait pour complet. Le combat etait annule, le corps libere, et la flotte
     * manquante n'etait rendue par personne, sans que rien ne le dise.
     *
     * L'initiatrice, elle, etait verifiee : c'est precisement ce qui rendait le trou invisible.
     */
    public function testAnEnrolmentThatLostItsFleetStopsTheCancellation(): void
    {
        foreach ([CombatParticipant::SIDE_ATTACKER, CombatParticipant::SIDE_DEFENDER] as $camp) {
            [$combat, $mission, $cible] = $this->anActiveCombat();

            $seconde = $this->aDefensiveReinforcement($cible, (int)$combat->started_at, 3_600, (int)$combat->started_at - 600);
            CombatParticipant::forceCreate([
                'combat_instance_id' => $combat->id,
                'participant_key' => CombatParticipantKey::forFleet($seconde->id),
                'player_id' => $seconde->user_id,
                'fleet_mission_id' => $seconde->id,
                'side' => $camp,
                'participant_type' => CombatParticipant::TYPE_ACS_DEFEND,
            ]);

            // Ce que la suppression d'un compte laisse derriere elle : l'inscription sans sa flotte.
            DB::table('combat_participants')
                ->where('combat_instance_id', $combat->id)
                ->where('fleet_mission_id', $seconde->id)
                ->update(['fleet_mission_id' => null]);

            try {
                $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
                $this->fail("A combat missing a {$camp} fleet from its roster was cancelled anyway.");
            } catch (IncoherentCombatEnrolment $refus) {
                $this->assertStringContainsString('n existe plus', $refus->getMessage());
            }

            $this->nothingWasWritten($combat, $mission);
        }
    }

    /**
     * Une flotte retenue par le combat sans y etre inscrite arrete l'annulation.
     *
     * Liberer le corps la laisserait posee dessus : plus de combat pour la reclamer, plus de
     * barriere pour la retenir, et aucun retour pour la ramener. L'inscription ne la nomme pas, donc
     * personne ne la rendrait.
     */
    public function testAFleetHeldWithoutBeingEnrolledStopsTheCancellation(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();

        $clandestine = $this->aDefensiveReinforcement($cible, (int)$combat->started_at, 3_600, (int)$combat->started_at - 600);
        DB::table('fleet_missions')->where('id', $clandestine->id)->update(['combat_instance_id' => $combat->id]);

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('A combat holding a fleet it never enrolled was cancelled anyway.');
        } catch (IncoherentCombatEnrolment $refus) {
            $this->assertStringContainsString((string)$clandestine->id, $refus->getMessage());
            $this->assertStringContainsString('sans figurer dans son effectif', $refus->getMessage());
        }

        $this->nothingWasWritten($combat, $mission);
        $this->assertSame(0, (int)$clandestine->refresh()->processed, 'The unenrolled fleet was sent home by a cancellation that stopped.');
    }

    /**
     * Une inscription qui ne decrit pas sa flotte arrete l'annulation, champ par champ.
     *
     * ## Ce qu'une cle etrangere ne verifie pas
     *
     * Elle lie l'inscription a une mission reelle. Elle ne verifie pas que ce que l'inscription
     * **affirme** de cette mission est vrai. Une Defense ACS inscrite une seule fois du cote
     * attaquant n'etait ni un doublon ni « deux camps » : elle passait, et l'annulation la rendait
     * du mauvais cote. De meme pour un proprietaire qui n'est pas le sien, une cle d'identite qui
     * nomme une autre flotte, ou un genre d'inscription que la fermeture n'aurait jamais ecrit.
     */
    public function testAnEnrolmentThatContradictsItsFleetStopsTheCancellation(): void
    {
        // **Le camp n'est pas dans cette boucle**, et c'est deliberé : sur l'initiatrice, c'est le
        // controle « l'initiatrice figure parmi les attaquants » qui rougit d'abord, et la mutation
        // serait tuee par un autre temoin que celui qu'on veut eprouver. Le camp a son propre essai,
        // sur un renfort — la ou seule cette comparaison peut parler.
        $permutations = [
            'player_id' => fn (FleetMission $mission): array => ['player_id' => (int)$mission->user_id + 1],
            'participant_key' => fn (FleetMission $mission): array => ['participant_key' => CombatParticipantKey::forFleet((int)$mission->id + 1)],
            'participant_type' => fn (FleetMission $mission): array => ['participant_type' => CombatParticipant::TYPE_ACS_DEFEND],
        ];

        foreach ($permutations as $champ => $fausser) {
            [$combat, $mission] = $this->anActiveCombat();

            DB::table('combat_participants')
                ->where('combat_instance_id', $combat->id)
                ->where('fleet_mission_id', $mission->id)
                ->update($fausser($mission));

            try {
                $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
                $this->fail("An enrolment whose {$champ} contradicts its fleet was cancelled anyway.");
            } catch (IncoherentCombatEnrolment $refus) {
                $this->assertStringContainsString('« ' . $champ . ' »', $refus->getMessage(), "The refusal did not name {$champ}.");
            }

            $this->nothingWasWritten($combat, $mission);
        }
    }

    /**
     * Une Defense ACS inscrite du cote attaquant arrete l'annulation.
     *
     * ## L'exemple exact que rien ne voyait
     *
     * Elle n'est inscrite qu'une fois : ce n'est donc ni un doublon, ni « deux camps ». Et elle
     * n'est pas l'initiatrice, donc le controle « l'initiatrice figure parmi les attaquants » ne
     * dit rien d'elle. Seule la confrontation de son inscription a sa mission voit que le camp est
     * faux — et sans elle, l'annulation aurait rendu un renfort defensif comme une attaquante.
     */
    public function testADefensiveReinforcementEnrolledOnTheAttackingSideStopsTheCancellation(): void
    {
        $renfort = null;
        [$combat, $mission] = $this->anActiveCombat(function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture - 10, 100_000, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $inscription = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $renfort->id)
            ->first();

        $this->assertNotNull($inscription, 'The reinforcement is not enrolled: nothing would be proved.');
        $this->assertSame(CombatParticipant::SIDE_DEFENDER, $inscription->side, 'The reinforcement was not enrolled as a defender.');
        $this->assertNotSame((int)$combat->mission_id, (int)$renfort->id, 'The reinforcement is the initiator: the initiator check would speak first.');

        DB::table('combat_participants')
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $renfort->id)
            ->update(['side' => CombatParticipant::SIDE_ATTACKER]);

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('A defensive reinforcement enrolled on the attacking side was cancelled anyway.');
        } catch (IncoherentCombatEnrolment $refus) {
            $this->assertStringContainsString('« side »', $refus->getMessage(), 'The refusal did not name the side.');
            $this->assertStringContainsString((string)$renfort->id, $refus->getMessage(), 'The refusal did not name the reinforcement.');
        }

        $this->nothingWasWritten($combat, $mission);
        $this->assertSame(0, (int)$renfort->refresh()->processed, 'The reinforcement was sent home by a cancellation that stopped.');
    }

    /**
     * Un genre de mission sans camp arrete l'annulation au lieu d'etre range chez les attaquants.
     *
     * ## Pourquoi « ne renforce pas la defense » ne veut pas dire « attaquant »
     *
     * Le camp se lisait d'une seule question, et tout ce qui repondait non y devenait attaquant :
     * transport, deploiement, espionnage, colonisation, recyclage, missile, expedition. Une donnee
     * incoherente qui aurait pose le lien sur l'une d'elles l'aurait fait rendre comme une flotte de
     * bataille. Les deux camps se nomment maintenant explicitement.
     */
    public function testAFleetKindWithNoSideStopsTheCancellation(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        // Le retour en ralliement : c'est la que le camp se lit du genre.
        $combat->participants()->delete();
        DB::table('combat_instances')->where('id', $combat->id)->update(['status' => CombatState::Rallying->value]);
        DB::table('fleet_missions')->where('id', $mission->id)->update(['combat_instance_id' => $combat->id, 'mission_type' => 3]);
        $combat->refresh();

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('A transport held by a combat was sent home as an attacking fleet.');
        } catch (IncoherentCombatEnrolment $refus) {
            $this->assertStringContainsString('transport', $refus->getMessage(), 'The refusal did not name the kind it could not place.');
            $this->assertStringContainsString('n a donc pas de camp', $refus->getMessage());
        }

        $combat->refresh();
        $this->assertSame(CombatState::Rallying, $combat->status, 'The combat was made final on a roster it cannot describe.');
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body was released.');
        $this->assertSame(0, (int)$mission->refresh()->processed);
    }

    /**
     * Une flotte inscrite dans les deux camps arrete l'annulation : un camp ne se devine pas.
     */
    public function testAFleetEnrolledOnBothSidesStopsTheCancellation(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        CombatParticipant::forceCreate([
            'combat_instance_id' => $combat->id,
            'participant_key' => CombatParticipantKey::forFleet($mission->id) . ':double',
            'player_id' => $mission->user_id,
            'fleet_mission_id' => $mission->id,
            'side' => CombatParticipant::SIDE_DEFENDER,
            'participant_type' => CombatParticipant::TYPE_ACS_DEFEND,
        ]);

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('A fleet enrolled on both sides was cancelled anyway.');
        } catch (IncoherentCombatEnrolment $refus) {
            $this->assertStringContainsString('inscrite plus d une fois', $refus->getMessage());
        }

        $this->nothingWasWritten($combat, $mission);
    }

    /**
     * Une inscrite dont la mission est retenue par un autre combat arrete l'annulation.
     *
     * Les deux liens se contredisent : celui-ci la dit sienne, l'autre la retient. La rendre ferait
     * disparaitre une flotte de la photographie d'une bataille qui, elle, sera bien appliquee.
     */
    public function testAnEnrolledFleetHeldByAnotherCombatStopsTheCancellation(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();

        $ailleurs = CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $mission->id,
            'target_planet_id' => $cible->getPlanetId(),
            'target_type' => 1,
            'galaxy' => $cible->getPlanetCoordinates()->galaxy,
            'system' => $cible->getPlanetCoordinates()->system,
            'position' => $cible->getPlanetCoordinates()->position,
            'started_at' => (int)$combat->started_at,
        ]);

        DB::table('fleet_missions')->where('id', $mission->id)->update(['combat_instance_id' => $ailleurs->id]);

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('A fleet held by another combat was sent home by this one.');
        } catch (IncoherentCombatEnrolment $refus) {
            $this->assertStringContainsString('se contredisent', $refus->getMessage());
        }

        $this->nothingWasWritten($combat, $mission);
    }

    /**
     * Un combat encore en ralliement s'annule : son effectif est ce qu'il retient.
     *
     * ## Le trou que la seule lecture des inscriptions ouvrait
     *
     * Personne n'est inscrit avant la fermeture — c'est elle qui prend la photographie. Exiger que
     * l'initiatrice y figure rendait donc **toute** annulation d'un combat en ralliement impossible :
     * la suppression d'un compte butait dessus, le corps restait tenu pour toujours, et la commande
     * d'exploitation rendait la meme erreur. Pendant le ralliement, le lien porte seul l'effectif, et
     * le camp se lit du genre de la mission.
     */
    public function testARallyingCombatIsCancelledOnTheFleetsItHolds(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        // Le retour en ralliement : la photographie n'a pas encore ete prise, et la flotte est
        // retenue par le lien que son arrivee pose — c'est tout l'etat d'un combat qui rallie.
        $combat->participants()->delete();
        DB::table('combat_instances')->where('id', $combat->id)->update(['status' => CombatState::Rallying->value]);
        DB::table('fleet_missions')->where('id', $mission->id)->update(['combat_instance_id' => $combat->id]);
        $combat->refresh();

        $this->assertSame(0, $combat->participants()->count(), 'The roster survived: the scenario proves nothing.');
        $this->assertSame($combat->id, (int)$mission->refresh()->combat_instance_id, 'The initiator is not held by the combat.');

        $issue = $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);

        $this->assertTrue($issue->cancelled, 'A rallying combat could not be cancelled: its body would be held forever.');
        $this->assertSame(1, $issue->fleetsSentHome, 'The held fleet was not counted as sent home.');
        $this->assertSame(CombatState::Cancelled, $combat->refresh()->status);
        $this->assertNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body is still held.');
        $this->assertSame(1, (int)$mission->refresh()->processed);
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'The held fleet did not come home.');
    }

    /**
     * Le journal de l'annulation s'ecrit apres le commit, jamais dedans.
     *
     * Une ligne posee dans la transaction survivrait a son annulation : l'exploitation lirait une
     * annulation reussie et chercherait un combat qui, lui, tourne toujours.
     */
    public function testTheCancellationLineIsWrittenOnlyAfterTheCommit(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        $niveaux = [];
        Log::partialMock()
            ->shouldReceive('warning')
            ->andReturnUsing(function (mixed $message, mixed $contexte) use (&$niveaux): void {
                if ($message === 'Combat durable annule.') {
                    $niveaux[] = DB::transactionLevel();
                }
            });

        $this->assertTrue($this->cancel($combat, CombatCancellationCause::AdministrativeDecision)->cancelled);

        $this->assertSame([0], $niveaux, 'The cancellation line was written inside the transaction that could still roll back.');
        unset($mission);
    }

    /**
     * Rien n'a ete ecrit : le combat tient toujours son corps, et sa flotte n'a pas bouge.
     */
    private function nothingWasWritten(CombatInstance $combat, FleetMission $mission): void
    {
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The combat was made final on an unverifiable roster.');
        $this->assertNull($combat->cancellation_cause);
        $this->assertNull($combat->cancelled_at);
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body was released.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
        $this->assertSame(0, (int)$mission->refresh()->processed);
    }

    /**
     * Une initiatrice qui n'est pas inscrite parmi les attaquants arrete l'annulation avant tout.
     *
     * L'effectif se verifie avant de changer d'etat : liberer un corps en pretendant avoir rendu un
     * effectif qu'on ne sait pas decrire serait la perte silencieuse que ce chemin existe pour eviter.
     */
    public function testAnInitiatorMissingFromTheRosterStopsTheCancellationBeforeAnythingIsWritten(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();

        $this->assertSame(1, $combat->participants()->where('fleet_mission_id', $mission->id)->delete(), 'The initiator was not enrolled: nothing would be proved.');

        // **Elle quitte les deux liens**, sinon c'est la flotte retenue sans inscription qui
        // parle la premiere — un autre ecart, et l'essai ne prouverait pas celui-ci.
        DB::table('fleet_missions')->where('id', $mission->id)->update(['combat_instance_id' => null]);

        // Un second inscrit reste : un effectif entierement vide est un autre ecart encore.
        $second = $this->aDefensiveReinforcement($cible, (int)$combat->started_at, 3_600, (int)$combat->started_at - 600);
        DB::table('fleet_missions')->where('id', $second->id)->update(['combat_instance_id' => $combat->id]);
        CombatParticipant::forceCreate([
            'combat_instance_id' => $combat->id,
            'participant_key' => CombatParticipantKey::forFleet($second->id),
            'player_id' => $second->user_id,
            'fleet_mission_id' => $second->id,
            'side' => CombatParticipant::SIDE_DEFENDER,
            'participant_type' => CombatParticipant::TYPE_ACS_DEFEND,
        ]);

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('The cancellation went through on a roster that does not describe the combat.');
        } catch (RuntimeException $refus) {
            $this->assertStringContainsString('initiatrice', $refus->getMessage());
        }

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The combat was made final on an unverifiable roster.');
        $this->assertNull($combat->cancellation_cause);
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body was released.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
        $this->assertSame(0, (int)$mission->refresh()->processed);
    }

    /**
     * Supprimer le compte de l'attaquant annule son combat, et l'inscription survit sans son lien.
     *
     * ## Le cycle de vie d'une inscription
     *
     * Une mission inscrite dans un combat actif ne s'efface pas : la suppression annule d'abord le
     * combat, avec la cause faite pour cela. Ensuite seulement la mission disparait — et
     * l'inscription reste, cle de participant, proprietaire et instantane intacts, son lien vers la
     * mission devenu nul. Un identifiant de mission reutilise ne peut plus devenir ce participant.
     */
    public function testDeletingTheAttackerAccountCancelsItsCombatAndKeepsTheEnrolmentWithoutItsLink(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        $inscription = CombatParticipant::query()->where('combat_instance_id', $combat->id)->where('fleet_mission_id', $mission->id)->first();
        $this->assertNotNull($inscription, 'The opener is not enrolled: nothing would be proved.');
        $instantane = $inscription->units_snapshot;
        $cle = $inscription->participant_key;

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->delete();

        $combat->refresh();
        $this->assertSame(CombatState::Cancelled, $combat->status, 'The combat of a deleted attacker is still running.');
        $this->assertSame(CombatCancellationCause::AttackerRemoved, $combat->cancellation_cause);
        $this->assertStringContainsString('suppression du compte ' . $this->currentUserId, (string)$combat->cancellation_note);

        $this->assertNull(FleetMission::query()->find($mission->id), 'The deleted account still owns its mission.');

        $inscription->refresh();
        $this->assertNull($inscription->fleet_mission_id, 'The enrolment still points at a mission that no longer exists.');
        $this->assertSame($cle, $inscription->participant_key, 'The enrolment lost its identity.');
        $this->assertSame($instantane, $inscription->units_snapshot, 'The enrolment lost its snapshot.');

        // Un identifiant reutilise ne ressuscite pas l'inscription.
        $neuve = FleetMission::forceCreate([
            'user_id' => (int)DB::table('users')->orderBy('id')->value('id'),
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 1,
        ]);
        $this->assertSame(0, CombatParticipant::query()->where('fleet_mission_id', $neuve->id)->count(), 'A brand-new mission inherited a former enrolment.');
    }

    /**
     * Supprimer un compte dont le combat rallie encore l'annule aussi.
     *
     * ## Le corps que personne n'aurait libere
     *
     * Avant la fermeture, personne n'est inscrit : le retrait cherchait les combats par les
     * inscriptions et par la cible, donc un combat en ralliement ouvert par ce compte n'etait trouve
     * par aucune des deux. Ses missions etaient effacees — la colonne du lien n'a pas de cle
     * etrangere qui l'aurait empeche —, le combat gardait une initiatrice qui n'existait plus, et sa
     * barriere tenait le corps pour toujours.
     */
    public function testDeletingAnAccountWhoseCombatIsStillRallyingCancelsItToo(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        // Le retour en ralliement, tel qu'il est avant la fermeture : aucune inscription, et la
        // flotte retenue par le lien que son arrivee a pose.
        $combat->participants()->delete();
        DB::table('combat_instances')->where('id', $combat->id)->update(['status' => CombatState::Rallying->value]);
        DB::table('fleet_missions')->where('id', $mission->id)->update(['combat_instance_id' => $combat->id]);
        $combat->refresh();

        $this->assertSame(0, $combat->participants()->count(), 'The roster survived: the scenario proves nothing.');

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->delete();

        $combat->refresh();
        $this->assertSame(CombatState::Cancelled, $combat->status, 'A rallying combat outlived the account that opened it.');
        $this->assertSame(CombatCancellationCause::AttackerRemoved, $combat->cancellation_cause);
        $this->assertNull(
            CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(),
            'The barrier still holds a body for a combat whose opener no longer exists.'
        );
    }

    /**
     * L'audit d'un combat annule se relit sans aucun modele vivant.
     *
     * L'inscription garde sa cle, son camp et sa photographie ; l'instance garde la cause, la note,
     * l'instant et l'empreinte des faits abandonnes ; l'avis reste lisible. Rien de tout cela ne
     * demande la mission, qui n'existe plus.
     */
    public function testTheAuditOfACancelledCombatSurvivesTheDeletedAccount(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        $empreinte = (string)$combat->frozen_facts_fingerprint;
        $this->assertNotSame('', $empreinte, 'The combat has no fingerprint: nothing would be proved.');

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->delete();

        $combat->refresh();
        $this->assertSame($empreinte, (string)$combat->frozen_facts_fingerprint, 'The abandoned facts lost their fingerprint.');
        $this->assertNotNull($combat->cancelled_at, 'The cancellation lost its instant.');

        $avis = CombatOutboxMessage::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($mission->id))
            ->where('kind', CombatOutboxKind::CombatCancelled->value)
            ->first();

        $this->assertNotNull($avis, 'The notice of a deleted fleet was erased with its account.');
        $this->assertSame($empreinte, (string)($avis->payload['abandoned_fingerprint'] ?? ''), 'The notice lost the fingerprint it was meant to carry.');
    }

    /**
     * Un combat en cours d'application arrete la suppression du compte, et dit pourquoi.
     *
     * `Resolving` n'est pas final, donc la recherche le trouve — mais la machine d'etats refuse de
     * l'annuler, et elle a raison : des ecritures sont en train de partir. La suppression attend, et
     * la raison se lit sur le compte, en mots qui parlent de suppression : sans ce contrôle, elle
     * echouait sur un message d'etats de combat devant un administrateur venu effacer un compte.
     */
    public function testDeletingAnAccountWaitsForACombatThatIsBeingApplied(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        DB::table('combat_instances')->where('id', $combat->id)->update(['status' => CombatState::Resolving->value]);
        $combat->refresh();

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->delete();

        $attendant = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertTrue($attendant->isPendingDeletion(), 'The account is not marked as awaiting deletion.');
        $this->assertStringContainsString('en cours d application', $attendant->deletionDeferredReason());
        $this->assertStringContainsString('combat ' . $combat->id, $attendant->deletionDeferredReason());

        $this->assertSame(CombatState::Resolving, $combat->refresh()->status, 'The combat being applied was changed.');
        $this->assertNotNull(FleetMission::query()->find($mission->id), 'The mission of a combat being applied was erased.');
    }

    /**
     * Supprimer le compte de la cible annule le combat : le corps attaque va disparaitre.
     */
    public function testDeletingTheTargetOwnerCancelsTheCombatBecauseTheTargetDisappears(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();
        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');

        resolve(PlayerServiceFactory::class)->make($proprietaire, true)->delete();

        $combat->refresh();
        $this->assertSame(CombatState::Cancelled, $combat->status, 'The combat over a deleted target is still running.');
        $this->assertSame(CombatCancellationCause::TargetDisappeared, $combat->cancellation_cause);

        // **L'attaquant, lui, rentre** : sa flotte n'a rien a faire sur un corps qui n'existe plus —
        // et elle n'est pas effacee avec le compte de sa cible. La suppression effacait toute
        // mission qui allait vers ses planetes, quel qu'en fut le proprietaire ; la flotte d'un
        // autre joueur disparaissait avec le compte attaque. Elle garde ses coordonnees et perd
        // seulement le lien vers un corps qui n'est plus la.
        $mission->refresh();
        $this->assertSame(1, (int)$mission->processed, 'The attacking fleet was left on a body that no longer exists.');
        $this->assertNull($mission->planet_id_to, 'The attacking mission still points at a planet that no longer exists.');
        $retour = FleetMission::query()->where('parent_id', $mission->id)->first();
        $this->assertNotNull($retour, 'The attacking fleet is not coming home: its return was erased with the target account.');
        $this->assertNull($retour->planet_id_from, 'The return still points at a planet that no longer exists.');
        $this->assertSame(350, (int)$retour->light_fighter, 'The attacking fleet lost ships to a deleted target.');
    }

    /**
     * Supprimer le compte d'un renfort inscrit dans le combat d'un autre est refuse tant qu'il dure.
     *
     * Aucune cause ne dit « un allie est parti », et annuler la bataille d'un tiers parce qu'un allie
     * efface son compte serait une decision de jeu qui n'a pas ete prise. La suppression attend ; un
     * combat dure des heures, pas des jours.
     */
    public function testDeletingAnEnrolledDefenderIsRefusedWhileTheCombatLasts(): void
    {
        $renfort = null;
        [$combat] = $this->anActiveCombat(function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture - 10, 100_000, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue($combat->participants()->where('fleet_mission_id', $renfort->id)->exists(), 'The reinforcement is not enrolled: nothing would be proved.');

        $joueur = resolve(PlayerServiceFactory::class)->make((int)$renfort->user_id, true);
        $joueur->delete();

        // **La suppression attend, elle n'echoue pas.** Le compte reste, il ne lance plus rien, et
        // la raison de l'attente se lit sur lui — pas dans un journal, pas dans une exception.
        $this->assertSame(CombatState::Active, $combat->refresh()->status, 'A third party combat was cancelled because an ally left.');
        $this->assertNotNull(FleetMission::query()->find($renfort->id), 'The deferred deletion still erased the reinforcement.');
        $this->assertNotNull(DB::table('users')->where('id', $renfort->user_id)->first(), 'The deferred deletion still erased the account.');

        $attendant = resolve(PlayerServiceFactory::class)->make((int)$renfort->user_id, true);
        $this->assertTrue($attendant->isPendingDeletion(), 'The account is not marked as awaiting deletion.');
        $this->assertStringContainsString('combat ' . $combat->id, $attendant->deletionDeferredReason(), 'The wait does not say which combat holds it.');
        $this->assertStringContainsString('renforce la defense', $attendant->deletionDeferredReason(), 'The wait does not say why.');
    }

    /**
     * La suppression reprend d'elle-meme quand le combat qui la retenait devient final.
     *
     * Un combat dure des heures, pas des jours : l'attente est bornee par lui, et la commande de
     * reprise n'a rien a forcer — elle redemande le plan, et n'agit que si plus rien ne retient.
     */
    public function testAPendingDeletionResumesOnceTheHoldingCombatIsOver(): void
    {
        $renfort = null;
        [$combat] = $this->anActiveCombat(function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture - 10, 100_000, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $compte = (int)$renfort->user_id;
        resolve(PlayerServiceFactory::class)->make($compte, true)->delete();
        $this->assertNotNull(DB::table('users')->where('id', $compte)->first(), 'The deletion did not wait.');

        // Tant que le combat tient, la reprise ne fait rien.
        $this->assertSame(Command::SUCCESS, Artisan::call('ogamex:comptes:reprendre-suppressions', ['--compte' => $compte]));
        $this->assertNotNull(DB::table('users')->where('id', $compte)->first(), 'The resumption deleted an account a combat still holds.');

        // **Ce que la commande dit compte autant que ce qu'elle fait** : l'attente doit se voir. Un
        // administrateur qui la lance cherche pourquoi le compte est toujours la, et la reponse est
        // dans sa sortie, avec le combat qui retient.
        $dit = Artisan::output();
        $this->assertStringContainsString('attend toujours', $dit, 'The command did not report the wait.');
        $this->assertStringContainsString('combat ' . $combat->id, $dit, 'The command did not name the combat that holds the deletion.');
        $this->assertStringNotContainsString('a ete supprime', $dit, 'The command announced a deletion that did not happen.');

        // Le combat devient final ; plus rien ne retient.
        DB::table('combat_instances')->where('id', $combat->id)->update(['status' => CombatState::Resolved->value]);

        $this->assertSame(Command::SUCCESS, Artisan::call('ogamex:comptes:reprendre-suppressions', ['--compte' => $compte]));
        $this->assertNull(DB::table('users')->where('id', $compte)->first(), 'The deletion did not resume once the combat was over.');

        $dit = Artisan::output();
        $this->assertStringContainsString('a ete supprime', $dit, 'The command did not report the deletion it performed.');
        $this->assertStringNotContainsString('attend toujours', $dit, 'The command still reported a wait that was over.');
    }

    /**
     * Un empechement sur le second combat n'annule pas le premier.
     *
     * ## Ce que l'annulation combat par combat perdait
     *
     * Le retrait annulait au fur et a mesure et ne decouvrait qu'en arrivant a sa ligne qu'un
     * combat retenait la suppression. Un compte engage dans deux combats — le premier annulable,
     * le second renforce — perdait donc le premier **et** gardait le compte : une bataille avait
     * disparu pour rien, et personne ne pouvait la rendre. Le plan couvre maintenant tout avant le
     * premier effet.
     */
    public function testABlockingSecondCombatLeavesTheFirstOneUntouched(): void
    {
        $renfort = null;
        [$retenu] = $this->anActiveCombat(function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture - 10, 100_000, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $compte = (int)$renfort->user_id;

        // Le meme compte ouvre par ailleurs un combat bien a lui, annulable, et d'identifiant
        // inferieur : sans le plan, c'est celui-la que le retrait aurait perdu en premier.
        $sien = CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $renfort->id,
            'target_planet_id' => (int)DB::table('planets')->where('user_id', '!=', $compte)->orderBy('id')->value('id'),
            'target_type' => 1,
            'galaxy' => 1,
            'system' => 1,
            'position' => 1,
            'started_at' => (int)$retenu->started_at,
        ]);

        $aLui = $this->anAttackingFleetOf($compte, (int)$sien->target_planet_id);
        DB::table('fleet_missions')->where('id', $aLui->id)->update(['combat_instance_id' => $sien->id]);
        DB::table('combat_instances')->where('id', $sien->id)->update(['mission_id' => $aLui->id]);

        resolve(PlayerServiceFactory::class)->make($compte, true)->delete();

        $this->assertSame(CombatState::Rallying, $sien->refresh()->status, 'A cancellable combat was lost although the deletion was refused.');
        $this->assertSame(CombatState::Active, $retenu->refresh()->status, 'The third party combat was cancelled.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $aLui->id)->count(), 'A fleet was sent home by a withdrawal that had to stop.');
        $this->assertNotNull(DB::table('users')->where('id', $compte)->first(), 'The account was erased.');
    }

    /**
     * Un compte en suppression en attente ne lance plus de flotte.
     *
     * C'est ce refus qui ferme la course : sans lui, une flotte partie apres l'inventaire des
     * combats du compte pourrait ouvrir une bataille que personne n'annulerait, et sa barriere
     * tiendrait un corps pour toujours.
     */
    public function testAnAccountAwaitingDeletionCannotLaunchAFleet(): void
    {
        $renfort = null;
        $this->anActiveCombat(function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture - 10, 100_000, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $compte = (int)$renfort->user_id;
        resolve(PlayerServiceFactory::class)->make($compte, true)->delete();

        $origine = resolve(PlanetServiceFactory::class)->make((int)$renfort->planet_id_from, true);
        $this->assertNotNull($origine, 'The reinforcement has no origin body: nothing would be proved.');

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);

        $this->expectExceptionMessage('en cours de suppression');
        resolve(FleetMissionService::class)->createNewFromPlanet(
            $origine,
            new Coordinate(1, 1, 1),
            PlanetType::Planet,
            3,
            $unites,
            new Resources(0, 0, 0, 0),
            10
        );
    }

    /**
     * Une mission du compte dont les deux liens de corps sont deja nuls disparait avec lui.
     *
     * Les missions etaient effacees corps par corps : une mission detachee — par la disparition
     * d'un autre corps, ou par une lune rasee — survivait a son proprietaire, et restait dans la
     * table en nommant un joueur qui n'existe plus.
     */
    public function testAMissionWithNoBodyLinksIsErasedWithItsOwner(): void
    {
        [, $mission] = $this->anActiveCombat();
        $compte = (int)$mission->user_id;

        $orpheline = $this->anAttackingFleetOf($compte, (int)$mission->planet_id_to);
        DB::table('fleet_missions')->where('id', $orpheline->id)->update(['planet_id_from' => null, 'planet_id_to' => null]);

        resolve(PlayerServiceFactory::class)->make($compte, true)->delete();

        $this->assertNull(DB::table('users')->where('id', $compte)->first(), 'The account was not deleted.');
        $this->assertNull(FleetMission::query()->find($orpheline->id), 'A mission with no body links outlived its owner.');
    }

    /**
     * Un plan qui retient quelque chose n'annule rien, meme si on l'applique.
     *
     * ## Pourquoi ce garde vit dans le service, et pas seulement chez son appelant
     *
     * `apply()` est publique. Un appelant qui lirait un plan, verrait sa liste de causes et
     * l'appliquerait sans regarder ses empechements annulerait exactement ce que le plan existe pour
     * retenir — une bataille perdue pour rien, et un compte toujours la. La suppression, elle,
     * s'arrete avant d'appeler ; c'est pourquoi seule une application directe rend ce garde
     * observable, et pourquoi il fallait cet essai plutot que de le supprimer.
     */
    public function testAPlanThatHoldsSomethingCancelsNothingEvenWhenApplied(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        // Un plan mixte : ce combat serait annulable, un autre retient tout.
        $plan = new AccountWithdrawalPlan(
            [(int)$combat->id => CombatCancellationCause::AttackerRemoved],
            [(int)$combat->id + 10_000 => 'le compte y renforce la defense d un autre joueur']
        );

        $this->assertTrue($plan->deferred(), 'The plan does not hold anything: nothing would be proved.');

        $annules = resolve(AccountCombatWithdrawal::class)->apply($this->currentUserId, $plan, (int)$combat->ends_at);

        $this->assertSame(0, $annules, 'A plan that holds something still cancelled a combat.');
        $this->assertSame(CombatState::Active, $combat->refresh()->status, 'The combat was cancelled by a plan that had to stop.');
        $this->assertNull($combat->cancellation_cause);
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body was released.');
        $this->assertSame(0, (int)$mission->refresh()->processed, 'The fleet was sent home by a plan that had to stop.');
    }

    /**
     * Une flotte attaquante brute appartenant a ce compte, sans passer par le lancement.
     */
    private function anAttackingFleetOf(int $userId, int $corps): FleetMission
    {
        $origine = Planet::query()->where('user_id', $userId)->orderBy('id')->first();

        if ($origine === null) {
            $this->fail('The account owns no planet.');
        }

        return FleetMission::forceCreate([
            'user_id' => $userId,
            'planet_id_from' => $origine->id,
            'type_from' => 1,
            'galaxy_from' => $origine->galaxy,
            'system_from' => $origine->system,
            'position_from' => $origine->planet,
            'planet_id_to' => $corps,
            'type_to' => 1,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
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

        // Deux heures de combat, puis une annulation. **L'horloge y est aussi** : sans cela, le
        // controle d'echeance interne de la creation du retour ne verrait pas la situation de
        // production, et un depart passe pourrait s'y glisser sans que rien ne le dise.
        $annulation = $arriveeInitiale + 7_200;
        $this->travelTo(Date::createFromTimestamp($annulation));
        $issue = resolve(AttackMission::class)->cancelPersistentCombat($combat->id, CombatCancellationCause::AdministrativeDecision, 'essai', $annulation);
        $this->assertTrue($issue->cancelled, 'The cancellation did nothing: ' . $issue->reason);

        $retour = FleetMission::query()->where('parent_id', $mission->id)->first();

        if ($retour === null) {
            $this->fail('The fleet has no return.');
        }

        $this->assertSame($annulation, (int)$retour->time_departure, 'The return leaves from the original arrival instead of the cancellation.');
        $this->assertSame($annulation + $trajet, (int)$retour->time_arrival, 'The return does not take the time the outbound trip took.');
        $this->assertSame(0, (int)$retour->processed, 'The return was already processed: it was created in the past.');
        $this->assertGreaterThan((int)Date::now()->timestamp, (int)$retour->time_arrival, 'The return is already due at the very instant it was created.');

        // **L aller garde son histoire.** Son heure d arrivee est un fait de l admission, de l ordre
        // causal et de l audit : une sortie d exploitation ne la reecrit pas pour piloter un retour.
        $mission->refresh();
        $this->assertSame($arriveeInitiale, (int)$mission->time_arrival, 'The cancellation rewrote the outbound arrival to steer the return.');
        $this->assertSame($depart, (int)$mission->time_departure);
    }

    /**
     * Une flotte dont le trajet aller ne se lit pas n est pas rendue, et le corps reste tenu.
     *
     * Un trajet nul ferait naitre un retour deja arrive, traite dans la transaction meme qui l a
     * cree : la flotte se poserait sur un corps que personne n a verrouille. L annulation s arrete
     * avant de rendre le combat final.
     */
    public function testAFleetWhoseOutboundTripCannotBeReadIsNotSentHome(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        DB::table('fleet_missions')->where('id', $mission->id)->update(['time_departure' => $mission->time_arrival]);

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('A fleet with an unreadable outbound trip was sent home anyway.');
        } catch (UnreturnableFleet $refus) {
            $this->assertSame($mission->id, $refus->fleetMissionId);
        }

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The combat was made final though a fleet could not be returned.');
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body was released though a fleet is still there.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
        $this->assertSame(0, (int)$mission->refresh()->processed, 'The fleet was marked processed without a return.');
    }

    /**
     * Une panne au deuxieme retour ramene tout en arriere.
     *
     * ## Ce que cet essai protege
     *
     * L'annulation rend plusieurs flottes dans une seule transaction. Si la creation du deuxieme
     * retour echoue — un corps disparu entre-temps, une contrainte violee —, le premier retour ne
     * doit pas subsister seul : il y aurait alors une flotte rentree, une flotte perdue, un combat
     * a moitie annule et un corps libere pour rien.
     *
     * La panne est injectee dans la fermeture que la mission prete, exactement la ou une vraie
     * defaillance se produirait.
     */
    public function testAFailureOnTheSecondReturnRollsEverythingBack(): void
    {
        [$combat, $missions] = $this->anActiveCombatWithTwoFleets();

        $avant = [
            'etat' => $combat->status,
            'barriere' => CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->count(),
            'traitees' => FleetMission::query()->whereIn('id', array_map(static fn (FleetMission $m): int => $m->id, $missions))->where('processed', 1)->count(),
            'retours' => FleetMission::query()->whereIn('parent_id', array_map(static fn (FleetMission $m): int => $m->id, $missions))->count(),
            'avis' => CombatOutboxMessage::query()->where('combat_instance_id', $combat->id)->count(),
        ];

        $appels = 0;
        $premierRetour = null;

        try {
            (new CombatCancellationService())->cancel(
                $combat->id,
                CombatCancellationCause::AdministrativeDecision,
                'essai',
                function (FleetMission $retourDe, int $duree, int $departA, ResolvedReturnDestination $ou) use (&$appels, &$premierRetour): void {
                    $appels++;

                    if ($appels === 2) {
                        // **La ligne du premier retour existe a cet instant precis.** Sans cette
                        // verification, l'assertion finale « aucun retour » partirait de zero et y
                        // resterait, avec ou sans transaction.
                        $this->assertNotNull($premierRetour, 'The first return was never created.');
                        $this->assertSame(1, FleetMission::query()->whereKey($premierRetour)->count(), 'The first return does not exist when the failure strikes.');

                        throw new RuntimeException('La creation du deuxieme retour echoue.');
                    }

                    // Un vrai retour, avec ses contraintes : c'est lui qui doit disparaitre.
                    $retour = FleetMission::forceCreate([
                        'parent_id' => $retourDe->id,
                        'user_id' => $retourDe->user_id,
                        'planet_id_from' => $retourDe->planet_id_to,
                        'type_from' => $retourDe->type_to,
                        'galaxy_from' => $retourDe->galaxy_to,
                        'system_from' => $retourDe->system_to,
                        'position_from' => $retourDe->position_to,
                        'planet_id_to' => $ou->bodyId,
                        'type_to' => $ou->type->value,
                        'galaxy_to' => $ou->coordinate->galaxy,
                        'system_to' => $ou->coordinate->system,
                        'position_to' => $ou->coordinate->position,
                        'mission_type' => $retourDe->mission_type,
                        'time_departure' => $departA,
                        'time_arrival' => $departA + $duree,
                        'light_fighter' => (int)$retourDe->light_fighter,
                        'metal' => (int)$retourDe->metal,
                        'crystal' => (int)$retourDe->crystal,
                        'deuterium' => (int)$retourDe->deuterium,
                    ]);

                    $premierRetour = $retour->id;
                },
                (int)$combat->ends_at
            );
            $this->fail('The cancellation swallowed a failure while returning fleets.');
        } catch (RuntimeException $panne) {
            $this->assertStringContainsString('deuxieme retour', $panne->getMessage());
        }

        $this->assertSame(2, $appels, 'The cancellation did not reach the second return: the test would prove nothing.');
        $this->assertNotNull($premierRetour, 'No first return was ever created.');
        $this->assertSame(0, FleetMission::query()->whereKey($premierRetour)->count(), 'The first return survived the rollback.');

        $combat->refresh();
        $this->assertSame($avant['etat'], $combat->status, 'The combat stayed cancelled though the returns were rolled back.');
        $this->assertNull($combat->cancellation_cause, 'The cause survived a rolled back cancellation.');
        $this->assertSame($avant['barriere'], CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->count(), 'The barrier was lifted by a cancellation that failed.');

        $identifiants = array_map(static fn (FleetMission $m): int => $m->id, $missions);
        $this->assertSame($avant['traitees'], FleetMission::query()->whereIn('id', $identifiants)->where('processed', 1)->count(), 'A fleet stayed marked processed after the rollback.');
        $this->assertSame($avant['retours'], FleetMission::query()->whereIn('parent_id', $identifiants)->count(), 'A return survived the rollback.');
        $this->assertSame($avant['avis'], CombatOutboxMessage::query()->where('combat_instance_id', $combat->id)->count(), 'A notice survived the rollback.');
    }

    /**
     * Une flotte sans destination arrete l'annulation, et le corps reste tenu.
     *
     * Les recours sont ordonnes — corps d'origine, planete associee, planete mere — et les epuiser
     * tous signifie que le proprietaire n'a plus aucun corps ou poser sa flotte. Liberer le corps
     * attaque en pretendant que toutes les flottes sont rendues serait la perte silencieuse que ce
     * chemin existe pour eviter.
     */
    public function testAFleetWithNowhereToReturnStopsTheCancellation(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        // L attaquant n a plus aucun corps : tous les recours sont epuises.
        DB::table('planets')->where('user_id', $mission->user_id)->update(['destroyed' => 1]);

        try {
            $this->cancel($combat, CombatCancellationCause::AdministrativeDecision);
            $this->fail('A fleet with nowhere to return was sent home anyway.');
        } catch (FleetHasNowhereToReturn $refus) {
            $this->assertSame($mission->id, $refus->fleetMissionId);
        }

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The combat was made final though a fleet had nowhere to go.');
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body was released while assets are still in the air.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
        $this->assertSame(0, (int)$mission->refresh()->processed);
    }

    /**
     * Le retour se pose la ou le plan le dit, pas sur un corps d'origine qui a disparu.
     */
    public function testTheReturnLandsWhereThePlanSaysWhenTheOriginIsGone(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        $origine = (int)$mission->planet_id_from;
        DB::table('planets')->where('id', $origine)->update(['destroyed' => 1]);

        $mere = DB::table('planets')
            ->where('user_id', $mission->user_id)
            ->where('planet_type', 1)
            ->where('destroyed', 0)
            ->orderBy('id')
            ->first();
        $this->assertNotNull($mere, 'The attacker has no other planet: the test would prove nothing.');

        $this->assertTrue($this->cancel($combat, CombatCancellationCause::AdministrativeDecision)->cancelled);

        $retour = FleetMission::query()->where('parent_id', $mission->id)->first();

        if ($retour === null) {
            $this->fail('The fleet has no return.');
        }

        $this->assertSame((int)$mere->id, (int)$retour->planet_id_to, 'The return heads for a body that no longer exists.');
        $this->assertSame((int)$mere->galaxy, (int)$retour->galaxy_to);
        $this->assertSame((int)$mere->system, (int)$retour->system_to);
        $this->assertSame((int)$mere->planet, (int)$retour->position_to);
    }

    /**
     * Une destination qui bouge entre le choix et le verrou arrete l'annulation.
     *
     * ## La course, et pourquoi elle se joue par un double
     *
     * Choisir une destination demande de lire des corps qu'on ne tient pas encore : c'est ce qui dit
     * quelles lignes verrouiller. Une fois tenues, la decision est reprise. Entre les deux, une
     * transaction concurrente peut transferer ou raser le corps choisi.
     *
     * SQLite n'a pas de transactions concurrentes : le double rend un plan different au second
     * appel, ce qui est exactement ce que la course produirait. La preuve reelle est MariaDB ; ici
     * on prouve que le service **compare** les deux passes et refuse quand elles divergent.
     */
    public function testADestinationThatMovesBetweenChoiceAndLockStopsTheCancellation(): void
    {
        [$combat, $mission, $cible] = $this->anActiveCombat();

        $ailleurs = DB::table('planets')
            ->where('user_id', $mission->user_id)
            ->where('id', '!=', $mission->planet_id_from)
            ->where('planet_type', 1)
            ->orderBy('id')
            ->first();
        $this->assertNotNull($ailleurs, 'The attacker owns a single body: the plan could not change.');

        $planificateur = new class ((int)$ailleurs->id) extends ReturnPlanner {
            private int $appels = 0;

            public function __construct(private int $ailleurs)
            {
            }

            public function planFor(FleetMission $mission): ReturnPlan
            {
                $this->appels++;
                $plan = parent::planFor($mission);

                // Au second appel — celui pris sous verrou —, le corps a bouge.
                if ($this->appels === 1) {
                    return $plan;
                }

                return ReturnPlan::toHomeworld($this->ailleurs, new Coordinate(9, 9, 9), (int)$mission->user_id);
            }
        };

        $avant = $this->stockOf($cible);

        try {
            (new CombatCancellationService(destinations: new ReturnDestinationResolver($planificateur)))->cancel(
                $combat->id,
                CombatCancellationCause::AdministrativeDecision,
                'essai',
                function (): void {
                    $this->fail('A return was created though the destination had moved.');
                },
                (int)$combat->ends_at
            );
            $this->fail('The cancellation wrote a destination it had never locked.');
        } catch (ReturnDestinationMoved $refus) {
            $this->assertSame($mission->id, $refus->fleetMissionId);
        }

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The combat was made final on a destination that had moved.');
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(), 'The body was released.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
        $this->assertSame(0, (int)$mission->refresh()->processed);
        $this->assertSame($avant, $this->stockOf($cible));
    }

    /**
     * L'annulation declare ses verrous, et les corps de destination viennent apres les missions.
     *
     * ## Pourquoi une garde de source, et non une observation
     *
     * SQLite ignore `lockForUpdate()` : la requete sort identique a une lecture ordinaire, et aucun
     * essai ne peut distinguer ici un verrou pris d'un verrou oublie. Retirer le verrou ne fait donc
     * tomber aucune assertion d'execution — c'est cette garde qui le voit.
     *
     * Elle ne prouve pas que le verrou tient : cela demande deux connexions MariaDB, et cette epreuve
     * reste a faire. Elle prouve qu'un futur passage ne l'enlevera pas en silence, et que l'ordre
     * annonce est celui du code.
     */
    public function testTheCancellationDeclaresItsLocksInTheFixedOrder(): void
    {
        $fichier = (new ReflectionClass(CombatCancellationService::class))->getFileName();
        $this->assertNotFalse($fichier);

        $source = preg_replace('/\s+/', ' ', (string)file_get_contents($fichier));
        $this->assertNotNull($source);

        $verrous = [
            'barriere' => "CelestialBodyCombatBarrier::query() ->where('combat_instance_id', \$combatInstanceId) ->lockForUpdate()",
            'instance' => 'CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()',
            'union' => 'FleetUnion::query()->whereKey($combat->union_id)->lockForUpdate()',
            'missions' => "->orderBy('id') ->lockForUpdate()",
            // Les corps ou les flottes vont se poser : sans eux, une destination peut changer entre
            // le choix et l'ecriture. Le verrou lui-meme vit dans le resolveur — un seul protocole
            // de destination pour tout le systeme —, et l'annulation le demande par ce nom.
            'destinations' => '$this->destinations->holdTheDecidingBodies($corpsDeRetour);',
        ];

        foreach ($verrous as $quoi => $declaration) {
            $this->assertStringContainsString($declaration, $source, "The cancellation no longer declares the lock on the {$quoi}.");
        }

        // **Le verrou que ce nom recouvre.** Deleguer ne doit pas faire disparaitre la declaration :
        // la garde suit le code jusqu'ou il est.
        $resolveur = (new ReflectionClass(ReturnDestinationResolver::class))->getFileName();
        $this->assertNotFalse($resolveur);

        $sourceDuResolveur = preg_replace('/\s+/', ' ', (string)file_get_contents($resolveur));
        $this->assertNotNull($sourceDuResolveur);
        $this->assertStringContainsString(
            (new ReflectionClass(Planet::class))->getShortName() . "::query()->whereIn('id', \$identifiants)->orderBy('id')->lockForUpdate()",
            $sourceDuResolveur,
            'The destination protocol no longer holds the bodies it decides on.'
        );

        // **Ce que l'on verrouille, c'est ce qui decide.** Tenir la seule destination retenue
        // rendrait sa ligne stable sans figer la raison pour laquelle elle a ete choisie.
        $this->assertStringContainsString(
            '$this->planner->bodiesThatDecideFor($mission)',
            $sourceDuResolveur,
            'The destination protocol does not lock the facts that decide the fallback, only the winner.'
        );

        // **Les destinations apres les missions.** L'ordre global est le meme partout : barriere,
        // instance, union, missions, corps. L'inverser rouvrirait l'interblocage que cet ordre ferme.
        $this->assertLessThan(
            strpos($source, $verrous['destinations']),
            strpos($source, $verrous['missions']),
            'The cancellation locks the destinations before the missions: the global order is broken.'
        );

        // **La seconde passe decide, la premiere ne fait que designer les lignes a tenir.**
        $this->assertLessThan(
            strpos($source, '$this->destinations->confirm($mission, $pressenti, $combat->id)'),
            strpos($source, $verrous['destinations']),
            'The cancellation decides before holding the destinations: the plan is not taken under lock.'
        );
    }

    /**
     * Un fait qui decide du recours, apparu entre les deux passes, arrete l'annulation.
     *
     * ## Ce que la comparaison de plans ne voyait pas
     *
     * Comparer les deux plans attrape un changement de destination. Elle ne voit pas un corps
     * **apparu ou disparu** parmi ceux qui font pencher le choix : le verdict peut rester le meme
     * par hasard, alors que la raison pour laquelle il a ete rendu n'est plus la meme. Et surtout,
     * le verrou pose a la premiere passe ne couvrirait pas cette ligne-la.
     */
    public function testAFactThatDecidesTheFallbackAppearingBetweenThePassesStopsTheCancellation(): void
    {
        [$combat, $mission] = $this->anActiveCombat();

        $planificateur = new class () extends ReturnPlanner {
            private int $appels = 0;

            /**
             * @return array<int, int>
             */
            public function bodiesThatDecideFor(FleetMission $mission): array
            {
                $this->appels++;
                $corps = parent::bodiesThatDecideFor($mission);

                // Au second appel — celui pris sous verrou —, une planete de plus decide.
                return $this->appels === 1 ? $corps : array_merge($corps, [999_999]);
            }
        };

        try {
            (new CombatCancellationService(destinations: new ReturnDestinationResolver($planificateur)))->cancel(
                $combat->id,
                CombatCancellationCause::AdministrativeDecision,
                'essai',
                function (): void {
                    $this->fail('A return was created though the facts behind the fallback had changed.');
                },
                (int)$combat->ends_at
            );
            $this->fail('The cancellation decided on facts it had never locked.');
        } catch (ReturnDestinationMoved $refus) {
            $this->assertSame($mission->id, $refus->fleetMissionId);
        }

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first());
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
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
     * Un combat actif avec deux flottes attaquantes du meme joueur.
     *
     * Deux flottes envoyees a la suite arrivent au meme instant : la fenetre attend la seconde, et
     * la fermeture est donc appelee explicitement. C'est le seul moyen d'obtenir deux retours a
     * creer dans la meme transaction.
     *
     * @return array{0: CombatInstance, 1: array<int, FleetMission>, 2: PlanetService}
     */
    private function anActiveCombatWithTwoFleets(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $cible = null;

        for ($i = 0; $i < 2; $i++) {
            $units = new UnitCollection();
            $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 25);
            $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 150);

            $envoyeeVers = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

            if ($cible !== null && $envoyeeVers->getPlanetId() !== $cible->getPlanetId()) {
                $this->fail('The second fleet was sent to another planet.');
            }

            $cible = $envoyeeVers;
        }

        $missions = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderBy('id')
            ->get()
            ->all();

        $this->assertCount(2, $missions, 'Not every dispatched fleet became a mission.');

        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 500_000,
            'crystal' => 300_000,
            'deuterium' => 100_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        $combat = (new CombatOpeningService())->openOrJoin($missions[0], $cible->getPlanetId(), (int)$missions[0]->time_arrival);
        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first();

        if ($barriere === null) {
            $this->fail('The opening left no barrier.');
        }

        $combat->refresh();

        if ($combat->status === CombatState::Rallying) {
            $this->assertTrue((new RallyClosureService())->close($combat->id, (int)$barriere->owned_through_effect_at)->closed, 'The rally did not close.');
            $combat->refresh();
        }

        $this->assertSame(CombatState::Active, $combat->status);

        return [$combat, $missions, $cible];
    }

    /**
     * Un combat actif, ouvert par une vraie attaque — et deja engage, puisque personne n'est attendu.
     *
     * @return array{0: CombatInstance, 1: FleetMission, 2: PlanetService}
     */
    private function anActiveCombat(Closure|null $avantOuverture = null): array
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

        if ($avantOuverture !== null) {
            $avantOuverture($cible, (int)$mission->time_arrival);
        }

        $combat = (new CombatOpeningService())->openOrJoin($mission, $cible->getPlanetId(), (int)$mission->time_arrival);
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'A lone attacker did not close its own rally.');

        return [$combat, $mission, $cible];
    }

    /**
     * Une Defense ACS d'un autre joueur, posee ou en route vers la cible.
     */
    private function aDefensiveReinforcement(PlanetService $cible, int $physicalArrival, int $holding, int $departsAt): FleetMission
    {
        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        $allie = User::query()
            ->where('is_npc', false)
            ->where('vacation_mode', false)
            ->where('username', '!=', User::SYSTEM_ACCOUNT_USERNAME)
            ->whereNotIn('id', [$this->currentUserId, $proprietaire])
            ->orderByDesc('id')
            ->first();

        if ($allie === null) {
            $this->fail('No third player exists to send a reinforcement.');
        }

        $origine = Planet::query()->where('user_id', $allie->id)->orderBy('id')->first();

        if ($origine === null) {
            $this->fail('The ally owns no planet.');
        }

        $coordonnees = $cible->getPlanetCoordinates();

        return FleetMission::forceCreate([
            'user_id' => $allie->id,
            'planet_id_from' => $origine->id,
            'type_from' => 1,
            'galaxy_from' => $origine->galaxy,
            'system_from' => $origine->system,
            'position_from' => $origine->planet,
            'planet_id_to' => $cible->getPlanetId(),
            'type_to' => 1,
            'galaxy_to' => $coordonnees->galaxy,
            'system_to' => $coordonnees->system,
            'position_to' => $coordonnees->position,
            'mission_type' => 5,
            'time_departure' => $departsAt,
            'time_arrival' => $physicalArrival + $holding,
            'time_holding' => $holding,
            'processed_hold' => 1,
            'light_fighter' => 5,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
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
        return resolve(AttackMission::class)->cancelPersistentCombat($combat->id, $cause, 'resultat fige illisible, constate a la main', (int)$combat->ends_at);
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
