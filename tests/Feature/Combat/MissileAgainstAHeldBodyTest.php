<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\MissileArrivalGate;
use OGame\Combat\Services\MissileRefundClaims;
use OGame\Combat\Services\RallyClosureService;
use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\MissileMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Un missile face a un corps qu'un combat tient : la matrice, enfin raccordee au lanceur et a la porte.
 *
 * ## Les trois verdicts que la matrice decrivait sans que rien ne les execute
 *
 * - **Au lancement**, cible deja verrouillee : refuse, par la mission et par le point de lancement de
 *   la Galaxie, avec le meme message traduit. Sans ce refus, tirer pendant un ralliement etait
 *   possible, et le report d'un missile deja parti aurait pu passer pour une autorisation.
 * - **A l'arrivee pendant la bataille** : differe. Le missile attend le reglement, puis frappe ce
 *   qui reste — une seule fois.
 * - **A l'arrivee pendant le ralliement, parti apres l'ouverture** : une anomalie (le lancement
 *   aurait du etre refuse ; seule une course l'a laisse passer). Annule sans impact, missiles rendus,
 *   joueur averti, alerte au journal. Le sort des missiles — rendus — est un choix d'implementation
 *   dit au journal, que Keven peut renverser.
 */
final class MissileAgainstAHeldBodyTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int GARRISON = 200;

    private const int DESTROYED_BY_ONE_MISSILE = 60;

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
        $this->playerSetResearchLevel('impulse_drive', object_level: 10);
        $this->planetAddUnit('interplanetary_missile', 5);
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    public function testALaunchAgainstAHeldBodyIsRefusedByTheMissionAndByTheGalaxy(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $coordonnees = $this->coordinatesOf($cible);

        $statut = $this->missileMission()->isMissionPossible($this->planetService, $coordonnees, PlanetType::Planet, new UnitCollection());
        $this->assertFalse($statut->possible, 'The mission allowed a missile launch against a body a combat holds.');
        $this->assertSame(__('t_ingame.galaxy.missile_target_combat_locked'), $statut->error, 'The refusal does not carry the translated message.');

        $reponse = $this->post(route('galaxy.missile-attack'), [
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'position' => $coordonnees->position,
            'type' => PlanetType::Planet->value,
            'missile_count' => 1,
            'target_priority' => 0,
        ]);
        $reponse->assertStatus(409);
        $reponse->assertJson(['success' => false, 'error' => __('t_ingame.galaxy.missile_target_combat_locked')]);
        $this->assertSame(5, $this->planetService->getObjectAmount('interplanetary_missile'), 'The refused launch consumed missiles.');

        // Le temoin inverse : un corps que personne ne tient recoit le lancement.
        $libre = $this->getNearbyForeignCleanPlanet();
        $statutLibre = $this->missileMission()->isMissionPossible($this->planetService, $libre->getPlanetCoordinates(), PlanetType::Planet, new UnitCollection());
        $this->assertTrue($statutLibre->possible, 'A body no combat holds refused the launch: the check does not distinguish held from free. ' . $statutLibre->error);
    }

    public function testAMissileArrivingDuringTheBattleWaitsForTheSettlementAndStrikesOnce(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        // Parti avant l'ouverture, il arrive pendant la bataille.
        $missile = $this->aPendingMissileTowards($cible, $ouverture - 50, $fermeture + 5);
        $this->travelTo(Date::createFromTimestamp($fermeture + 5));
        $this->get('/overview')->assertStatus(200);
        $this->assertSame(0, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The missile struck during the battle instead of waiting for the settlement.');
        $this->assertSame(self::GARRISON, $this->garrisonOf($cible, 'rocket_launcher'), 'The body lost defences to a missile while the battle was running.');

        // Le reglement passe ; le missile frappe ce qui reste.
        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);
        $apresReglement = $this->garrisonOf($cible, 'rocket_launcher');

        $this->get('/overview')->assertStatus(200);
        $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The deferred missile never struck after the settlement.');
        $this->assertSame(max(0, $apresReglement - self::DESTROYED_BY_ONE_MISSILE), $this->garrisonOf($cible, 'rocket_launcher'), 'The deferred missile did not strike what remained after the settlement.');

        // Une seule fois.
        $apresFrappe = $this->garrisonOf($cible, 'rocket_launcher');
        $this->get('/overview')->assertStatus(200);
        $this->assertSame($apresFrappe, $this->garrisonOf($cible, 'rocket_launcher'), 'The deferred missile struck twice.');
    }

    public function testAMissileLaunchedAfterTheOpeningIsCancelledWithoutImpactAndReturned(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $silo = $this->planetService->getObjectAmount('interplanetary_missile');

        // Parti **apres** l'ouverture : le lancement aurait du etre refuse. Une course l'a laisse passer.
        $missile = $this->aPendingMissileTowards($cible, $ouverture + 1, $ouverture + 8);
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));
        $this->get('/overview')->assertStatus(200);

        $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The anomalous missile was left pending: it would strike later.');
        $this->assertSame(self::GARRISON, $this->garrisonOf($cible, 'rocket_launcher'), 'A missile launched after the opening struck the body.');
        $this->planetService->reloadPlanet();
        $this->assertSame($silo + 1, $this->planetService->getObjectAmount('interplanetary_missile'), 'The cancelled missile was not returned to its silo.');
        $this->assertSame(0, DB::table('combat_effect_ledger')->where('combat_instance_id', $combat->id)->count(), 'A cancelled missile left a line in the effect ledger.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count(), 'The launcher was not told that the missile was cancelled.');

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');
        $this->assertSame(self::GARRISON, $this->defenderStartOf($combat, 'rocket_launcher'), 'The cancelled missile entered the photograph.');
    }

    /**
     * Le remboursement est unique : un second appelant trouve la mission traitee et ne rend rien.
     *
     * Sous SQLite, `lockForUpdate()` ne compile a rien : cet essai prouve l'idempotence par
     * `processed`, pas la course. La course — deux travailleurs au meme instant — est jouee sur le
     * bac MariaDB (`MissileRefundRaceTest`).
     */
    public function testCancellingTheSameMissileTwiceRefundsItOnce(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $silo = $this->planetService->getObjectAmount('interplanetary_missile');
        $missile = $this->aPendingMissileTowards($cible, $ouverture + 1, $ouverture + 8, missiles: 2);
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));

        $porte = resolve(MissileArrivalGate::class);
        $this->assertSame(MissileArrivalGate::CANCELLED, $porte->decide(FleetMission::query()->findOrFail($missile->id)));
        $this->assertSame(MissileArrivalGate::CANCELLED, $porte->decide(FleetMission::query()->findOrFail($missile->id)), 'The second call did not recognise a mission already cancelled.');
        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

        $this->planetService->reloadPlanet();
        $this->assertSame($silo + 2, $this->planetService->getObjectAmount('interplanetary_missile'), 'The missiles were refunded twice, or never.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count(), 'The launcher was told twice, or never.');
    }

    /**
     * Le silo d'origine n'existe plus : les missiles suivent le protocole canonique de destination.
     *
     * Aucune destination inventee — c'est celui qui ramene une flotte refusee. Et l'annulation est
     * **definitive** : le combat se termine, la barriere disparait, un nouveau passage a lieu, et le
     * missile ne frappe toujours pas. Une version anterieure le laissait non traite, « en attente
     * d'exploitation » : le verdict redevenait alors `APPLY`, et il frappait des heures plus tard.
     */
    public function testAMissileWhoseSiloDisappearedIsStillCancelledAfterTheCombatEnds(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $silo = $this->planetService->getObjectAmount('interplanetary_missile');
        $missile = $this->aPendingMissileTowards($cible, $ouverture + 1, $ouverture + 8, missiles: 2);

        // Un corps supprime laisse aux missions leurs coordonnees et leur retire le lien
        // (`PlayerService::delete()`) : c'est ainsi qu'un silo disparait.
        DB::table('fleet_missions')->where('id', $missile->id)->update(['planet_id_from' => null]);
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));

        $this->assertSame(MissileArrivalGate::CANCELLED, resolve(MissileArrivalGate::class)->decide(FleetMission::query()->findOrFail($missile->id)));
        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

        $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The cancellation was not made final: a later pass could still apply the missile.');
        $this->assertSame(self::GARRISON, $this->garrisonOf($cible, 'rocket_launcher'), 'The cancelled missile struck the body.');

        // **Le protocole canonique a rendu les missiles** : la planete du lanceur est sa destination.
        $this->planetService->reloadPlanet();
        $this->assertSame($silo + 2, $this->planetService->getObjectAmount('interplanetary_missile'), 'The canonical destination protocol returned nothing.');

        // **Le combat se termine, la barriere disparait, le monde repasse.** Le verdict ne se retourne pas.
        DB::table('celestial_body_combat_barriers')->where('target_body_id', $cible)->delete();
        $this->travelTo(Date::createFromTimestamp($ouverture + 600));
        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

        $this->assertSame(self::GARRISON, $this->garrisonOf($cible, 'rocket_launcher'), 'The missile struck once the barrier was gone: the cancellation was not durable.');
        $this->planetService->reloadPlanet();
        $this->assertSame($silo + 2, $this->planetService->getObjectAmount('interplanetary_missile'), 'The missiles were returned a second time.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count(), 'The launcher was told twice.');
    }

    /**
     * Nulle part ou rendre a cet instant : les missiles deviennent une **creance**, jamais une perte.
     *
     * C'est le cas que la revue 99 a nomme : fermer le risque de frappe tardive en detruisant les
     * actifs violerait la decision de Keven — un missile parti par une erreur du serveur est rendu.
     * L'annulation reste definitive, ce qui est du est inscrit, et le reglement le rend **apres la fin
     * du combat**, une seule fois.
     */
    public function testWithNoDestinationTheMissilesBecomeARecoverableClaimSettledOnlyOnce(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $origine = $this->planetService->getPlanetId();
        $silo = $this->planetService->getObjectAmount('interplanetary_missile');
        $missile = $this->aPendingMissileTowards($cible, $ouverture + 1, $ouverture + 8, missiles: 2);

        // Le lanceur n'a plus aucun corps : ni silo de depart, ni destination canonique. La colonne
        // est NOT NULL, donc ses corps passent a un autre joueur — c'est ce que vit un compte dont les
        // planetes ont change de main.
        $autre = (int)DB::table('planets')->where('id', $cible)->value('user_id');
        DB::table('fleet_missions')->where('id', $missile->id)->update(['planet_id_from' => null]);
        DB::table('planets')->where('user_id', $this->currentUserId)->update(['user_id' => $autre]);
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));

        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));

        $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The cancellation was not made final.');
        $this->assertSame(self::GARRISON, $this->garrisonOf($cible, 'rocket_launcher'), 'The cancelled missile struck the body.');
        $this->assertSame(0, DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count(), 'A refund was announced although nothing was returned.');

        // **La creance existe, et elle dit ce qui est du.**
        $creance = DB::table('combat_missile_refunds')->where('fleet_mission_id', $missile->id)->first();
        $this->assertNotNull($creance, 'The missiles were destroyed instead of being owed: a log line is not a recoverable claim.');
        $this->assertSame(2, (int)$creance->missiles);
        $this->assertSame($this->currentUserId, (int)$creance->owner_id);
        $this->assertNull($creance->credited_at, 'The claim was marked credited although nothing was credited.');

        // Un second passage n'inscrit pas une seconde creance.
        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($missile->id));
        $this->assertSame(1, DB::table('combat_missile_refunds')->where('fleet_mission_id', $missile->id)->count(), 'A replayed cancellation wrote a second claim.');

        // **Le combat se termine, et le joueur retrouve un corps.** La creance se regle alors.
        DB::table('celestial_body_combat_barriers')->where('target_body_id', $cible)->delete();
        DB::table('planets')->where('id', $origine)->update(['user_id' => $this->currentUserId]);
        $this->travelTo(Date::createFromTimestamp($ouverture + 600));

        $issue = resolve(MissileRefundClaims::class)->settlePending((int)Date::now()->timestamp);
        $this->assertSame(1, $issue['credited'], 'The claim was not settled although the owner has a body again.');
        $this->assertSame($silo + 2, (int)DB::table('planets')->where('id', $origine)->value('interplanetary_missile'), 'The owed missiles were not returned.');

        // **Une fois, et une seule.**
        $secondIssue = resolve(MissileRefundClaims::class)->settlePending((int)Date::now()->timestamp);
        $this->assertSame(0, $secondIssue['credited'], 'The claim was settled twice.');
        $this->assertSame($silo + 2, (int)DB::table('planets')->where('id', $origine)->value('interplanetary_missile'), 'The missiles were credited a second time.');
    }

    private function missileMission(): MissileMission
    {
        $mission = resolve(GameMissionFactory::class)->getMissionById(10, [
            'fleetMissionService' => resolve(FleetMissionService::class),
            'messageService' => resolve(MessageService::class),
        ]);
        $this->assertInstanceOf(MissileMission::class, $mission);

        return $mission;
    }

    private function coordinatesOf(int $planetId): Coordinate
    {
        $ligne = DB::table('planets')->where('id', $planetId)->first(['galaxy', 'system', 'planet']);
        $this->assertNotNull($ligne);

        return new Coordinate((int)$ligne->galaxy, (int)$ligne->system, (int)$ligne->planet);
    }

    private function garrisonOf(int $planetId, string $machineName): int
    {
        return (int)DB::table('planets')->where('id', $planetId)->value($machineName);
    }

    private function defenderStartOf(CombatInstance $combat, string $machineName): int
    {
        $combat->refresh();

        return BattleResultCodec::fromStorage($combat->battle_result)->defenderUnitsStart->getAmountByMachineName($machineName);
    }
}
