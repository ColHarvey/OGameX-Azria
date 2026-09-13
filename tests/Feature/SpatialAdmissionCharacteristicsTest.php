<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Services\BattleFieldStateStore;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\AttackerFleetSnapshot;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\LootPolicy;
use OGame\Enums\AllianceClass;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\History\ClassHistoryBaseline;
use OGame\Models\Alliance;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Patrol\Combat\FrozenCombatant;
use OGame\Patrol\Combat\SpatialBattle;
use OGame\Patrol\Combat\SpatialCombatOpening;
use OGame\Patrol\Combat\SpatialCombatSite;
use OGame\Patrol\Combat\SpatialFieldOpening;
use OGame\Patrol\Combat\SpatialOpeningState;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\FrozenPatrolTarget;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\InitialUserDataService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\RecordsClassHistory;

/**
 * **Le combat en espace libre gele lui aussi a l admission** (revue de Codex, 13 septembre 2026).
 *
 * ## Le defaut
 *
 * La bataille contre une patrouille se resout quand un travailleur traite l arrivee de l attaquante, et elle
 * composait les deux camps depuis **les comptes vivants** a ce moment-la — alors que son en-tete affirmait le
 * contraire. Une classe prise, une alliance rejointe ou quittee, une recherche achevee entre l arrivee et un
 * traitement tardif changeait donc des tirs deja decides. La photographie du chemin progressif avait le meme
 * defaut, a l instant ou elle etait prise.
 *
 * ## Ce qui est prouve ici, sans second mecanisme
 *
 * Les deux chemins passent par la derivation de `CombatEntryCharacteristicsRegistry` et par
 * `CombatantFrozenAtEntry`, exactement comme le combat durable :
 *
 * - l attaquante tire avec la classe et la recherche de **son arrivee**, dans les deux sens ;
 * - la patrouille, deja la, tire avec le niveau d alliance de **cet instant**, dans les deux sens ;
 * - une arrivee anterieure a la ligne de base garde **l ancienne regle** ;
 * - un historique inconnu **suspend** l arrivee sans rien decider, et elle est rejouee des que l historique
 *   repond ;
 * - le champ progressif, photographie en retard, sauvegarde puis repris depuis la base, tire toujours avec les
 *   caracteristiques de **l ouverture**.
 *
 * ## Ce qui ne l est pas
 *
 * La reprise est relue depuis la base par le magasin du champ, dans ce processus : la traversee d un autre
 * processus est eprouvee par `SpatialFieldResumeTest`, sur des unites dont les puissances sont ecrites de la
 * meme facon.
 */
