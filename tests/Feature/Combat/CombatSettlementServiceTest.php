<?php

namespace Tests\Feature\Combat;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\Combat\Allocation\CappedLoot;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\LootAllocator;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\MismatchedCombatIdentity;
use OGame\Combat\Exceptions\MismatchedRuleVersionSet;
use OGame\Combat\Exceptions\UnsettleableAtThisScale;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\CombatResolutionService;
use OGame\Combat\Services\CombatRosterReader;
use OGame\Combat\Services\CombatSettlementOutcome;
use OGame\Combat\Services\CombatSettlementService;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use ReflectionClass;
use RuntimeException;
use Tests\FleetDispatchTestCase;
use Throwable;

/**
 * Le reglement d'un combat durable : les huit scenarios du contrat, sur une vraie bataille.
 *
 * ## Le cycle entier, a chaque essai
 *
 * Flotte envoyee par la route, combat ouvert, ralliement clos — la cloture calcule la bataille et
 * l'ecrit — puis reglement a l'echeance. Aucun essai ne fabrique de resultat : celui qui est
 * regle est celui que la cloture a fige, relu depuis sa colonne.
 *
 * ## Ce que chaque scenario observe
 *
 * Jamais une valeur de retour seule. Ce qui compte est ce qui reste ecrit : le solde de la cible,
 * les colonnes de l'instance, la cargaison des retours, le rapport. Un reglement qui rendrait les
 * bons nombres sans les ecrire — ou qui les ecrirait deux fois — serait juste en memoire et faux
 * pour les joueurs.
 *
 * ## Ce que SQLite ne peut pas prouver ici
 *
 * Qu'une depense concurrente **attend** le verrou. SQLite n'a pas de verrou de ligne, et une seule
 * connexion ne peut pas en tenir deux. Les essais prouvent ce qui en depend et se laisse observer :
 * la cible est relue **dans** la transaction, apres la barriere, l'instance et les missions, et une
 * depense qui a gagne la course avant le verrou est honoree. Le blocage lui-meme est une preuve
 * MariaDB, a rejouer avant la candidature.
 */
class CombatSettlementServiceTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

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
     * Scenario 1 — solde inchange : l'applique vaut le potentiel, et tout est ecrit sur ce nombre.
     */
    public function testAnUnchangedBalancePaysThePotentialInFull(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        $potentiel = $this->potentialOf($combat);
        $this->assertGreaterThan(0, array_sum($potentiel), 'The battle produced no loot: nothing would be settled.');

        $avant = $this->stockOf($cible);
        $instant = (int)$combat->ends_at;

        $issue = $this->settleIt($combat, $instant);

        $this->assertTrue($issue->settled, 'The settlement did nothing: ' . $issue->reason);
        $this->assertNotNull($issue->loot);
        $this->assertTrue($issue->loot->wasPaidInFull());

        $apres = $this->stockOf($cible);
        foreach (['metal', 'crystal', 'deuterium'] as $composante) {
            $this->assertSame($avant[$composante] - $potentiel[$composante], $apres[$composante], "The target was not debited by exactly the potential on {$composante}.");
        }

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status);
        $this->assertSame($instant, $combat->loot_settled_at);
        $this->assertSame($instant, $combat->potential_loot_frozen_at);
        $this->assertSame($potentiel['metal'], $combat->potential_loot_metal);
        $this->assertSame($potentiel['metal'], $combat->applied_loot_metal);
        $this->assertSame($potentiel['crystal'], $combat->applied_loot_crystal);
        $this->assertSame($potentiel['deuterium'], $combat->applied_loot_deuterium);

        $rapport = BattleReport::query()->find($combat->battle_report_id);
        $this->assertNotNull($rapport, 'The instance does not point at the report that was written.');
        $this->assertSame($issue->battleReportId, $rapport->id);
        $this->assertSame($potentiel['metal'], $this->lootMetalOf($rapport));

        // La garnison a perdu ce que la bataille figee lui a fait perdre : le camp defenseur a bien
        // ete applique, et pas seulement le butin. **Les defenses reparees ne comptent pas** — une
        // part des ruines se releve, et c'est la resolution qui en tient compte.
        $bataille = BattleResultCodec::fromStorage($combat->battle_result);
        $perdus = $bataille->defenderUnitsLost->toArray();
        $reparees = $bataille->repairedDefenses->toArray();
        $this->assertArrayHasKey('rocket_launcher', $perdus, 'The garrison lost nothing in the frozen battle.');
        $this->assertSame(
            20 - $perdus['rocket_launcher'] + ($reparees['rocket_launcher'] ?? 0),
            (int)Planet::query()->whereKey($cible->getPlanetId())->value('rocket_launcher'),
            'The defences destroyed by the battle are still standing.'
        );

        // Le retour embarque ce que la flotte portait deja — le moteur y compte le carburant a bord —
        // plus sa part, et sa part est l'applique tout entier : il n'y a qu'une flotte.
        $cargaison = $this->cargoOf($combat, 0);
        $retour = $this->returnOf($missions[0]);
        $this->assertSame($cargaison['metal'] + $potentiel['metal'], (int)$retour->metal, 'The return does not carry exactly its cargo plus the applied loot.');
        $this->assertSame($cargaison['crystal'] + $potentiel['crystal'], (int)$retour->crystal);
        $this->assertSame($cargaison['deuterium'] + $potentiel['deuterium'], (int)$retour->deuterium);
    }

    /**
     * Scenario 2 — le defenseur a depense une composante : l'applique est plafonne la, et la seulement.
     *
     * Le resultat fige n'est pas touche : c'est lui qu'un rejeu doit retrouver.
     */
    public function testALowerBalanceOnOneComponentIsCappedThere(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        $potentiel = $this->potentialOf($combat);
        $this->assertGreaterThan(1_000, $potentiel['metal'], 'Not enough metal loot to leave a shortfall of a thousand.');

        $fige = $combat->battle_result;
        $this->setStockOf($cible, ['metal' => $potentiel['metal'] - 1_000]);

        $issue = $this->settleIt($combat, (int)$combat->ends_at);

        $this->assertTrue($issue->settled);
        $this->assertNotNull($issue->loot);
        $this->assertFalse($issue->loot->wasPaidInFull());
        $this->assertSame($potentiel['metal'] - 1_000, $issue->loot->applied->metal);
        $this->assertSame($potentiel['crystal'], $issue->loot->applied->crystal);
        $this->assertSame($potentiel['deuterium'], $issue->loot->applied->deuterium);
        $this->assertSame(1_000, $issue->loot->shortfall()->metal);
        $this->assertSame(0, $issue->loot->shortfall()->crystal);

        $apres = $this->stockOf($cible);
        $this->assertSame(0, $apres['metal'], 'The target kept metal it no longer had, or went negative.');

        $combat->refresh();
        $this->assertSame($potentiel['metal'], $combat->potential_loot_metal, 'The potential was overwritten by the applied.');
        $this->assertSame($potentiel['metal'] - 1_000, $combat->applied_loot_metal);
        $this->assertSame($fige, $combat->battle_result, 'The frozen battle result was rewritten by the settlement.');

        $retour = $this->returnOf($missions[0]);
        $this->assertSame($this->cargoOf($combat, 0)['metal'] + $potentiel['metal'] - 1_000, (int)$retour->metal);

        $rapport = BattleReport::query()->find($combat->battle_report_id);
        $this->assertNotNull($rapport);
        $this->assertSame($potentiel['metal'] - 1_000, $this->lootMetalOf($rapport), 'The report tells the potential, not what was taken.');
    }

    /**
     * Scenario 3 — cible videe : rien n'est pris, rien n'est debite, et le retour part quand meme.
     */
    public function testAnEmptiedTargetYieldsNothing(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        $potentiel = $this->potentialOf($combat);
        $this->setStockOf($cible, ['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);

        $issue = $this->settleIt($combat, (int)$combat->ends_at);

        $this->assertTrue($issue->settled);
        $this->assertNotNull($issue->loot);
        $this->assertTrue($issue->loot->applied->isNothing());
        $this->assertSame(['metal' => 0, 'crystal' => 0, 'deuterium' => 0], $this->stockOf($cible));

        $combat->refresh();
        $this->assertSame($potentiel['metal'], $combat->potential_loot_metal);
        $this->assertSame(0, $combat->applied_loot_metal);
        $this->assertSame(0, $combat->applied_loot_crystal);
        $this->assertSame(0, $combat->applied_loot_deuterium);

        // Le retour part avec ce que la flotte portait, et rien de plus.
        $cargaison = $this->cargoOf($combat, 0);
        $retour = $this->returnOf($missions[0]);
        $this->assertSame($cargaison, ['metal' => (int)$retour->metal, 'crystal' => (int)$retour->crystal, 'deuterium' => (int)$retour->deuterium], 'The return carries loot that was never taken.');

        $rapport = BattleReport::query()->find($combat->battle_report_id);
        $this->assertNotNull($rapport);
        $this->assertSame(0, $this->lootMetalOf($rapport));
    }

    /**
     * Scenario 4 — la cible a produit depuis la photographie : l'applique ne depasse jamais le potentiel.
     */
    public function testProductionSinceTheSnapshotNeverRaisesTheApplied(): void
    {
        [$combat, , $cible] = $this->anEngagedCombat();

        $potentiel = $this->potentialOf($combat);
        $this->setStockOf($cible, ['metal' => $potentiel['metal'] + 123_456]);

        $issue = $this->settleIt($combat, (int)$combat->ends_at);

        $this->assertTrue($issue->settled);
        $this->assertNotNull($issue->loot);
        $this->assertSame($potentiel['metal'], $issue->loot->applied->metal);
        $this->assertGreaterThan($potentiel['metal'], $issue->loot->remaining->metal);
        $this->assertSame(123_456, $this->stockOf($cible)['metal'], 'The production since the snapshot was looted too.');
    }

    /**
     * Scenario 5 — une depense qui a gagne la course avant le verrou est honoree.
     *
     * Elle passe par le vrai debit de production, pas par une ecriture directe : c'est le chemin
     * qu'une construction ou un envoi de flotte emprunterait entre la cloture et l'echeance.
     */
    public function testASpendThatWonTheRaceBeforeTheLockIsHonoured(): void
    {
        [$combat, , $cible] = $this->anEngagedCombat();

        $potentiel = $this->potentialOf($combat);
        $avant = $this->stockOf($cible);
        $cible->deductResources(new Resources($avant['metal'], 0, 0, 0));

        $issue = $this->settleIt($combat, (int)$combat->ends_at);

        $this->assertTrue($issue->settled);
        $this->assertNotNull($issue->loot);
        $this->assertSame(0, $issue->loot->applied->metal, 'Metal the defender had already spent was looted.');
        $this->assertSame($potentiel['crystal'], $issue->loot->applied->crystal);
        $this->assertSame(0, $this->stockOf($cible)['metal']);
    }

    /**
     * Une depense passee derriere un service de planete deja charge n'est pas ressuscitee.
     *
     * La fabrique de planetes est partagee et garde ses instances. Un travailleur qui a deja touche
     * ce corps dans la meme execution en garde un service en memoire ; si le reglement le
     * reutilisait, il debiterait bien la ligne, puis la resolution **sauverait ce modele** apres le
     * retrait des unites — reecrivant un solde d'avant la depense, moins le butin, et rendant au
     * defenseur ce qu'il a paye.
     */
    public function testASpendBehindAnAlreadyLoadedPlanetServiceIsNotResurrected(): void
    {
        [$combat, , $cible] = $this->anEngagedCombat();

        $potentiel = $this->potentialOf($combat);
        $avant = $this->stockOf($cible);

        // Le service est charge, puis la ligne change derriere lui.
        resolve(PlanetServiceFactory::class)->make($cible->getPlanetId(), true);
        DB::table('planets')->where('id', $cible->getPlanetId())->update(['metal' => $avant['metal'] - 50_000]);

        $issue = $this->settleIt($combat, (int)$combat->ends_at);

        $this->assertTrue($issue->settled);
        $this->assertSame($avant['metal'] - 50_000 - $potentiel['metal'], $this->stockOf($cible)['metal'], 'The settlement wrote back a balance from before the spend.');
    }

    /**
     * La cible est relue dans la transaction, apres la barriere, l'instance et les missions.
     *
     * C'est la moitie observable ici de « la depense concurrente attend le verrou » : l'ordre des
     * premieres lectures, celui que la migration de barriere fixe.
     */
    public function testTheTargetIsReadLastUnderTheFixedLockOrder(): void
    {
        [$combat] = $this->anEngagedCombat();

        $tables = [];
        $suivies = ['celestial_body_combat_barriers', 'combat_instances', 'fleet_missions', 'planets'];

        DB::listen(function (QueryExecuted $requete) use (&$tables, $suivies): void {
            foreach ($suivies as $table) {
                if (str_contains($requete->sql, '"' . $table . '"') && !in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        });

        $this->settleIt($combat, (int)$combat->ends_at);

        $this->assertSame($suivies, array_slice($tables, 0, 4), 'The settlement does not take its locks in the order the barrier migration fixes.');
    }

    /**
     * Les verrous sont declares la ou SQLite ne peut pas les montrer.
     *
     * `lockForUpdate()` ne compile a rien sous SQLite : une mutation qui retire le verrou des
     * missions laisse l'ordre des lectures intact et survit a l'essai precedent. La seule chose
     * observable ici est la source, et c'est ce que cet essai lit — comme l'essai de la fermeture
     * le fait deja pour la meme raison. La preuve reelle est MariaDB.
     */
    public function testTheLocksAreDeclaredWhereSqliteCannotShowThem(): void
    {
        $fichier = (new ReflectionClass(CombatSettlementService::class))->getFileName();
        $this->assertNotFalse($fichier);

        $source = preg_replace('/\s+/', ' ', (string)file_get_contents($fichier));
        $this->assertNotNull($source);

        $verrous = [
            'barriere' => "CelestialBodyCombatBarrier::query() ->where('combat_instance_id', \$combatInstanceId) ->lockForUpdate()",
            'instance' => 'CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()',
            'union' => 'FleetUnion::query()->whereKey($combat->union_id)->lockForUpdate()',
            'missions' => "->orderBy('id') ->lockForUpdate()",
            // Le nom de classe vient de la reflexion : ecrit d'un trait entre guillemets, il
            // ressemblerait a une cle de participant pour la garde qui traque celles ecrites a la main.
            'cible' => (new ReflectionClass(Planet::class))->getShortName() . '::query()->whereKey($combat->target_planet_id)->lockForUpdate()',
        ];

        foreach ($verrous as $quoi => $declaration) {
            $this->assertStringContainsString($declaration, $source, "The settlement no longer declares the lock on the {$quoi}.");
        }

        // **L'effectif se lit apres le verrou de la cible.** Le lecteur charge le corps a neuf ;
        // le charger avant le verrou donnerait un service d'avant une depense concurrente, que la
        // resolution sauverait apres le debit. SQLite ne verrouille rien, donc rien ne separerait
        // les deux ordres a l'execution : seule la source le montre.
        $this->assertLessThan(
            strpos($source, '$this->roster->forCombat($combat)'),
            strpos($source, $verrous['cible']),
            'The settlement reads its roster before locking the target: a concurrent spend could be resurrected.'
        );
    }

    /**
     * L'initiatrice mene le camp attaquant.
     *
     * Le moteur traite la premiere flotte comme celle qui mene — c'est elle qui donne le joueur
     * attaquant et la flotte principale, et c'est son repli qui gouverne. Un effectif range par
     * identifiant sans egard pour elle changerait la bataille des que l'initiatrice n'est pas la
     * plus ancienne : une union ou un rappel suffit.
     */
    public function testTheInitiatorLeadsTheAttackingSide(): void
    {
        [$combat] = $this->anEngagedCombat(2);

        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        $this->assertCount(2, $resultat->attackerFleetResults, 'Only one fleet fought: leading would mean nothing.');
        $this->assertSame(
            $combat->mission_id,
            $resultat->attackerFleetResults[0]->fleetMissionId,
            'The initiating fleet does not lead the attacking side.'
        );
    }

    /**
     * Scenario 6 — une panne au second retour n'ecrit rien : ni nombres, ni debit, ni retour, ni rapport.
     */
    public function testAFailureAtTheSecondReturnRollsEverythingBack(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat(2);

        $resultat = BattleResultCodec::fromStorage($combat->battle_result);
        $this->assertCount(2, $resultat->attackerFleetResults, 'The battle does not involve two fleets: no second return can fail.');

        $avant = $this->stockOf($cible);
        $rapports = BattleReport::query()->count();
        $retours = 0;

        $retourEnPanne = function () use (&$retours): void {
            $retours++;
            if ($retours === 2) {
                throw new RuntimeException('panne injectee au second retour');
            }
        };

        try {
            $this->settleIt($combat, (int)$combat->ends_at, $retourEnPanne);
            $this->fail('The injected failure did not propagate.');
        } catch (RuntimeException $panne) {
            $this->assertSame('panne injectee au second retour', $panne->getMessage());
        }

        $this->assertSame(2, $retours, 'The failure was injected before the second return was attempted.');
        $this->assertSame($avant, $this->stockOf($cible), 'The debit survived the rollback.');
        $this->assertSame($rapports, BattleReport::query()->count(), 'A report survived the rollback.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The combat is no longer settleable after a rolled-back attempt.');
        $this->assertNull($combat->potential_loot_frozen_at);
        $this->assertNull($combat->applied_loot_metal);
        $this->assertNull($combat->loot_settled_at);

        foreach ($missions as $mission) {
            $mission->refresh();
            $this->assertSame(0, (int)$mission->processed, "Mission {$mission->id} stayed processed after the rollback.");
            $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count(), "A return of mission {$mission->id} survived the rollback.");
        }
    }

    /**
     * Scenario 7 — relivraison apres le commit : rien n'est refait, et l'issue le dit.
     */
    public function testRedeliveryAfterCommitChangesNothing(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        $instant = (int)$combat->ends_at;
        $premiere = $this->settleIt($combat, $instant);
        $this->assertTrue($premiere->settled);

        $stock = $this->stockOf($cible);
        $rapports = BattleReport::query()->count();
        $this->assertSame(1, FleetMission::query()->where('parent_id', $missions[0]->id)->count());

        $seconde = $this->settleIt($combat, $instant + 60);

        $this->assertFalse($seconde->settled);
        $this->assertSame(CombatSettlementOutcome::REASON_ALREADY_SETTLED, $seconde->reason);
        $this->assertSame($stock, $this->stockOf($cible), 'The target was debited twice.');
        $this->assertSame($rapports, BattleReport::query()->count(), 'A second report was written.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $missions[0]->id)->count(), 'A second return was created.');

        $combat->refresh();
        $this->assertSame($instant, $combat->loot_settled_at, 'The settlement instant was overwritten by the redelivery.');
    }

    /**
     * Scenario 8 — un combat ouvert sous V1 se regle sous V1, meme quand une V2 est devenue courante.
     *
     * Le registre injecte porte une V2 courante qui **leve** des qu'on lui demande de repartir :
     * si le reglement la choisissait, il s'arreterait la. Il ne s'arrete pas, et les parts qu'il
     * ecrit sont celles de V1 — sur deux flottes, pour que la repartition ait quelque chose a dire.
     */
    public function testAV1CombatSettlesUnderV1WhenV2IsCurrent(): void
    {
        [$combat, $missions] = $this->anEngagedCombat(2);

        $v1 = new ExactLootAllocationV1();
        $v2 = $v1->version() . '_but_newer';
        $registre = LootAllocatorRegistry::of([$v1, $this->anAllocatorThatRefusesToWork($v2)], $v2);
        $this->assertSame($v2, $registre->currentVersion(), 'The fake V2 is not current: the test would prove nothing.');

        $service = new CombatSettlementService(resolve(CombatResolutionService::class), new CombatRosterReader(), $registre);
        $issue = $this->settleWith($service, $combat, (int)$combat->ends_at);

        $this->assertTrue($issue->settled);
        $this->assertNotNull($issue->shares);
        $this->assertNotNull($issue->loot);

        // La part de chaque retour est ce qu'il embarque au-dela de sa cargaison ; les parts font l'applique.
        $total = 0;
        foreach ($missions as $rang => $mission) {
            $total += (int)$this->returnOf($mission)->metal - $this->cargoOf($combat, $rang)['metal'];
        }
        $this->assertSame($issue->loot->applied->metal, $total, 'The returns do not carry exactly the applied metal between them.');

        $combat->refresh();
        $this->assertSame($v1->version(), $combat->loot_allocator_version);
    }

    /**
     * Une version gelee qui ne correspond plus au resultat est un refus type, pas une boucle.
     *
     * Le reglement s'arrete avant d'ecrire quoi que ce soit ; un travail relance retrouvera le meme
     * refus, et c'est a l'exploitation de trancher — jamais a un rejeu de deviner une version.
     */
    public function testAMismatchedFrozenVersionIsARefusalThatWritesNothing(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        DB::table('combat_instances')->where('id', $combat->id)->update(['loot_allocator_version' => 'exact_loot_allocation_v9']);
        $avant = $this->stockOf($cible);

        try {
            $this->settleIt($combat, (int)$combat->ends_at);
            $this->fail('A result computed under another allocator version was settled anyway.');
        } catch (Throwable $refus) {
            $this->assertInstanceOf(MismatchedRuleVersionSet::class, $refus);
        }

        $this->assertSame($avant, $this->stockOf($cible));

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertNull($combat->potential_loot_frozen_at);
        $this->assertSame(0, FleetMission::query()->where('parent_id', $missions[0]->id)->count());
    }

    /**
     * Avant l'echeance, le combat dure : le regler le couperait court.
     */
    public function testACombatStillFightingIsNotSettled(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        $avant = $this->stockOf($cible);

        $issue = $this->settleIt($combat, (int)$combat->ends_at - 1);

        $this->assertFalse($issue->settled);
        $this->assertSame(CombatSettlementOutcome::REASON_STILL_FIGHTING, $issue->reason);
        $this->assertSame($avant, $this->stockOf($cible), 'A combat still under way was looted.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $missions[0]->id)->count());

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
    }

    /**
     * Une bataille figee qui ne parle pas des flottes inscrites arrete le reglement.
     *
     * Tout vient de l'instance — le resultat de sa colonne, l'effectif de ses participants — mais
     * les deux ont ete ecrits a des moments differents. Une ligne de participant effacee entre les
     * deux ferait creer un retour a une flotte qui n'a pas combattu, ou en oublierait une qui l'a
     * fait. Le reglement s'arrete avant d'ecrire quoi que ce soit.
     */
    public function testAFrozenBattleThatDoesNotDescribeTheRegisteredFleetsIsRefused(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat(2);

        // La bataille figee porte deux flottes ; l'une n'est plus inscrite. C'est ce que produirait
        // une ligne de participant effacee entre la cloture et l'echeance.
        $this->assertCount(2, BattleResultCodec::fromStorage($combat->battle_result)->attackerFleetResults);

        $efface = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $missions[1]->id)
            ->delete();
        $this->assertSame(1, $efface, 'The non-initiating fleet was not registered: nothing would be missing.');

        $avant = $this->stockOf($cible);

        try {
            $this->settleIt($combat, (int)$combat->ends_at);
            $this->fail('A battle that does not describe the registered fleets was applied anyway.');
        } catch (Throwable $refus) {
            $this->assertInstanceOf(MismatchedCombatIdentity::class, $refus);
        }

        $this->assertSame($avant, $this->stockOf($cible));

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertNull($combat->potential_loot_frozen_at);
        $this->assertSame(0, FleetMission::query()->where('parent_id', $missions[0]->id)->count());
    }

    /**
     * Un combat actif sans barriere est une contradiction, pas un cas a poursuivre.
     *
     * La barriere est le « ce corps est pris » du systeme, et son unicite par corps empeche deux
     * combats de se debiter la meme cible. Sans elle, rien ne dit qu'un autre combat ne s'est pas
     * ouvert pendant celui-ci.
     */
    public function testAnActiveCombatWithoutItsBarrierIsRefused(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->delete();
        $avant = $this->stockOf($cible);

        try {
            $this->settleIt($combat, (int)$combat->ends_at);
            $this->fail('A combat whose body was no longer held was settled anyway.');
        } catch (Throwable $refus) {
            $this->assertInstanceOf(MismatchedCombatIdentity::class, $refus);
        }

        $this->assertSame($avant, $this->stockOf($cible));
        $this->assertSame(0, FleetMission::query()->where('parent_id', $missions[0]->id)->count());
    }

    /**
     * Un combat persiste en « resolving » n'est pas reapplique a l'aveugle.
     *
     * Cet etat n'existe qu'entre deux ecritures de la meme transaction : le trouver persiste veut
     * dire qu'une application s'est interrompue sans etre annulee, et rien ne dit ce qui a ete
     * ecrit. Reappliquer debiterait peut-etre une seconde fois.
     */
    public function testACombatStuckInResolvingIsNotReappliedBlindly(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        DB::table('combat_instances')->where('id', $combat->id)->update(['status' => CombatState::Resolving->value]);
        $avant = $this->stockOf($cible);

        try {
            $this->settleIt($combat, (int)$combat->ends_at);
            $this->fail('An interrupted application was replayed without anyone looking at it.');
        } catch (Throwable $refus) {
            $this->assertInstanceOf(RuntimeException::class, $refus);
            $this->assertStringContainsString('resolving', $refus->getMessage());
        }

        $this->assertSame($avant, $this->stockOf($cible), 'A combat stuck mid-application was debited again.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $missions[0]->id)->count());
    }

    /**
     * Une fortune que le stockage ne distingue plus arrete le reglement au lieu de l'approcher.
     *
     * Les soldes vivent en colonnes flottantes : au-dela de 2^53, debiter exactement ce qui est
     * embarque n'est pas possible, et approcher reviendrait a prendre a l'un ce qu'on rend a
     * l'autre sans le dire. `SettlementPrecisionLimitTest` etablit le fait de stockage ; ici on
     * verifie que le reglement en tire la consequence.
     */
    public function testAFortuneTheStorageCannotTellApartStopsTheSettlement(): void
    {
        [$combat, $missions, $cible] = $this->anEngagedCombat();

        // Deux puissance cinquante-cinq : reel, positif, et deja indistinct dans la colonne.
        $this->setStockOf($cible, ['metal' => 36_028_797_018_963_968]);
        $avant = $this->stockOf($cible);

        try {
            $this->settleIt($combat, (int)$combat->ends_at);
            $this->fail('A settlement promised exactness at a scale the storage cannot hold.');
        } catch (Throwable $refus) {
            $this->assertInstanceOf(UnsettleableAtThisScale::class, $refus);
            $this->assertSame($combat->id, $refus->combatInstanceId);
        }

        $this->assertSame($avant, $this->stockOf($cible), 'The target was debited despite the refusal.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertNull($combat->potential_loot_frozen_at, 'Numbers were written before the refusal.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $missions[0]->id)->count());
    }

    /**
     * Un combat inconnu ne leve pas : un travail relivre apres une purge se journalise, il ne casse pas.
     */
    public function testAnUnknownCombatIsReportedNotThrown(): void
    {
        $this->anEngagedCombat();

        $service = new CombatSettlementService(resolve(CombatResolutionService::class));
        $issue = $this->settleWith($service, null, 1);

        $this->assertFalse($issue->settled);
        $this->assertSame(CombatSettlementOutcome::REASON_UNKNOWN_COMBAT, $issue->reason);
    }

    /**
     * Une vraie bataille, ouverte, close et engagee, prete a etre reglee.
     *
     * @param int $fleets Le nombre de flottes attaquantes, toutes du meme joueur, vers la meme cible.
     * @return array{0: CombatInstance, 1: array<int, FleetMission>, 2: PlanetService}
     */
    private function anEngagedCombat(int $fleets = 1): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $cible = null;
        for ($i = 0; $i < $fleets; $i++) {
            $units = new UnitCollection();
            $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
            // Ecrasante, et c'est indispensable : le butin n'existe que si l'attaquant l'emporte.
            $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

            $envoyeeVers = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

            if ($cible !== null && $envoyeeVers->getPlanetId() !== $cible->getPlanetId()) {
                $this->fail('The second fleet was sent to another planet: the battle would not involve two fleets.');
            }

            $cible = $envoyeeVers;
        }

        if ($cible === null) {
            $this->fail('No fleet was dispatched.');
        }

        $missions = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderBy('id')
            ->get()
            ->all();

        $this->assertCount($fleets, $missions, 'Not every dispatched fleet became a mission.');

        // Un stock connu **avant la cloture** : c'est elle qui calcule la bataille, donc le butin.
        // Et une garnison : sans defense, la cible ne perd rien et le camp defenseur ne prouverait
        // rien. Vingt lanceurs tombent devant trois cent cinquante chasseurs sans changer l'issue.
        $this->setStockOf($cible, ['metal' => 500_000, 'crystal' => 300_000, 'deuterium' => 100_000, 'rocket_launcher' => 20]);

        $combat = (new CombatOpeningService())->openOrJoin($missions[0], $cible->getPlanetId(), (int)$missions[0]->time_arrival);

        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($barriere, 'The opening left no barrier.');

        $fermeture = (new RallyClosureService())->close($combat->id, (int)$barriere->owned_through_effect_at);
        $this->assertTrue($fermeture->closed, 'The rally did not close: ' . $fermeture->reason);

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertNotNull($combat->battle_result, 'The closure left no battle to settle.');
        $this->assertNotNull($combat->ends_at);

        $bataille = BattleResultCodec::fromStorage($combat->battle_result);
        $this->assertNotSame([], $bataille->defenderUnitsLost->toArray(), 'The garrison lost nothing: the defending side would prove nothing.');

        return [$combat, $missions, $cible];
    }

    /**
     * Regle par le service construit sur les dependances de production.
     */
    private function settleIt(CombatInstance $combat, int $instant, Closure|null $retour = null): CombatSettlementOutcome
    {
        $service = new CombatSettlementService(resolve(CombatResolutionService::class));

        return $this->settleWith($service, $combat, $instant, $retour);
    }

    /**
     * @param Closure|null $retour Remplace la creation reelle des retours, pour y injecter une panne.
     */
    private function settleWith(CombatSettlementService $service, CombatInstance|null $combat, int $instant, Closure|null $retour = null): CombatSettlementOutcome
    {
        $mission = resolve(ReturningAttackMission::class);

        return $service->settle(
            $combat->id ?? 0,
            $mission,
            $retour ?? function (FleetMission $retourDe, Resources $ressources, UnitCollection $unites, int $tempsSupplementaire = 0, array|null $epaves = null, int|null $dureeImposee = null) use ($mission): void {
                $mission->returnFor($retourDe, $ressources, $unites, $tempsSupplementaire, $epaves, $dureeImposee);
            },
            $instant,
        );
    }

    /**
     * Le butin potentiel de la bataille figee, en entiers.
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function potentialOf(CombatInstance $combat): array
    {
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        return [
            'metal' => (int)$resultat->loot->metal->get(),
            'crystal' => (int)$resultat->loot->crystal->get(),
            'deuterium' => (int)$resultat->loot->deuterium->get(),
        ];
    }

    /**
     * Ce qu'une flotte portait deja en sortant du combat, tel que la bataille figee l'a inscrit.
     *
     * Les flottes partent sans ressources, et la cargaison n'est pourtant pas nulle : le moteur y
     * compte le carburant a bord. C'est un fait du moteur, le meme sur le chemin instantane, et
     * les retours s'y ajoutent — ils ne le remplacent pas.
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function cargoOf(CombatInstance $combat, int $rang): array
    {
        $flotte = BattleResultCodec::fromStorage($combat->battle_result)->attackerFleetResults[$rang];

        return [
            'metal' => (int)$flotte->survivingCargo->metal->get(),
            'crystal' => (int)$flotte->survivingCargo->crystal->get(),
            'deuterium' => (int)$flotte->survivingCargo->deuterium->get(),
        ];
    }

    /**
     * Le solde de la cible tel qu'il est ecrit, relu hors de tout cache.
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function stockOf(PlanetService $cible): array
    {
        $ligne = Planet::query()->whereKey($cible->getPlanetId())->first();

        if ($ligne === null) {
            $this->fail('The target planet vanished.');
        }

        return [
            'metal' => (int)$ligne->metal,
            'crystal' => (int)$ligne->crystal,
            'deuterium' => (int)$ligne->deuterium,
        ];
    }

    /**
     * Pose un solde sur la cible, a l'abri de la mise a jour de production.
     *
     * @param array<string, int> $stock
     */
    private function setStockOf(PlanetService $cible, array $stock): void
    {
        DB::table('planets')->where('id', $cible->getPlanetId())->update(
            $stock + ['time_last_update' => (int)now()->timestamp + 86_400]
        );
        $cible->reloadPlanet();
    }

    /**
     * Le retour cree pour une mission, et il n'y en a qu'un.
     */
    private function returnOf(FleetMission $mission): FleetMission
    {
        $retours = FleetMission::query()->where('parent_id', $mission->id)->get();

        $this->assertCount(1, $retours, "Mission {$mission->id} has " . $retours->count() . ' returns instead of one.');

        $retour = $retours->first();

        if ($retour === null) {
            $this->fail("Mission {$mission->id} has no return.");
        }

        return $retour;
    }

    /**
     * Le metal que le rapport raconte : celui sur lequel tout a ete ecrit.
     */
    private function lootMetalOf(BattleReport $rapport): int
    {
        $butin = $rapport->loot;

        $this->assertIsArray($butin, 'The report carries no loot.');
        $this->assertArrayHasKey('metal', $butin);

        return (int)$butin['metal'];
    }

    /**
     * Un allocateur qui ne sait que dire sa version : l'appeler est la preuve qu'on l'a choisi.
     */
    private function anAllocatorThatRefusesToWork(string $version): LootAllocator
    {
        return new class ($version) implements LootAllocator {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }

            public function lootableAmount(
                float $inStock,
                int $rateInBasisPoints,
                string $phase,
                ResourceNormalizationDiagnostics &$diagnostics,
            ): int {
                throw new LogicException('La V2 courante a ete choisie a la place de la V1 gelee.');
            }

            public function capByCargo(Resources $loot, int $totalCargoCapacity, string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT, string $subject = ''): CappedLoot
            {
                throw new LogicException('La V2 courante a ete choisie a la place de la V1 gelee.');
            }

            /**
             * @param array<int, int> $weights
             * @param array<int, int> $remainingCapacity
             * @return array<int, int>
             */
            public function shareBetweenFleets(
                int $amount,
                array $weights,
                array $remainingCapacity,
                int $initiatorFleetMissionId,
            ): array {
                throw new LogicException('La V2 courante a ete choisie a la place de la V1 gelee.');
            }
        };
    }
}

/**
 * La mission d'attaque, avec sa creation de retour rendue accessible.
 *
 * `startReturn()` est protegee : en production, la fermeture que la mission passe a la resolution
 * la rend accessible sans elargir sa visibilite. Le harnais fait la meme chose pour l'essai, et les
 * retours qu'il cree sont de vraies missions, avec leur cargaison en base.
 */
final class ReturningAttackMission extends AttackMission
{
    /**
     * @param array<string, mixed>|null $epaves
     */
    public function returnFor(FleetMission $retourDe, Resources $ressources, UnitCollection $unites, int $tempsSupplementaire = 0, array|null $epaves = null, int|null $dureeImposee = null): void
    {
        $this->startReturn($retourDe, $ressources, $unites, $tempsSupplementaire, $epaves, $dureeImposee);
    }
}
