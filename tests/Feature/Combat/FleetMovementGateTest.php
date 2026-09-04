<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\AcsDefendMission;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * La porte unique des mouvements : une section critique, un ordre de verrous, une relecture.
 *
 * ## Le defaut qu'elle ferme
 *
 * Trois chemins decidaient du sort d'une meme flotte sans se voir : le rappel du joueur, le
 * demi-tour d'une refusee, l'expiration du stationnement. Chacun lisait un modele charge par son
 * appelant, puis ecrivait. Deux d'entre eux pouvaient donc creer **deux missions retour pour une
 * seule flotte** — les vaisseaux existaient deux fois.
 *
 * ## Ce que ces essais prouvent, et ce qu'ils ne prouvent pas
 *
 * `lockForUpdate()` ne compile a rien sous SQLite. Ce qui est reellement observable ici est la
 * **relecture** — et c'est elle qui porte la correction : decider sur la ligne, pas sur le souvenir
 * qu'on en avait. La forme des requetes et l'ordre des verrous se lisent dans la source et dans le
 * journal des requetes. **La preuve d'interblocage et de stabilite est MariaDB.**
 */
class FleetMovementGateTest extends TestCase
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
     * La decision se prend sur la ligne, pas sur le modele que l'appelant tenait.
     *
     * C'est toute la correction. Un modele charge avant la porte decrit un passe : entre son
     * chargement et l'ecriture, un demi-tour a pu renvoyer la flotte, une retenue a pu l'inscrire,
     * un reglement a pu la traiter. Decider sur lui accordait un second retour a une flotte deja
     * partie.
     */
    public function testTheDecisionIsTakenOnTheRowAndNotOnTheModelHeldByTheCaller(): void
    {
        [, $mission] = $this->aCombatAndAFleet();

        // Le modele que l'appelant tient. Rien ne le previendra de ce qui suit.
        $perime = FleetMission::query()->findOrFail($mission->id);

        // Un autre acteur renvoie la flotte pendant ce temps.
        DB::table('fleet_missions')->where('id', $mission->id)->update([
            'processed' => 1,
            'time_holding' => 0,
        ]);

        $vu = (new FleetMovementGate())->decideUnderLock($perime, fn (FleetMission $tenue): array => [
            (int)$tenue->processed,
            (int)$tenue->time_holding,
        ]);

        $this->assertSame(0, (int)$perime->processed, 'The stale model was expected to still describe the past.');
        $this->assertSame([1, 0], $vu, 'The gate decided on the caller model instead of re-reading the row.');
    }

    /**
     * Une mission disparue arrete l'operation au lieu de decider sur rien.
     */
    public function testAMissionThatDisappearedStopsTheOperation(): void
    {
        [, $mission] = $this->aCombatAndAFleet();
        $perime = FleetMission::query()->findOrFail($mission->id);

        DB::table('fleet_missions')->where('id', $mission->id)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La mission ' . $mission->id . ' a disparu');

        (new FleetMovementGate())->decideUnderLock($perime, function (FleetMission $tenue): void {
            $this->fail('A movement was decided for a mission that no longer exists.');
        });
    }

    /**
     * Les lignes sont prises dans l'ordre global, et la barriere en premier.
     *
     * ## Pourquoi le journal des requetes plutot que la seule source
     *
     * Une garde de source montre que le code **contient** les verrous ; elle ne montre pas dans quel
     * ordre ils sont demandes a l'execution — une condition, une sortie anticipee ou un appel
     * intermediaire suffiraient a changer l'ordre reel sans toucher aux lignes verrouillees. Le
     * journal des requetes, lui, observe l'execution.
     *
     * La barriere en premier n'est pas un detail de gout : c'est l'ordre que la migration fixe et que
     * le reglement, la fermeture et l'annulation suivent. Deux transactions qui prennent les memes
     * lignes dans deux sens s'attendent mutuellement.
     */
    public function testTheRowsAreTakenInTheGlobalOrderWithTheBarrierFirst(): void
    {
        [$combat, $mission, $corps] = $this->aCombatAndAFleet();
        $this->aBarrierOver($corps, $combat);

        $tables = [];
        DB::listen(function ($requete) use (&$tables): void {
            foreach ([
                'celestial_body_combat_barriers',
                'combat_instances',
                'fleet_unions',
                'fleet_missions',
            ] as $table) {
                if (str_contains($requete->sql, '"' . $table . '"') || str_contains($requete->sql, '`' . $table . '`')) {
                    $tables[] = $table;

                    return;
                }
            }
        });

        (new FleetMovementGate())->decideUnderLock($mission, fn (FleetMission $tenue): int => $tenue->id);

        // Chaque famille apparait, et une seule fois chacune : la porte ne repasse pas.
        $this->assertSame(
            ['celestial_body_combat_barriers', 'combat_instances', 'fleet_unions', 'fleet_missions'],
            $tables,
            'The gate no longer takes the rows in the global order: barrier, instance, union, mission.'
        );
    }

    /**
     * Le combat qui tient le corps vise est pris, meme quand la flotte n'y est pas encore liee.
     *
     * Une Defense ACS qui se pose sur un corps en ralliement n'a ni `combat_instance_id` ni
     * inscription : c'est precisement l'instant ou son sort se decide. Ne verrouiller que ses liens
     * existants laisserait la fermeture avancer pendant que la porte delibere.
     */
    public function testTheCombatHoldingTheTargetBodyIsTakenEvenWithoutAnyLinkYet(): void
    {
        [$combat, $mission, $corps] = $this->aCombatAndAFleet();
        $this->aBarrierOver($corps, $combat);

        $this->assertNull($mission->combat_instance_id);
        $this->assertSame(0, CombatParticipant::query()->where('fleet_mission_id', $mission->id)->count());

        $liaisons = [];
        DB::listen(function ($requete) use (&$liaisons): void {
            if (str_contains($requete->sql, 'combat_instances')) {
                $liaisons[] = $requete->bindings;
            }
        });

        (new FleetMovementGate())->decideUnderLock($mission, fn (FleetMission $tenue): int => $tenue->id);

        $this->assertContains(
            [$combat->id],
            $liaisons,
            'The gate deliberates without holding the combat that owns the target body.'
        );
    }

    /**
     * Un travailleur qui tient une lecture plus ancienne ne renvoie pas une flotte deja rappelee.
     *
     * ## La troisieme course
     *
     * Deux travailleurs peuvent toucher la meme mission dans la meme seconde, et chacun l'a lue
     * avant l'autre. Le premier — ou le rappel du joueur — la fait partir ; le second continue avec
     * son souvenir : stationnement en cours, mission non traitee, et il cree **un second retour**.
     *
     * Le travailleur est appele ici par sa methode publique, avec un modele charge avant le rappel :
     * c'est exactement ce que tient un processus qui a lu la ligne une seconde plus tot.
     */
    public function testAWorkerHoldingAnOlderReadDoesNotSendAnAlreadyRecalledFleetHomeAgain(): void
    {
        [, $mission] = $this->aCombatAndAFleet();

        $perime = FleetMission::query()->findOrFail($mission->id);

        // Le joueur rappelle entre-temps : la ligne decrit desormais une flotte partie.
        DB::table('fleet_missions')->where('id', $mission->id)->update([
            'processed' => 1,
            'canceled' => 1,
            'time_holding' => 0,
        ]);

        $defense = GameMissionFactory::getMissionById(5, [
            'fleetMissionService' => resolve(FleetMissionService::class),
            'messageService' => resolve(MessageService::class),
        ]);

        $this->assertInstanceOf(AcsDefendMission::class, $defense);

        $defense->process($perime);

        $this->assertSame(
            0,
            FleetMission::query()->where('parent_id', $mission->id)->count(),
            'A worker sent home a fleet that had already been recalled: its ships exist twice.'
        );
    }

    /**
     * Les trois chemins passent par la porte, et aucun ne decide en dehors d'elle.
     *
     * Une porte que l'un des trois contourne ne serait pas une section critique : c'est exactement
     * l'etat d'avant, ou chacun lisait son propre modele. Les deux decisions sont donc **privees**,
     * et leur seul appelant est la fermeture confiee a la porte.
     */
    public function testTheThreePathsDecideOnlyBehindTheGate(): void
    {
        $rappel = new ReflectionMethod(FleetMissionService::class, 'recallIfNothingHoldsIt');
        $this->assertTrue($rappel->isPrivate(), 'The recall decision is reachable without the gate.');

        $expiration = new ReflectionMethod(AcsDefendMission::class, 'sendHomeWhenTheHoldIsOver');
        $this->assertTrue($expiration->isPrivate(), 'The hold expiry decision is reachable without the gate.');

        $service = $this->sourceOf(FleetMissionService::class);
        $acs = $this->sourceOf(AcsDefendMission::class);

        $appels = [
            'le rappel' => [$service, 'resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue): void { $this->recallIfNothingHoldsIt($tenue); });'],
            'la retenue et le demi-tour' => [$service, 'resolve(FleetMovementGate::class)->decideUnderLock( $mission, function (FleetMission $tenue) use ($defense): bool {'],
            'l expiration' => [$acs, 'resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue): void { $this->sendHomeWhenTheHoldIsOver($tenue); });'],
        ];

        foreach ($appels as $chemin => [$source, $appel]) {
            $this->assertStringContainsString($appel, $source, "The path « {$chemin} » no longer goes through the movement gate.");
        }
    }

    /**
     * @param class-string $classe
     */
    private function sourceOf(string $classe): string
    {
        $fichier = (new ReflectionClass($classe))->getFileName();
        $this->assertNotFalse($fichier);

        $source = preg_replace('/\s+/', ' ', (string)file_get_contents($fichier));
        $this->assertNotNull($source);

        return $source;
    }

    /**
     * @return array{0: CombatInstance, 1: FleetMission, 2: Planet}
     */
    private function aCombatAndAFleet(): array
    {
        $joueur = User::factory()->create();
        $origine = $this->aBodyOf($joueur);
        $corps = $this->aBodyOf(User::factory()->create());

        $mission = FleetMission::forceCreate([
            'user_id' => $joueur->id,
            'planet_id_from' => $origine->id,
            'type_from' => 1,
            'planet_id_to' => $corps->id,
            'type_to' => 1,
            'galaxy_to' => $corps->galaxy,
            'system_to' => $corps->system,
            'position_to' => $corps->planet,
            'mission_type' => 5,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'time_holding' => 300,
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

        return [$combat, $mission, $corps];
    }

    private function aBarrierOver(Planet $corps, CombatInstance $combat): void
    {
        CelestialBodyCombatBarrier::query()->create([
            'target_body_id' => $corps->id,
            'combat_instance_id' => $combat->id,
            'opened_at' => 1_700_000_000,
            'owned_through_effect_at' => 1_700_000_600,
        ]);
    }

    private function aBodyOf(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 8,
            'system' => 400 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
