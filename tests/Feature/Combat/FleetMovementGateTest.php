<?php

namespace Tests\Feature\Combat;

use ArrayObject;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\MovementLocksOutdated;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameMissions\AttackMission;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
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
    public function testTheDecisionsAreReachableOnlyBehindTheGate(): void
    {
        // **Le type impose ce qu'une garde de texte ne faisait que constater.** Une decision
        // publique peut etre appelee avec un modele jamais relu ; privee, son seul appelant est
        // l'entree qui prend la porte.
        foreach ([
            [FleetMissionService::class, 'recallIfNothingHoldsIt'],
            [AcsDefendMission::class, 'sendHomeWhenTheHoldIsOver'],
            [AcsDefendMission::class, 'holdIfTheBodyIsRallying'],
            [AcsDefendMission::class, 'turnBackIfTheCombatHasNoPlaceForIt'],
            [AttackMission::class, 'enterOrLeaveTheCombat'],
        ] as [$classe, $methode]) {
            $this->assertTrue(
                (new ReflectionMethod($classe, $methode))->isPrivate(),
                "{$methode} can be called with a fleet mission that was never re-read under the lock."
            );
        }

        $service = $this->sourceOf(FleetMissionService::class);
        $acs = $this->sourceOf(AcsDefendMission::class);
        $attaque = $this->sourceOf(AttackMission::class);

        $appels = [
            'le rappel' => [$service, 'resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue): void { $this->recallIfNothingHoldsIt($tenue); });'],
            'la retenue et le demi-tour' => [$acs, 'public function settleArrival(FleetMission $mission, int $now): bool { return resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue) use ($now): bool {'],
            'l expiration' => [$acs, 'resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue): void { $this->sendHomeWhenTheHoldIsOver($tenue); });'],
            'l arrivee attaquante' => [$attaque, 'resolve(FleetMovementGate::class)->decideUnderLock( $mission, function (FleetMission $tenue) use ($defenderPlanet): void { $this->enterOrLeaveTheCombat($tenue, $defenderPlanet->getPlanetId()); } );'],
        ];

        foreach ($appels as $chemin => [$source, $appel]) {
            $this->assertStringContainsString($appel, $source, "The path « {$chemin} » no longer goes through the movement gate.");
        }

        $this->assertStringContainsString('$defense->settleArrival($mission,', $service, 'The worker no longer enters the ACS arrival through its single entry.');
    }

    /**
     * Un lien qui a change sous la porte est tenu a la reprise, jamais decide sans l'etre.
     *
     * ## Pourquoi relacher plutot que prendre le verrou manquant
     *
     * La porte calcule ce qu'elle tient depuis le modele recu, avant de relire la mission — l'ordre
     * global l'impose. Si une jointure a change l'union entre-temps, la porte tient l'ancienne et
     * s'apprete a decider sur la nouvelle. Prendre ce verrou-la maintenant, ce serait le prendre
     * **apres** la mission : l'ordre inverse, et l'interblocage qu'il ouvre. Elle relache tout et
     * recommence depuis la barriere, avec le lien a jour.
     */
    public function testALinkThatChangedUnderTheGateIsHeldOnTheRetry(): void
    {
        $this->outsideAnyTransaction(function (): void {
            [, $mission] = $this->aCombatAndAFleet();
            $union = $this->aUnionFor($mission);

            // Le modele que l'appelant tient ne connait aucune union ; la ligne, si.
            $perime = FleetMission::query()->findOrFail($mission->id);
            DB::table('fleet_missions')->where('id', $mission->id)->update(['union_id' => $union->id]);

            $unionsDemandees = [];
            DB::listen(function ($requete) use (&$unionsDemandees): void {
                if (str_contains($requete->sql, '"fleet_unions"')) {
                    $unionsDemandees[] = $requete->bindings;
                }
            });

            $vu = (new FleetMovementGate())->decideUnderLock(
                $perime,
                fn (FleetMission $tenue): int|null => $tenue->union_id === null ? null : (int)$tenue->union_id
            );

            $this->assertSame($union->id, $vu, 'The decision did not see the current union.');
            $this->assertContains([$union->id], $unionsDemandees, 'The gate decided on a union it never held.');
        });
    }

    /**
     * Un combat rattache sous la porte est tenu a la reprise, comme une union.
     *
     * L'arrivee pose `combat_instance_id` sur la mission ; si elle passe entre le calcul des
     * instances a tenir et la relecture, la porte tient un ensemble sans ce combat et deciderait
     * sur un combat jamais verrouille — le combat que la barriere designe n'est pas toujours celui
     * de la mission, quand la barriere a deja ete levee.
     */
    public function testACombatBoundUnderTheGateIsHeldOnTheRetry(): void
    {
        $this->outsideAnyTransaction(function (): void {
            [$combat, $mission] = $this->aCombatAndAFleet();

            // Aucune barriere ne designe ce combat : seule la mission le nomme, et le modele de
            // l'appelant ne le sait pas encore.
            $perime = FleetMission::query()->findOrFail($mission->id);
            DB::table('fleet_missions')->where('id', $mission->id)->update(['combat_instance_id' => $combat->id]);

            $combatsDemandes = [];
            DB::listen(function ($requete) use (&$combatsDemandes): void {
                if (str_contains($requete->sql, '"combat_instances"')) {
                    $combatsDemandes[] = $requete->bindings;
                }
            });

            $vu = (new FleetMovementGate())->decideUnderLock(
                $perime,
                fn (FleetMission $tenue): int|null => $tenue->combat_instance_id === null ? null : (int)$tenue->combat_instance_id
            );

            $this->assertSame($combat->id, $vu, 'The decision did not see the current combat.');
            $this->assertContains([$combat->id], $combatsDemandes, 'The gate decided on a combat it never held.');
        });
    }

    /**
     * Des liens qui changent plus vite que la porte ne les rattrape finissent par la faire refuser.
     *
     * Une reprise sans borne tournerait pour toujours sous une jointure qui ne s'arrete pas ; une
     * reprise absente deciderait sur un lien jamais tenu. Entre les deux, un nombre fini d'essais,
     * puis un refus que l'appelant voit.
     */
    public function testLinksThatKeepChangingExhaustTheRetriesInsteadOfDecidingOnAnUnheldOne(): void
    {
        $this->outsideAnyTransaction(function (): void {
            $this->linksThatKeepChangingExhaustTheRetries();
        });
    }

    private function linksThatKeepChangingExhaustTheRetries(): void
    {
        [, $mission] = $this->aCombatAndAFleet();

        $unions = [];
        for ($i = 0; $i < 5; $i++) {
            $unions[] = $this->aUnionFor($mission)->id;
        }

        // A chaque prise des unions, la ligne change encore d'union. L'ecouteur se desarme a la
        // fin de l'essai : il reste enregistre sur la connexion. L'etat vit dans un objet, pour
        // qu'une analyse statique ne conclue pas qu'il ne change jamais.
        $course = new ArrayObject(['actif' => true, 'passages' => 0]);
        DB::listen(function ($requete) use ($course, $unions, $mission): void {
            $passages = (int)$course['passages'];

            if ($course['actif'] === true && str_contains($requete->sql, '"fleet_unions"') && $passages < count($unions)) {
                DB::table('fleet_missions')->where('id', $mission->id)->update(['union_id' => $unions[$passages]]);
                $course['passages'] = $passages + 1;
            }
        });

        $decide = false;

        // **Renoncer ne se fait jamais en silence** : le travail sera repris au passage suivant, et
        // l'exploitation doit savoir qu'un lien change plus vite que la porte ne le rattrape.
        Log::partialMock()->shouldReceive('warning')->once();

        try {
            (new FleetMovementGate())->decideUnderLock($mission, function (FleetMission $tenue) use (&$decide): int {
                $decide = true;

                return $tenue->id;
            });
            $this->fail('The gate never gave up on links that kept changing.');
        } catch (MovementLocksOutdated $refus) {
            $this->assertSame($mission->id, $refus->fleetMissionId);
            $this->assertSame('l union', $refus->lien);
        } finally {
            $course['actif'] = false;
        }

        $this->assertFalse($decide, 'The gate decided on a link it never held.');
        $this->assertGreaterThan(1, $course['passages'], 'The gate refused on the first divergence instead of retrying from the barrier.');
    }

    /**
     * Imbriquee dans une transaction qu'elle ne possede pas, la porte ne recommence jamais.
     *
     * ## Pourquoi « relacher tout » serait un mensonge ici
     *
     * Un retour au point de sauvegarde ne relache pas les verrous pris avant ce point : MariaDB les
     * garde jusqu'a la fin de la transaction exterieure. Une porte imbriquee qui recommencerait
     * tiendrait encore l'ancien lien pendant qu'elle prend le nouveau, dans un ordre qu'elle ne
     * controle plus. Elle fait donc une seule prise, et une divergence remonte au proprietaire de
     * la transaction — avec le signal d'exploitation.
     */
    public function testNestedInATransactionItDoesNotOwnTheGateNeverRetries(): void
    {
        [, $mission] = $this->aCombatAndAFleet();
        $union = $this->aUnionFor($mission);

        $perime = FleetMission::query()->findOrFail($mission->id);
        DB::table('fleet_missions')->where('id', $mission->id)->update(['union_id' => $union->id]);

        $prises = 0;
        DB::listen(function ($requete) use (&$prises): void {
            if (str_contains($requete->sql, '"fleet_unions"')) {
                $prises++;
            }
        });

        // Le signal d'exploitation part aussi quand c'est le proprietaire de la transaction qui
        // apprend qu'il decide sur un lien qu'il ne tient pas.
        Log::partialMock()->shouldReceive('warning')->once();

        // La porte de production : racine au niveau zero. L'essai est deja dans sa transaction.
        $this->assertGreaterThan(0, DB::transactionLevel(), 'The test is not inside a transaction: nothing would be proved about nesting.');

        try {
            (new FleetMovementGate())->decideUnderLock($perime, fn (FleetMission $tenue): int => $tenue->id);
            $this->fail('A nested gate decided on a link it never held.');
        } catch (MovementLocksOutdated $refus) {
            $this->assertSame('l union', $refus->lien);
        }

        $this->assertSame(1, $prises, 'A nested gate retried from the barrier while the outer transaction still held the old locks.');
    }

    /**
     * Une union que la decision va toucher est tenue, meme si la flotte n'y est pas encore.
     *
     * La jointure ecrit le lien vers une union que la mission ne porte pas : la porte ne peut pas
     * la deduire. L'appelant la nomme, et elle entre dans la famille des unions a son rang.
     */
    public function testAUnionTheDecisionWillTouchIsHeldEvenIfTheFleetIsNotInItYet(): void
    {
        [, $mission] = $this->aCombatAndAFleet();
        $visee = $this->aUnionFor($mission);

        $this->assertNull($mission->union_id);

        $unionsDemandees = [];
        DB::listen(function ($requete) use (&$unionsDemandees): void {
            if (str_contains($requete->sql, '"fleet_unions"')) {
                $unionsDemandees[] = $requete->bindings;
            }
        });

        (new FleetMovementGate())->decideUnderLock($mission, fn (FleetMission $tenue): int => $tenue->id, [$visee->id]);

        $this->assertContains([$visee->id], $unionsDemandees, 'The union a join is about to enter was not held.');
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

    /**
     * Personne ne peut dire a la porte ou est la racine : c'est un fait de la base.
     *
     * Une premiere version acceptait un niveau « racine » a la construction. Un essai enveloppe
     * dans sa transaction pouvait ainsi faire passer un point de sauvegarde pour une racine —
     * et MariaDB, lui, aurait garde les verrous du niveau exterieur pendant la reprise.
     */
    public function testNobodyCanTellTheGateWhereTheRootIs(): void
    {
        $constructeur = (new ReflectionClass(FleetMovementGate::class))->getConstructor();

        $this->assertTrue(
            $constructeur === null || $constructeur->getNumberOfParameters() === 0,
            'The gate accepts a construction parameter: a caller could redefine what a root transaction is.'
        );

        $source = $this->sourceOf(FleetMovementGate::class);
        $this->assertStringContainsString('if (DB::transactionLevel() > 0) {', $source, 'The gate no longer decides root or nested from the database fact alone.');
        $this->assertStringNotContainsString('rootLevel', $source);
    }

    /**
     * Joue l'essai hors de toute transaction : la porte y possede la sienne et peut recommencer.
     *
     * ## Pourquoi sortir de l'enveloppe du banc
     *
     * Le banc enveloppe chaque essai dans une transaction, et la porte, imbriquee, ne recommence
     * jamais. Pour eprouver la reprise il faut une vraie racine — pas un niveau declare. Ce que
     * l'essai ecrit est donc reellement valide dans la base ; tout est efface ensuite, dans l'ordre
     * des cles etrangeres, et l'enveloppe du banc est rouverte pour que le demontage la trouve.
     *
     * @param Closure(): void $essai
     */
    private function outsideAnyTransaction(Closure $essai): void
    {
        $tables = [
            'combat_fleet_dispositions',
            'combat_participants',
            'celestial_body_combat_barriers',
            'combat_instances',
            'fleet_missions',
            'fleet_unions',
            'planets',
            'users',
        ];

        $plafonds = [];
        foreach ($tables as $table) {
            $plafonds[$table] = (int)DB::table($table)->max('id');
        }

        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel(), 'The test is still inside a transaction: the gate would not own its own.');

        try {
            $essai();
        } finally {
            foreach ($tables as $table) {
                DB::table($table)->where('id', '>', $plafonds[$table])->delete();
            }

            DB::beginTransaction();
        }
    }

    private function aUnionFor(FleetMission $mission): FleetUnion
    {
        return FleetUnion::create([
            'user_id' => $mission->user_id,
            'name' => null,
            'galaxy_to' => $mission->galaxy_to,
            'system_to' => $mission->system_to,
            'position_to' => $mission->position_to,
            'planet_type_to' => $mission->type_to,
            'time_arrival' => $mission->time_arrival,
            'max_fleets' => 16,
            'max_players' => 5,
        ]);
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
