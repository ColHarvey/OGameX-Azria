<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\FrozenPatrolTarget;
use OGame\Patrol\SpatialAttackOrder;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * **Les cumuls militaires lisent une bataille en espace libre**, dans la transaction du reglement spatial.
 *
 * Deux joueurs classes, memes regles qu une bataille sur un corps : chacun perd ce qu il a perdu et detruit ce que
 * l autre a perdu. Le site n a ni corps ni unites : il porte la clef que le moteur lui donne et ne recoit aucun
 * evenement. La patrouille et l attaquante sont des flottes, identifiees par leur mission.
 */
final class MilitaryTalliesSpatialTest extends AccountTestCase
{
    use ReadsMilitaryTallies;
    use StagesASpatialBattle;

    protected function setUp(): void
    {
        parent::setUp();

        // Les arrivees suivent la naissance des comptes : une attaque datee de la seconde de naissance de son
        // proprietaire arriverait avant lui, et le gel a l admission refuserait de dire sa classe.
        $this->travelTo(now()->addHour());
        resolve(SettingsService::class)->set('patrols_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        $this->desactiverLesCumuls();

        parent::tearDown();
    }

    public function testABattleInFreeSpaceCreditsBothPlayersLikeAnyBattle(): void
    {
        $this->activerLesCumulsDepuis((int)Date::now()->timestamp - 1);

        $chezMoi = $this->planetService->getPlanetCoordinates();
        [$patrouille, $segment] = $this->unePatrouillePosee(['light_fighter' => 5], 120, -80, $chezMoi->galaxy, $chezMoi->system);
        $this->planetService->addUnit('battle_ship', 60);
        $this->planetService->addResources(new Resources(0, 0, 5_000_000, 0));
        $this->planetService->reloadPlanet();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('battle_ship'), 60);
        $attaque = resolve(SpatialAttackOrder::class)->launch($this->planetService, $flotte, FrozenPatrolTarget::of($patrouille), 10.0, (int)now()->timestamp);

        $arrivee = (int)now()->timestamp - 1;
        DB::table('fleet_missions')->where('id', $attaque->id)->update(['time_arrival' => $arrivee]);
        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Destroyed->value, $patrouille->state->value ?? $patrouille->state, 'Premisse : la bataille a eu lieu et la patrouille est tombee.');

        $retour = FleetMission::query()->where('user_id', $this->currentUserId)->where('id', '>', $attaque->id)->orderByDesc('id')->first();
        $this->assertNotNull($retour, 'Premisse : l attaquante est rentree, la bataille a donc ete reglee.');
        $pertesDeLAttaquante = ['battle_ship' => 60 - (int)$retour->battle_ship];
        $pertesDeLaPatrouille = ['light_fighter' => 5];

        $prefixe = 'battle:spatial:' . $attaque->id . ':';
        $evenements = $this->evenementsSous($prefixe);
        $this->assertCount(2, $evenements, 'Une bataille en espace libre doit produire un evenement par flotte, et aucun pour le site : ' . implode(', ', array_keys($evenements)));

        $attaquante = $evenements[$prefixe . CombatParticipantKey::forFleet((int)$attaque->id)] ?? null;
        $this->assertNotNull($attaquante, 'L attaquante n a pas son evenement sous la clef de sa mission.');
        $this->assertSame($this->currentUserId, (int)$attaquante->player_id);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $attaquante->status, 'La bataille est restee en attente : ' . (string)$attaquante->reason);
        $this->assertSame($this->valeurMilitaireDe($pertesDeLaPatrouille), (int)$attaquante->destroyed_value, 'Les « detruits » de l attaquante ne sont pas la valeur de la patrouille abattue.');
        $this->assertSame($this->valeurMilitaireDe($pertesDeLAttaquante), (int)$attaquante->lost_value);

        $defenseur = $evenements[$prefixe . CombatParticipantKey::forFleet((int)$segment->id)] ?? null;
        $this->assertNotNull($defenseur, 'La patrouille n a pas son evenement sous la clef de son segment.');
        $this->assertSame((int)$patrouille->user_id, (int)$defenseur->player_id);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $defenseur->status);
        $this->assertSame($this->valeurMilitaireDe($pertesDeLaPatrouille), (int)$defenseur->lost_value, 'Les « perdus » de la patrouille ne sont pas la valeur de ses chasseurs.');
        $this->assertSame($this->valeurMilitaireDe($pertesDeLAttaquante), (int)$defenseur->destroyed_value);
    }

    /**
     * **L instant du fait est l arrivee de l attaquante, jamais l heure du reglement** : une collecte ouverte apres
     * l arrivee, mais avant que le travailleur ne regle la bataille, ne la voit pas.
     */
    public function testABattleInFreeSpaceIsCollectedByItsArrivalNeverByItsSettlementTime(): void
    {
        $chezMoi = $this->planetService->getPlanetCoordinates();
        [$patrouille] = $this->unePatrouillePosee(['light_fighter' => 5], 120, -80, $chezMoi->galaxy, $chezMoi->system);
        $this->planetService->addUnit('battle_ship', 60);
        $this->planetService->addResources(new Resources(0, 0, 5_000_000, 0));
        $this->planetService->reloadPlanet();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('battle_ship'), 60);
        $attaque = resolve(SpatialAttackOrder::class)->launch($this->planetService, $flotte, FrozenPatrolTarget::of($patrouille), 10.0, (int)now()->timestamp);

        $arrivee = (int)now()->timestamp - 1;
        DB::table('fleet_missions')->where('id', $attaque->id)->update(['time_arrival' => $arrivee]);
        $this->activerLesCumulsDepuis($arrivee + 1);
        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Destroyed->value, $patrouille->state->value ?? $patrouille->state, 'Premisse : la bataille a eu lieu.');
        $this->assertSame([], $this->evenementsSous('battle:spatial:' . $attaque->id . ':'), 'Une bataille arrivee avant l ouverture de la collecte a ete comptee sur l heure de son reglement.');
    }
}
