<?php

namespace Tests\Feature\Combat;

use ArrayObject;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Presentation\BattleReportParticipants;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\CombatResolutionService;
use OGame\Combat\Services\CombatRoster;
use OGame\Combat\Services\CombatRosterReader;
use OGame\Combat\Services\CombatSettlementService;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatantFrozenAtEntry;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\History\ClassHistoryRecorder;
use OGame\Models\BattleReport;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;
use Tests\RecordsClassHistory;

/**
 * **La classe elle-meme se gele a l admission**, pas seulement son bonus (correction approuvee par Keven,
 * 13 septembre 2026).
 *
 * ## Le defaut
 *
 * `CombatantFrozenAtEntry` gelait les trois niveaux et le bonus des classes, mais son `getUser()` rendait le
 * compte lu quand la bataille se calculait. Tout ce que le jeu decide depuis la classe suivait donc le compte du
 * moment ou un travailleur passait : la manoeuvre de Hamill, le fret, la part de pillage du Decouvreur. Et la
 * photographie d application relisait la classe de l initiatrice et de la garnison sur deux comptes charges
 * vivants : champ d epaves du General et classe du rapport compris.
 *
 * ## Ce qui est prouve ici
 *
 * Chaque effet est eprouve **apres un changement de classe, une persistance et un rechargement**, et dans les
 * deux sens quand un seul laisserait passer une lecture constante :
 *
 * - la manoeuvre de Hamill ;
 * - la capacite de fret ;
 * - le champ d epaves du General, pour une attaque seule, et **flotte par flotte** quand deux flottes d un meme
 *   joueur sont entrees sous deux classes ;
 * - la part de pillage du Decouvreur ;
 * - la classe nommee au rapport, cote attaquant et cote garnison ;
 * - la cargaison des survivants, qui rentre entiere quand leur capacite a baisse — sans la part des vaisseaux
 *   detruits ;
 * - une ligne ecrite avant l enregistrement de la classe, reprise **depuis l historique a son admission**, ou
 *   suspendue quand l historique ne sait pas ; une classe enregistree que le jeu ne connait pas, suspendue
 *   aussi. Jamais la classe courante.
 *
 * ## Le montage
 *
 * Une attaque seule dont la fenetre est nulle : l ouverture calcule la bataille dans sa transaction. Le
 * travailleur passe **dix secondes apres l arrivee**, la classe change a la cinquieme : l admission est
 * l arrivee, le traitement lit un autre compte. Le reglement repart de la base, dans une application
 * rechargee.
 */
final class ClassIdentityFrozenAtAdmissionTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use RecordsClassHistory;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /**
     * Le proprietaire d une cible rendue inactive, et la derniere connexion a lui rendre.
     *
     * @var array{0: int, 1: mixed}|null
     */
    private array|null $inactiveOwner = null;

    private int|null $hamillChance = null;

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();

        // **Aucun seuil de champ d epaves** : ces essais portent sur la classe, pas sur l ampleur des pertes, que
        // le moteur tire au sort. Remis au demontage — les reglages vivent en base.
        $reglages = resolve(SettingsService::class);
        $reglages->set('wreck_field_min_resources_loss', 0);
        $reglages->set('wreck_field_min_fleet_percentage', 0);
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        $reglages = resolve(SettingsService::class);
        $reglages->set('persistent_combat_enabled', '0');
        $reglages->set('wreck_field_min_resources_loss', 150000);
        $reglages->set('wreck_field_min_fleet_percentage', 5);

        if ($this->hamillChance !== null) {
            $reglages->set('hamill_manoeuvre_chance', $this->hamillChance);
        }

        // La cible propre appartient au joueur etranger que les essais d un processus partagent.
        if ($this->inactiveOwner !== null) {
            DB::table('users')->where('id', $this->inactiveOwner[0])->update(['time' => $this->inactiveOwner[1]]);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------ la manoeuvre de Hamill

    /**
     * **Un General a son admission garde sa manoeuvre de Hamill**, meme devenu Collecteur avant le calcul.
     *
     * **Le temoin est le declenchement, pas l Etoile de la mort perdue**, et c est mesure : les deux moteurs
     * ne comptent pas cette perte de la meme facon — le moteur Rust retire l Etoile de `defenderUnitsStart`
     * sans la retirer des flottes qu il envoie a la bibliotheque, si bien qu elle continue de tirer et ne peut
     * plus etre comptee perdue. L ecart est reel, il est anterieur a ce gel, et il est remonte a part. Ce que
     * cet essai doit etablir — **quelle classe decide la manoeuvre** — se lit sur le declenchement, qui est
     * pris du meme cote de la couture dans les deux moteurs.
     */
    public function testAGeneralAtItsAdmissionKeepsItsHamillManoeuvre(): void
    {
        $this->hamillAlwaysTriggers();

        [$combat] = $this->aLoneAttackProcessedLate(
            CharacterClass::GENERAL,
            function (): void {
                $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);
            },
            ['deathstar' => 1],
        );

        $this->assertSame(CharacterClass::COLLECTOR->value, $this->livingClassOf($this->currentUserId), 'The premise is missing: the account is not a Collector when the battle is computed.');

        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'A General at its admission lost its Hamill manoeuvre because its account changed class before the battle was computed.');
    }

    /**
     * **Et une classe prise apres l admission ne l offre pas.** Sans ce second sens, une lecture qui rendrait
     * toujours General passerait le premier.
     */
    public function testAClassTakenAfterTheAdmissionDoesNotGrantTheHamillManoeuvre(): void
    {
        $this->hamillAlwaysTriggers();

        [$combat] = $this->aLoneAttackProcessedLate(
            CharacterClass::COLLECTOR,
            function (): void {
                $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
            },
            ['deathstar' => 1],
        );

        $this->assertSame(CharacterClass::GENERAL->value, $this->livingClassOf($this->currentUserId), 'The premise is missing: the account is not a General when the battle is computed.');
        $this->assertFalse(BattleResultCodec::fromStorage($combat->battle_result)->hamillManoeuvreTriggered, 'A class taken after the admission granted the Hamill manoeuvre.');
    }

    // ------------------------------------------------------------------ le fret

    /**
     * **La capacite de fret est celle de la classe d admission.** Collecteur a l arrivee, ses petits
     * transporteurs portent davantage ; General au calcul, ils ne porteraient que leur base.
     */
    public function testTheCargoCapacityIsTheOneOfTheClassAtAdmission(): void
    {
        [$combat] = $this->aLoneAttackProcessedLate(CharacterClass::COLLECTOR, function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        });

        $flotte = BattleResultCodec::fromStorage($combat->battle_result)->attackerFleetResults[0];

        // Le meme effectif sous chacune des deux classes : l essai n a de sens que si elles different. La
        // bataille est deja figee ; changer la classe maintenant n y touche plus.
        $auCollecteur = $this->capacityOfTheLoneAttackUnder(CharacterClass::COLLECTOR);
        $auGeneral = $this->capacityOfTheLoneAttackUnder(CharacterClass::GENERAL);

        $this->assertNotSame($auCollecteur, $auGeneral, 'The premise is missing: both classes give the same capacity.');
        $this->assertSame($auCollecteur, $flotte->startingCargoCapacity, 'The cargo capacity was computed with the class of the account when the battle was computed, not at the admission.');
    }

    /**
     * **La cargaison des survivants rentre entiere quand leur capacite a baisse** — et elle seule.
     *
     * Parti Collecteur, soutes pleines ; devenu General avant son arrivee. A l admission, sa flotte porte plus
     * que sa capacite. La bataille en detruit une partie : la cargaison de ces vaisseaux-la est perdue, comme
     * toujours. Ce qui a survecu n est plus coupe a la capacite : le plafond ne borne que ce que la bataille
     * ajoute, et ici elle n a laisse aucune place.
     */
    public function testTheCargoOfTheSurvivorsComesHomeWholeWhenTheirCapacityFell(): void
    {
        $chargement = 300_000;

        [$combat, $mission] = $this->aLoneAttackProcessedLate(
            CharacterClass::COLLECTOR,
            function (): void {
            },
            ['rocket_launcher' => 200],
            new Resources($chargement, 0, 0, 0),
            avantLArrivee: function (): void {
                $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
            },
        );

        $flotte = BattleResultCodec::fromStorage($combat->battle_result)->attackerFleetResults[0];

        $this->assertSame($chargement, (int)$mission->metal, 'The premise is missing: the fleet did not leave with its full load.');
        $this->assertLessThan($chargement, $flotte->startingCargoCapacity, 'The premise is missing: the capacity at the admission is not below the load.');
        $this->assertLessThan($flotte->startingCargoCapacity, $flotte->survivingCargoCapacity, 'The premise is missing: no capacity was destroyed, the lost share would not show.');
        $this->assertGreaterThan($flotte->survivingCargoCapacity, (int)$flotte->survivingCargo->sum(), 'The premise is missing: the surviving cargo fits the surviving capacity, the cap would change nothing.');

        $retours = $this->settleFromStorage($combat);
        $rapporte = $retours[(int)$mission->id]['ressources'] ?? null;

        $this->assertNotNull($rapporte, 'The survivors have no return.');

        // Ressource par ressource : ce qui rentre est la cargaison survivante, entiere, et rien de plus — la
        // bataille ne lui a laisse aucune place pour du butin.
        $this->assertSame((int)$flotte->survivingCargo->metal->get(), (int)$rapporte->metal->get(), 'The metal the survivors carried was cut to their capacity on the way home.');
        $this->assertSame((int)$flotte->survivingCargo->crystal->get(), (int)$rapporte->crystal->get(), 'The return brought crystal the surviving cargo left no room for.');
        $this->assertSame((int)$flotte->survivingCargo->deuterium->get(), (int)$rapporte->deuterium->get(), 'The deuterium the survivors carried was cut, or loot was added where no room was left.');
        $this->assertLessThan($chargement, (int)$rapporte->metal->get(), 'The cargo of the destroyed ships came home with the survivors.');
    }

    // ------------------------------------------------------------------ le champ d epaves du General

    /**
     * **Un General a son admission rapporte son champ d epaves**, meme devenu Collecteur avant le calcul.
     */
    public function testAGeneralAtItsAdmissionBringsItsWreckFieldHome(): void
    {
        [$combat, $mission] = $this->aLoneAttackProcessedLate(CharacterClass::GENERAL, function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);
        }, ['rocket_launcher' => 200]);

        $this->assertLossesAndSurvivors($combat);

        $retours = $this->settleFromStorage($combat);

        $this->assertNotNull($retours[(int)$mission->id]['epaves'] ?? null, 'A General at its admission brought no wreck field home: the class of the account at the closure decided it.');
        $this->assertNotEmpty($this->reportOf($combat)->general['attacker_wreckage'] ?? [], 'The report shows no wreck field for a General at its admission.');
    }

    /**
     * **Une classe prise apres l admission ne rapporte aucun champ d epaves.**
     */
    public function testAClassTakenAfterTheAdmissionBringsNoWreckFieldHome(): void
    {
        [$combat, $mission] = $this->aLoneAttackProcessedLate(CharacterClass::COLLECTOR, function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        }, ['rocket_launcher' => 200]);

        $this->assertLossesAndSurvivors($combat);

        $retours = $this->settleFromStorage($combat);

        $this->assertArrayHasKey((int)$mission->id, $retours, 'The survivors have no return.');
        $this->assertNull($retours[(int)$mission->id]['epaves'], 'A class taken after the admission brought a wreck field home.');
        $this->assertArrayNotHasKey('attacker_wreckage', $this->reportOf($combat)->general ?? [], 'The report shows a wreck field for a class taken after the admission.');
    }

    /**
     * **Deux flottes d un meme joueur, entrees sous deux classes, decident chacune de leur champ d epaves.**
     *
     * L ouvreuse arrive General ; son joueur devient Collecteur avant l arrivee de la seconde vague. La
     * photographie par joueur n en garde qu une : sans la classe par flotte, la vague rapportait les epaves de
     * son General d ouvreuse.
     */
    public function testTwoFleetsOfOnePlayerAdmittedUnderTwoClassesDecideTheirWreckFieldsApart(): void
    {
        [$ouvreuse, , $ouverture] = $this->aRallyAboutToOpen(200);

        // General avant l arrivee de l ouvreuse : l horloge est encore au depart.
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);
        $vague = $this->theSecondWaveOf($combat);

        $this->travelTo(Date::createFromTimestamp($ouverture + 5));
        $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);

        $this->closeAt($combat, (int)$vague->time_arrival + 30);
        $relu = CombatInstance::query()->findOrFail($combat->id);

        $document = $relu->frozen_settings;
        $this->assertIsArray($document);
        $this->assertSame(
            [(int)$ouvreuse->id => true, (int)$vague->id => false],
            $document['attacker_generals'] ?? null,
            'The closure did not photograph the General class of each fleet at its own admission.'
        );

        foreach (BattleResultCodec::fromStorage($relu->battle_result)->attackerFleetResults as $flotte) {
            $this->assertGreaterThan(0, $flotte->unitsLost->getAmount(), 'The premise is missing: fleet ' . $flotte->fleetMissionId . ' lost nothing, no wreck field would exist either way.');
            $this->assertTrue($flotte->hasSurvivors(), 'The premise is missing: fleet ' . $flotte->fleetMissionId . ' has no survivor to bring a wreck field home.');
        }

        $retours = $this->settleFromStorage($relu);

        $this->assertNotNull($retours[(int)$ouvreuse->id]['epaves'] ?? null, 'The fleet admitted as a General brought no wreck field home.');
        $this->assertArrayHasKey((int)$vague->id, $retours, 'The second wave has no return.');
        $this->assertNull($retours[(int)$vague->id]['epaves'], 'The fleet admitted as a Collector brought a wreck field home: the class of another fleet of its player decided it.');
    }

    // ------------------------------------------------------------------ la part du Decouvreur

    /**
     * **La part de pillage du Decouvreur est celle de la classe d admission.** Contre une cible inactive, un
     * Decouvreur pille davantage ; devenu General avant le calcul, il la garde.
     */
    public function testTheDiscovererShareIsTheOneOfTheClassAtAdmission(): void
    {
        [$combat] = $this->aLoneAttackProcessedLate(CharacterClass::DISCOVERER, function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        }, cibleInactive: true);

        $this->assertSame(
            CargoWeightedV1::BASE_RATE + CargoWeightedV1::DISCOVERER_BONUS,
            BattleResultCodec::fromStorage($combat->battle_result)->lootRateInBasisPoints,
            'A Discoverer at its admission lost its share because its account changed class before the battle was computed.'
        );
    }

    /**
     * **Et une classe de Decouvreur prise apres l admission ne la donne pas.**
     */
    public function testADiscovererClassTakenAfterTheAdmissionGivesNoShare(): void
    {
        [$combat] = $this->aLoneAttackProcessedLate(CharacterClass::GENERAL, function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::DISCOVERER);
        }, cibleInactive: true);

        $this->assertSame(
            CargoWeightedV1::BASE_RATE,
            BattleResultCodec::fromStorage($combat->battle_result)->lootRateInBasisPoints,
            'A Discoverer class taken after the admission raised the loot rate.'
        );
    }

    // ------------------------------------------------------------------ le rapport

    /**
     * **Le rapport nomme la classe que l attaquant avait a son admission.**
     */
    public function testTheReportNamesTheClassTheAttackerHadAtItsAdmission(): void
    {
        [$combat] = $this->aLoneAttackProcessedLate(CharacterClass::COLLECTOR, function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        });

        $this->settleFromStorage($combat);

        $this->assertSame(
            CharacterClass::COLLECTOR->getName(),
            $this->reportOf($combat)->attacker['character_class'] ?? null,
            'The report names the class the account had when the battle was computed, not at the admission.'
        );
    }

    /**
     * **Le bloc des participants d un combat durable gele ce que la bataille a employe** (journal §161) : la classe de
     * General prise entre l arrivee et le traitement (l admission lit l historique a l arrivee) et six niveaux d armes
     * finis entre la cloture et le reglement n atteignent ni ses niveaux, ni ses caracteristiques par type d unite, ni
     * ses niveaux de classe — le rapport lit le combattant gele, jamais le compte au moment du reglement.
     */
    public function testTheParticipantsBlockFreezesWhatTheAttackerHadAtItsAdmission(): void
    {
        $entree = 3;
        [$combat] = $this->aLoneAttackProcessedLate(CharacterClass::COLLECTOR, function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        }, [], null, false, function () use ($entree): void {
            $this->playerSetResearchLevel('weapon_technology', $entree);
        });

        // --- Le compte bouge entre la cloture (bataille calculee) et le reglement (rapport ecrit) ---
        $this->playerSetResearchLevel('weapon_technology', $entree + 6);
        $this->assertSame($entree + 6, resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->getResearchLevel('weapon_technology'), 'The premise is missing: the research did not change.');

        $this->settleFromStorage($combat);

        $bloc = BattleReportParticipants::fromStorage($this->reportOf($combat)->participants);
        $this->assertNotNull($bloc, 'The settlement froze no participants block.');
        $this->assertCount(1, $bloc['attackers']);
        $attaquant = $bloc['attackers'][0];
        $this->assertSame($this->currentUserId, $attaquant['player_id']);
        $this->assertSame($entree, $attaquant['weapon_technology'], 'The block reads the research finished after the admission.');
        $this->assertSame(0, $attaquant['class_combat_levels'], 'The block reads the class bought after the admission.');
        $this->assertSame(CharacterClass::COLLECTOR->getName(), $attaquant['character_class']);
        $base = ObjectService::getUnitObjectByMachineName('light_fighter')->properties->attack->rawValue;
        $this->assertSame($base + intdiv($base * $entree * 10, 100), $attaquant['unit_characteristics']['light_fighter']['weapon'], 'The weapon of the light fighter is the one the battle used.');
        $this->assertSame(350, $attaquant['units_start']['light_fighter']);
        $this->assertSame(BattleReportParticipants::GARRISON_KEY, $bloc['defenders'][0]['key']);
        $this->assertSame('garrison', $bloc['defenders'][0]['kind']);
    }

    /**
     * **Le rapport nomme la classe que la garnison avait a l ouverture**, pas celle que son proprietaire a prise
     * avant que le travailleur passe.
     */
    public function testTheReportNamesTheClassTheGarrisonHadAtTheOpening(): void
    {
        /** @var ArrayObject<string, mixed> $avant */
        $avant = new ArrayObject();

        try {
            [$combat] = $this->aLoneAttackProcessedLate(null, function (int $proprietaire) use ($avant): void {
                $avant['proprietaire'] = $proprietaire;
                $avant['classe'] = DB::table('users')->where('id', $proprietaire)->value('character_class');
                $avant['instant'] = (int)Date::now()->timestamp;
                $this->recordCharacterClass($proprietaire, CharacterClass::GENERAL);
            });

            $classe = is_numeric($avant['classe']) ? CharacterClass::from((int)$avant['classe']) : null;
            $this->assertNotSame(CharacterClass::GENERAL, $classe, 'The premise is missing: the defender was already a General at the opening.');

            $this->settleFromStorage($combat);

            $this->assertSame(
                $classe?->getName(),
                $this->reportOf($combat)->defender['character_class'] ?? null,
                'The report names the class the defender took after the attacker arrived, not the one it had at the opening.'
            );
        } finally {
            if (is_int($avant['proprietaire'] ?? null)) {
                // **La remise en etat se date apres la ligne qu elle annule.** Le reglement a ramene l horloge a
                // l echeance, cinq secondes avant la classe posee ci-dessus : ecrite la, la ligne de retour n etait
                // pas la derniere, et le proprietaire — partage par tout le processus — restait illisible.
                $this->travelTo(Date::createFromTimestamp(max((int)Date::now()->timestamp, (int)$avant['instant']) + 1));
                $this->recordCharacterClass($avant['proprietaire'], is_numeric($avant['classe']) ? CharacterClass::from((int)$avant['classe']) : null);
            }
        }
    }

    // ------------------------------------------------------------------ les lignes sans classe

    /**
     * **Une ligne ecrite avant l enregistrement de la classe la reprend dans l historique, a son admission.**
     *
     * General a l arrivee de l ouvreuse, Collecteur ensuite. La ligne perd sa classe et son marqueur, comme une
     * admission anterieure a la migration : la bataille compose l ouvreuse en General, et la photographie
     * d application nomme General — jamais le Collecteur que le compte est devenu.
     */
    public function testARowWrittenBeforeTheClassWasRecordedTakesItFromTheHistoryAtItsAdmission(): void
    {
        [$ouvreuse, , $ouverture] = $this->aRallyAboutToOpen();
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);

        $this->assertSame(1, (int)$this->entryRowOf($combat, (int)$combat->mission_id)->value('character_class_recorded'), 'The opener row was written without recording its class.');
        $this->assertSame(CharacterClass::GENERAL->value, (int)$this->entryRowOf($combat, (int)$combat->mission_id)->value('character_class'), 'The opener row did not record the class it was admitted with.');

        // La ligne telle qu une admission anterieure a la migration l a laissee : ni classe, ni marqueur.
        $this->entryRowOf($combat, (int)$combat->mission_id)->update(['character_class' => null, 'character_class_recorded' => 0]);

        $vague = $this->theSecondWaveOf($combat);
        $this->travelTo(Date::createFromTimestamp($ouverture + 5));
        $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);

        $this->closeAt($combat, (int)$vague->time_arrival + 30);
        $relu = CombatInstance::query()->findOrFail($combat->id);

        $this->assertSame(0, (int)$this->entryRowOf($combat, (int)$combat->mission_id)->value('character_class_recorded'), 'The closure rewrote a first entry.');

        $initiatrice = $this->theBattleRosterOf($relu)->initiatorOwner;
        $this->assertInstanceOf(CombatantFrozenAtEntry::class, $initiatrice);
        $this->assertSame(CharacterClass::GENERAL, $initiatrice->characterClass(), 'A row without its class took the class of the account instead of the history at its admission.');

        $document = $relu->frozen_settings;
        $this->assertIsArray($document);
        $this->assertSame(
            CharacterClass::GENERAL->value,
            $document['players'][$this->currentUserId]['character_class'] ?? null,
            'The application photograph did not take the class of the admission for a row written before the class was recorded.'
        );
    }

    /**
     * **Et quand l historique ne sait pas, la fermeture se suspend** : ni classe courante, ni classe inventee.
     *
     * L historique du compte ne commence qu apres l admission de l ouvreuse ; il sait tout de la vague. Seule la
     * ligne sans classe ne peut donc pas etre reprise, et c est elle que l alerte nomme.
     */
    public function testARowWrittenBeforeTheClassWasRecordedSuspendsTheClosureWhenTheHistoryDoesNotKnow(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWaveOf($combat);
        $arrivee = (int)$vague->time_arrival;

        $this->entryRowOf($combat, (int)$combat->mission_id)->update(['character_class' => null, 'character_class_recorded' => 0]);

        DB::table('character_class_history')->where('user_id', $this->currentUserId)->delete();
        $this->travelTo(Date::createFromTimestamp($ouverture + 5));
        $classe = $this->livingClassOf($this->currentUserId);
        $this->recordCharacterClass($this->currentUserId, $classe === null ? null : CharacterClass::from($classe));

        $this->assertSuspendedNaming($combat, $arrivee + 30, 'ecrite avant l enregistrement de la classe');
    }

    /**
     * **Une classe enregistree que le jeu ne connait pas suspend la fermeture**, au lieu de devenir « aucune
     * classe ».
     */
    public function testARecordedClassTheGameDoesNotKnowSuspendsTheClosure(): void
    {
        [$combat] = $this->anOpenRally();
        $vague = $this->theSecondWaveOf($combat);

        $this->entryRowOf($combat, (int)$combat->mission_id)->update(['character_class' => 9]);

        $this->assertSuspendedNaming($combat, (int)$vague->time_arrival + 30, 'que le jeu ne connait pas');
    }

    /**
     * **Une classe que le jeu ne connait pas, dans l historique, suspend aussi la fermeture.**
     *
     * La colonne et la ligne concordent : le lecteur la rend sans rien signaler. C est le gel qui refuse de la
     * traduire en « aucune classe » — un joueur sans classe et un joueur dont la classe est illisible ne sont
     * pas la meme chose.
     */
    public function testAHistoryClassTheGameDoesNotKnowSuspendsTheClosure(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWaveOf($combat);

        $this->travelTo(Date::createFromTimestamp($ouverture + 5));
        DB::transaction(function (): void {
            DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => 9]);
            resolve(ClassHistoryRecorder::class)->personalClass($this->currentUserId, 9, 'test');
        });

        $this->assertSuspendedNaming($combat, (int)$vague->time_arrival + 30, 'que le jeu ne connait pas');
    }

    // ------------------------------------------------------------------ ce que la photographie doit porter

    /**
     * **Une photographie qui ne porte pas la classe de chaque attaquante est refusee**, comme celle qui
     * oublierait un joueur : sans cette egalite, une flotte absente se verrait decider par une autre ligne.
     */
    public function testASnapshotMissingTheGeneralClassOfAFleetIsRefused(): void
    {
        [$combat, $mission] = $this->aLoneAttackProcessedLate(CharacterClass::GENERAL, function (): void {
        });

        $document = $combat->frozen_settings;
        $this->assertIsArray($document);
        $this->assertArrayHasKey((int)$mission->id, $document['attacker_generals'], 'The closure photographed no General class for the only attacking fleet.');

        unset($document['attacker_generals'][(int)$mission->id]);
        DB::table('combat_instances')->where('id', $combat->id)->update(['frozen_settings' => json_encode($document)]);

        $this->expectException(CorruptedFrozenApplicationContext::class);
        $this->settleFromStorage(CombatInstance::query()->findOrFail($combat->id));
    }

    // ------------------------------------------------------------------ le plafond du retour, en union

    /**
     * **En attaque groupee aussi, la cargaison des survivants rentre entiere quand leur capacite a baisse.**
     *
     * Le retour d une union suit un autre chemin que celui d une attaque seule, avec son propre plafond : sans
     * ce temoin, il aurait continue de couper ce que la flotte portait deja.
     */
    public function testInAUnionTheCargoOfTheSurvivorsComesHomeWholeWhenTheirCapacityFell(): void
    {
        $chargement = 300_000;

        [$ouvreuse, , $ouverture] = $this->aRallyAboutToOpen(200, [], new Resources($chargement, 0, 0, 0), function (): void {
            $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);
        });

        // Devenue General avant son arrivee : a l admission, la flotte porte plus que sa capacite.
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);
        $vague = $this->theSecondWaveOf($combat);
        $this->closeAt($combat, (int)$vague->time_arrival + 30);
        $relu = CombatInstance::query()->findOrFail($combat->id);

        $resultat = BattleResultCodec::fromStorage($relu->battle_result);
        $this->assertGreaterThan(1, count($resultat->attackerFleetResults), 'The premise is missing: a single fleet takes the lone attack path, not the union one.');

        $flotte = $this->fleetResultOf($relu, (int)$ouvreuse->id);

        $this->assertSame($chargement, (int)$ouvreuse->metal, 'The premise is missing: the opener did not leave with its full load.');
        $this->assertLessThan($chargement, $flotte->startingCargoCapacity, 'The premise is missing: the capacity at the admission is not below the load.');
        $this->assertGreaterThan($flotte->survivingCargoCapacity, (int)$flotte->survivingCargo->sum(), 'The premise is missing: the surviving cargo fits the surviving capacity, the cap would change nothing.');

        $retours = $this->settleFromStorage($relu);
        $rapporte = $retours[(int)$ouvreuse->id]['ressources'] ?? null;

        $this->assertNotNull($rapporte, 'The opener has no return.');
        $this->assertSame((int)$flotte->survivingCargo->metal->get(), (int)$rapporte->metal->get(), 'In a union, the cargo the survivors carried was cut to their capacity on the way home.');
        $this->assertSame((int)$flotte->survivingCargo->crystal->get(), (int)$rapporte->crystal->get(), 'In a union, the return brought crystal the surviving cargo left no room for.');
        $this->assertSame((int)$flotte->survivingCargo->deuterium->get(), (int)$rapporte->deuterium->get(), 'In a union, the deuterium the survivors carried was cut, or loot was added where no room was left.');
        $this->assertLessThan($chargement, (int)$rapporte->metal->get(), 'The cargo of the destroyed ships came home with the survivors.');
    }

    // ------------------------------------------------------------------ le montage

    /**
     * Une attaque seule, admise sous une classe et traitee apres un changement.
     *
     * @param Closure(int): void $entreLArriveeEtLeTraitement Appelee cinq secondes apres l arrivee, avec le
     *                                                        proprietaire de la cible.
     * @param array<string, int> $cible Colonnes posees sur la planete visee.
     * @param Closure(): void|null $avantLArrivee Appelee juste apres le depart, avant l arrivee.
     * @return array{0: CombatInstance, 1: FleetMission, 2: int} Le combat relu, la mission, l arrivee.
     */
    private function aLoneAttackProcessedLate(
        CharacterClass|null $aLAdmission,
        Closure $entreLArriveeEtLeTraitement,
        array $cible = [],
        Resources|null $cargaison = null,
        bool $cibleInactive = false,
        Closure|null $avantLArrivee = null,
    ): array {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }
        $this->basicSetup();

        if ($cargaison !== null) {
            $this->planetAddResources($cargaison);
        }

        $this->recordCharacterClass($this->currentUserId, $aLAdmission);

        $planete = $this->sendMissionToOtherPlayerCleanPlanet($this->theLoneAttackUnits(), $cargaison ?? new Resources(0, 0, 0, 0));
        $mission = $this->lastMissionDispatched();
        $arrivee = (int)$mission->time_arrival;

        if ($avantLArrivee !== null) {
            $avantLArrivee();
        }

        $proprietaire = (int)DB::table('planets')->where('id', $planete->getPlanetId())->value('user_id');
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $planete->getPlanetId())->update($cible + [
            'metal' => 100_000,
            'crystal' => 50_000,
            'deuterium' => 10_000,
            'time_last_update' => $arrivee + 86_400,
        ]);

        if ($cibleInactive) {
            $this->inactiveOwner = [$proprietaire, DB::table('users')->where('id', $proprietaire)->value('time')];
            DB::table('users')->where('id', $proprietaire)->update(['time' => (string)($arrivee - 8 * 86_400)]);
        }

        $this->travelTo(Date::createFromTimestamp($arrivee + 5));
        $entreLArriveeEtLeTraitement($proprietaire);

        $this->travelTo(Date::createFromTimestamp($arrivee + 10));
        $combat = (new CombatOpeningService())->openOrJoin(FleetMission::query()->findOrFail($mission->id), $planete->getPlanetId(), $arrivee);

        $relu = CombatInstance::query()->findOrFail($combat->id);
        $this->assertSame(CombatState::Active, $relu->status, 'The lone attack did not close at its opening: the battle is not computed.');
        $this->assertSame('v2', $relu->unit_characteristics_version, 'The combat is not under the frozen at entry rule.');

        return [$relu, $mission, $arrivee];
    }

    private function theLoneAttackUnits(): UnitCollection
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        return $unites;
    }

    private function capacityOfTheLoneAttackUnder(CharacterClass $classe): int
    {
        $this->recordCharacterClass($this->currentUserId, $classe);

        return (int)$this->theLoneAttackUnits()->getTotalCargoCapacity(resolve(PlayerServiceFactory::class)->make($this->currentUserId, true));
    }

    private function hamillAlwaysTriggers(): void
    {
        $reglages = resolve(SettingsService::class);
        $this->hamillChance = $reglages->hamillManoeuvreChance();
        $reglages->set('hamill_manoeuvre_chance', 1);
    }

    private function livingClassOf(int $userId): int|null
    {
        $valeur = DB::table('users')->where('id', $userId)->value('character_class');

        return is_numeric($valeur) ? (int)$valeur : null;
    }

    private function assertLossesAndSurvivors(CombatInstance $combat): void
    {
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        $this->assertGreaterThan(0, $resultat->attackerUnitsLost->getAmount(), 'The premise is missing: the attacker lost nothing, no wreck field would exist either way.');
        $this->assertGreaterThan(0, $resultat->attackerUnitsResult->getAmount(), 'The premise is missing: the attacker has no survivor, no return would carry a wreck field.');
    }

    /**
     * Regle le combat a son echeance, depuis la base et dans une application rechargee, et rend ce que chaque
     * retour emporte.
     *
     * @return array<int, array{ressources: Resources, epaves: array<mixed>|null}> Par mission aller.
     */
    private function settleFromStorage(CombatInstance $combat): array
    {
        $echeance = (int)$combat->ends_at;

        // **Aucun service, aucune fabrique, aucun joueur de la cloture ne survit jusqu ici.**
        $this->reloadApplication();
        $this->travelTo(Date::createFromTimestamp($echeance));

        /** @var ArrayObject<int, array{ressources: Resources, epaves: array<mixed>|null}> $retours */
        $retours = new ArrayObject();

        (new CombatSettlementService(resolve(CombatResolutionService::class)))->settle(
            (int)$combat->id,
            resolve(AttackMission::class),
            static function (FleetMission $retourDe, Resources $ressources, UnitCollection $unites, int $tempsSupplementaire = 0, array|null $epaves = null, int|null $dureeImposee = null) use ($retours): void {
                $retours[(int)$retourDe->id] = ['ressources' => $ressources, 'epaves' => $epaves];
            },
            $echeance
        );

        $this->assertSame(CombatState::Resolved, CombatInstance::query()->findOrFail($combat->id)->status, 'The combat was not settled.');

        return $retours->getArrayCopy();
    }

    private function reportOf(CombatInstance $combat): BattleReport
    {
        $rapport = BattleReport::query()->find(CombatInstance::query()->findOrFail($combat->id)->battle_report_id);
        $this->assertInstanceOf(BattleReport::class, $rapport, 'The settlement wrote no battle report.');

        return $rapport;
    }

    private function theSecondWaveOf(CombatInstance $combat): FleetMission
    {
        $vague = FleetMission::query()
            ->where('user_id', $this->currentUserId)
            ->where('mission_type', 1)
            ->where('processed', 0)
            ->whereKeyNot($combat->mission_id)
            ->orderByDesc('id')
            ->first();

        $this->assertInstanceOf(FleetMission::class, $vague, 'The second wave is missing.');

        return $vague;
    }

    private function entryRowOf(CombatInstance $combat, int $fleetMissionId): Builder
    {
        return DB::table('combat_entry_characteristics')
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($fleetMissionId));
    }

    private function closeAt(CombatInstance $combat, int $instant): void
    {
        $this->travelTo(Date::createFromTimestamp($instant));
        $issue = (new RallyClosureService())->close((int)$combat->id, $instant);

        // **Un refus qui dit pourquoi** : suspendue, trop tot, deja fermee ou tenue ailleurs ne se corrigent pas
        // de la meme facon, et un « false » nu a deja coute un passage entier.
        $this->assertTrue($issue->closed, 'The rally did not close (suspendue : ' . var_export($issue->suspended, true)
            . ', etat : ' . CombatInstance::query()->findOrFail($combat->id)->status->value
            . ', echeance : ' . var_export(DB::table('celestial_body_combat_barriers')->where('combat_instance_id', $combat->id)->value('owned_through_effect_at'), true)
            . ', instant : ' . $instant . ').');
    }

    private function fleetResultOf(CombatInstance $combat, int $fleetMissionId): AttackerFleetResult
    {
        foreach (BattleResultCodec::fromStorage($combat->battle_result)->attackerFleetResults as $flotte) {
            if ((int)$flotte->fleetMissionId === $fleetMissionId) {
                return $flotte;
            }
        }

        $this->fail('The frozen result holds no fleet ' . $fleetMissionId . '.');
    }

    private function assertSuspendedNaming(CombatInstance $combat, int $instant, string $raison): void
    {
        $journal = Log::spy();

        $this->travelTo(Date::createFromTimestamp($instant));
        $issue = (new RallyClosureService())->close((int)$combat->id, $instant);

        $this->assertFalse($issue->closed, 'The rally closed on a class nobody knows.');
        $this->assertTrue($issue->suspended, 'The closure did not suspend: the anomaly would pass for an ordinary race.');
        $this->assertNull(CombatInstance::query()->findOrFail($combat->id)->battle_result, 'A battle was computed with a class nobody knows.');

        $journal->shouldHaveReceived('critical')->withArgs(
            static fn (string $message, array $contexte = []): bool => str_contains((string)($contexte['raison'] ?? ''), $raison)
        )->once();
    }

    private function theBattleRosterOf(CombatInstance $combat): CombatRoster
    {
        return (new CombatRosterReader())->forTheBattle(
            $combat,
            OpeningStateRecorder::openingUnitsOf($combat),
            OpeningStateRecorder::openingDefenderOf($combat)
        );
    }
}