final class SpatialAdmissionCharacteristicsTest extends AccountTestCase
{
    use RecordsClassHistory;

    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('patrols_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Une classe prise apres l arrivee n arme pas l attaquante traitee en retard.**
     */
    public function testAClassTakenAfterTheArrivalDoesNotArmASpatialAttackerProcessedLate(): void
    {
        [$patrouille, $segment, $attaque] = $this->anArrivalOnThePatrolOf($this->aFreshDefender());
        $armes = $this->livingWeaponLevel($this->currentUserId);

        // --- Apres l arrivee, avant le traitement : seule la classe change ---
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        $this->assertSame(2, $this->livingBonus($this->currentUserId), 'The premise is missing: the attacker is not a General.');

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame($armes, $resultat->attackerWeaponLevel, 'The attacker fired with a class taken after it arrived.');
    }

    /**
     * **Et dans l autre sens** : une classe abandonnee apres l arrivee arme encore l attaquante.
     */
    public function testAClassGivenUpAfterTheArrivalStillArmsTheSpatialAttacker(): void
    {
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        [$patrouille, $segment, $attaque] = $this->anArrivalOnThePatrolOf($this->aFreshDefender());
        $armes = $this->livingWeaponLevel($this->currentUserId);

        $this->recordCharacterClass($this->currentUserId, null);
        $this->assertSame(0, $this->livingBonus($this->currentUserId), 'The premise is missing: the attacker is still a General.');

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame($armes + 2, $resultat->attackerWeaponLevel, 'The attacker lost a class bonus it had when it arrived.');
    }

    /**
     * **Une alliance de Guerriers rejointe apres l arrivee n arme pas la patrouille.**
     */
    public function testAnAllianceJoinedAfterTheArrivalDoesNotArmThePatrol(): void
    {
        $defenseur = $this->aFreshDefender();
        [$patrouille, $segment, $attaque] = $this->anArrivalOnThePatrolOf($defenseur);
        $armes = $this->livingWeaponLevel($defenseur);

        $this->aWarriorsAllianceFoundedBy($defenseur);
        $this->assertSame(1, $this->livingBonus($defenseur), 'The premise is missing: the patrol owner is not in a Warriors alliance.');

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame($armes, $resultat->defenderWeaponLevel, 'The patrol fired with an alliance joined after the attacker arrived.');
    }

    /**
     * **Une alliance quittee apres l arrivee laisse son niveau aux tirs de la patrouille.**
     */
    public function testAnAllianceLeftAfterTheArrivalStillArmsThePatrol(): void
    {
        $defenseur = $this->aFreshDefender();
        $alliance = $this->aWarriorsAllianceFoundedBy($defenseur);
        [$patrouille, $segment, $attaque] = $this->anArrivalOnThePatrolOf($defenseur);
        $armes = $this->livingWeaponLevel($defenseur);

        resolve(AllianceService::class)->disbandAlliance((int)$alliance->id, $defenseur);
        $this->assertSame(0, $this->livingBonus($defenseur), 'The premise is missing: the patrol owner still belongs to a Warriors alliance.');

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame($armes + 1, $resultat->defenderWeaponLevel, 'The patrol lost the alliance level it had when the attacker arrived.');
    }

    /**
     * **Une recherche achevee apres l arrivee, et deja appliquee, n arme pas l attaquante** : la meme
     * reconstitution par les files que le combat durable.
     */
    public function testAResearchFinishedAfterTheArrivalDoesNotArmTheSpatialAttacker(): void
    {
        [$patrouille, $segment, $attaque, $arrivee] = $this->anArrivalOnThePatrolOf($this->aFreshDefender());
        $avant = $this->livingWeaponLevel($this->currentUserId);

        DB::table('research_queues')->insert([
            'planet_id' => $this->planetService->getPlanetId(),
            'object_id' => ObjectService::getResearchObjectByMachineName('weapon_technology')->id,
            'object_level_target' => $avant + 1,
            'time_duration' => 100,
            'time_start' => $arrivee - 90,
            'time_end' => $arrivee + 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'building' => 1,
            'processed' => 1,
            'canceled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->playerSetResearchLevel('weapon_technology', $avant + 1);

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame($avant, $resultat->attackerWeaponLevel, 'The attacker fired with a research finished after it arrived.');
    }

    /**
     * **Une arrivee anterieure a la ligne de base des historiques garde l ancienne regle** : le compte vivant,
     * sans historique invente.
     */
    public function testAnArrivalBeforeTheHistoryBaselineKeepsTheFirstRule(): void
    {
        [$patrouille, $segment, $attaque] = $this->anArrivalOnThePatrolOf($this->aFreshDefender());
        $armes = $this->livingWeaponLevel($this->currentUserId);

        // La ligne de base est le plus ancien instant que la migration a inscrit : on les repousse toutes.
        DB::table('character_class_history')->insert([
            'user_id' => 1,
            'character_class' => null,
            'changed_at' => 4_000_000_000,
            'cause' => ClassHistoryBaseline::CAUSE,
        ]);
        DB::table('character_class_history')->where('cause', ClassHistoryBaseline::CAUSE)->update(['changed_at' => 4_000_000_000]);

        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame($armes + 2, $resultat->attackerWeaponLevel, 'An arrival before the history baseline was frozen at admission, on a history that does not exist.');
    }

    /**
     * **Un historique inconnu suspend l arrivee sans rien decider, et elle est rejouee des qu il repond**
     * (decision de Keven, 13 septembre 2026).
     *
     * Par le vrai point d entree : ce que fait une page du joueur attaquant. Aucune exception ne remonte — elle
     * fermerait ses pages —, la patrouille n est pas touchee, aucun retour ne part, l exploitation est alertee.
     */
    public function testAnUnknownHistorySuspendsTheSpatialArrivalAndReplaysItOnceKnown(): void
    {
        [$patrouille, $segment, $attaque] = $this->anArrivalOnThePatrolOf($this->aFreshDefender());

        // Une colonne ecrite sans sa ligne : l anomalie que la confrontation existe pour voir.
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => CharacterClass::GENERAL->value]);

        $journal = Log::spy();

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $journal->shouldHaveReceived('critical')->once();
        $this->assertSame(0, (int)FleetMission::query()->whereKey($attaque->id)->value('processed'), 'An arrival whose history is unknown was decided anyway.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $attaque->id)->count(), 'A suspended arrival started a return.');
        $this->assertSame(40, (int)FleetMission::query()->whereKey($segment->id)->value('light_fighter'), 'The patrol lost units in a battle that never took place.');

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed->value, $patrouille->state->value, 'The patrol was touched by a suspended battle.');

        // --- La ligne manquante est retablie : le passage suivant rejoue l arrivee ---
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $this->assertSame(1, (int)FleetMission::query()->whereKey($attaque->id)->value('processed'), 'The suspended arrival was not replayed once its history answered.');
    }

    /**
     * **Le champ progressif garde les caracteristiques de l ouverture, apres sauvegarde et reprise.**
     *
     * Le proprietaire de la patrouille devient General entre l ouverture et le passage qui photographie ; le
     * champ est ouvert depuis la photographie, ecrit par son magasin, le monde bouge encore, et le champ repris
     * depuis la base tire avec la puissance de l ouverture. L essai etablit d abord que les deux classes donnent
     * des puissances differentes : sans cela, le juste et le faux coincideraient.
     */
    public function testASpatialFieldKeepsTheOpeningCharacteristicsAfterSaveAndResume(): void
    {
        $defenseur = $this->aFreshDefender();
        [$patrouille, $segment, $attaque, $ouverture] = $this->anArrivalOnThePatrolOf($defenseur);
        $armes = $this->livingWeaponLevel($defenseur);

        $combat = resolve(SpatialCombatOpening::class)->openOrJoin($attaque, FrozenPatrolTarget::of($patrouille), $ouverture);
        $combat = CombatInstance::query()->findOrFail($combat->id);
        $this->assertSame('v2', $combat->unit_characteristics_version, 'The free-space combat opened without a declared rule.');

        // --- Entre l ouverture et le passage qui photographie, le proprietaire devient General ---
        $this->recordCharacterClass($defenseur, CharacterClass::GENERAL);
        $this->assertSame(2, $this->livingBonus($defenseur), 'The premise is missing: the patrol owner is not a General.');

        new SpatialOpeningState()->capture($combat, $patrouille, $segment, $ouverture);
        $photographie = new SpatialOpeningState()->protectedDefenceOf(CombatInstance::query()->findOrFail($combat->id));

        $this->assertSame(0, $photographie->defender->classCombatBonus, 'The photograph took the class bonus of the worker instant, not of the opening.');
        $this->assertNull($photographie->characterClass, 'The photograph took the class of the worker instant, not of the opening.');

        $admise = new FrozenCombatant($defenseur, $armes, $photographie->defender->shieldLevel, $photographie->defender->armorLevel, 0, null);
        $tardive = new FrozenCombatant($defenseur, $armes, $photographie->defender->shieldLevel, $photographie->defender->armorLevel, 2, CharacterClass::GENERAL->value);
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $puissanceAdmise = $chasseur->properties->attack->calculate($admise)->totalValue;

        $this->assertNotSame($puissanceAdmise, $chasseur->properties->attack->calculate($tardive)->totalValue, 'The premise is missing: both classes give the same attack power.');

        // --- Le champ s ouvre depuis la photographie, et son magasin l ecrit ---
        $proprietaire = new FrozenCombatant(
            $defenseur,
            $photographie->defender->weaponLevel,
            $photographie->defender->shieldLevel,
            $photographie->defender->armorLevel,
            $photographie->defender->classCombatBonus,
            $photographie->characterClass,
        );
        $magasin = resolve(BattleFieldStateStore::class);
        $magasin->writeStep((int)$combat->id, 0, $this->aFieldOpenedAgainst($proprietaire, (int)$segment->id, $photographie->units), static function (): void {
        });

        // --- Le monde bouge encore, puis le champ est repris depuis la base ---
        DB::table('users_tech')->where('user_id', $defenseur)->update(['weapon_technology' => $armes + 9]);
        $this->aWarriorsAllianceFoundedBy($defenseur);

        $repris = $magasin->heldLatestStep((int)$combat->id);
        $this->assertNotNull($repris, 'The saved field could not be read back.');

        $unites = array_values(array_filter(
            $repris['state']->defenderUnits,
            static fn ($unite): bool => $unite->fleetMissionId === (int)$segment->id
        ));

        $this->assertNotSame([], $unites, 'The resumed field lost the patrol units.');

        foreach ($unites as $unite) {
            $this->assertSame($puissanceAdmise, $unite->attackPower, 'A resumed patrol unit fires with something acquired after the opening.');
        }
    }

    /**
     * **Un combat spatial ouvert sous la premiere regle photographie le compte tel qu il est lu** : l ancien
     * geste, garde explicitement par le versionnement, et non un historique qui ne connaitrait pas l ouverture.
     */
    public function testASpatialCombatUnderTheFirstRulePhotographsTheAccountAsItIs(): void
    {
        $defenseur = $this->aFreshDefender();
        [$patrouille, $segment, $attaque, $ouverture] = $this->anArrivalOnThePatrolOf($defenseur);

        $combat = resolve(SpatialCombatOpening::class)->openOrJoin($attaque, FrozenPatrolTarget::of($patrouille), $ouverture);
        DB::table('combat_instances')->where('id', $combat->id)->update(['unit_characteristics_version' => 'v1']);

        // Apres l ouverture : sous la premiere regle, la photographie le voit, puisqu elle lit le compte.
        $this->recordCharacterClass($defenseur, CharacterClass::GENERAL);

        new SpatialOpeningState()->capture(CombatInstance::query()->findOrFail($combat->id), $patrouille, $segment, $ouverture);
        $photographie = new SpatialOpeningState()->protectedDefenceOf(CombatInstance::query()->findOrFail($combat->id));

        $this->assertSame(2, $photographie->defender->classCombatBonus, 'A first-rule photograph read a history instead of the account as it is.');
        $this->assertSame(CharacterClass::GENERAL->value, $photographie->characterClass, 'A first-rule photograph read the class from a history.');
    }

    // ------------------------------------------------------------------ le montage

    /**
     * Un defenseur neuf : aucun essai voisin ne partage ce compte, et changer sa classe ne touche personne.
     */
    private function aFreshDefender(): int
    {
        $compte = User::factory()->create(['username' => 'patrouille_' . Str::random(12)]);
        resolve(InitialUserDataService::class)->createFor($compte);

        return (int)$compte->id;
    }

    /**
     * La patrouille posee du defenseur, puis une attaque arrivee trente secondes avant l horloge.
     *
     * **Tout ce qui precede l arrivee est ne avant elle** — les comptes, leurs lignes d historique, la
     * patrouille : l horloge avance de deux minutes avant que l arrivee soit datee.
     *
     * @return array{0: Patrol, 1: FleetMission, 2: FleetMission, 3: int}
     */
    private function anArrivalOnThePatrolOf(int $defenseur): array
    {
        $patrouille = Patrol::forceCreate([
            'user_id' => $defenseur,
            'home_planet_id' => null,
            'state' => PatrolState::Stationed,
            'galaxy' => 4,
            'system' => 77,
            'x' => 120,
            'y' => -80,
            'fuel_reserve' => 5000.0,
            'upkeep_paid_at' => null,
            'order_version' => 1,
        ]);

        $segment = new FleetMission();
        $segment->user_id = $defenseur;
        $segment->patrol_id = (int)$patrouille->id;
        $segment->mission_type = 11;
        $segment->galaxy_to = 4;
        $segment->system_to = 77;
        $segment->x_to = 120;
        $segment->y_to = -80;
        $segment->time_departure = (int)Date::now()->timestamp - 3600;
        $segment->time_arrival = (int)Date::now()->timestamp - 1800;
        $segment->processed = 0;
        $segment->canceled = 0;
        $segment->metal = 0;
        $segment->crystal = 0;
        $segment->deuterium = 0;
        $segment->light_fighter = 40;
        $segment->save();

        $patrouille->forceFill(['current_mission_id' => (int)$segment->id])->save();

        $this->travelTo(Date::createFromTimestamp((int)Date::now()->timestamp + 120));
        $arrivee = (int)Date::now()->timestamp - 30;
        $depart = $this->planetService->getPlanetCoordinates();

        $attaque = new FleetMission();
        $attaque->user_id = $this->currentUserId;
        $attaque->mission_type = 1;
        $attaque->planet_id_from = $this->planetService->getPlanetId();
        $attaque->type_from = PlanetType::Planet->value;
        $attaque->galaxy_from = $depart->galaxy;
        $attaque->system_from = $depart->system;
        $attaque->position_from = $depart->position;
        $attaque->planet_id_to = null;
        $attaque->type_to = PlanetType::SpatialPoint->value;
        $attaque->target_patrol_id = (int)$patrouille->id;
        $attaque->target_patrol_owner_id = $defenseur;
        $attaque->galaxy_to = 4;
        $attaque->system_to = 77;
        $attaque->position_to = 0;
        $attaque->x_to = 120;
        $attaque->y_to = -80;
        $attaque->time_departure = $arrivee - 600;
        $attaque->time_arrival = $arrivee;
        $attaque->processed = 0;
        $attaque->canceled = 0;
        $attaque->metal = 0;
        $attaque->crystal = 0;
        $attaque->deuterium = 0;
        $attaque->battle_ship = 60;
        $attaque->save();

        return [$patrouille, $segment, $attaque, $arrivee];
    }

    private function aWarriorsAllianceFoundedBy(int $joueur): Alliance
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');

        $alliance = resolve(AllianceService::class)->createAlliance(
            $joueur,
            'SA' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Espace ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        // Une alliance fondee a l instant n a pas les quatorze jours qui offrent le premier choix.
        DB::table('users')->where('id', $joueur)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);
        resolve(AllianceClassService::class)->choose(User::query()->findOrFail($joueur), $alliance, AllianceClass::WARRIORS);

        return $alliance;
    }

