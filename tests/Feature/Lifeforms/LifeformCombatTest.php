<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Application\FrozenCombatApplicationContext;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatResolutionService;
use OGame\Combat\Services\CombatRosterReader;
use OGame\Combat\Services\CombatSettlementService;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Combat\Services\PhotographedDefender;
use OGame\Combat\Support\CombatantFrozenAtEntry;
use OGame\Combat\Support\FrozenCombatCharacteristics;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\DemographicRules;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformPlanetUpdater;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatEntryCharacteristic;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Message;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\Feature\Combat\OpensARallyWithAWindow;
use Tests\FleetDispatchTestCase;
use Tests\Support\PlacesLifeformSlots;

/**
 * Les formes de vie dans un combat reel (journal §155.6) : les habitants non proteges perissent quand
 * l attaque reussit, personne ne meurt quand elle echoue, et ce que l ouverture photographie ne se relit plus.
 *
 * **Le proprietaire de la planete etrangere voisine est partage par tout le processus** et peut avoir deja
 * choisi n importe quelle espece : le montage prend celle qu il a, et le Bouclier planetaire — propre aux
 * Humains — n entre pas dans ce qui est mesure ici. La part protegee de 30 % est mesuree sur ma propre
 * planete par `LifeformCombatEngineTest`.
 */
