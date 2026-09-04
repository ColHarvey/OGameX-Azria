<?php

namespace Tests\Feature\Combat;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatFleetDisposition;
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
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Une Defense ACS ne stationne jamais hors photographie, et une engagee ne part pas avant le combat.
 *
 * ## Les deux moities de la regle
 *
 * Refusee a la fermeture, ou arrivee apres elle, une Defense ACS repart des que le travailleur la
 * touche — avec la raison de la fermeture si elle existe, `RallyClosed` sinon. Inscrite, elle reste
 * jusqu'a ce que le combat soit final, meme si son stationnement expire entre-temps : la bataille
 * est calculee avec elle et appliquee a l'echeance, la renvoyer entre les deux la ferait combattre
 * et rentrer.
 *
 * Le travailleur est appele directement (`FleetMissionService::updateMission()`) : en jeu, c'est la
 * page de l'allie ou celle du defenseur qui le fait passer sur cette mission.
 */
class AcsDefenceUnderCombatTest extends FleetDispatchTestCase
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

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
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
     * Un renfort refuse a la fermeture repart a son arrivee physique, avec la raison de la fermeture.
     *
     * Parti a l'ouverture, il n'etait pas en vol : la fermeture le refuse (`NotAlreadyInFlight`).
     * Le travailleur le renvoie des qu'il est la, et la raison lue est celle du jugement — pas un
     * « ralliement ferme » ecrit par-dessus.
     */
    public function testARefusedReinforcementGoesHomeAtItsArrivalWithTheClosureReason(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 15, 30, $ouverture);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');
        $this->assertFalse($this->isADefendingParticipant($combat, $renfort), 'A reinforcement launched at the opening was admitted: nothing would be refused.');
        $this->assertSame(CombatReasonCode::NotAlreadyInFlight->value, $this->reasonToldTo($combat, $renfort));

        $this->travelTo(Date::createFromTimestamp($ouverture + 25));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'A refused reinforcement is still holding outside the photograph.');
        $this->assertSame(0, (int)$renfort->canceled, 'The server turned the fleet back as if the player had recalled it.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'The refused reinforcement is not coming home.');
        $this->assertSame(CombatReasonCode::NotAlreadyInFlight->value, $this->reasonToldTo($combat, $renfort), 'The closure reason was overwritten.');
    }

    /**
     * Un renfort arrive apres la fermeture repart avec « ralliement ferme ».
     *
     * Son arrivee physique tombe au-dela du plafond : il n'a jamais ete candidat, personne ne l'a
     * juge. Il apprend pourquoi par le meme canal que les refus.
     */
    public function testAReinforcementArrivingAfterTheClosureGoesHomeWithRallyClosed(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(false, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 90, 600, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertSame(CombatState::Active, $combat->refresh()->status, 'A reinforcement beyond the ceiling kept the rally open.');
        $this->assertNull($this->reasonToldTo($combat, $renfort), 'A fleet that was never a candidate received a verdict.');

        $this->travelTo(Date::createFromTimestamp($ouverture + 95));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'A late reinforcement is holding outside the photograph.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count());
        $this->assertSame(CombatReasonCode::RallyClosed->value, $this->reasonToldTo($combat, $renfort), 'The late reinforcement went home without being told why.');

        // **La retenue ne vaut que pendant le ralliement.** Retenir une flotte arrivee apres la
        // fermeture la ferait passer pour engagee alors qu elle rentre : `EngagedFleetCheck` la
        // verrait, et son propre retour serait bloque.
        $this->assertNull($renfort->combat_instance_id, 'A reinforcement that landed after the closure was held by the combat.');
    }

    /**
     * Le stationnement d'un renfort inscrit ne s'acheve pas avant le combat ; le combat fini, il rentre.
     */
    public function testTheHoldOfAnEngagedReinforcementDoesNotEndBeforeTheCombat(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 5, 30, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');
        $this->assertTrue($this->isADefendingParticipant($combat, $renfort), 'The reinforcement was not admitted (' . ($this->reasonToldTo($combat, $renfort) ?? 'no notice') . ').');

        // Son stationnement expire pendant le combat.
        $this->travelTo(Date::createFromTimestamp($ouverture + 40));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(0, (int)$renfort->processed, 'An engaged reinforcement went home before the battle was applied: it fights and comes home at once.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $renfort->id)->count());

        // Le combat fini, son stationnement expire est traite.
        $issue = resolve(AttackMission::class)->cancelPersistentCombat($combat->id, CombatCancellationCause::AdministrativeDecision, $ouverture + 41);
        $this->assertTrue($issue->cancelled);

        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'The hold of a reinforcement outlived the combat.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count());
    }

    /**
     * Une vague attaquante refusee garde la raison de la fermeture quand son arrivee est traitee.
     *
     * L'avis de la fermeture est construit a la main : il est exactement ce qu'elle ecrit pour une
     * vague refusee, et c'est le traitement de l'arrivee qui est eprouve, pas la fermeture.
     */
    public function testARefusedWaveKeepsTheClosureReasonWhenItsArrivalIsProcessed(): void
    {
        [$combat, $ouvreuse, , $ouverture, $cible] = $this->anOpenedCombat(false);
        $this->assertSame(CombatState::Active, $combat->refresh()->status);

        $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
        DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 30]);

        CombatOutboxMessage::query()->create([
            'combat_instance_id' => $combat->id,
            'participant_key' => CombatParticipantKey::forFleet($vague->id),
            'kind' => CombatOutboxKind::RallyRefused->value,
            'payload' => ['reason' => CombatReasonCode::FleetLimitReached->value, 'group_fleets' => 1],
            'available_at' => $ouverture,
        ]);

        $this->travelTo(Date::createFromTimestamp($ouverture + 40));
        $this->get('/overview')->assertStatus(200);

        $vague->refresh();
        $this->assertSame(1, (int)$vague->processed, 'The refused wave is still waiting.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $vague->id)->count());
        $this->assertSame(CombatReasonCode::FleetLimitReached->value, $this->reasonToldTo($combat, $vague), 'The closure reason was overwritten by « rally closed ».');
    }

    /**
     * Une vague refusee par une limite rentre apres la fin du combat, une seule fois, avec sa raison.
     *
     * ## Le defaut que cet essai ferme
     *
     * La fermeture ecrit une disposition pour **chaque** flotte refusee, vagues attaquantes
     * comprises. Seule la Defense ACS la consommait : l'attaque ecrivait son avis, marquait l'aller
     * et creait son retour de son cote, et le verdict attaquant restait « en attente » pour toujours.
     *
     * Pire, une vague touchee apres le reglement trouvait le corps libre — barriere levee — et
     * **ouvrait un second combat** : celui-la meme que son refus existait pour empecher.
     */
    public function testARefusedWaveStillGoesHomeAfterTheCombatIsOverAndOnlyOnce(): void
    {
        [$combat, $ouvreuse, , $ouverture, $cible] = $this->anOpenedCombat(false);
        $this->assertSame(CombatState::Active, $combat->refresh()->status);

        $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
        DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 30]);
        $vague->refresh();

        // Ce que la fermeture ecrit pour une vague qu'elle refuse : le mouvement, et sa raison.
        (new FleetDispositionRegistry())->record(
            $combat,
            $vague->id,
            CombatReasonCode::FleetLimitReached,
            $ouverture + 30,
            FleetDispositionKind::ReturnToOrigin
        );

        // **Personne ne la touche entre la fermeture et le reglement.**
        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        $this->assertSame(1, (new PersistentCombatAdvancer())->advance((int)$combat->ends_at)->settled, 'The combat did not settle.');

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status);
        $this->assertNull(
            CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(),
            'The barrier is still there: the test would not prove that the disposition survives it.'
        );

        $apres = (int)$combat->ends_at + 3_600;
        $this->travelTo(Date::createFromTimestamp($apres));
        resolve(FleetMissionService::class)->updateMission($vague->refresh());

        $vague->refresh();
        $this->assertSame(1, (int)$vague->processed, 'The refused wave is still waiting on a body that no longer holds a combat.');
        $this->assertCount(1, FleetMission::query()->where('parent_id', $vague->id)->get(), 'The refused wave did not come home exactly once.');
        $this->assertSame(CombatReasonCode::FleetLimitReached->value, $this->reasonToldTo($combat, $vague), 'The limit reason was replaced.');

        $this->assertSame(
            1,
            CombatInstance::query()->count(),
            'The late wave opened a second combat on the body its refusal had freed.'
        );

        $decidee = CombatFleetDisposition::query()->where('fleet_mission_id', $vague->id)->first();
        $this->assertNotNull($decidee);
        $this->assertFalse($decidee->isPending(), 'The attacking verdict stayed pending forever.');
        $this->assertSame($apres, (int)$decidee->consumed_at);

        // Rejeu du travailleur : toujours un seul retour.
        resolve(FleetMissionService::class)->updateMission($vague->refresh());
        $this->assertCount(1, FleetMission::query()->where('parent_id', $vague->id)->get(), 'A replay of the worker created a second return.');
    }

    /**
     * Une vague que personne n'a jugee, arrivee apres la fermeture, ecrit sa decision puis rentre.
     *
     * Elle n'a jamais ete candidate : la photographie etait prise, les budgets consommes, la
     * bataille calculee. Sa raison est « ralliement ferme », et elle s'ecrit **avant** d'etre
     * executee — comme celle d'une refusee, pour que les deux chemins se relisent de la meme facon.
     */
    public function testAWaveNeverJudgedWritesItsDecisionBeforeGoingHome(): void
    {
        [$combat, $ouvreuse, , $ouverture, $cible] = $this->anOpenedCombat(false);
        $this->assertSame(CombatState::Active, $combat->refresh()->status);

        $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
        DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 30]);

        $this->assertNull(
            CombatFleetDisposition::query()->where('fleet_mission_id', $vague->id)->first(),
            'The wave already carried a decision: nothing would be proved about the never-judged case.'
        );

        $this->travelTo(Date::createFromTimestamp($ouverture + 40));
        $this->get('/overview')->assertStatus(200);

        $vague->refresh();
        $this->assertSame(1, (int)$vague->processed, 'The never-judged wave is still waiting.');
        $this->assertCount(1, FleetMission::query()->where('parent_id', $vague->id)->get());
        $this->assertSame(CombatReasonCode::RallyClosed->value, $this->reasonToldTo($combat, $vague));

        $decidee = CombatFleetDisposition::query()->where('fleet_mission_id', $vague->id)->first();
        $this->assertNotNull($decidee, 'The wave went home without its movement ever being written.');
        $this->assertSame(CombatReasonCode::RallyClosed, $decidee->reason);
        $this->assertSame(FleetDispositionKind::ReturnToOrigin, $decidee->movement);

        // L'instant de decision est l'arrivee, non l'horloge du travailleur qui passe dix secondes plus tard.
        $this->assertSame($ouverture + 30, (int)$decidee->decided_at, 'The decision was dated from the worker clock.');
        $this->assertFalse($decidee->isPending(), 'The decision was written and never carried out.');
    }

    /**
     * Une vague refusee dont le corps de depart a ete rase rentre elle aussi par le recours suivant.
     *
     * Le meme trou que du cote defensif, et il se ferme au meme endroit : les deux genres passent
     * par le protocole commun, qui resout la destination sous verrou. Sans lui, la vague repartait
     * vers `planet_id_from` — un corps qui n'existe plus.
     */
    public function testARefusedWaveWhoseOriginWasRazedComesHomeSomewhereReal(): void
    {
        [$combat, $ouvreuse, , $ouverture, $cible] = $this->anOpenedCombat(false);
        $this->assertSame(CombatState::Active, $combat->refresh()->status);

        $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
        DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 30]);
        $vague->refresh();

        $origine = (int)$vague->planet_id_from;

        // L'attaquant garde un autre foyer, et son corps de depart est rase pendant que la vague vole.
        $libre = (int)Planet::query()->where('galaxy', 6)->max('system') + 1;
        Planet::factory()->create([
            'user_id' => $vague->user_id,
            'galaxy' => 6,
            'system' => max(300, $libre),
            'planet' => 7,
        ]);
        DB::table('planets')->where('id', $origine)->update(['destroyed' => $ouverture - 10]);

        $this->travelTo(Date::createFromTimestamp($ouverture + 40));
        resolve(FleetMissionService::class)->updateMission($vague->refresh());

        $retour = FleetMission::query()->where('parent_id', $vague->id)->first();
        $this->assertNotNull($retour, 'The refused wave did not come home at all.');
        $this->assertNotSame($origine, (int)$retour->planet_id_to, 'The wave was sent back to a body that no longer exists.');

        $foyer = Planet::query()->find($retour->planet_id_to);
        $this->assertNotNull($foyer, 'The return targets a body that is not in the database.');
        $this->assertSame((int)$vague->user_id, (int)$foyer->user_id, 'The wave was sent to somebody else.');
        $this->assertSame(0, (int)($foyer->destroyed ?? 0), 'The wave was sent to a razed body.');
        $this->assertSame((int)$foyer->galaxy, (int)$retour->galaxy_to);
        $this->assertSame((int)$foyer->system, (int)$retour->system_to);
        $this->assertSame((int)$foyer->planet, (int)$retour->position_to);
    }

    /**
     * Un renfort dont le corps de depart a ete rase rentre par le recours suivant, pas dans le vide.
     *
     * ## Pourquoi le demi-tour a besoin d'une destination decidee
     *
     * Le renvoi creait son retour sans destination explicite : la mission repartait vers
     * `planet_id_from`, c'est-a-dire vers le corps d'ou elle etait partie — meme rase entre-temps.
     * L'annulation avait deja ferme ce trou avec le protocole de recours ; le demi-tour, lui, ne
     * l'utilisait pas, et deux protocoles de destination auraient fini par diverger.
     */
    public function testARefusedReinforcementWhoseOriginWasRazedComesHomeSomewhereReal(): void
    {
        $renfort = null;
        [, , , $ouverture] = $this->anOpenedCombat(false, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 90, 600, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $origine = (int)$renfort->planet_id_from;

        // L'allie garde un autre foyer, et son corps de depart est rase pendant que la flotte vole.
        $libre = (int)Planet::query()->where('galaxy', 7)->max('system') + 1;
        Planet::factory()->create([
            'user_id' => $renfort->user_id,
            'galaxy' => 7,
            'system' => max(400, $libre),
            'planet' => 4,
        ]);
        DB::table('planets')->where('id', $origine)->update(['destroyed' => $ouverture - 10]);

        $this->travelTo(Date::createFromTimestamp($ouverture + 95));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $retour = FleetMission::query()->where('parent_id', $renfort->id)->first();
        $this->assertNotNull($retour, 'The refused reinforcement did not come home at all.');

        $this->assertNotSame($origine, (int)$retour->planet_id_to, 'The fleet was sent back to a body that no longer exists.');

        $foyer = Planet::query()->find($retour->planet_id_to);
        $this->assertNotNull($foyer, 'The return targets a body that is not in the database.');
        $this->assertSame((int)$renfort->user_id, (int)$foyer->user_id, 'The fleet was sent to somebody else.');
        $this->assertSame(0, (int)($foyer->destroyed ?? 0), 'The fleet was sent to a razed body.');

        // Les coordonnees suivent la destination : les relire ailleurs exposerait a un atterrissage
        // qui ne correspond pas au corps decide.
        $this->assertSame((int)$foyer->galaxy, (int)$retour->galaxy_to);
        $this->assertSame((int)$foyer->system, (int)$retour->system_to);
        $this->assertSame((int)$foyer->planet, (int)$retour->position_to);
    }

    /**
     * Un renfort refuse rentre meme si personne ne le touche avant la fin du combat.
     *
     * ## Le trou que cet essai ferme
     *
     * Le demi-tour etait rededuit : on cherchait la barriere du corps, on retrouvait le combat, on
     * constatait que la flotte n'y etait pas inscrite. La barriere est levee au reglement — apres
     * quoi il ne reste plus rien a interroger.
     *
     * Un renfort refuse dont le stationnement s'acheve longtemps apres la bataille suivait donc son
     * chemin ordinaire : il stationnait hors photographie, et l'avis de refus annoncait un mouvement
     * qui n'arrivait jamais. La disposition ecrite a la fermeture survit a tout cela.
     */
    public function testARefusedReinforcementStillGoesHomeAfterTheCombatIsOver(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            // Lance a l'ouverture, donc pas encore en vol : la fermeture le refusera. Son
            // stationnement, lui, dure bien plus longtemps que la bataille.
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 5, 100_000, $ouverture);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');
        $this->assertFalse($this->isADefendingParticipant($combat, $renfort), 'The reinforcement was admitted: nothing would be refused.');

        // La decision est ecrite, avec la raison que l'admission a prononcee.
        $decidee = CombatFleetDisposition::query()->where('fleet_mission_id', $renfort->id)->first();
        $this->assertNotNull($decidee, 'The closure refused the reinforcement without writing what it must do.');
        $this->assertSame(FleetDispositionKind::ReturnToOrigin, $decidee->movement);
        $this->assertSame(CombatReasonCode::NotAlreadyInFlight, $decidee->reason);
        $this->assertTrue($decidee->isPending());

        // **Personne ne le touche entre la fermeture et le reglement.**
        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        $this->assertSame(1, (new PersistentCombatAdvancer())->advance((int)$combat->ends_at)->settled, 'The combat did not settle.');

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status);
        $this->assertNull(
            CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first(),
            'The barrier is still there: the test would not prove that the disposition survives it.'
        );

        // Le travailleur passe enfin, bien apres la fin du combat.
        $apres = (int)$combat->ends_at + 3_600;
        $this->travelTo(Date::createFromTimestamp($apres));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'A refused reinforcement stayed on the body after the combat was over.');
        $retours = FleetMission::query()->where('parent_id', $renfort->id)->get();
        $this->assertCount(1, $retours, 'The refused reinforcement did not come home exactly once.');
        $retour = $retours->first();

        if ($retour === null) {
            $this->fail('The refused reinforcement has no return.');
        }

        $this->assertSame($apres, (int)$retour->time_departure, 'The return does not leave at the instant the disposition was consumed.');

        // La raison est celle de l'admission, pas un « ralliement ferme » invente apres coup.
        $this->assertSame(CombatReasonCode::NotAlreadyInFlight->value, $this->reasonToldTo($combat, $renfort));

        $decidee->refresh();
        $this->assertFalse($decidee->isPending(), 'The disposition was not marked as done.');
        $this->assertSame($apres, (int)$decidee->consumed_at);
    }

    /**
     * Une disposition ne se consomme qu'une fois, quel que soit le nombre de passages.
     */
    public function testADispositionIsConsumedOnlyOnce(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 5, 100_000, $ouverture);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed);

        $decidee = CombatFleetDisposition::query()->where('fleet_mission_id', $renfort->id)->first();
        $this->assertNotNull($decidee);

        $this->travelTo(Date::createFromTimestamp($ouverture + 30));
        $mission = resolve(AcsDefendMission::class);

        $this->assertTrue($mission->settleArrival($renfort->refresh(), $ouverture + 30), 'The first pass did nothing.');
        $this->assertFalse($mission->settleArrival($renfort->refresh(), $ouverture + 60), 'A second pass consumed the same disposition again.');

        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'A second return was created.');
    }

    /**
     * Un renfort qui se pose pendant le ralliement est retenu, et son stationnement n'expire pas.
     *
     * C'est la moitie que la fermeture ne couvrait pas : entre son arrivee physique et le verdict,
     * la flotte est sur le corps sans etre encore inscrite. Rien ne la retenait.
     */
    public function testAReinforcementLandingDuringTheRallyIsHeldAtOnce(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            // Arrivee physique cinq secondes apres l'ouverture, stationnement de dix secondes : il se
            // pose pendant le ralliement, et sa fin de stationnement tombe avant la fermeture.
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 5, 10, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertSame(CombatState::Rallying, $combat->refresh()->status, 'The rally closed at once.');
        $this->assertNull($renfort->refresh()->combat_instance_id, 'The reinforcement was held before it landed.');

        // Il se pose : le travailleur le retient.
        $this->travelTo(Date::createFromTimestamp($ouverture + 6));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $this->assertSame($combat->id, (int)$renfort->refresh()->combat_instance_id, 'A reinforcement landing during the rally was not held.');

        // Son stationnement expire avant la fermeture : il ne part pas pour autant.
        $this->travelTo(Date::createFromTimestamp($ouverture + 16));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(0, (int)$renfort->processed, 'The hold expired and the fleet left before the verdict.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $renfort->id)->count());
    }

    /**
     * La decision est datee de l'arrivee physique, pas de l'horloge du travailleur.
     *
     * ## Pourquoi l'instant compte
     *
     * Une decision datee de l'horloge changerait de valeur a chaque passage : le registre refuserait
     * alors comme une contradiction ce qui n'est qu'un rejeu, et l'audit lirait le retard du
     * travailleur au lieu de l'instant ou la flotte s'est posee. Le travailleur passe ici cinq
     * secondes apres l'arrivee physique, pour que les deux valeurs ne coincident pas.
     */
    public function testTheDecisionIsDatedFromThePhysicalArrivalNotTheWorkerClock(): void
    {
        $renfort = null;
        [, , , $ouverture] = $this->anOpenedCombat(false, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 90, 600, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->travelTo(Date::createFromTimestamp($ouverture + 95));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $decidee = CombatFleetDisposition::query()->where('fleet_mission_id', $renfort->id)->first();
        $this->assertNotNull($decidee, 'The late reinforcement went home without a decision being written.');
        $this->assertSame($ouverture + 90, (int)$decidee->decided_at, 'The decision was dated from the worker clock instead of the physical arrival.');

        $avis = CombatOutboxMessage::query()
            ->where('participant_key', CombatParticipantKey::forFleet($renfort->id))
            ->first();
        $this->assertNotNull($avis);
        $this->assertSame($ouverture + 90, (int)$avis->available_at, 'The notice becomes readable at the worker clock instead of the arrival.');
    }

    /**
     * Une flotte dont le combat a deja decide le mouvement ne se rappelle pas.
     *
     * ## Ce que le rappel casserait
     *
     * Une refusee porte sa disposition des la fermeture, et rentrera par elle — avec la raison que
     * le joueur lira. Un rappel accorde dans cette fenetre creerait le retour **hors du protocole**,
     * et laisserait le verdict inexecute pour toujours : la disposition resterait « en attente »,
     * puisque l'aller serait deja traite quand le travailleur y passerait.
     *
     * Ce que le joueur voit ne change pas : sa flotte rentre, un peu plus tard, avec sa raison.
     */
    public function testAFleetWhoseMovementTheCombatDecidedIsNotRecalled(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 15, 30, $ouverture);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertTrue((new RallyClosureService())->close($combat->id, $ouverture + 19)->closed, 'The rally did not close.');

        $decidee = CombatFleetDisposition::query()->where('fleet_mission_id', $renfort->id)->first();
        $this->assertNotNull($decidee, 'The closure refused the reinforcement without writing its movement.');
        $this->assertNull($decidee->consumed_at, 'The decision was already carried out.');

        // Le joueur rappelle pendant le stationnement, avant que le travailleur ait execute le verdict.
        $this->travelTo(Date::createFromTimestamp($ouverture + 25));
        resolve(FleetMissionService::class)->cancelMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(0, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'The recall created the return outside the movement protocol.');
        $this->assertSame(0, (int)$renfort->canceled, 'The recall marked a fleet the combat had already decided for.');
        $this->assertSame(0, (int)$renfort->processed);

        // Et le verdict s'execute normalement au passage suivant, avec sa raison.
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'The decided movement was never carried out.');
        $this->assertNotNull(CombatFleetDisposition::query()->where('fleet_mission_id', $renfort->id)->value('consumed_at'), 'The decision stayed pending forever.');
        $this->assertSame(CombatReasonCode::NotAlreadyInFlight->value, $this->reasonToldTo($combat, $renfort), 'The fleet went home without the reason the closure gave it.');
    }

    /**
     * Le demi-tour ne touche pas aux heures de l'aller, et le retour part de l'instant courant.
     *
     * ## Une heure autoritative n'est pas une variable de travail
     *
     * Le renvoi reecrivait `time_arrival` a l'heure du travailleur et `time_holding` a zero, puis
     * corrigeait la duree du retour par un ecart arithmetique. Le resultat visible etait juste, et
     * l'aller perdait pourtant son arrivee et son stationnement planifies : ce que le joueur avait
     * decide, ce que l'admission avait juge, ce que l'audit relira. C'est le meme defaut que
     * l'annulation a retire.
     *
     * Le depart se dit maintenant explicitement, et la duree se calcule des faits intacts.
     */
    public function testTheTurnBackKeepsTheOutboundHoursAndDepartsNow(): void
    {
        $renfort = null;
        [, , , $ouverture] = $this->anOpenedCombat(false, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 90, 600, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $arriveePlanifiee = (int)$renfort->time_arrival;
        $stationnement = (int)$renfort->time_holding;
        $depart = (int)$renfort->time_departure;
        $dureeAller = ($arriveePlanifiee - $stationnement) - $depart;

        // Le travailleur passe cinq secondes apres l'arrivee physique : son horloge n'est ni le
        // depart, ni l'arrivee, ni la fin du stationnement. Aucune des valeurs comparees ci-dessous
        // ne coincide donc avec une autre.
        $maintenant = $ouverture + 95;
        $this->travelTo(Date::createFromTimestamp($maintenant));
        resolve(FleetMissionService::class)->updateMission($renfort->refresh());

        $renfort->refresh();
        $this->assertSame(1, (int)$renfort->processed, 'The reinforcement was not sent home.');
        $this->assertSame($arriveePlanifiee, (int)$renfort->time_arrival, 'The turn back rewrote the planned arrival of the outbound leg.');
        $this->assertSame($stationnement, (int)$renfort->time_holding, 'The turn back rewrote the planned hold of the outbound leg.');
        $this->assertSame($depart, (int)$renfort->time_departure, 'The turn back rewrote the departure of the outbound leg.');

        $retour = FleetMission::query()->where('parent_id', $renfort->id)->first();
        $this->assertNotNull($retour, 'The refused reinforcement was not sent home.');
        $this->assertSame($maintenant, (int)$retour->time_departure, 'The return did not leave at the instant it was decided.');
        $this->assertSame($maintenant + $dureeAller, (int)$retour->time_arrival, 'The return does not take the outbound duration computed from intact facts.');
    }

    /**
     * Un rappel n'accorde pas un second retour a une flotte deja renvoyee.
     *
     * ## La course, telle qu'elle se produit en jeu
     *
     * La page du joueur affiche sa flotte en stationnement ; il clique « rappeler ». Entre le rendu
     * de la page et le clic, le travailleur d'un autre joueur a touche la meme mission et l'a
     * renvoyee — refusee par un combat qui n'a pas de place pour elle.
     *
     * Le rappel portait alors le modele d'avant : stationnement en cours, mission non traitee. Il
     * creait **une seconde mission retour pour la meme flotte**, et les vaisseaux existaient deux
     * fois. Aucune des deux ecritures n'etait fautive prise seule ; c'est de les avoir laissees
     * decider chacune sur son propre souvenir que naissait la duplication.
     *
     * La porte relit la ligne : le rappel voit une flotte deja partie, et refuse.
     */
    public function testARecallDoesNotGrantASecondReturnToAFleetAlreadySentHome(): void
    {
        $renfort = null;
        [, , , $ouverture] = $this->anOpenedCombat(false, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 90, 600, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->travelTo(Date::createFromTimestamp($ouverture + 95));

        // **Le modele que le joueur tient**, charge avant le demi-tour. C'est celui que son rappel
        // portera, et il decrira un passe des la ligne suivante.
        $perime = FleetMission::query()->findOrFail($renfort->id);

        $service = resolve(FleetMissionService::class);
        $service->updateMission($renfort->refresh());

        $this->assertSame(1, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'The turn back did not send the fleet home.');
        $this->assertSame(0, (int)$perime->processed, 'The stale model was expected to still describe the hold.');

        $service->cancelMission($perime);

        $this->assertSame(
            1,
            FleetMission::query()->where('parent_id', $renfort->id)->count(),
            'A recall granted a second return to a fleet already sent home: its ships exist twice.'
        );
    }

    /**
     * Une flotte que la retenue vient d'inscrire ne se rappelle plus, meme lue avant l'inscription.
     *
     * L'autre sens de la meme course. La retenue pose `combat_instance_id` a l'arrivee physique ;
     * un rappel qui a lu la mission une seconde plus tot ne voit ni ce lien ni aucune inscription —
     * le ralliement n'en a pas encore ecrit. Il laissait donc partir une flotte qui compose deja la
     * photographie du corps, et le combat se calculait avec des vaisseaux repartis.
     */
    public function testARecallIsRefusedForAFleetTheHoldJustBoundToTheCombat(): void
    {
        $renfort = null;
        [$combat, , , $ouverture] = $this->anOpenedCombat(true, function (PlanetService $cible, int $ouverture) use (&$renfort): void {
            $renfort = $this->aDefensiveReinforcement($cible, $ouverture + 5, 60, $ouverture - 600);
        });

        if ($renfort === null) {
            $this->fail('The reinforcement was never launched.');
        }

        $this->assertSame(CombatState::Rallying, $combat->refresh()->status, 'The rally closed at once.');

        $this->travelTo(Date::createFromTimestamp($ouverture + 6));

        $perime = FleetMission::query()->findOrFail($renfort->id);
        $this->assertNull($perime->combat_instance_id, 'The fleet was bound before it landed.');

        $service = resolve(FleetMissionService::class);
        $service->updateMission($renfort->refresh());

        $this->assertSame($combat->id, (int)$renfort->refresh()->combat_instance_id, 'The landing fleet was not held.');

        $service->cancelMission($perime);

        $renfort->refresh();
        $this->assertSame(0, FleetMission::query()->where('parent_id', $renfort->id)->count(), 'A fleet already counted in the photograph was recalled on a stale read.');
        $this->assertSame(0, (int)$renfort->processed, 'The recall marked a held fleet as processed.');
        $this->assertSame($combat->id, (int)$renfort->combat_instance_id, 'The recall unbound a held fleet.');
    }

    /**
     * Une arrivee traitee sur une lecture plus ancienne n'engage pas une flotte deja rappelee.
     *
     * ## La course, telle qu'elle se produit
     *
     * Le travailleur charge la mission. Le joueur la rappelle : retour cree, aller marque. Le
     * travailleur poursuit avec son souvenir — non traitee, en vol — et traite l'arrivee : il ouvre
     * un combat, ou en rejoint un, et **rattache** la flotte. Elle est partie et engagee a la fois ;
     * la bataille se calculera avec des vaisseaux qui rentrent, et le retour les ramenera.
     *
     * Le lien s'ecrivait apres le retour de l'ouverture, hors de sa transaction et sur le modele
     * recu. Il s'ecrit maintenant derriere la porte, sur la mission relue.
     */
    public function testAnArrivalHoldingAnOlderReadDoesNotEngageAFleetAlreadyRecalled(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);
        $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

        $ouvreuse = $this->lastAttackDispatched();
        $arrivee = (int)$ouvreuse->time_arrival;

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');

        // Le travailleur a lu la mission ; le joueur la rappelle ensuite.
        $perime = FleetMission::query()->findOrFail($ouvreuse->id);
        resolve(FleetMissionService::class)->cancelMission($ouvreuse->refresh());

        $this->assertSame(1, (int)$ouvreuse->refresh()->canceled, 'The recall did not happen: nothing would be proved.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $ouvreuse->id)->count());

        // L'arrivee est traitee avec la lecture d'avant.
        $this->travelTo(Date::createFromTimestamp($arrivee));
        $attaque = GameMissionFactory::getMissionById(1, [
            'fleetMissionService' => resolve(FleetMissionService::class),
            'messageService' => resolve(MessageService::class),
        ]);
        $attaque->process($perime);

        $this->assertSame(0, CombatInstance::query()->count(), 'A recalled fleet opened a combat: it is gone and engaged at the same time.');
        $this->assertNull(FleetMission::query()->findOrFail($ouvreuse->id)->combat_instance_id, 'A recalled fleet was bound to a combat.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $ouvreuse->id)->count(), 'The fleet got a second return.');
    }

    /**
     * Un combat ouvert par une vraie attaque, traitee par la route reelle a la seconde de son arrivee.
     *
     * @param bool $secondeVague Une seconde vague du meme joueur, attendue dix-huit secondes plus tard.
     * @param Closure(PlanetService, int): void|null $avantOuverture Ce qui doit exister avant l'arrivee.
     * @return array{0: CombatInstance, 1: FleetMission, 2: FleetMission|null, 3: int, 4: PlanetService}
     */
    private function anOpenedCombat(bool $secondeVague, Closure|null $avantOuverture = null): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));
        $ouvreuse = $this->lastAttackDispatched();
        $ouverture = (int)$ouvreuse->time_arrival;

        $vague = null;

        if ($secondeVague) {
            $vague = $this->aSecondAttackAgainst($cible, $ouvreuse);
            DB::table('fleet_missions')->where('id', $vague->id)->update(['time_arrival' => $ouverture + 18]);
            $vague->refresh();
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
            $avantOuverture($cible, $ouverture);
        }

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp($ouverture));
        $this->get('/overview')->assertStatus(200);

        $ouvreuse->refresh();
        $combat = CombatInstance::query()->find((int)($ouvreuse->combat_instance_id ?? 0));

        if ($combat === null) {
            $this->fail('The arrival did not open a combat.');
        }

        return [$combat, $ouvreuse, $vague, $ouverture, $cible];
    }

    private function aSecondAttackAgainst(PlanetService $cible, FleetMission $premiere): FleetMission
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);

        $coordonnees = $cible->getPlanetCoordinates();
        $this->dispatchFleet(
            new Coordinate($coordonnees->galaxy, $coordonnees->system, $coordonnees->position),
            $unites,
            new Resources(0, 0, 0, 0),
            PlanetType::Planet
        );

        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->where('planet_id_to', $cible->getPlanetId())
            ->where('id', '>', $premiere->id)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('The second attack was never dispatched against the same body.');
        }

        return $mission;
    }

    /**
     * Un renfort defensif d'un vrai joueur tiers : `time_arrival` porte la fin du stationnement.
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

    private function isADefendingParticipant(CombatInstance $combat, FleetMission $mission): bool
    {
        return CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $mission->id)
            ->where('side', CombatParticipant::SIDE_DEFENDER)
            ->exists();
    }

    private function reasonToldTo(CombatInstance $combat, FleetMission $mission): string|null
    {
        $avis = CombatOutboxMessage::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($mission->id))
            ->first();

        if ($avis === null) {
            return null;
        }

        $raison = $avis->payload['reason'] ?? null;

        return is_string($raison) ? $raison : null;
    }

    private function lastAttackDispatched(): FleetMission
    {
        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        return $mission;
    }
}