    /**
     * Le champ initial d une bataille spatiale contre ce proprietaire, ouvert comme le chemin progressif l ouvre.
     */
    private function aFieldOpenedAgainst(FrozenCombatant $proprietaire, int $segmentId, UnitCollection $unites): BattleFieldState
    {
        $attaquantJoueur = new FrozenCombatant($this->currentUserId, 0, 0, 0, 0, null);

        $flotteAttaquante = new UnitCollection();
        $flotteAttaquante->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 10);

        $attaquant = new AttackerFleet();
        $attaquant->units = $flotteAttaquante;
        $attaquant->player = $attaquantJoueur;
        $attaquant->fleetMissionId = 101;
        $attaquant->ownerId = $this->currentUserId;
        $attaquant->cargoResources = new Resources(0, 0, 0, 0);
        $attaquant->isInitiator = true;
        $attaquant->fleetMission = null;

        $site = new SpatialCombatSite(
            resolve(PlayerServiceFactory::class),
            resolve(SettingsService::class),
            $proprietaire,
            new SpatialPoint(120, -80),
            4,
            77,
        );

        $patrouille = new DefenderFleet();
        $patrouille->units = $unites;
        $patrouille->player = $proprietaire;
        $patrouille->fleetMissionId = $segmentId;
        $patrouille->ownerId = $proprietaire->getId();
        $patrouille->fleetMission = null;

        $contexte = LootContext::fromObservedFacts(
            new LootPolicy(false, new AttackerCargoShare(0, 0)),
            [AttackerFleetSnapshot::of($attaquant, ActorKind::Player, false, 0)],
            ['body_key' => CombatParticipantKey::UNIDENTIFIED_BODY, 'owner_id' => $proprietaire->getId()],
            (int)Date::now()->timestamp,
            LootAllocatorRegistry::default()->currentVersion(),
        );

        $ouverture = new SpatialFieldOpening(
            [$attaquant],
            $site,
            [DefenderFleet::fromPlanet($site), $patrouille],
            resolve(SettingsService::class),
            $contexte,
        );

        return $ouverture->withDraws(new SeededDraws(12345))->initialField();
    }

    private function livingWeaponLevel(int $joueur): int
    {
        return resolve(PlayerServiceFactory::class)->make($joueur, true)->getResearchLevel('weapon_technology');
    }

    private function livingBonus(int $joueur): int
    {
        return resolve(PlayerServiceFactory::class)->make($joueur, true)->getCombatResearchBonusLevels();
    }
}
