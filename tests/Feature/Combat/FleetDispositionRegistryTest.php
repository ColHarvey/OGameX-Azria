<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Exceptions\ContradictoryFleetDisposition;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Models\CombatFleetDisposition;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Le registre des mouvements : une decision par flotte, executee une seule fois.
 *
 * ## Les deux idempotences, et pourquoi elles ne sont pas la meme
 *
 * **A l'ecriture** : une fermeture rejouee ne doit pas remplacer une decision deja prise. La
 * premiere raison prononcee est celle que le joueur lira, et une seconde ecriture n'aurait aucune
 * raison d'etre plus juste — elle effacerait seulement la trace de ce qui a ete decide.
 *
 * **A la consommation** : deux travailleurs simultanes ne doivent pas creer deux retours. Le second
 * trouve la ligne consommee, et ne refait rien.
 *
 * Ces deux garanties vivent au meme endroit — la ligne et sa colonne de consommation — precisement
 * pour qu'elles ne puissent pas se contredire.
 */
class FleetDispositionRegistryTest extends TestCase
{
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Un rejeu de la meme decision ne fait rien, et n'en cree pas une seconde.
     */
    public function testReplayingTheSameDecisionChangesNothing(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        $registre = new FleetDispositionRegistry();

        $registre->record($combat, $mission->id, CombatReasonCode::PlayerLimitReached, 1_700_000_100, FleetDispositionKind::ReturnToOrigin);
        $registre->record($combat, $mission->id, CombatReasonCode::PlayerLimitReached, 1_700_000_100, FleetDispositionKind::ReturnToOrigin);

        $this->assertSame(1, CombatFleetDisposition::query()->where('fleet_mission_id', $mission->id)->count(), 'A replay created a second decision.');

        $decidee = $registre->pendingFor($mission);
        $this->assertNotNull($decidee);
        $this->assertSame(CombatReasonCode::PlayerLimitReached, $decidee->reason);
        $this->assertSame(1_700_000_100, (int)$decidee->decided_at);
    }

    /**
     * Deux decisions differentes pour une meme flotte s'arretent, champ par champ.
     *
     * ## Ce que la cle unique ne prouvait pas
     *
     * `firstOrCreate()` rend la ligne existante sans regarder son contenu. La contrainte empeche
     * bien deux lignes ; elle ne dit rien de ce que la ligne gagnante contient. « La premiere
     * ecriture a forcement raison » devenait donc une regle du systeme, et un desaccord — course
     * que l'ordre des verrous aurait du fermer, ou reparation manuelle incoherente — disparaissait
     * en silence.
     *
     * Chaque champ est eprouve separement : comparer les quatre en bloc laisserait passer trois
     * divergences sur quatre.
     */
    public function testTwoDifferentDecisionsForOneFleetStop(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        $autre = $this->anotherCombat($combat);
        $registre = new FleetDispositionRegistry();

        $divergences = [
            'le combat' => [$autre, CombatReasonCode::PlayerLimitReached, 1_700_000_100],
            'la raison' => [$combat, CombatReasonCode::RallyClosed, 1_700_000_100],
            'l instant de decision' => [$combat, CombatReasonCode::PlayerLimitReached, 1_700_000_200],
        ];

        foreach ($divergences as $champ => [$sur, $raison, $instant]) {
            $registre->record($combat, $mission->id, CombatReasonCode::PlayerLimitReached, 1_700_000_100, FleetDispositionKind::ReturnToOrigin);

            try {
                $registre->record($sur, $mission->id, $raison, $instant, FleetDispositionKind::ReturnToOrigin);
                $this->fail("A contradictory decision on « {$champ} » was accepted as a replay.");
            } catch (ContradictoryFleetDisposition $refus) {
                $this->assertSame($champ, $refus->champ);
                $this->assertSame($mission->id, $refus->fleetMissionId);
            }

            // La decision inscrite n'a pas bouge : le refus n'ecrit rien.
            $decidee = $registre->pendingFor($mission);
            $this->assertNotNull($decidee);
            $this->assertSame($combat->id, (int)$decidee->combat_instance_id);
            $this->assertSame(CombatReasonCode::PlayerLimitReached, $decidee->reason);
            $this->assertSame(1_700_000_100, (int)$decidee->decided_at);

            CombatFleetDisposition::query()->where('fleet_mission_id', $mission->id)->delete();
        }
    }

    /**
     * Le mouvement s'execute une seule fois, et le second passage le sait.
     */
    public function testTheMovementHappensOnlyOnce(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        $registre = new FleetDispositionRegistry();

        $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_100, FleetDispositionKind::ReturnToOrigin);

        $decidee = $registre->pendingFor($mission);
        $this->assertNotNull($decidee);

        $faits = 0;
        $compter = function () use (&$faits): void {
            $faits++;
        };

        $this->assertTrue($registre->consume($decidee, 1_700_000_300, $compter), 'The first pass did not execute the movement.');
        $this->assertFalse($registre->consume($decidee, 1_700_000_400, $compter), 'The second pass executed the movement again.');

        $this->assertSame(1, $faits, 'The movement happened twice.');
        $this->assertNull($registre->pendingFor($mission), 'A consumed disposition is still pending.');
        $this->assertSame(1_700_000_300, (int)CombatFleetDisposition::query()->where('fleet_mission_id', $mission->id)->value('consumed_at'));
    }

    /**
     * Une flotte sans decision n'en invente pas.
     */
    public function testAFleetWithNoDecisionHasNothingPending(): void
    {
        [, $mission] = $this->aCombatAndAFleet();

        $this->assertNull((new FleetDispositionRegistry())->pendingFor($mission));
    }

    /**
     * @return array{0: CombatInstance, 1: FleetMission}
     */
    private function aCombatAndAFleet(): array
    {
        $joueur = User::factory()->create();
        $corps = $this->aBodyOf($joueur);

        $mission = FleetMission::forceCreate([
            'user_id' => $joueur->id,
            'planet_id_from' => $corps->id,
            'type_from' => 1,
            'planet_id_to' => $corps->id,
            'type_to' => 1,
            'galaxy_to' => $corps->galaxy,
            'system_to' => $corps->system,
            'position_to' => $corps->planet,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        $combat = CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $mission->id,
            'target_planet_id' => $corps->id,
            'target_type' => 1,
            'galaxy' => $corps->galaxy,
            'system' => $corps->system,
            'position' => $corps->planet,
            'started_at' => 1_700_000_000,
        ]);

        return [$combat, $mission];
    }

    private function anotherCombat(CombatInstance $premier): CombatInstance
    {
        return CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $premier->mission_id,
            'target_planet_id' => $premier->target_planet_id,
            'target_type' => 1,
            'galaxy' => $premier->galaxy,
            'system' => $premier->system,
            'position' => $premier->position + 1,
            'started_at' => 1_700_000_000,
        ]);
    }

    private function aBodyOf(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 9,
            'system' => 300 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
