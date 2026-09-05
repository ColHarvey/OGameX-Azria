<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Application\FrozenCombatApplicationContext;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\CombatResolutionService;
use OGame\Combat\Services\CombatSettlementService;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Models\WreckField;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use Tests\FleetDispatchTestCase;

/**
 * Ce qu'un joueur devient pendant la bataille ne change pas l'issue de la bataille.
 *
 * ## La garantie
 *
 * Quelques faits du monde **changent ce que l'application ecrit** : la classe General fait
 * apparaitre un champ d'epaves, le niveau de chantier spatial en fixe la taille, la classe des deux
 * camps figure au rapport. Sur le chemin instantane, les lire vivants est juste — quelques
 * millisecondes separent le calcul de l'application.
 *
 * Un combat durable dure des heures. Ces essais changent la classe du joueur **entre la cloture et
 * l'echeance**, et verifient que le rapport raconte ce qui etait vrai a la cloture. Sans la
 * photographie, deux rejeux du meme combat ne donneraient pas le meme rapport, et personne ne
 * saurait dire lequel est le bon.
 */
class FrozenApplicationContextTest extends FleetDispatchTestCase
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

        // **Aucun seuil de champ d'epaves.** Ces essais portent sur le niveau de chantier spatial,
        // pas sur le seuil : laisser les seuils par defaut ferait dependre le champ d'epaves de
        // l'ampleur des pertes, que le moteur tire au sort. Ils sont remis au demontage — les
        // reglages vivent en base et survivent a l'essai.
        $settingsService->set('wreck_field_min_resources_loss', 0);
        $settingsService->set('wreck_field_min_fleet_percentage', 0);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function tearDown(): void
    {
        $reglages = resolve(SettingsService::class);
        $reglages->set('wreck_field_min_resources_loss', 150000);
        $reglages->set('wreck_field_min_fleet_percentage', 5);
        $reglages->set('debris_field_from_ships', 30);
        $reglages->set('wreck_field_lifetime_hours', 72);

        parent::tearDown();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * La classe est celle de la cloture, meme si le joueur en a change depuis.
     */
    public function testTheReportTellsTheClassTheAttackerHadWhenTheBattleWasComputed(): void
    {
        [$combat, $attaquant] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::COLLECTOR);

        // Entre la cloture et l'echeance, l'attaquant devient General.
        DB::table('users')->where('id', $attaquant->id)->update(['character_class' => CharacterClass::GENERAL->value]);

        $this->settle($combat);

        $combat->refresh();
        $rapport = BattleReport::query()->find($combat->battle_report_id);
        $this->assertNotNull($rapport);

        $this->assertSame(
            CharacterClass::COLLECTOR->getName(),
            $rapport->attacker['character_class'] ?? null,
            'The report tells the class the attacker has now, not the one he had when the battle was computed.'
        );

        $this->assertArrayNotHasKey(
            'attacker_wreckage',
            $rapport->general ?? [],
            'A wreck field appeared because the attacker changed class while the battle was under way.'
        );
    }

    /**
     * Le champ d'epaves a la taille que le chantier spatial avait a la cloture.
     *
     * Un chantier monte d'un niveau pendant les heures que dure la bataille : sans photographie, la
     * part recuperable serait celle du chantier d'aujourd'hui, appliquee a des pertes d'hier.
     */
    public function testTheWreckFieldHasTheSizeTheSpaceDockHadWhenTheBattleWasComputed(): void
    {
        [$combat, $attaquant, $mission] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::GENERAL);

        $origine = (int)$mission->planet_id_from;
        $document = $combat->frozen_settings;
        $this->assertIsArray($document);
        $this->assertSame(1, $document['space_docks'][$origine], 'The origin space dock was not photographed at level one.');

        // Entre la cloture et l'echeance, le chantier spatial monte de onze niveaux.
        DB::table('planets')->where('id', $origine)->update(['space_dock' => 12]);

        $this->settle($combat);

        $combat->refresh();
        $rapport = BattleReport::query()->find($combat->battle_report_id);
        $this->assertNotNull($rapport);

        $perdus = BattleResultCodec::fromStorage($combat->battle_result)->attackerUnitsLost;
        $this->assertGreaterThan(0, $perdus->getAmount(), 'The attacker lost nothing: no wreck field would exist either way.');

        $epaves = new WreckFieldService($this->playerOf($attaquant), resolve(SettingsService::class));
        $auNiveauGele = $this->asReportShape($epaves->calculateShipsForWreckField($perdus, 1));
        $auNiveauCourant = $this->asReportShape($epaves->calculateShipsForWreckField($perdus, 12));

        $this->assertNotSame([], $auNiveauGele, 'The frozen space dock level recovers nothing from these losses: the test would compare two absences.');
        $this->assertNotSame($auNiveauGele, $auNiveauCourant, 'Both space dock levels give the same wreck field: the test would prove nothing.');

        $this->assertSame(
            $auNiveauGele,
            $rapport->general['attacker_wreckage'] ?? null,
            'The wreck field was sized by the space dock as it is now, not as it was when the battle was computed.'
        );
    }

    /**
     * La photographie porte les joueurs des deux camps et les corps d'origine.
     */
    public function testTheSnapshotCarriesBothSidesAndTheOriginBodies(): void
    {
        [$combat, $attaquant, $mission] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::COLLECTOR);

        $document = $combat->frozen_settings;
        $this->assertIsArray($document, 'The closure wrote no application snapshot.');

        $this->assertSame(FrozenCombatApplicationContext::SCHEMA, $document['schema']);
        $this->assertArrayHasKey($attaquant->id, $document['players'], 'The attacking player was not photographed.');
        $this->assertArrayHasKey((int)$mission->planet_id_from, $document['space_docks'], 'The origin body of the attacking fleet was not photographed.');
        $this->assertGreaterThanOrEqual(1, $document['space_docks'][(int)$mission->planet_id_from]);
        $this->assertArrayHasKey('min_resources_loss', $document['wreck_field']);

        // Le document se relit tel qu'il a ete ecrit.
        $this->assertSame($document, FrozenCombatApplicationContext::fromStorage($document)->toStorage());
    }

    /**
     * Un joueur absent de la photographie est un refus, pas un repli sur le monde courant.
     *
     * Se rabattre sur le monde vivant ferait exactement ce que la photographie existe pour empecher,
     * et le ferait en silence.
     */
    public function testAPlayerMissingFromTheSnapshotIsRefusedRatherThanReadLive(): void
    {
        [$combat] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::COLLECTOR);

        $document = $combat->frozen_settings;
        $this->assertIsArray($document);

        $document['players'] = [];
        DB::table('combat_instances')->where('id', $combat->id)->update(['frozen_settings' => json_encode($document)]);

        $this->expectException(CorruptedFrozenApplicationContext::class);
        $this->settle($combat);
    }

    /**
     * Le champ d'epaves a la taille que la part de debris avait a la cloture.
     *
     * Un administrateur change `debris_field_from_ships` pendant les heures que dure la bataille :
     * sans photographie, la part recuperable serait celle d'aujourd'hui, appliquee a des pertes d'hier.
     */
    public function testTheWreckFieldIsSizedByTheDebrisShareFrozenAtTheClosure(): void
    {
        [$combat, $attaquant] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::GENERAL);

        $document = $combat->frozen_settings;
        $this->assertIsArray($document);
        $this->assertSame(30, $document['wreck_field']['debris_field_from_ships'], 'The debris share was not photographed at thirty percent.');

        // Entre la cloture et l'echeance, la part de debris passe de trente a quatre-vingt-dix.
        resolve(SettingsService::class)->set('debris_field_from_ships', 90);

        $this->settle($combat);
        $combat->refresh();

        $rapport = BattleReport::query()->find($combat->battle_report_id);
        $this->assertNotNull($rapport);

        $perdus = BattleResultCodec::fromStorage($combat->battle_result)->attackerUnitsLost;
        $this->assertGreaterThan(0, $perdus->getAmount(), 'The attacker lost nothing: no wreck field would exist either way.');

        $reglages = resolve(SettingsService::class);
        $aLaPartGelee = $this->asReportShape((new WreckFieldService($this->playerOf($attaquant), $reglages, 30))->calculateShipsForWreckField($perdus, 1));
        $aLaPartCourante = $this->asReportShape((new WreckFieldService($this->playerOf($attaquant), $reglages, 90))->calculateShipsForWreckField($perdus, 1));

        $this->assertNotSame([], $aLaPartGelee, 'The frozen share recovers nothing from these losses: the test would compare two absences.');
        $this->assertNotSame($aLaPartGelee, $aLaPartCourante, 'Both shares give the same wreck field: the test would prove nothing.');
        $this->assertSame(
            $aLaPartGelee,
            $rapport->general['attacker_wreckage'] ?? null,
            'The wreck field was sized by the debris share as it is now, not as it was when the battle was computed.'
        );
    }

    /**
     * Le champ d'epaves du defenseur nait a l'echeance du combat, et vit la duree figee a la cloture.
     *
     * Le travailleur applique dix heures apres l'echeance, et l'administrateur a ramene la duree de
     * vie a une heure entre-temps : le champ doit naitre a l'echeance et vivre soixante-douze heures.
     */
    public function testADefenderWreckFieldIsDatedAtTheDeadlineWithTheFrozenLifetime(): void
    {
        [$combat] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::GENERAL, 300);

        $result = BattleResultCodec::fromStorage($combat->battle_result);
        $this->assertTrue((bool)($result->wreckField['formed'] ?? false), 'The defender left no wreck field: nothing would be dated.');
        $this->assertNotSame([], $result->wreckField['ships'] ?? [], 'The defender wreck field holds no ship.');

        $document = $combat->frozen_settings;
        $this->assertIsArray($document);
        $this->assertSame(72, $document['wreck_field']['lifetime_hours']);
        $this->assertSame((int)$combat->ends_at, $document['applied_at'], 'The application instant is not the deadline.');

        // Entre la cloture et l'echeance, la duree de vie tombe a une heure ; et le travailleur est en retard.
        resolve(SettingsService::class)->set('wreck_field_lifetime_hours', 1);
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at + 36_000));

        $this->settle($combat);

        $cible = DB::table('planets')->where('id', $combat->target_planet_id)->first();
        $this->assertNotNull($cible);
        $champ = WreckField::query()
            ->where('galaxy', $cible->galaxy)
            ->where('system', $cible->system)
            ->where('planet', $cible->planet)
            ->where('owner_player_id', $cible->user_id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($champ, 'No wreck field was created for the defender.');
        $this->assertSame((int)$combat->ends_at, (int)$champ->created_at->timestamp, 'The wreck field was dated at the worker clock, not at the deadline.');
        $this->assertSame((int)$combat->ends_at + 72 * 3_600, (int)$champ->expires_at->timestamp, 'The wreck field lives the lifetime of today, not the one frozen at the closure.');
    }

    /**
     * Une photographie qui ne porte pas exactement l'effectif est refusee, meme complete.
     */
    public function testASnapshotThatDoesNotCoverTheRosterExactlyIsRefused(): void
    {
        [$combat] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::COLLECTOR);

        $document = $combat->frozen_settings;
        $this->assertIsArray($document);
        $document['players'][999_999] = ['is_general' => false, 'reaper_debris_percentage' => 0.3, 'character_class' => null];
        DB::table('combat_instances')->where('id', $combat->id)->update(['frozen_settings' => json_encode($document)]);

        $this->expectException(CorruptedFrozenApplicationContext::class);
        $this->settle($combat);
    }

    /**
     * Un instant d'application qui n'est pas l'echeance est refuse.
     */
    public function testAnApplicationInstantThatIsNotTheDeadlineIsRefused(): void
    {
        [$combat] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::COLLECTOR);

        $document = $combat->frozen_settings;
        $this->assertIsArray($document);
        $document['applied_at'] = (int)$combat->ends_at + 1;
        DB::table('combat_instances')->where('id', $combat->id)->update(['frozen_settings' => json_encode($document)]);

        $this->expectException(CorruptedFrozenApplicationContext::class);
        $this->settle($combat);
    }

    /**
     * Un combat entre joueurs n'a pas de recit : une absence explicite, pas un hasard inutilise.
     */
    public function testAPlayerAttackDrawsNoNarrative(): void
    {
        [$combat] = $this->anEngagedCombatWhoseAttackerIs(CharacterClass::COLLECTOR);

        $document = $combat->frozen_settings;
        $this->assertIsArray($document);
        $this->assertSame(
            ['motive' => null, 'variation' => null, 'variations' => null],
            $document['npc_narrative'],
            'A narrative was drawn for a combat between players.'
        );
    }

    /**
     * Un combat ouvert, clos et engage, dont l'attaquant porte la classe demandee.
     *
     * La cible porte deux cents lanceurs : l'attaquant l'emporte mais **perd des vaisseaux**, sans
     * quoi aucun champ d'epaves n'existerait et le niveau de chantier spatial ne changerait rien.
     *
     * @return array{0: CombatInstance, 1: User, 2: FleetMission}
     */
    private function anEngagedCombatWhoseAttackerIs(CharacterClass $classe, int $defenderFighters = 0): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $attaquant = User::query()->findOrFail($this->currentUserId);
        DB::table('users')->where('id', $attaquant->id)->update(['character_class' => $classe->value]);

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        // **Une planete propre, et pas la voisine partagee.** Celle-la porte ce que les essais du
        // meme processus y ont laisse — des milliers de tourelles, parfois —, et cet essai compte sur
        // un rapport dont les faits sont ceux qu il a poses. Il echouait environ une fois sur deux.
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($units, new Resources(0, 0, 0, 0));

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
            'rocket_launcher' => 200,
            // Des vaisseaux au defenseur, quand l'essai veut qu'il laisse un champ d'epaves.
            'light_fighter' => $defenderFighters,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        $combat = (new CombatOpeningService())->openOrJoin($mission, $cible->getPlanetId(), (int)$mission->time_arrival);

        $this->assertNotNull(CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first());

        // **L'ouverture a ferme le ralliement elle-meme.** Personne n'etait attendu : la fenetre
        // est nulle, et la protection contre le harcelement veut qu'elle ne fasse pas attendre le
        // corps une minute de plus. La bataille est donc deja calculee.
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);

        return [$combat, $attaquant, $mission];
    }

    /**
     * Le champ d'epaves sous la forme que le rapport lui donne.
     *
     * @param array<int, array{machine_name: string, quantity: int, repair_progress: int}> $vaisseaux
     * @return array<string, int>
     */
    private function asReportShape(array $vaisseaux): array
    {
        $forme = [];

        foreach ($vaisseaux as $vaisseau) {
            $forme[$vaisseau['machine_name']] = $vaisseau['quantity'];
        }

        return $forme;
    }

    private function playerOf(User $utilisateur): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($utilisateur->id, true);
    }

    private function settle(CombatInstance $combat): void
    {
        $mission = resolve(AttackMission::class);

        (new CombatSettlementService(resolve(CombatResolutionService::class)))->settle(
            $combat->id,
            $mission,
            function (): void {
                // La creation du retour n'est pas l'objet de ces essais : le rapport l'est.
            },
            (int)$combat->ends_at
        );
    }
}