final class LifeformCombatTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use PlacesLifeformSlots;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int GENERAL_OVERHAUL_LIGHT_FIGHTER = 13205;

    /**
     * Les planetes etrangeres que cet essai a peuplees, a rendre au demontage.
     *
     * `getNearbyForeignCleanPlanet()` cree une planete **neuve** au proprietaire etranger a chaque essai, et le
     * gel d un combat lit **toutes** les planetes de ce proprietaire. Une planete peuplee et laissee la porte une
     * horloge d un autre essai ; l instant demande par le suivant tombe tantot avant, tantot apres — un rouge sur
     * trois passages, sans qu aucun des deux essais ne soit faux. Ce qu un essai peuple, il le depeuple.
     *
     * @var array<int, int>
     */
    private array $planetesPeuplees = [];

    /**
     * Une technologie par espece qui arme un vaisseau, avec le vaisseau et **l emplacement de son indice** — le seul que
     * le jeu accepte (WRONG_SLOT) ; les Humains, les Rock'tal et les Kaelesh n en ont qu au palier 2 (3 a 5 M d habitants).
     *
     * @var array<int, array{0: int, 1: string, 2: int}>
     */
    private const array UNIT_TECH = [
        1 => [11209, 'light_fighter', 9],
        2 => [12208, 'heavy_fighter', 8],
        3 => [13205, 'light_fighter', 5],
        4 => [14209, 'heavy_fighter', 9],
    ];

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
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
        resolve(SettingsService::class)->set('lifeforms_enabled', '0');
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '25');
        if ($this->planetesPeuplees !== []) {
            LifeformQueue::query()->whereIn('planet_id', $this->planetesPeuplees)->delete();
            LifeformSlotChange::query()->whereIn('planet_id', $this->planetesPeuplees)->delete();
            LifeformSlot::query()->whereIn('planet_id', $this->planetesPeuplees)->delete();
            LifeformTechnologyLevel::query()->whereIn('planet_id', $this->planetesPeuplees)->delete();
            LifeformBuildingLevel::query()->whereIn('planet_id', $this->planetesPeuplees)->delete();
            LifeformPlanet::query()->whereIn('planet_id', $this->planetesPeuplees)->delete();
            $this->planetesPeuplees = [];
        }
        LifeformBonusCache::invalidate();
        parent::tearDown();
    }

    public function testASuccessfulInstantAttackKillsTheUnprotectedPopulationAndReportsIt(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        // Ecrit sous la regle dure : tout ce qui n est pas protege meurt (journal §155.6) ; le taux est un reglage depuis §155.20.
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '100');
        $this->basicSetup();
        $this->planetAddUnit('light_fighter', 200);

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 200);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        // Attaque instantanee : le corps est lu vivant, aucun instant n est rejoue, la population est figee.
        [$proprietaire, , $habitants] = $this->populate($cible->getPlanetId(), 1000.0);

        $service = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $duree = $service->calculateFleetMissionDuration($this->planetService, $cible->getPlanetCoordinates(), $unites, resolve(AttackMission::class));
        $this->travel($duree + 1)->seconds();
        $this->reloadApplication();
        $this->get('/overview')->assertStatus(200);

        $population = (float)LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->value('population');
        $this->assertEqualsWithDelta((float)DemographicRules::SHELTERED, $population, 0.001, 'Sans Bouclier, seul l abri de cent habitants survit a une attaque reussie.');

        $message = Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->orderByDesc('id')->first();
        $this->assertNotNull($message, 'Le proprietaire apprend ses pertes civiles.');
        $this->assertSame((int)$habitants - DemographicRules::SHELTERED, (int)$message->params['lost']);
        $this->assertSame(DemographicRules::SHELTERED, (int)$message->params['survivors']);
        $this->assertSame(0, (int)$message->params['protected_percent']);
        $this->assertStringContainsString('[coordinates]' . $cible->getPlanetCoordinates()->asString() . '[/coordinates]', (string)$message->params['coordinates']);
    }

    /**
     * **Une vraie attaque instantanee epargne la part que le Bouclier planetaire protege** (audit des effets, §157) :
     * chaque maillon etait prouve isolement (photographe → 30 %, pertes → 25 % de l expose) ; ici la cible est un compte
     * neuf, Humains, Bouclier planetaire niveau 10, et l arrivee de la flotte tue 25 % des 70 % exposes — pas 25 % de tout.
     */
    public function testARealInstantAttackSparesTheShareThePlanetaryShieldProtects(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '25');

        // La cible : un compte neuf, donc sans espece, qui prend les Humains et leur Bouclier.
        $this->createAndLoginUser();
        $cibleId = $this->currentPlanetId;
        $cibleJoueur = $this->currentUserId;
        $coordonnees = $this->planetService->getPlanetCoordinates();
        [$proprietaire, $espece, $habitants] = $this->populate($cibleId, 10000.0);
        $this->assertSame($cibleJoueur, $proprietaire);
        $this->assertSame(Species::Humans, $espece, 'Un compte neuf prend les Humains.');
        resolve(LifeformLevels::class)->setLevel($cibleId, LifeformKind::Building, 11112, 10); // 3 % par niveau : 30 %
        LifeformBonusCache::invalidate();
        DB::table('users')->where('id', $cibleJoueur)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cibleId)->update(['rocket_launcher' => 0, 'light_laser' => 0]);

        // L attaquant : un autre compte neuf.
        $this->createAndLoginUser();
        $this->basicSetup();
        $this->planetAddUnit('light_fighter', 200);
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 200);
        $this->dispatchFleet($coordonnees, $unites, new Resources(0, 0, 0, 0), PlanetType::Planet);
        $mission = DB::table('fleet_missions')->where('user_id', $this->currentUserId)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($mission);

        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 1));
        $this->reloadApplication();
        $this->get('/overview')->assertStatus(200);

        $message = Message::query()->where('user_id', $cibleJoueur)->where('key', 'lifeform_population_loss')->orderByDesc('id')->first();
        $this->assertNotNull($message, 'Le proprietaire apprend ses pertes civiles.');
        $this->assertSame(30, (int)$message->params['protected_percent'], 'Le Bouclier niveau 10 protege 30 %.');
        $this->assertSame(25, (int)$message->params['loss_percent']);
        $attendu = (int)floor($habitants * 0.70 * 0.25);
        $this->assertSame($attendu, (int)$message->params['lost'], '25 % des 70 % exposes.');
        $this->assertLessThan((int)floor($habitants * 0.25), $attendu, 'Sans Bouclier, 25 % de tous auraient peri : le Bouclier a change le chiffre.');
        $population = (float)LifeformPlanet::query()->where('planet_id', $cibleId)->value('population');
        $this->assertEqualsWithDelta($habitants - $attendu, $population, 0.001);
    }

    /**
     * **Les morts d une bataille ferment l emplacement sans que personne ne vide la memoire** (relance de
     * Codex, journal §155.18). Les bonus calcules avant la bataille restaient en memoire apres elle — une
     * technologie comptait encore sur une population qui n existait plus.
     */
    public function testTheDeathsOfABattleCloseTheSlotWithoutClearingTheMemoryByHand(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        // Ecrit sous la regle dure : tout ce qui n est pas protege meurt (journal §155.6) ; le taux est un reglage depuis §155.20.
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '100');
        $this->basicSetup();
        $this->planetAddUnit('light_fighter', 200);

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 200);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        // Assez d habitants pour les six emplacements du palier 1 (le sixieme en exige un million).
        [$proprietaire, $espece, $habitants] = $this->populate($cible->getPlanetId(), 1100000.0);
        $this->assertGreaterThanOrEqual(1100000.0, $habitants);
        // La premiere technologie du palier 1 dont l effet est servi par le resolveur, dans son propre emplacement.
        $technologie = null;
        foreach (LifeformCatalogue::technologiesOf($espece) as $candidate) {
            if ($candidate->tier() === 1 && $candidate->bonuses[0]->target === null && in_array($candidate->bonuses[0]->code, LifeformBonusResolver::APPLIED, true)) {
                $technologie = $candidate;
                break;
            }
        }
        $this->assertNotNull($technologie, 'Chaque espece a une technologie de palier 1 dont l effet est servi.');
        $this->placeLifeformSlot($cible->getPlanetId(), $technologie->index, $technologie->id, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($cible->getPlanetId(), LifeformKind::Technology, $technologie->id, 4);
        $effet = $technologie->bonuses[0]->code;

        $avant = resolve(LifeformBonusResolver::class)->forPlayer($proprietaire)->fraction($effet);
        $this->assertGreaterThan(0.0, $avant, 'Emplacement ouvert : la technologie compte, et la memoire la garde.');

        $service = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $duree = $service->calculateFleetMissionDuration($this->planetService, $cible->getPlanetCoordinates(), $unites, resolve(AttackMission::class));
        $this->travel($duree + 1)->seconds();
        $this->reloadApplication();
        $this->get('/overview')->assertStatus(200);
        $this->assertEqualsWithDelta((float)DemographicRules::SHELTERED, (float)LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->value('population'), 0.001, 'Seul l abri survit.');

        // Sans rien vider a la main : la lecture qui suit la bataille est celle des survivants.
        $apres = resolve(LifeformBonusResolver::class)->forPlayer($proprietaire)->fraction($effet);
        LifeformBonusCache::invalidate();
        $verite = resolve(LifeformBonusResolver::class)->forPlayer($proprietaire)->fraction($effet);
        $this->assertLessThan($avant, $verite, 'Cent habitants ne tiennent aucun emplacement.');
        $this->assertSame($verite, $apres, 'La memoire a suivi les morts : la lecture d apres la bataille est celle des survivants, sans invalidation a la main.');
    }

    /**
     * **Au taux de depart, un quart de la population exposee meurt** (decision de Keven, 16 septembre 2026 : 25 %,
     * choix d equilibrage Azria — sur un million d habitants sans protection, environ 250 000 morts, 750 000
     * survivants ; journal §155.20). Chemin instantane, par l entree reelle, sans rien poser : le reglage vaut 25
     * quand rien ne l a ecrit.
     */
    public function testAtTheDefaultRateAQuarterOfTheExposedPopulationDies(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        $this->assertSame(25, resolve(SettingsService::class)->lifeformPopulationLossPercent(), 'Premisse : le taux de depart.');
        $this->basicSetup();
        $this->planetAddUnit('light_fighter', 200);

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 200);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        [$proprietaire, , $habitants] = $this->populate($cible->getPlanetId(), 1000000.0);
        $this->assertGreaterThanOrEqual(1000000.0, $habitants, 'Premisse : au moins un million d habitants, sans Bouclier.');
        $messagesAvant = Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count();

        $service = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $duree = $service->calculateFleetMissionDuration($this->planetService, $cible->getPlanetCoordinates(), $unites, resolve(AttackMission::class));
        $this->travel($duree + 1)->seconds();
        $this->reloadApplication();
        $this->get('/overview')->assertStatus(200);

        $morts = (int)floor($habitants * 0.25);
        $population = (float)LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->value('population');
        $this->assertEqualsWithDelta($habitants - $morts, $population, 0.001, 'Un quart de la population exposee est mort ; trois quarts survivent.');
        $this->assertGreaterThan(700000.0, $population, 'Des attaques repetees restent dangereuses, mais une seule ne vide pas la planete.');

        $message = Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->orderByDesc('id')->first();
        $this->assertNotNull($message);
        $this->assertSame($messagesAvant + 1, Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count());
        $this->assertSame($morts, (int)$message->params['lost']);
        $this->assertSame((int)floor($habitants - $morts), (int)$message->params['survivors']);
        $this->assertSame(0, (int)$message->params['protected_percent']);
        $this->assertSame(25, (int)$message->params['loss_percent']);
    }

    /**
     * **A zero, les morts sont desactivees** : une attaque reussie ne touche ni la population, ni l ancre, et
     * n envoie aucun message — la voie de desactivation explicite que Keven demandait (journal §155.20).
     */
    public function testAtZeroASuccessfulAttackKillsNobodyAndSaysNothing(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '0');
        $this->basicSetup();
        $this->planetAddUnit('light_fighter', 200);

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 200);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        [$proprietaire, , $habitants] = $this->populate($cible->getPlanetId(), 1000.0);
        $ancreAvant = LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->first(['previous_population', 'previous_calculated_at']);
        $this->assertNotNull($ancreAvant);
        $messagesAvant = Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count();

        $service = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $duree = $service->calculateFleetMissionDuration($this->planetService, $cible->getPlanetCoordinates(), $unites, resolve(AttackMission::class));
        $this->travel($duree + 1)->seconds();
        $this->reloadApplication();
        $this->get('/overview')->assertStatus(200);
        $this->assertSame($duree + 1, (int)Date::now()->timestamp - (int)$ancreAvant->previous_calculated_at, 'Premisse : l attaque est arrivee et le monde a avance.');

        $this->assertEqualsWithDelta($habitants, (float)LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->value('population'), 0.001, 'Personne ne meurt : la population stationnaire est intacte.');
        $this->assertSame($messagesAvant, Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count(), 'Aucun message de pertes civiles.');
        $ancreApres = LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->first(['previous_population', 'previous_calculated_at']);
        $this->assertNotNull($ancreApres);
        $this->assertEqualsWithDelta((float)$ancreAvant->previous_population, (float)$ancreApres->previous_population, 0.001, 'L ancre n a pas ete ramenee : aucune mort a porter.');
    }

    /**
     * **Le taux est fige a l ouverture du combat** : change pendant le ralliement, il ne touche pas la bataille
     * deja ouverte — ni sa cloture, ni son reglement (exigence de Keven, journal §155.20). L ouverture le
     * photographie (version 9), la cloture le fige (schema 7), le reglement l applique.
     */
    public function testTheLossRateIsFrozenAtTheOpeningAndAChangeDoesNotTouchAnOpenedBattle(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '25');
        [$ouvreuse, $cibleId, $ouverture] = $this->aRallyAboutToOpen(0, [], null, function (): void {
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
            $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, 900000.0, (int)Date::now()->timestamp);
        });
        [$proprietaire, , $habitants] = $this->populate($cibleId, 400000.0);
        $messagesAvant = Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count();

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);
        $etat = $combat->opening_state;
        $this->assertIsArray($etat);
        $this->assertSame(OpeningStateRecorder::VERSION, (int)$etat['version']);
        $this->assertSame(25, OpeningStateRecorder::openingLifeformLossPercentOf($combat), 'L ouverture photographie le taux du moment.');
        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->firstOrFail();
        $echeanceDuRalliement = (int)$barriere->owned_through_effect_at;
        $this->assertSame(CombatState::Rallying, $combat->status, 'Premisse : la fenetre est ouverte, rien n est clos.');

        // L administration passe le taux a cent pendant le ralliement : cette bataille n en sait rien.
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '100');
        $this->assertSame(100, resolve(SettingsService::class)->lifeformPopulationLossPercent(), 'Premisse : le reglage vivant a change.');

        // La cloture d abord (l echeance du ralliement), puis le reglement a l echeance de la bataille.
        $this->travelTo(Date::createFromTimestamp($echeanceDuRalliement + 120));
        $avance = (new PersistentCombatAdvancer())->advance($echeanceDuRalliement + 120);
        $this->assertArrayNotHasKey($combat->id, $avance->failures, 'La cloture a echoue : ' . json_encode($avance->failures[$combat->id] ?? null));
        $combat->refresh();
        $this->assertNotNull($combat->ends_at, 'Premisse : la bataille est datee.');
        // Le reglement date son heure de l horloge du serveur, pas de l appelant : le banc y va.
        $this->travelTo(Date::createFromTimestamp(max($echeanceDuRalliement + 120, (int)$combat->ends_at + 1)));
        $avance = (new PersistentCombatAdvancer())->advance((int)Date::now()->timestamp);
        $this->assertArrayNotHasKey($combat->id, $avance->failures, 'Le reglement a echoue : ' . json_encode($avance->failures[$combat->id] ?? null));
        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'Premisse : la bataille est reglee.');
        $photographie = $combat->frozen_settings;
        $this->assertIsArray($photographie);
        $contexte = FrozenCombatApplicationContext::fromStorage($photographie);
        $this->assertSame(FrozenCombatApplicationContext::SCHEMA, (int)$photographie['schema']);
        $this->assertSame(25, $contexte->lifeformPopulationLossPercent(), 'La cloture gele le taux de l ouverture, pas celui du moment.');

        $morts = (int)floor($habitants * 0.25);
        $this->assertEqualsWithDelta($habitants - $morts, (float)LifeformPlanet::query()->where('planet_id', $cibleId)->value('population'), 0.001, 'Un quart est mort, pas la totalite : le taux change apres l ouverture n a pas touche la bataille.');
        $message = Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->orderByDesc('id')->first();
        $this->assertNotNull($message);
        $this->assertSame($messagesAvant + 1, Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count());
        $this->assertSame($morts, (int)$message->params['lost']);
        $this->assertSame(25, (int)$message->params['loss_percent']);
    }

    public function testAFailedAttackSparesThePopulation(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        $this->basicSetup();
        $this->planetAddUnit('light_fighter', 5);

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        [$proprietaire, , $habitants] = $this->populate($cible->getPlanetId(), 1000.0);
        $messagesAvant = Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count();
        $cible->addUnit('rocket_launcher', 300);
        $cible->save();

        $service = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $duree = $service->calculateFleetMissionDuration($this->planetService, $cible->getPlanetCoordinates(), $unites, resolve(AttackMission::class));
        $this->travel($duree + 1)->seconds();
        $this->reloadApplication();
        $this->get('/overview')->assertStatus(200);

        $this->assertEqualsWithDelta($habitants, (float)LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->value('population'), 0.001, 'Cinq chasseurs contre trois cents lanceurs : l attaque echoue, personne ne meurt.');
        $this->assertSame($messagesAvant, Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->count());
    }

    public function testTheOpeningPhotographFreezesTheLifeformFactsOfBothSides(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');

        [$ouvreuse, $cibleId, $ouverture] = $this->aRallyAboutToOpen(0, [], null, function () use ($chasseur): void {
            // L attaquant est un Mechas dont la Revision generale du chasseur leger vaut +3 % au depart.
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
            // La planete porte vraiment ses deux millions d habitants : logement et ferme au niveau qu il faut.
            $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, 900000.0, (int)Date::now()->timestamp);
            $this->placeLifeformSlot($this->currentPlanetId, 5, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, (int)Date::now()->timestamp);
            resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 10);
            $this->assertSame(3.0, resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->getLifeformUnitStatsPercent($chasseur), 'Premisse : l attaquant part avec +3 %.');
        });

        // Le defenseur : une technologie de son espece qui arme un vaisseau, au niveau 10 (+3 %), dans l emplacement de
        // son indice — la population soutenue et le palier ouvert par ses capacites.
        [, $especeCible] = $this->populate($cibleId, 5000000.0);
        [$technologie, $vaisseauCible, $position] = self::UNIT_TECH[$especeCible->value];
        $unite = ObjectService::getShipObjectByMachineName($vaisseauCible);
        $this->openLifeformTierFor($cibleId, $especeCible, $position);
        $this->placeLifeformSlot($cibleId, $position, $technologie, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($cibleId, LifeformKind::Technology, $technologie, 10);

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);

        $defenseur = OpeningStateRecorder::openingDefenderOf($combat);
        $this->assertSame(0.0, $defenseur->lifeformBonuses->protectedShare, 'Une planete peuplee sans Bouclier : zero de protege, et non null.');
        $this->assertSame(3.0, $defenseur->lifeformBonuses->unitStatsPercent($unite), 'La technologie du defenseur est photographiee.');
        $etat = $combat->opening_state;
        $this->assertIsArray($etat);
        $this->assertSame(OpeningStateRecorder::VERSION, (int)$etat['version']);

        $ligne = CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->where('fleet_mission_id', $ouvreuse->id)->first();
        $this->assertNotNull($ligne, 'L ouvreuse est inscrite avec ses caracteristiques.');
        $gele = FrozenCombatCharacteristics::fromStorage($ligne->getAttributes());
        $this->assertSame(3.0, $gele->lifeformBonuses->unitStatsPercent($chasseur), 'La flotte porte ses +3 % geles a l admission. Colonne relue : ' . json_encode($ligne->getAttributes()['lifeform_bonuses'] ?? null));
        $this->assertNull($gele->lifeformBonuses->protectedShare, 'Une flotte ne porte rien du corps.');

        // Ce que les deux camps acquierent apres l ouverture n arme pas la bataille.
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 20);
        resolve(LifeformLevels::class)->setLevel($cibleId, LifeformKind::Technology, $technologie, 20);

        $vivant = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertSame(6.0, $vivant->getLifeformUnitStatsPercent($chasseur), 'Le compte vivant porte deja +6 %.');
        $combattant = new CombatantFrozenAtEntry($this->currentUserId, FrozenCombatCharacteristics::fromStorage(CombatEntryCharacteristic::query()->whereKey($ligne->id)->firstOrFail()->getAttributes()), null);
        $this->assertSame(3.0, $combattant->getLifeformUnitStatsPercent($chasseur), 'Le combattant gele repond +3 %.');
        $this->assertSame(51, $chasseur->properties->attack->calculate($combattant)->totalValue);

        $combat->refresh();
        $relu = OpeningStateRecorder::openingDefenderOf($combat);
        $this->assertSame(3.0, $relu->lifeformBonuses->unitStatsPercent($unite), 'La technologie montee pendant le ralliement n arme pas cette bataille.');
        $this->assertSame(0.0, $relu->lifeformBonuses->unitStatsPercent(ObjectService::getShipObjectByMachineName('cruiser')));
    }

    /**
     * **Le chemin durable** : ce que l ouverture a photographie decide des morts a l echeance, et la garnison
     * tire avec les bonus de formes de vie de cette photographie — jamais avec ceux du corps au reglement.
     */
    public function testADurableCombatKillsFromTheFrozenShareAndFiresWithThePhotographedBonuses(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        // Ecrit sous la regle dure : tout ce qui n est pas protege meurt (journal §155.6) ; le taux est un reglage depuis §155.20.
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '100');
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }
        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));

        // La planete propre est partagee par le processus : on vide avant de poser la garnison de cet essai.
        $cible->removeUnits($cible->getShipUnits(), false);
        $cible->removeUnits($cible->getDefenseUnits(), false);
        $cible->save();
        $cible->reloadPlanet();

        $mission = DB::table('fleet_missions')->where('user_id', $this->currentUserId)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($mission, 'No fleet was dispatched.');
        $arrivee = (int)$mission->time_arrival;

        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 200_000,
            'crystal' => 100_000,
            'deuterium' => 20_000,
            'rocket_launcher' => 60,
            'light_laser' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $this->requireAnAdmissibleHistoryFor($proprietaire, $arrivee, 'le proprietaire de la cible');
        $this->requireAnAdmissibleHistoryFor($this->currentUserId, $arrivee, 'l attaquant');

        // **La forme de vie est posee avant l arrivee** : c est elle que l ouverture photographiera.
        $this->populate($cible->getPlanetId(), 1000.0);

        // La bataille est rejouable ; la liaison se pose apres le dernier envoi, que `reloadApplication()` efface.
        $this->app->bind(BattleDraws::class, static fn (): SeededDraws => new SeededDraws(4242));
        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');

        $this->travelTo(Date::createFromTimestamp($arrivee));
        $this->get('/overview')->assertStatus(200);

        $combat = $this->theCombatOf((int)$mission->id, $cible->getPlanetId());
        $this->assertNotNull($combat, 'The arrival did not open a combat.');
        $this->assertSame(CombatState::Active, $combat->status, 'A single fleet closes its window at once.');
        $this->assertNotNull($combat->battle_result);

        // Ce que l ouverture a photographie, et ce que la cloture en a gele.
        $photographie = OpeningStateRecorder::openingDefenderOf($combat);
        $this->assertSame(0.0, $photographie->lifeformBonuses->protectedShare, 'Une planete peuplee sans Bouclier : zero de protege, et non null.');
        $contexte = FrozenCombatApplicationContext::fromStorage($combat->frozen_settings);
        $this->assertSame(0.0, $contexte->lifeformProtectedShareOf($cible), 'La cloture gele la part de l ouverture.');

        // **La garnison tire avec les bonus photographies**, pris a la photographie et non au corps.
        $armee = new PhotographedDefender(
            $photographie->weaponLevel,
            $photographie->shieldLevel,
            $photographie->armorLevel,
            $photographie->classCombatBonus,
            $photographie->spaceDockLevel,
            new FrozenLifeformCombatBonuses([FrozenLifeformCombatBonuses::DEFENCE => 7.0], 0.42, 0.0, 0.0, 0.0)
        );
        $effectif = resolve(CombatRosterReader::class)->forTheBattle($combat, OpeningStateRecorder::openingUnitsOf($combat), $armee);
        $garnison = null;
        foreach ($effectif->defenders as $flotte) {
            if ($flotte->fleetMissionId === 0) {
                $garnison = $flotte->player;
            }
        }
        $this->assertNotNull($garnison, 'The garrison is not in the roster.');
        $this->assertSame(7.0, $garnison->getLifeformUnitStatsPercent(ObjectService::getUnitObjectByMachineName('rocket_launcher')), 'La garnison tire avec le bonus de defense photographie.');
        $this->assertSame(0.0, $garnison->getLifeformUnitStatsPercent(ObjectService::getShipObjectByMachineName('light_fighter')), 'Le bonus des defenses ne touche pas les vaisseaux.');

        // Le reglement applique les morts depuis la part gelee, jamais depuis le corps.
        $this->settle($combat);

        $this->assertEqualsWithDelta((float)DemographicRules::SHELTERED, (float)LifeformPlanet::query()->where('planet_id', $cible->getPlanetId())->value('population'), 0.001, 'A l echeance, seule la part protegee gelee — ici l abri — survit.');
        $this->assertNotNull(Message::query()->where('user_id', $proprietaire)->where('key', 'lifeform_population_loss')->orderByDesc('id')->first());
    }

    /**
     * **Un travail ne demarre pas grace a une population qui devait mourir avant son demarrage** (relance de Codex).
     *
     * La bataille est datee de son echeance, mais le reglement arrive apres. Si, entre les deux, une page du
     * proprietaire fait passer sa planete au-dela de l echeance, le passage livre un travail et **demarre le
     * suivant sur la population d avant la bataille** — un emplacement que les morts auraient ferme. Le
     * reglement, arrive ensuite, retrouve la population de l echeance et l applique ; il ne defait pas ce qui a
     * demarre. Deux joueurs obtiendraient des possibilites differentes selon la vitesse du serveur.
     *
     * La regle proposee : **le passage demographique ne depasse jamais l echeance d une bataille non reglee**.
     * La bataille est une coupe que l horloge ne franchit pas ; ce qui suit son echeance attend qu elle soit
     * reglee, et demarre alors sur les survivants, a sa vraie echeance.
     */
    public function testAWorkDoesNotStartOnAPopulationThatShouldHaveDiedBeforeIt(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        // Ecrit sous la regle dure : tout ce qui n est pas protege meurt (journal §155.6) ; le taux est un reglage depuis §155.20.
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '100');
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }
        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        $cibleId = $cible->getPlanetId();
        $cible->removeUnits($cible->getShipUnits(), false);
        $cible->removeUnits($cible->getDefenseUnits(), false);
        $cible->save();
        $cible->reloadPlanet();

        $mission = DB::table('fleet_missions')->where('user_id', $this->currentUserId)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($mission, 'No fleet was dispatched.');
        $arrivee = (int)$mission->time_arrival;
        $proprietaire = (int)DB::table('planets')->where('id', $cibleId)->value('user_id');
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cibleId)->update(['rocket_launcher' => 10, 'time_last_update' => (int)now()->timestamp + 86_400]);
        $this->requireAnAdmissibleHistoryFor($proprietaire, $arrivee, 'le proprietaire de la cible');
        $this->requireAnAdmissibleHistoryFor($this->currentUserId, $arrivee, 'l attaquant');

        // La cible porte assez d habitants pour ouvrir l emplacement 1 (deux cent mille), sans Bouclier : la
        // bataille ne laissera que l abri, et l emplacement se fermera.
        [, $espece] = $this->populate($cibleId, 200000.0);
        $technologie = LifeformResearchService::technologyAt($espece, 1, 1);
        $centre = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::LF_RESEARCH_TIME_REDUCTION);
        $this->assertNotNull($centre);
        resolve(LifeformLevels::class)->setLevel($cibleId, LifeformKind::Building, $centre->id, 1);
        $this->placeLifeformSlot($cibleId, 1, $technologie->id, $arrivee - 100);
        DB::table('planets')->where('id', $cibleId)->update(['metal' => 50_000_000, 'crystal' => 50_000_000, 'deuterium' => 50_000_000]);

        $this->app->bind(BattleDraws::class, static fn (): SeededDraws => new SeededDraws(4242));
        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp($arrivee));
        $this->get('/overview')->assertStatus(200);

        $combat = $this->theCombatOf((int)$mission->id, $cibleId);
        $this->assertNotNull($combat, 'The arrival did not open a combat.');
        $this->assertSame(CombatState::Active, $combat->status, 'A single fleet closes its window at once.');
        $echeance = (int)$combat->ends_at;
        $this->assertGreaterThan($arrivee, $echeance, 'Premisse : la bataille est datee apres l arrivee.');

        // **Entre la cloture et l echeance de la bataille, rien ne tient la planete** : la bataille est devant,
        // le proprietaire ne voit aucun bandeau, et sa planete avance normalement jusqu a maintenant.
        $this->actingAs(User::query()->findOrFail($proprietaire));
        DB::table('users')->where('id', $proprietaire)->update(['planet_current' => $cibleId]);
        $this->travelTo(Date::createFromTimestamp($echeance - 5));
        $this->get('/lifeforms/buildings')->assertStatus(200)->assertDontSee(e(__('t_lifeforms_ui.held.title')), false);
        $this->assertSame($echeance - 5, (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('calculated_at'), 'Une bataille datee dans le futur a tenu la planete avant son echeance.');
        $this->actingAs(User::query()->findOrFail($this->currentUserId));

        // **Deux niveaux de la technologie en file** : le premier s acheve une minute apres la bataille, le
        // second attend derriere lui. Le second ne peut demarrer que si l emplacement est ouvert a ce moment.
        LifeformQueue::query()->create([
            'planet_id' => $cibleId, 'user_id' => $proprietaire, 'kind' => LifeformKind::Technology->value,
            'object_id' => $technologie->id, 'target_level' => 1, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => $arrivee, 'time_end' => $echeance + 60, 'status' => 'running',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        $second = LifeformQueue::query()->create([
            'planet_id' => $cibleId, 'user_id' => $proprietaire, 'kind' => LifeformKind::Technology->value,
            'object_id' => $technologie->id, 'target_level' => 2, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => null, 'time_end' => null, 'status' => 'waiting',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);

        // **Le proprietaire charge une page deux minutes apres l echeance, avant le reglement.** Son passage
        // ne doit pas franchir la bataille : rien de ce qui suit son echeance ne se livre ni ne demarre.
        $this->travelTo(Date::createFromTimestamp($echeance + 120));
        $corps = resolve(PlanetServiceFactory::class)->make($cibleId, true);
        $this->assertNotNull($corps);
        resolve(LifeformPlanetUpdater::class)->update($corps, $echeance + 120);
        $this->assertSame($echeance, (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('calculated_at'), 'Le passage a franchi l echeance d une bataille non reglee.');
        $this->assertSame('waiting', $second->refresh()->status, 'Le second niveau a demarre sur une population que la bataille allait tuer.');

        // Le reglement applique la bataille a son echeance ; puis la vie reprend, sur les survivants.
        $this->settle($combat);
        $this->assertEqualsWithDelta((float)DemographicRules::SHELTERED, (float)LifeformPlanet::query()->where('planet_id', $cibleId)->value('population'), 0.001, 'Premisse : seule l abri survit.');
        $reprise = resolve(PlanetServiceFactory::class)->make($cibleId, true);
        $this->assertNotNull($reprise);
        resolve(LifeformPlanetUpdater::class)->update($reprise, $echeance + 120);

        $this->assertSame(1, resolve(LifeformLevels::class)->levelOf($cibleId, LifeformKind::Technology, $technologie->id), 'Le premier niveau, echu une minute apres la bataille, est livre a sa vraie echeance.');
        $this->assertSame('canceled', $second->refresh()->status, 'Le second niveau devait etre juge sur les survivants : emplacement ferme, travail annule — le meme sort qu un reglement a l heure lui aurait fait.');
        $this->assertSame($echeance + 120, (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('calculated_at'), 'Une fois la bataille reglee, l horloge rattrape.');
    }

    /**
     * **Avant la cloture aussi, l horloge ne franchit pas la bataille** (relance de Codex).
     *
     * La coupe de `testAWorkDoesNotStartOnAPopulationThatShouldHaveDiedBeforeIt` nait a la cloture, quand la
     * bataille recoit son echeance. Mais un ralliement peut etre **echu et non cloture** — l avanceur passe a
     * la minute —, et sa bataille est alors a venir sans instant connu. La cloture la datera de l echeance du
     * ralliement, que la barriere porte depuis l ouverture : c est la que l horloge doit s arreter en attendant.
     *
     * Le chemin est **l entree reelle** : `PlanetService::update()`, ce que toute page du proprietaire appelle.
     */
    public function testBeforeTheClosureTheClockStopsAtTheRallyDeadline(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        // Ecrit sous la regle dure : tout ce qui n est pas protege meurt (journal §155.6) ; le taux est un reglage depuis §155.20.
        resolve(SettingsService::class)->set('lifeform_population_loss_rate', '100');
        [$ouvreuse, $cibleId, $ouverture] = $this->aRallyAboutToOpen(0, [], null, function (): void {
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
            $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, 900000.0, (int)Date::now()->timestamp);
        });

        // La cible ouvre l emplacement 1 sans Bouclier : la bataille ne laissera que l abri.
        [$proprietaire, $espece] = $this->populate($cibleId, 200000.0);
        $technologie = LifeformResearchService::technologyAt($espece, 1, 1);
        $centre = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::LF_RESEARCH_TIME_REDUCTION);
        $this->assertNotNull($centre);
        resolve(LifeformLevels::class)->setLevel($cibleId, LifeformKind::Building, $centre->id, 1);
        $this->placeLifeformSlot($cibleId, 1, $technologie->id, $ouverture - 100);
        DB::table('planets')->where('id', $cibleId)->update(['metal' => 50_000_000, 'crystal' => 50_000_000, 'deuterium' => 50_000_000]);

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);
        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->firstOrFail();
        $echeanceDuRalliement = (int)$barriere->owned_through_effect_at;
        $this->assertGreaterThan($ouverture, $echeanceDuRalliement, 'Premisse : la fenetre est ouverte, son echeance est devant.');
        $this->assertNull($combat->ends_at, 'Premisse : avant la cloture, la bataille n a pas d echeance.');

        // **Tant que la fenetre est ouverte, rien ne tient la planete** : son echeance est devant, le proprietaire
        // ne voit aucun bandeau, et sa planete avance normalement jusqu a maintenant.
        $this->actingAs(User::query()->findOrFail($proprietaire));
        DB::table('users')->where('id', $proprietaire)->update(['planet_current' => $cibleId]);
        $this->travelTo(Date::createFromTimestamp($echeanceDuRalliement - 5));
        $this->get('/lifeforms/buildings')->assertStatus(200)->assertDontSee(e(__('t_lifeforms_ui.held.title')), false);
        $this->assertSame($echeanceDuRalliement - 5, (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('calculated_at'), 'Une fenetre encore ouverte a tenu la planete avant son echeance.');
        $this->actingAs(User::query()->findOrFail($this->currentUserId));

        // Deux niveaux en file : le premier s acheve une minute apres l echeance, le second attend derriere.
        // (Sans defense en face, la bataille se regle a l echeance meme du ralliement : une minute la suit.)
        LifeformQueue::query()->create([
            'planet_id' => $cibleId, 'user_id' => $proprietaire, 'kind' => LifeformKind::Technology->value,
            'object_id' => $technologie->id, 'target_level' => 1, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => $ouverture, 'time_end' => $echeanceDuRalliement + 60, 'status' => 'running',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        $second = LifeformQueue::query()->create([
            'planet_id' => $cibleId, 'user_id' => $proprietaire, 'kind' => LifeformKind::Technology->value,
            'object_id' => $technologie->id, 'target_level' => 2, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => null, 'time_end' => null, 'status' => 'waiting',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);

        // **L echeance est passee, la cloture n a pas tourne, et le proprietaire charge une page** deux minutes
        // plus tard : la mise a jour normale de sa planete ne doit rien livrer ni demarrer au-dela de l echeance.
        $this->travelTo(Date::createFromTimestamp($echeanceDuRalliement + 120));
        $corps = resolve(PlanetServiceFactory::class)->make($cibleId, true);
        $this->assertNotNull($corps);
        $corps->update();
        $this->assertSame(CombatState::Rallying, $combat->refresh()->status, 'Premisse : personne n a cloture.');
        $this->assertSame($echeanceDuRalliement, (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('calculated_at'), 'Avant la cloture, l horloge a franchi l echeance du ralliement.');
        $this->assertSame('waiting', $second->refresh()->status, 'Un travail posterieur a la bataille a demarre avant qu elle ne soit cloturee.');

        // **Et le proprietaire le lit**, plutot que de voir une page qui semble defectueuse : sur ses pages de
        // formes de vie comme sur sa vue generale, avec l instant depuis lequel sa planete est figee.
        $this->actingAs(User::query()->findOrFail($proprietaire));
        DB::table('users')->where('id', $proprietaire)->update(['planet_current' => $cibleId]);
        foreach (['/lifeforms/buildings', '/lifeforms/research', '/lifeforms/discoveries', '/overview'] as $page) {
            $reponse = $this->get($page);
            $reponse->assertStatus(200);
            $reponse->assertSee(e(__('t_lifeforms_ui.held.title')), false);
            $reponse->assertSee('data-held-since="' . $echeanceDuRalliement . '"', false);
            // L avis se pose AVANT le cadre `#technologies` : dedans, il repoussait la barre de titre loin de
            // l embout haut du cadre (mesure a l ecran, journal §155.23). Sur la vue generale, avant les boites.
            $html = (string)$reponse->getContent();
            $avis = strpos($html, 'data-held-since=');
            $cadre = strpos($html, $page === '/overview' ? 'id="productionboxBottom"' : '<div id="technologies">');
            $this->assertNotFalse($avis, $page);
            $this->assertNotFalse($cadre, $page . ' : le cadre attendu manque.');
            $this->assertLessThan($cadre, $avis, $page . ' : l avis de suspension doit preceder le cadre, pas vivre dedans.');
        }
        $this->assertSame($echeanceDuRalliement, (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('calculated_at'), 'Charger ses pages ne fait pas avancer une planete tenue.');

        // La cloture date la bataille de l echeance du ralliement, le reglement l applique, la vie reprend.
        $avance = (new PersistentCombatAdvancer())->advance($echeanceDuRalliement + 120);
        $this->assertArrayNotHasKey($combat->id, $avance->failures, 'La cloture ou le reglement a echoue : ' . json_encode($avance->failures[$combat->id] ?? null));
        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'Premisse : la bataille est reglee dans ce passage.');
        $this->assertGreaterThanOrEqual($echeanceDuRalliement, (int)$combat->ends_at, 'La bataille est datee au plus tot de l echeance du ralliement.');
        $this->assertLessThan($echeanceDuRalliement + 60, (int)$combat->ends_at, 'Premisse : le premier niveau s acheve apres la bataille.');

        $corps = resolve(PlanetServiceFactory::class)->make($cibleId, true);
        $this->assertNotNull($corps);
        $corps->update();
        $this->assertSame(1, resolve(LifeformLevels::class)->levelOf($cibleId, LifeformKind::Technology, $technologie->id), 'Le premier niveau est livre a sa vraie echeance.');
        $this->assertSame('canceled', $second->refresh()->status, 'Le second niveau devait etre juge sur les survivants : emplacement ferme, travail annule.');
        $this->assertSame($echeanceDuRalliement + 120, (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('calculated_at'), 'Une fois la bataille reglee, l horloge rattrape.');
        $this->get('/lifeforms/buildings')->assertStatus(200)->assertDontSee(e(__('t_lifeforms_ui.held.title')), false);
    }

    private function settle(CombatInstance $combat): void
    {
        (new CombatSettlementService(resolve(CombatResolutionService::class)))->settle(
            $combat->id,
            resolve(AttackMission::class),
            function (): void {
                // La creation du retour n est pas l objet de cet essai.
            },
            (int)$combat->ends_at
        );
    }

    /**
     * **Une recherche achevée après l arrivée n arme pas la flotte qui vient d arriver** (revue de Codex).
     *
     * Le travailleur traite une arrivée quand il passe, pas à la seconde où elle a lieu. Entre les deux, une
     * recherche de forme de vie peut s achever. Si le gel lisait le compte **au traitement**, cette flotte
     * partirait au combat avec un niveau qu elle n avait pas en arrivant — un bonus rétroactif que rien ne
     * justifie, et que le gel des recherches ordinaires interdit déjà.
     */
    public function testAResearchFinishedAfterTheArrivalDoesNotArmTheFleetThatJustArrived(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');

        [$ouvreuse, $cibleId, $ouverture] = $this->aRallyAboutToOpen(0, [], null, function () use ($chasseur): void {
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
            $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, 900000.0, (int)Date::now()->timestamp);
            $this->placeLifeformSlot($this->currentPlanetId, 5, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, (int)Date::now()->timestamp);
            resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 10);
            $this->assertSame(3.0, resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->getLifeformUnitStatsPercent($chasseur), 'Premisse : la flotte part avec +3 %.');
        });

        [, $especeCible] = $this->populate($cibleId, 5000000.0);

        // **Le defenseur aussi** : sa technologie passe au niveau 11 apres l ouverture, avant le traitement.
        [$technologieCible, $vaisseauCible, $position] = self::UNIT_TECH[$especeCible->value];
        $uniteCible = ObjectService::getShipObjectByMachineName($vaisseauCible);
        $this->openLifeformTierFor($cibleId, $especeCible, $position);
        $this->placeLifeformSlot($cibleId, $position, $technologieCible, $ouverture - 1000);
        resolve(LifeformLevels::class)->setLevel($cibleId, LifeformKind::Technology, $technologieCible, 10);
        LifeformQueue::query()->create([
            'planet_id' => $cibleId,
            'user_id' => (int)DB::table('planets')->where('id', $cibleId)->value('user_id'),
            'kind' => LifeformKind::Technology->value,
            'object_id' => $technologieCible,
            'target_level' => 11,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'energy' => 0,
            'time_start' => $ouverture - 100,
            'time_end' => $ouverture + 5,
            'status' => 'done',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        resolve(LifeformLevels::class)->setLevel($cibleId, LifeformKind::Technology, $technologieCible, 11);

        // **La recherche s achève cinq secondes après l arrivée**, et le monde l a déjà appliquée quand le
        // travailleur passe : niveau 11, donc +3,3 % sur le compte vivant.
        LifeformQueue::query()->create([
            'planet_id' => $this->currentPlanetId,
            'user_id' => $this->currentUserId,
            'kind' => LifeformKind::Technology->value,
            'object_id' => self::GENERAL_OVERHAUL_LIGHT_FIGHTER,
            'target_level' => 11,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'energy' => 0,
            'time_start' => $ouverture - 100,
            'time_end' => $ouverture + 5,
            'status' => 'done',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 11);

        $vivant = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertSame(3.3, round($vivant->getLifeformUnitStatsPercent($chasseur), 4), 'Premisse : le compte vivant porte desormais +3,3 %.');

        // **La borne exacte** : une echeance egale a l instant compte comme precedente, une seconde avant non.
        $niveaux = resolve(LifeformLevels::class);
        $this->assertSame(11, $niveaux->levelsAt($this->currentPlanetId, LifeformKind::Technology, $ouverture + 5)[self::GENERAL_OVERHAUL_LIGHT_FIGHTER] ?? 0);
        $this->assertSame(10, $niveaux->levelsAt($this->currentPlanetId, LifeformKind::Technology, $ouverture + 4)[self::GENERAL_OVERHAUL_LIGHT_FIGHTER] ?? 0);
        $this->assertSame(10, $niveaux->levelsAt($this->currentPlanetId, LifeformKind::Technology, $ouverture)[self::GENERAL_OVERHAUL_LIGHT_FIGHTER] ?? 0);

        // **Le cas symetrique** : un travail **du** a l instant mais que le travailleur n a pas encore livre y
        // etait quand meme. La ligne est posee, mesuree, puis retiree — la bataille qui suit n en depend pas.
        $enRetard = LifeformQueue::query()->create([
            'planet_id' => $this->currentPlanetId,
            'user_id' => $this->currentUserId,
            'kind' => LifeformKind::Technology->value,
            'object_id' => self::GENERAL_OVERHAUL_LIGHT_FIGHTER,
            'target_level' => 12,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'energy' => 0,
            'time_start' => $ouverture - 200,
            'time_end' => $ouverture - 10,
            'status' => 'running',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        $this->assertSame(12, $niveaux->levelsAt($this->currentPlanetId, LifeformKind::Technology, $ouverture)[self::GENERAL_OVERHAUL_LIGHT_FIGHTER] ?? 0, 'Un travail echu avant l instant y etait, meme non livre.');
        $enRetard->delete();

        // **Une technologie posee dans son emplacement apres l instant n armait pas la flotte.**
        $resolveur = resolve(LifeformBonusResolver::class);
        $this->assertEqualsWithDelta(0.03, $resolveur->forPlayer($this->currentUserId, $ouverture)->fraction(LifeformEffect::SHIP_STATS, 'light_fighter'), 1e-9);
        LifeformSlotChange::query()->where('planet_id', $this->currentPlanetId)->where('slot', 5)->update(['from_at' => $ouverture + 50]);
        LifeformBonusCache::invalidate();
        $this->assertSame(0.0, $resolveur->forPlayer($this->currentUserId, $ouverture)->fraction(LifeformEffect::SHIP_STATS, 'light_fighter'), 'Un emplacement choisi apres l arrivee arme la flotte.');
        LifeformSlotChange::query()->where('planet_id', $this->currentPlanetId)->where('slot', 5)->update(['from_at' => $ouverture - 1000]);
        LifeformBonusCache::invalidate();

        // Le travailleur passe **deux minutes après** l arrivée logique.
        $this->travelTo(Date::createFromTimestamp($ouverture + 120));
        $this->get('/overview')->assertStatus(200);

        $combat = $this->theCombatOf((int)$ouvreuse->id, $cibleId);
        $this->assertNotNull($combat, 'The arrival did not open a combat.');

        $ligne = CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->where('fleet_mission_id', $ouvreuse->id)->first();
        $this->assertNotNull($ligne, 'L ouvreuse est inscrite avec ses caracteristiques.');
        $this->assertSame($ouverture, (int)$ligne->entered_at, 'La flotte est gelee a son arrivee, pas au traitement.');

        $gele = FrozenCombatCharacteristics::fromStorage($ligne->getAttributes());
        $this->assertSame(
            3.0,
            round($gele->lifeformBonuses->unitStatsPercent($chasseur), 4),
            'La recherche achevee apres l arrivee arme la flotte : le gel lit le compte au traitement au lieu de le ramener a l admission.'
        );

        // La garnison suit la meme regle, a l instant de l ouverture.
        $defenseur = OpeningStateRecorder::openingDefenderOf($combat);
        $this->assertSame(
            3.0,
            round($defenseur->lifeformBonuses->unitStatsPercent($uniteCible), 4),
            'La recherche du defenseur achevee apres l ouverture arme sa garnison.'
        );
    }

    /**
     * **Une remise a zero du palier faite apres l arrivee ne desarme pas la flotte** (revue de Codex).
     *
     * La flotte arrive avec son bonus ; avant que le travailleur ne traite l arrivee, le joueur vide le
     * palier. La reconstruction lisait l occupation **courante** des emplacements : la technologie avait
     * disparu, et la flotte etait gelee sans son bonus. Deux traitements de la meme arrivee ne donnaient
     * alors pas la meme bataille — c est exactement ce que le gel existe pour interdire.
     */
    public function testATierResetAfterTheArrivalDoesNotDisarmTheFleet(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');

        [$ouvreuse, $cibleId, $ouverture] = $this->aRallyAboutToOpen(0, [], null, function () use ($chasseur): void {
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
            $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, 900000.0, (int)Date::now()->timestamp);
            $this->placeLifeformSlot($this->currentPlanetId, 5, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, (int)Date::now()->timestamp);
            resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 10);
            $this->assertSame(3.0, resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->getLifeformUnitStatsPercent($chasseur), 'Premisse : la flotte part avec +3 %.');
        });

        $this->populate($cibleId, 900000.0);

        // **Le joueur vide le palier une seconde apres l arrivee**, avant que le travailleur ne passe.
        resolve(LifeformResearchService::class)->resetTier($this->currentPlanetId, 1, $ouverture + 1);
        $this->assertSame(0.0, resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->getLifeformUnitStatsPercent($chasseur), 'Premisse : le compte vivant n a plus le bonus.');
        $this->assertEqualsWithDelta(
            0.03,
            resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $ouverture)->fraction(LifeformEffect::SHIP_STATS, 'light_fighter'),
            1e-9,
            'La remise a zero faite apres l arrivee efface le bonus de l instant : l occupation des emplacements est lue au present.'
        );

        $this->travelTo(Date::createFromTimestamp($ouverture + 120));
        $this->get('/overview')->assertStatus(200);

        $combat = $this->theCombatOf((int)$ouvreuse->id, $cibleId);
        $this->assertNotNull($combat, 'The arrival did not open a combat.');
        $ligne = CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->where('fleet_mission_id', $ouvreuse->id)->first();
        $this->assertNotNull($ligne);
        $gele = FrozenCombatCharacteristics::fromStorage($ligne->getAttributes());
        $this->assertSame(3.0, round($gele->lifeformBonuses->unitStatsPercent($chasseur), 4), 'La flotte a ete gelee sans le bonus qu elle portait en arrivant.');
    }

    /**
     * **Deux passages de population entre l arrivee et son traitement : les valeurs de l admission, ou rien.**
     *
     * C est le cas que la relance de Codex nomme. L etat garde ne couvre qu un passage ; si un second avance la
     * planete avant que le travailleur ne traite l arrivee, l instant d admission sort de la fenetre. La regle
     * exigee ici ne laisse aucune troisieme voie : **soit les valeurs exactes de l admission, soit une
     * suspension controlee** — jamais celles du moment.
     *
     * Une suspension se reconnait a ce qu elle **n ecrit rien** : pas de combat, pas de ligne de
     * caracteristiques, la mission non traitee, et les pages du joueur intactes.
     */
    public function testTwoPopulationPassesBeforeProcessingGiveTheAdmissionValuesOrASuspension(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');

        [$ouvreuse, $cibleId, $ouverture] = $this->aRallyAboutToOpen(0, [], null, function () use ($chasseur): void {
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
            $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, 900000.0, (int)Date::now()->timestamp);
            $this->placeLifeformSlot($this->currentPlanetId, 5, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, (int)Date::now()->timestamp);
            resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 10);
            $this->assertSame(3.0, resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->getLifeformUnitStatsPercent($chasseur), 'Premisse : la flotte part avec +3 %.');
        });
        $this->populate($cibleId, 900000.0);

        // **Deux passages de la planete apres l arrivee**, sans que personne ne traite la mission : c est l etat
        // qu une arrivee differee laisse derriere elle.
        $passage = resolve(LifeformPlanetUpdater::class);
        $passage->update($this->planetService, $ouverture + 60);
        $passage->update($this->planetService, $ouverture + 120);
        $etat = LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->firstOrFail();
        $this->assertGreaterThan($ouverture, (int)$etat->previous_calculated_at, 'Premisse : l instant d admission est sorti de la fenetre rejouable.');

        $this->travelTo(Date::createFromTimestamp($ouverture + 180));
        // Une anomalie d historique ne doit jamais fermer les pages du joueur (journal §120).
        $this->get('/overview')->assertStatus(200);

        $combat = $this->theCombatOf((int)$ouvreuse->id, $cibleId);
        if ($combat === null) {
            // **Suspension controlee** : rien n a ete decide, et la mission attend le passage suivant.
            $this->assertSame(0, (int)$ouvreuse->refresh()->processed, 'Une arrivee suspendue reste a traiter.');
            $this->assertNull($ouvreuse->combat_instance_id, 'Une arrivee suspendue ne porte aucun lien de combat.');
            $this->assertEqualsWithDelta(2000000.0, (float)LifeformPlanet::query()->where('planet_id', $cibleId)->value('population'), 1000.0, 'Aucun reglement partiel : la population de la cible est intacte.');

            return;
        }

        // Le ralliement s ouvre — c est le corps vise qu il photographie, et lui reste lisible. Restent alors
        // deux issues, et **une seule est interdite** : des caracteristiques qui ne seraient pas celles de
        // l admission.
        $ligne = CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->where('fleet_mission_id', $ouvreuse->id)->first();

        if ($ligne !== null) {
            $gele = FrozenCombatCharacteristics::fromStorage($ligne->getAttributes());
            $this->assertSame(3.0, round($gele->lifeformBonuses->unitStatsPercent($chasseur), 4), 'La flotte porte autre chose que ce qu elle portait a son admission.');

            return;
        }

        // Aucune caracteristique n a ete inventee : la fermeture doit alors **se suspendre**, et ne rien regler.
        // Le ralliement se ferme quand la barriere du corps est echue, pas a une heure inscrite sur l instance :
        // on avance donc franchement au-dela.
        // **La base du processus porte les batailles des essais voisins** : tout se mesure sur celle-ci,
        // jamais sur les compteurs globaux du passage.
        $fermeture = $ouverture + 3600;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $avance = (new PersistentCombatAdvancer())->advance($fermeture);
        $this->assertArrayHasKey($combat->id, $avance->failures, 'Une fermeture suspendue se compte comme un echec, sinon elle repasse chaque minute sans jamais etre vue.');
        $this->assertSame(CombatState::Rallying, $combat->refresh()->status, 'La fermeture a conclu alors que l admission n est pas etablie.');
        $this->assertNull($combat->battle_result, 'Une bataille a ete calculee sans savoir ce que la flotte apportait.');
    }

    /**
     * **Une arrivee suspendue est reprise, et la reprise ne fabrique rien non plus.**
     *
     * Suspendre n est utile que si la suspension est **rejouable** : la mission reste a traiter, le passage
     * suivant la retrouve, et la meme question se repose. Tant que l instant d admission reste hors de portee,
     * la reponse reste « je ne sais pas » — pas « prends la valeur du moment ». C est le second temoin demande.
     */
    public function testASuspendedArrivalIsReplayedAndStillInventsNothing(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');

        [$ouvreuse, $cibleId, $ouverture] = $this->aRallyAboutToOpen(0, [], null, function (): void {
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
            $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, 900000.0, (int)Date::now()->timestamp);
            $this->placeLifeformSlot($this->currentPlanetId, 5, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, (int)Date::now()->timestamp);
            resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 10);
        });
        $this->populate($cibleId, 900000.0);

        // La cible, elle, sort de la fenetre : c est son corps que l ouverture doit photographier. **Son
        // proprietaire est partage par tout le processus** : son etat est releve avant d etre bouscule, et
        // repose exactement a la fin.
        $avant = LifeformPlanet::query()->where('planet_id', $cibleId)->firstOrFail()->only(['population', 'food', 'calculated_at', 'previous_population', 'previous_food', 'previous_calculated_at']);
        $passage = resolve(LifeformPlanetUpdater::class);
        $cible = resolve(PlanetServiceFactory::class)->make($cibleId, true);
        $this->assertNotNull($cible, 'La planete visee doit exister pour etre bousculee.');
        $passage->update($cible, $ouverture + 60);
        $passage->update($cible, $ouverture + 120);
        $this->assertGreaterThan(
            $ouverture,
            (int)LifeformPlanet::query()->where('planet_id', $cibleId)->value('previous_calculated_at'),
            'Premisse : l ouverture ne peut plus etre photographiee sur ce corps.'
        );

        $stockAvant = (float)LifeformPlanet::query()->where('planet_id', $cibleId)->value('population');

        // Premier passage du travailleur : suspension.
        $this->travelTo(Date::createFromTimestamp($ouverture + 180));
        $this->get('/overview')->assertStatus(200);
        $this->assertNull($this->theCombatOf((int)$ouvreuse->id, $cibleId), 'L ouverture devait etre suspendue.');
        $this->assertSame(0, (int)$ouvreuse->refresh()->processed, 'La mission reste a traiter.');

        // **Le passage suivant la rejoue** : meme question, meme reponse, et toujours rien d invente.
        $this->travelTo(Date::createFromTimestamp($ouverture + 240));
        // Les pages restent accessibles a chaque reprise.
        $this->get('/overview')->assertStatus(200);
        $this->assertNull($this->theCombatOf((int)$ouvreuse->id, $cibleId), 'La reprise a decide au lieu de se suspendre a nouveau.');
        $this->assertSame(0, (int)$ouvreuse->refresh()->processed);
        $this->assertSame(0, CombatEntryCharacteristic::query()->where('fleet_mission_id', $ouvreuse->id)->count(), 'Aucune caracteristique n a ete inventee.');
        $this->assertEqualsWithDelta($stockAvant, (float)LifeformPlanet::query()->where('planet_id', $cibleId)->value('population'), 1000.0, 'Aucun reglement partiel sur la cible.');

        // **Une epreuve remet ce qu elle a leve**, a l identique : la laisser hors de sa fenetre rejouable
        // ferait suspendre le ralliement de l essai suivant, qui ne dirait rien de ce qu il mesure.
        LifeformPlanet::query()->where('planet_id', $cibleId)->update($avant);
        LifeformBonusCache::invalidate();
    }

    /**
     * Installe la forme de vie du proprietaire sur la planete visee (l espece qu il a, ou les Humains s il n en a pas),
     * avec une population que la planete soutient ; rend le proprietaire et son espece.
     *
     * La population posee est **stationnaire** : elle vaut l espace de vie du couple logement/ferme choisi, donc
     * l horloge ne la fait plus bouger et un essai peut mesurer des pertes sans qu une croissance brouille le
     * compte. Elle vaut **au moins** ce qui est demande, rarement le chiffre exact : l aide la rend.
     *
     * @return array{0: int, 1: Species, 2: float}
     */
    private function populate(int $planetId, float $auMoins): array
    {
        $proprietaire = (int)DB::table('planets')->where('id', $planetId)->value('user_id');
        $installation = resolve(LifeformInstallationService::class);
        $espece = $installation->speciesOf($proprietaire);
        if ($espece === null) {
            $installation->chooseSpecies($proprietaire, Species::Humans, (int)Date::now()->timestamp);
            $espece = Species::Humans;
        }
        $installation->installOnExistingPlanet($planetId, (int)Date::now()->timestamp);
        $this->planetesPeuplees[] = $planetId;
        // **La planete porte vraiment sa population** : logement et ferme au niveau qui l abrite et la nourrit.
        // Une horloge gelee dans le futur rendrait tout instant passe irreconstituable, et le gel d un combat
        // se suspendrait a juste titre (journal §155.12).
        $population = $this->sustainLifeformPopulation($planetId, $espece, $auMoins, (int)Date::now()->timestamp);

        return [$proprietaire, $espece, $population];
    }
}
