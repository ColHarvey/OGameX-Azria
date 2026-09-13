<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\MissingEntryCharacteristics;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\CombatEntryCharacteristicsRegistry;
use OGame\Combat\Services\CombatRoster;
use OGame\Combat\Services\CombatRosterReader;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatantFrozenAtEntry;
use OGame\Combat\Support\CombatantUnderTheFirstRule;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Enums\AllianceClass;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\History\ClassHistoryBaseline;
use OGame\History\ClassHistoryReader;
use OGame\Models\Alliance;
use OGame\Models\CombatEntryCharacteristic;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;
use Tests\RecordsClassHistory;

/**
 * **Ce qu une flotte apporte a ses tirs se gele a son entree dans un combat durable** (decision de Keven,
 * 12 septembre 2026).
 *
 * ## Ce qui est prouve ici
 *
 * - un combat ouvert aujourd hui porte la regle `v2` ;
 * - l ouvreuse inscrit ses niveaux et son bonus de classe **a son arrivee** ;
 * - une recherche ou une classe acquise pendant le ralliement **n arme pas** ses tirs, ni ne s annonce
 *   au rapport ;
 * - la garnison tire avec **sa photographie d ouverture**, et son bonus de classe est celui de l instant
 *   d ouverture — plus rien ne vient du compte vivant ;
 * - quand **seule la classe ou l alliance** change entre l arrivee et un traitement tardif, la flotte garde
 *   ce qu elle avait a son arrivee — dans les deux sens ;
 * - une decision prise **a la seconde meme** d une admission compte apres elle ;
 * - a la meme seconde, une **barriere** et une **arrivee** ne voient pas la meme recherche ;
 * - un historique **inconnu** suspend la fermeture sans rien perdre, et l avanceur compte l echec ;
 * - un combat ouvert **avant la ligne de base** des historiques s ouvre sous la premiere regle ;
 * - une vague que son travailleur n a pas vue est gelee **a la cloture** ;
 * - un combat ouvert sous la premiere regle **la garde** : niveaux vivants, aucun bonus aux tirs, bonus
 *   au rapport ;
 * - une ligne manquante a la composition est **refusee** ; une premiere entree n est **jamais reecrite** ;
 *   la premiere regle **n ecrit rien**.
 *
 * ## Pourquoi la composition est lue sur l effectif, et le rapport sur le resultat
 *
 * Le moteur construit ses unites depuis `$flotte->player` (les deux moteurs, `openTheField()` et l entree
 * FFI) : lire le joueur que `forTheBattle()` pose, c est lire ce que les unites emploient. Le rapport, lui,
 * est dans le resultat gele : il se lit la ou le joueur le lira.
 */
final class UnitCharacteristicsFrozenAtEntryTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use RecordsClassHistory;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

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
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');
        parent::tearDown();
    }

    /**
     * Un combat ouvert aujourd hui compose ses unites sous le gel a l entree.
     */
    public function testACombatOpenedTodayIsComposedUnderTheFrozenAtEntryRule(): void
    {
        [$combat] = $this->anOpenRally();

        $this->assertSame('v2', CombatInstance::query()->findOrFail($combat->id)->unit_characteristics_version);
    }

    /**
     * **L ouvreuse inscrit ce qu elle apporte, a l instant de son arrivee.**
     */
    public function testTheOpenerFreezesWhatItBringsAtItsArrival(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vivant = $this->theLivingAttacker();

        $ligne = $this->lineOf($combat, $combat->mission_id);

        $this->assertNotNull($ligne, 'The opener entered the combat without its characteristics being frozen.');
        $this->assertSame($ouverture, (int)$ligne->entered_at, 'The opener is not frozen at its arrival.');
        $this->assertSame($this->currentUserId, (int)$ligne->player_id);
        $this->assertSame($vivant->getResearchLevel('weapon_technology'), (int)$ligne->weapon_level);
        $this->assertSame($vivant->getResearchLevel('shielding_technology'), (int)$ligne->shield_level);
        $this->assertSame($vivant->getResearchLevel('armor_technology'), (int)$ligne->armor_level);
        $this->assertSame($vivant->getCombatResearchBonusLevels(), (int)$ligne->class_combat_bonus);
    }

    /**
     * **Ce que l attaquant acquiert pendant le ralliement n arme pas ses tirs.**
     *
     * Six niveaux d armes et la classe de General, acquis apres l arrivee : le compte vivant tire a
     * `entree + 6 + 2`, la bataille a `entree`. Les deux nombres sont differents par construction — c est
     * la premisse qui rend l essai probant.
     */
    public function testWhatTheAttackerAcquiresDuringTheRallyDoesNotArmItsShots(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $entree = $this->theLivingAttacker()->getResearchLevel('weapon_technology');

        // --- Le monde bouge pendant le ralliement ---
        $this->playerSetResearchLevel('weapon_technology', $entree + 6);
        // La classe est posee a l horloge du banc : l ouverture, donc la seconde meme de l arrivee de
        // l ouvreuse — et une decision prise a cette seconde compte apres elle.
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);

        $vivant = $this->theLivingAttacker();
        $this->assertSame($entree + 6, $vivant->getResearchLevel('weapon_technology'), 'The premise is missing: the research did not change.');
        $this->assertSame(2, $vivant->getCombatResearchBonusLevels(), 'The premise is missing: the attacker is not a General.');

        $this->close($combat, $ouverture);

        $initiatrice = $this->theBattleRoster($combat)->attackers[0];

        $this->assertInstanceOf(CombatantFrozenAtEntry::class, $initiatrice->player, 'The battle composes the attacker from the living account.');
        $this->assertSame($entree, $initiatrice->player->getResearchLevel('weapon_technology'), 'The attacker fires with a research finished after its arrival.');
        $this->assertSame(0, $initiatrice->player->getCombatResearchBonusLevels(), 'The attacker fires with a class bought after its arrival.');

        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $base = $chasseur->properties->attack->rawValue;
        $this->assertSame($base + intdiv($base * $entree * 10, 100), $chasseur->properties->attack->calculate($initiatrice->player)->totalValue);

        $resultat = BattleResultCodec::fromStorage(CombatInstance::query()->findOrFail($combat->id)->battle_result);
        $this->assertSame($entree, $resultat->attackerWeaponLevel, 'The report announces what the attacker acquired after its arrival.');
    }

    /**
     * **La garnison tire avec sa photographie d ouverture, et plus avec le compte vivant.**
     *
     * C est le defaut anterieur que ce raccordement ferme : le rapport lisait deja la photographie, les
     * tirs lisaient le compte. Neuf niveaux d armes ecrits apres l ouverture, hors de tout effet
     * admissible, ne doivent rien changer.
     */
    public function testTheGarrisonFiresWithItsOpeningPhotographAndNotWithTheLivingAccount(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $photographie = OpeningStateRecorder::openingDefenderOf(CombatInstance::query()->findOrFail($combat->id));
        $proprietaire = (int)DB::table('planets')->where('id', $cible)->value('user_id');

        // --- Le compte du defenseur bouge apres l ouverture ---
        UserTech::query()->where('user_id', $proprietaire)->update(['weapon_technology' => $photographie->weaponLevel + 9]);
        $this->assertSame(
            $photographie->weaponLevel + 9,
            resolve(PlayerServiceFactory::class)->make($proprietaire, true)->getResearchLevel('weapon_technology'),
            'The premise is missing: the defender account did not change.'
        );

        $this->close($combat, $ouverture);

        $garnison = $this->theBattleRoster($combat)->defenders[0];

        $this->assertSame(0, $garnison->fleetMissionId, 'The first defender is not the garrison.');
        $this->assertInstanceOf(CombatantFrozenAtEntry::class, $garnison->player, 'The garrison is composed from the living account.');
        $this->assertSame(
            $photographie->weaponLevel,
            $garnison->player->getResearchLevel('weapon_technology'),
            'The garrison fires with the living account: a research finished during the rally strengthens defences already engaged.'
        );
        $this->assertSame($photographie->classCombatBonus, $garnison->player->getCombatResearchBonusLevels());

        $resultat = BattleResultCodec::fromStorage(CombatInstance::query()->findOrFail($combat->id)->battle_result);
        $this->assertSame($photographie->weaponLevel + $photographie->classCombatBonus, $resultat->defenderWeaponLevel, 'The report and the shots of the garrison disagree.');
    }

    /**
     * **Une recherche achevee apres l arrivee, puis une page chargee en retard : la vague garde le niveau de
     * son arrivee** (revue de Codex, 12 septembre 2026).
     *
     * Le scenario exact : la vague arrive, une recherche d armes s acheve douze secondes plus tard, et
     * personne ne charge de page avant. Au premier chargement, le middleware du jeu acheve **d abord** les
     * recherches du joueur (`PlayerService::update()`), **puis** traite ses missions (`updateFleetMissions()`) :
     * lu au traitement, le compte porte deja le niveau suivant. Les deux valeurs different d un niveau, et
     * c est celle de l arrivee — l instant d admission que les regles fixent — qui doit s inscrire.
     */
    public function testAResearchFinishedAfterTheArrivalDoesNotReachAWaveProcessedLate(): void
    {
        [$combat] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;
        $avant = $this->theLivingAttacker()->getResearchLevel('weapon_technology');
        $bouclier = $this->theLivingAttacker()->getResearchLevel('shielding_technology');
        $blindage = $this->theLivingAttacker()->getResearchLevel('armor_technology');

        // **Les trois recherches, pas seulement les armes** : chacune passe par sa propre lecture, et une seule
        // qui relirait le compte au traitement ne se verrait pas sur les deux autres.
        $recherche = $this->aResearchEndingAt($avant + 1, $arrivee - 100, $arrivee + 12);
        $this->aResearchEndingAt($bouclier + 1, $arrivee - 100, $arrivee + 12, 'shielding_technology');
        $this->aResearchEndingAt($blindage + 1, $arrivee - 100, $arrivee + 12, 'armor_technology');

        // --- Le premier chargement de page, en retard ---
        $this->travelTo(Date::createFromTimestamp($arrivee + 22));
        $this->get('/overview')->assertStatus(200);

        // Les trois premisses : la recherche est appliquee, le compte porte le niveau suivant, et la vague a
        // bien ete traitee par ce meme chargement — apres la recherche.
        $this->assertSame(1, (int)DB::table('research_queues')->where('id', $recherche)->value('processed'), 'The premise is missing: the page load did not complete the research.');
        $this->assertSame($avant + 1, $this->theLivingAttacker()->getResearchLevel('weapon_technology'), 'The premise is missing: the account does not carry the new level.');
        $this->assertSame($bouclier + 1, $this->theLivingAttacker()->getResearchLevel('shielding_technology'), 'The premise is missing: the account does not carry the new shielding level.');
        $this->assertSame($blindage + 1, $this->theLivingAttacker()->getResearchLevel('armor_technology'), 'The premise is missing: the account does not carry the new armor level.');
        $this->assertSame((int)$combat->id, (int)FleetMission::query()->findOrFail($vague->id)->combat_instance_id, 'The premise is missing: the page load did not process the late wave.');

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne, 'The late wave entered the combat without frozen characteristics.');
        $this->assertSame($arrivee, (int)$ligne->entered_at, 'The late wave is not dated from its arrival.');
        $this->assertSame($avant, (int)$ligne->weapon_level, 'The wave was frozen with a research finished after its arrival: the late processing decided its shots.');
        $this->assertSame($bouclier, (int)$ligne->shield_level, 'The wave was frozen with a shielding research finished after its arrival.');
        $this->assertSame($blindage, (int)$ligne->armor_level, 'The wave was frozen with an armor research finished after its arrival.');
    }

    /**
     * **Une vague que personne n a vue est gelee a la cloture, mais telle qu elle etait a son arrivee.**
     *
     * La cloture l inscrit : c est l instant ou quelqu un l observe enfin. L instant d admission, lui, reste
     * son arrivee. Une recherche achevee cinq secondes apres cette arrivee — et deja appliquee par le monde
     * quand la cloture passe — ne doit pas entrer dans ses tirs.
     */
    public function testAResearchAppliedAfterTheArrivalDoesNotReachAWaveFrozenAtTheClosure(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;
        $avant = $this->theLivingAttacker()->getResearchLevel('weapon_technology');

        $this->assertNull($this->lineOf($combat, (int)$vague->id), 'The premise is missing: the wave was already seen at its arrival.');

        // --- La recherche s acheve apres l arrivee, et le monde l applique sans traiter la vague ---
        $recherche = $this->aResearchEndingAt($avant + 1, $arrivee - 100, $arrivee + 5);
        DB::table('research_queues')->where('id', $recherche)->update(['processed' => 1]);
        $this->playerSetResearchLevel('weapon_technology', $avant + 1);

        $this->close($combat, $ouverture, $arrivee + 30);

        $this->assertTrue(
            CombatParticipant::query()->where('combat_instance_id', $combat->id)->where('fleet_mission_id', $vague->id)->exists(),
            'The premise is missing: the second wave was not admitted.'
        );

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne, 'An admitted wave reached the battle with no frozen characteristics.');
        $this->assertSame($arrivee, (int)$ligne->entered_at, 'A wave nobody saw is dated from the closure instead of its arrival.');
        $this->assertSame($avant, (int)$ligne->weapon_level, 'A wave frozen at the closure carries a research finished after its arrival.');
    }

    /**
     * **L inverse : une recherche achevee avant l arrivee, pas encore appliquee, compte.**
     *
     * Le monde n a pas encore traite la file quand la vague est gelee : le compte porte l ancien niveau.
     * Les regles, elles, disent que la recherche etait achevee a l arrivee. Sans ce temoin, « ne jamais
     * compter ce que le compte ne porte pas » passerait le temoin precedent.
     */
    public function testAResearchFinishedBeforeTheArrivalButNotYetAppliedIsCounted(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;
        $avant = $this->theLivingAttacker()->getResearchLevel('weapon_technology');

        $this->aResearchEndingAt($avant + 1, $arrivee - 100, $arrivee - 5);
        $this->assertSame($avant, $this->theLivingAttacker()->getResearchLevel('weapon_technology'), 'The premise is missing: the world already applied the research.');

        $this->close($combat, $ouverture, $arrivee + 30);

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne, 'An admitted wave reached the battle with no frozen characteristics.');
        $this->assertSame($avant + 1, (int)$ligne->weapon_level, 'A research finished before the arrival was left out because the world had not applied it yet.');
    }

    /**
     * **Une recherche achevee a la seconde exacte de l arrivee compte : elle la precede.**
     *
     * Ce n est pas une comparaison choisie pour cet essai, c est l ordre causal deja etabli du jeu :
     * `CausalEventOrderV1` classe, a effet simultane, la recherche (rang 1) avant l arrivee (rang 4), et
     * `CausalEventOrderTest` l epingle. Le monde fait de meme : le middleware acheve les recherches dues
     * **avant** de traiter les missions dues de la meme seconde. Le registre compare donc la fin de la
     * recherche a l arrivee par `EffectOrderKey`, sous l ordre gele du combat — jamais par un signe ecrit a la
     * main.
     */
    public function testAResearchFinishedExactlyAtTheArrivalCountsBecauseItPrecedesTheArrival(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;
        $avant = $this->theLivingAttacker()->getResearchLevel('weapon_technology');

        $recherche = $this->aResearchEndingAt($avant + 1, $arrivee - 100, $arrivee);
        DB::table('research_queues')->where('id', $recherche)->update(['processed' => 1]);
        $this->playerSetResearchLevel('weapon_technology', $avant + 1);

        $this->close($combat, $ouverture, $arrivee + 30);

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne);
        $this->assertSame($avant + 1, (int)$ligne->weapon_level, 'A research finished at the very second of the arrival was left out, although the causal order of the game places it before the arrival.');
    }

    /**
     * **Un combat ouvert sous la premiere regle la garde** : niveaux vivants, aucun bonus aux tirs, bonus
     * annonce au rapport — exactement ce que le jeu faisait.
     */
    public function testACombatOpenedUnderTheFirstRuleKeepsIt(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();

        DB::table('combat_instances')->where('id', $combat->id)->update(['unit_characteristics_version' => 'v1']);
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        $brut = $this->theLivingAttacker()->getResearchLevel('weapon_technology');

        $this->close($combat, $ouverture);

        $effectif = $this->theBattleRoster($combat);
        $initiatrice = $effectif->attackers[0];

        $this->assertInstanceOf(CombatantUnderTheFirstRule::class, $initiatrice->player, 'A combat opened under the first rule is composed under another one.');
        $this->assertInstanceOf(CombatantUnderTheFirstRule::class, $effectif->defenders[0]->player, 'The garrison of a first-rule combat is composed under another rule.');
        $this->assertSame(0, $initiatrice->player->getCombatResearchBonusLevels(), 'The first rule armed the shots with the class bonus.');
        $this->assertSame(2, $initiatrice->player->getReportedCombatResearchBonusLevels(), 'The first rule no longer announces the class bonus in its report.');

        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $base = $chasseur->properties->attack->rawValue;
        $this->assertSame($base + intdiv($base * $brut * 10, 100), $chasseur->properties->attack->calculate($initiatrice->player)->totalValue);

        $resultat = BattleResultCodec::fromStorage(CombatInstance::query()->findOrFail($combat->id)->battle_result);
        $this->assertSame($brut + 2, $resultat->attackerWeaponLevel, 'The report of a first-rule combat lost the class bonus it used to announce.');
    }

    /**
     * **Une ligne manquante a la composition est refusee**, jamais remplacee par le compte vivant.
     *
     * La cloture inscrit toute flotte admise : a la composition, l absence n est pas un retard mais une
     * contradiction. La garde reste sur ce chemin meme si la cloture la rend inatteignable.
     */
    public function testAMissingEntryIsRefusedAtCompositionRatherThanReadFromTheAccount(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $this->close($combat, $ouverture);

        CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->delete();

        $this->expectException(MissingEntryCharacteristics::class);

        $this->theBattleRoster($combat);
    }

    /**
     * **La premiere entree n est jamais reecrite** : une seconde observation voit un monde qui a change
     * depuis l entree, precisement ce que le gel ignore.
     */
    public function testTheFirstEntryIsNeverRewritten(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $avant = $this->lineOf($combat, $combat->mission_id);
        $this->assertNotNull($avant);

        $this->playerSetResearchLevel('weapon_technology', (int)$avant->weapon_level + 4);

        resolve(CombatEntryCharacteristicsRegistry::class)->recordAtArrival(
            CombatInstance::query()->findOrFail($combat->id),
            $combat->mission_id,
            $this->currentUserId,
            $ouverture + 5
        );

        $apres = $this->lineOf($combat, $combat->mission_id);
        $this->assertNotNull($apres);

        $this->assertSame((int)$avant->weapon_level, (int)$apres->weapon_level, 'A second observation rewrote what the fleet brought at its entry.');
        $this->assertSame($ouverture, (int)$apres->entered_at);
        $this->assertSame(1, CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->where('participant_key', CombatParticipantKey::forFleet($combat->mission_id))->count());
    }

    /**
     * **La premiere regle n ecrit rien** : des caracteristiques gelees laisseraient croire qu elles servent.
     */
    public function testTheFirstRuleWritesNoFrozenCharacteristics(): void
    {
        [$combat] = $this->anOpenRally();

        DB::table('combat_instances')->where('id', $combat->id)->update(['unit_characteristics_version' => 'v1']);
        CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->delete();

        resolve(CombatEntryCharacteristicsRegistry::class)->recordAtArrival(
            CombatInstance::query()->findOrFail($combat->id),
            $combat->mission_id,
            $this->currentUserId,
            1_700_000_000
        );

        $this->assertSame(0, CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->count(), 'The first rule wrote frozen characteristics it never reads.');
    }

    /**
     * **Une classe prise apres l arrivee n arme pas une vague traitee en retard** (revue de Codex,
     * 13 septembre 2026).
     *
     * Le scenario exact que Codex decrit : la flotte arrive sans bonus, son joueur devient General avant que
     * le travailleur la traite. La recherche, elle, ne bouge pas : l essai isole la classe, et rien d autre.
     * Un changement de classe par le jeu est refuse tant qu une mission n est pas traitee ; celui-ci passe par
     * le chemin de l administration, qui ecrit la colonne **et** sa ligne.
     */
    public function testAClassTakenAfterTheArrivalDoesNotArmAWaveProcessedLate(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;
        $niveau = $this->theLivingAttacker()->getResearchLevel('weapon_technology');

        $this->travelTo(Date::createFromTimestamp($arrivee + 5));
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        $this->assertSame(2, $this->theLivingAttacker()->getCombatResearchBonusLevels(), 'The premise is missing: the account is not a General.');

        $this->close($combat, $ouverture, $arrivee + 30);

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne);
        $this->assertSame($arrivee, (int)$ligne->entered_at, 'The wave is not frozen at its arrival.');
        $this->assertSame(0, (int)$ligne->class_combat_bonus, 'A class taken after the arrival armed shots frozen at that arrival.');
        $this->assertSame($niveau, (int)$ligne->weapon_level, 'The research moved: the witness would no longer isolate the class.');
    }

    /**
     * **Et dans l autre sens** : une classe abandonnee apres l arrivee ne retire pas le bonus qu elle donnait.
     *
     * Sans ce second temoin, une lecture qui rendrait toujours zero passerait le premier.
     */
    public function testAClassGivenUpAfterTheArrivalStillArmsAWaveProcessedLate(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;

        // General avant l arrivee de la vague — l horloge est a l ouverture, dix-huit secondes plus tot.
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);

        $this->travelTo(Date::createFromTimestamp($arrivee + 5));
        $this->recordCharacterClass($this->currentUserId, null);
        $this->assertSame(0, $this->theLivingAttacker()->getCombatResearchBonusLevels(), 'The premise is missing: the account is still a General.');

        $this->close($combat, $ouverture, $arrivee + 30);

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne);
        $this->assertSame(2, (int)$ligne->class_combat_bonus, 'A class given up after the arrival disarmed shots frozen at that arrival.');
    }

    /**
     * **Une classe d alliance choisie apres l arrivee n arme pas la vague.**
     *
     * L appartenance, elle, ne bouge pas : le joueur est dans l alliance avant de partir. Seule la classe de
     * cette alliance est choisie ensuite — par un fondateur qui n a aucune flotte en vol, et que rien
     * n empeche donc de la choisir pendant le ralliement.
     */
    public function testAnAllianceClassChosenAfterTheArrivalDoesNotArmAWaveProcessedLate(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;

        $alliance = $this->anAllianceFoundedByTheAttacker();

        $this->travelTo(Date::createFromTimestamp($arrivee + 5));
        $this->theAllianceChooses($alliance, AllianceClass::WARRIORS);
        $this->assertSame(1, $this->theLivingAttacker()->getCombatResearchBonusLevels(), 'The premise is missing: the alliance is not Warriors.');

        $this->close($combat, $ouverture, $arrivee + 30);

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne);
        $this->assertSame(0, (int)$ligne->class_combat_bonus, 'An alliance class chosen after the arrival armed shots frozen at that arrival.');
    }

    /**
     * **Une alliance quittee apres l arrivee ne retire pas le niveau qu elle donnait.**
     *
     * La dissolution est un depart pour chacun : c est le chemin du jeu qui fait perdre une appartenance
     * pendant qu une flotte vole, sans passer par une main sur la base.
     */
    public function testAnAllianceLeftAfterTheArrivalStillArmsAWaveProcessedLate(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;

        $alliance = $this->anAllianceFoundedByTheAttacker();
        $this->theAllianceChooses($alliance, AllianceClass::WARRIORS);
        $this->assertSame(1, $this->theLivingAttacker()->getCombatResearchBonusLevels(), 'The premise is missing: the alliance is not Warriors before the arrival.');

        $this->travelTo(Date::createFromTimestamp($arrivee + 5));
        resolve(AllianceService::class)->disbandAlliance((int)$alliance->id, $this->currentUserId);
        $this->assertSame(0, $this->theLivingAttacker()->getCombatResearchBonusLevels(), 'The premise is missing: the account still belongs to a Warriors alliance.');

        $this->close($combat, $ouverture, $arrivee + 30);

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne);
        $this->assertSame(1, (int)$ligne->class_combat_bonus, 'An alliance left after the arrival disarmed shots frozen at that arrival.');
    }

    /**
     * **Une alliance rejointe apres l arrivee n arme pas la vague** — c est l appartenance qui est lue a
     * l instant d admission, pas seulement la classe de l alliance actuelle.
     */
    public function testAnAllianceJoinedAfterTheArrivalDoesNotArmAWaveProcessedLate(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;

        $this->travelTo(Date::createFromTimestamp($arrivee + 5));
        $alliance = $this->anAllianceFoundedByTheAttacker();
        $this->theAllianceChooses($alliance, AllianceClass::WARRIORS);
        $this->assertSame(1, $this->theLivingAttacker()->getCombatResearchBonusLevels(), 'The premise is missing: the alliance is not Warriors.');

        $this->close($combat, $ouverture, $arrivee + 30);

        $ligne = $this->lineOf($combat, (int)$vague->id);

        $this->assertNotNull($ligne);
        $this->assertSame(0, (int)$ligne->class_combat_bonus, 'An alliance joined after the arrival armed shots frozen at that arrival.');
    }

    /**
     * **Une decision prise a la seconde meme d une admission compte apres elle** — et une seconde plus tot,
     * avant.
     *
     * Ce n est pas une evidence technique mais la regle d ordre du jeu, celle de `DecisionOrder` : le
     * middleware traite les missions dues **avant** l action de la requete, si bien qu une action prise a la
     * seconde d une arrivee s execute apres elle. Les deux cotes sont epingles ici, sur la meme decision.
     */
    public function testADecisionTakenAtTheVerySecondOfAnAdmissionCountsAfterIt(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $instant = $ouverture + 5;

        $this->travelTo(Date::createFromTimestamp($instant));
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);

        $relu = CombatInstance::query()->findOrFail($combat->id);
        $registre = resolve(CombatEntryCharacteristicsRegistry::class);

        $meme = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 3_600, 0);
        $apres = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 3_600, 0);

        $registre->recordAtArrival($relu, (int)$meme->id, $this->currentUserId, $instant);
        $registre->recordAtArrival($relu, (int)$apres->id, $this->currentUserId, $instant + 1);

        $ligneMeme = $this->lineOf($combat, (int)$meme->id);
        $ligneApres = $this->lineOf($combat, (int)$apres->id);

        $this->assertNotNull($ligneMeme);
        $this->assertNotNull($ligneApres);
        $this->assertSame(0, (int)$ligneMeme->class_combat_bonus, 'A class taken at the very second of an admission counted before it.');
        $this->assertSame(2, (int)$ligneApres->class_combat_bonus, 'A class taken a second before an admission did not count.');
    }

    /**
     * **A la meme seconde, une barriere et une arrivee ne voient pas la meme recherche.**
     *
     * C est l ordre causal du combat, et il est epingle ici parce qu il ne va pas de soi : une barriere
     * precede tout evenement de sa seconde, tandis qu entre evenements d une meme seconde une recherche
     * (`ResearchCompletion`) precede une arrivee (`FleetArrival`). Un renfort deja pose a l ouverture et une
     * flotte qui arrive a cette seconde-la n emportent donc pas le meme niveau.
     */
    public function testAtTheSameSecondABarrierAndAnArrivalDoNotSeeTheSameResearch(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $avant = $this->theLivingAttacker()->getResearchLevel('weapon_technology');

        $recherche = $this->aResearchEndingAt($avant + 1, $ouverture - 100, $ouverture);
        DB::table('research_queues')->where('id', $recherche)->update(['processed' => 1]);
        $this->playerSetResearchLevel('weapon_technology', $avant + 1);

        $relu = CombatInstance::query()->findOrFail($combat->id);
        $registre = resolve(CombatEntryCharacteristicsRegistry::class);

        $arrivante = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 3_600, 0);
        $posee = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 3_600, 0);

        $registre->recordAtArrival($relu, (int)$arrivante->id, $this->currentUserId, $ouverture);
        $registre->recordAtOpening($relu, (int)$posee->id, $this->currentUserId, $ouverture);

        $ligneArrivee = $this->lineOf($combat, (int)$arrivante->id);
        $lignePosee = $this->lineOf($combat, (int)$posee->id);

        $this->assertNotNull($ligneArrivee);
        $this->assertNotNull($lignePosee);
        $this->assertSame($avant + 1, (int)$ligneArrivee->weapon_level, 'A research finished at the very second of an arrival was left out, although the causal order places it before the arrival.');
        $this->assertSame($avant, (int)$lignePosee->weapon_level, 'A research finished at the very second of the opening reached a reinforcement the barrier admitted before it.');
    }

    /**
     * **La garnison garde la classe de l ouverture, meme si le travailleur passe en retard.**
     *
     * Le corps vise n a pas de flotte a faire arriver : ce que sa garnison apporte se gele a la barriere
     * d ouverture. La photographie du defenseur, elle, est prise a l instant ou un travailleur traite cette
     * ouverture — l essai etablit qu elle a bien vu la classe tardive, sans quoi le juste et le faux
     * coincideraient et rien ne serait prouve.
     */
    public function testTheGarrisonKeepsTheClassOfTheOpeningWhenTheWorkerIsLate(): void
    {
        [$ouvreuse, $cible, $ouverture] = $this->aRallyAboutToOpen();
        $proprietaire = (int)DB::table('planets')->where('id', $cible)->value('user_id');
        $classeInitiale = DB::table('users')->where('id', $proprietaire)->value('character_class');

        try {
            // Entre l arrivee de l attaquante et le passage du travailleur, le defenseur devient General.
            $this->travelTo(Date::createFromTimestamp($ouverture + 5));
            $this->recordCharacterClass($proprietaire, CharacterClass::GENERAL);

            $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture + 10);
            $photographie = OpeningStateRecorder::openingDefenderOf(CombatInstance::query()->findOrFail($combat->id));

            $this->assertSame(2, $photographie->classCombatBonus, 'The premise is missing: the photograph did not see the late class, and the two values would coincide.');

            $this->close($combat, $ouverture);

            $garnison = $this->theBattleRoster($combat)->defenders[0];

            $this->assertSame(0, $garnison->fleetMissionId, 'The first defender is not the garrison.');
            $this->assertSame(0, $garnison->player->getCombatResearchBonusLevels(), 'The garrison fires with a class taken after the attacker arrived, because a worker was late.');
            $this->assertSame($photographie->weaponLevel, $garnison->player->getResearchLevel('weapon_technology'), 'The garrison lost the research levels of its photograph.');

            $resultat = BattleResultCodec::fromStorage(CombatInstance::query()->findOrFail($combat->id)->battle_result);
            $this->assertSame($photographie->weaponLevel, $resultat->defenderWeaponLevel, 'The report announces a class bonus the shots did not carry.');
        } finally {
            $this->recordCharacterClass($proprietaire, is_numeric($classeInitiale) ? CharacterClass::from((int)$classeInitiale) : null);
        }
    }

    /**
     * **Un historique inconnu suspend la fermeture, sans perte ni credit partiel** (decision de Keven,
     * 13 septembre 2026).
     *
     * Une colonne de classe ecrite sans sa ligne est exactement l anomalie que la confrontation existe pour
     * voir. La porte d arrivee la journalise **sans lever** — une exception dans le traitement des missions
     * condamne toutes les pages du joueur — et n inscrit rien ; la fermeture, elle, revient en arriere et se
     * suspend, et l avanceur compte l echec.
     */
    public function testAnUnknownHistorySuspendsTheClosureAndCountsAsAFailure(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();
        $vague = $this->theSecondWave($combat);
        $arrivee = (int)$vague->time_arrival;

        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => CharacterClass::GENERAL->value]);

        $journal = Log::spy();

        $this->travelTo(Date::createFromTimestamp($arrivee + 1));
        $this->get('/overview')->assertStatus(200);

        $this->assertNull($this->lineOf($combat, (int)$vague->id), 'An unknown history was frozen as if it were known.');
        $journal->shouldHaveReceived('critical')->once();

        $this->travelTo(Date::createFromTimestamp($arrivee + 30));
        $issue = (new RallyClosureService())->close($combat->id, $arrivee + 30);

        $this->assertFalse($issue->closed, 'The rally closed although what a fleet brought to its shots is unknown.');
        $this->assertTrue($issue->suspended, 'The closure did not suspend: the anomaly would pass for an ordinary race.');

        $relu = CombatInstance::query()->findOrFail($combat->id);

        $this->assertSame(CombatState::Rallying, $relu->status);
        $this->assertNull($relu->battle_result, 'A battle was computed on an unknown history.');
        $this->assertSame(0, CombatParticipant::query()->where('combat_instance_id', $combat->id)->count(), 'The suspended closure left participants behind.');

        // La porte a journalise une fois, la fermeture une seconde : l exploitation est alertee deux fois,
        // et aucune des deux n a leve.
        $journal->shouldHaveReceived('critical')->twice();

        $avance = (new PersistentCombatAdvancer())->advance($arrivee + 31);

        $this->assertArrayHasKey((int)$combat->id, $avance->failures, 'The advancer did not count the suspension as a failure.');
        $this->assertSame(1, (int)CombatInstance::query()->findOrFail($combat->id)->advance_attempts);
    }

    /**
     * **Un combat ouvert avant la ligne de base des historiques s ouvre sous la premiere regle.**
     *
     * Une flotte arrivee pendant la maintenance du deploiement, traitee apres, ne doit pas se voir composer
     * sur un historique qui ne la connait pas : on conserve les anciennes regles par le versionnement, sans
     * inventer d historique.
     */
    public function testACombatOpenedBeforeTheHistoryBaselineOpensUnderTheFirstRule(): void
    {
        // La ligne de base est le **plus ancien** instant que la migration a inscrit : la repousser
        // demande de deplacer toutes ses lignes, pas d en ajouter une tardive. Le montage du banc les
        // ramenera avant son horloge des l essai suivant.
        DB::table('character_class_history')->insert([
            'user_id' => 1,
            'character_class' => null,
            'changed_at' => 4_000_000_000,
            'cause' => ClassHistoryBaseline::CAUSE,
        ]);
        DB::table('character_class_history')->where('cause', ClassHistoryBaseline::CAUSE)->update(['changed_at' => 4_000_000_000]);

        $this->assertSame(4_000_000_000, resolve(ClassHistoryReader::class)->baselineInstant(), 'The premise is missing: the baseline is not after the opening to come.');

        [$combat] = $this->anOpenRally();

        $this->assertSame('v1', CombatInstance::query()->findOrFail($combat->id)->unit_characteristics_version, 'A combat opened before the history baseline froze at entry, on a history that does not exist.');
        $this->assertSame(0, CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->count(), 'The first rule wrote frozen characteristics it never reads.');
    }

    /**
     * **Les trois technologies de tir sont ramenees a l admission**, pas seulement les armes.
     *
     * Une seule des trois eprouvee, les deux autres pourraient lire le compte vivant sans que rien ne le
     * dise : le bouclier et le blindage fixent eux aussi ce qu une unite encaisse.
     */
    public function testTheThreeCombatTechnologiesAreBroughtBackToTheAdmission(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $vivant = $this->theLivingAttacker();
        $niveaux = [
            'weapon_technology' => $vivant->getResearchLevel('weapon_technology'),
            'shielding_technology' => $vivant->getResearchLevel('shielding_technology'),
            'armor_technology' => $vivant->getResearchLevel('armor_technology'),
        ];

        // --- Les trois s achevent apres l arrivee, et le monde les a deja appliquees ---
        foreach ($niveaux as $nom => $niveau) {
            $file = $this->aResearchEndingAt($niveau + 1, $ouverture + 10, $ouverture + 20, $nom);
            DB::table('research_queues')->where('id', $file)->update(['processed' => 1]);
            $this->playerSetResearchLevel($nom, $niveau + 1);
        }

        $mission = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 3_600, 0);
        resolve(CombatEntryCharacteristicsRegistry::class)->recordAtArrival(
            CombatInstance::query()->findOrFail($combat->id),
            (int)$mission->id,
            $this->currentUserId,
            $ouverture
        );

        $ligne = $this->lineOf($combat, (int)$mission->id);

        $this->assertNotNull($ligne);
        $this->assertSame($niveaux['weapon_technology'], (int)$ligne->weapon_level, 'A weapon research finished after the admission armed the shots.');
        $this->assertSame($niveaux['shielding_technology'], (int)$ligne->shield_level, 'A shielding research finished after the admission armed the shields.');
        $this->assertSame($niveaux['armor_technology'], (int)$ligne->armor_level, 'An armour research finished after the admission armed the hulls.');
    }

    // ------------------------------------------------------------------ le montage

    /**
     * Une alliance fondee par l attaquant, sans classe : l appartenance existe, le bonus non.
     */
    private function anAllianceFoundedByTheAttacker(): Alliance
    {
        return resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'GA' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Gel ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );
    }

    /**
     * Le fondateur paie la classe de son alliance et la choisit.
     */
    private function theAllianceChooses(Alliance $alliance, AllianceClass $classe): void
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');

        // Une alliance fondee a l instant n a pas les quatorze jours qui offrent le premier choix.
        DB::table('users')->where('id', $this->currentUserId)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);

        resolve(AllianceClassService::class)->choose(
            User::query()->findOrFail($this->currentUserId),
            $alliance,
            $classe
        );
    }

    /**
     * Le compte de l attaquant, relu a neuf.
     */
    private function theLivingAttacker(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function lineOf(CombatInstance $combat, int $fleetMissionId): CombatEntryCharacteristic|null
    {
        return CombatEntryCharacteristic::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($fleetMissionId))
            ->first();
    }

    /**
     * La seconde vague du montage : elle arrive a l echeance, et aucune page ne l a encore traitee.
     */
    private function theSecondWave(CombatInstance $combat): FleetMission
    {
        $vague = FleetMission::query()
            ->where('user_id', $this->currentUserId)
            ->where('mission_type', 1)
            ->where('processed', 0)
            ->whereKeyNot($combat->mission_id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($vague, 'The second wave is missing.');

        return $vague;
    }

    /**
     * Une recherche **commencee** sur la planete de l attaquant, que le jeu achevera a `$end`.
     *
     * Elle a la forme exacte que `ResearchQueueService::retrieveFinishedForUser()` acheve : en cours
     * (`building`), ni traitee ni annulee.
     */
    private function aResearchEndingAt(int $target, int $start, int $end, string $machineName = 'weapon_technology'): int
    {
        return (int)DB::table('research_queues')->insertGetId([
            'planet_id' => $this->planetService->getPlanetId(),
            'object_id' => ObjectService::getResearchObjectByMachineName($machineName)->id,
            'object_level_target' => $target,
            'time_duration' => $end - $start,
            'time_start' => $start,
            'time_end' => $end,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'building' => 1,
            'processed' => 0,
            'canceled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Ferme le ralliement apres son echeance — ou a l instant donne, s il est plus tardif.
     */
    private function close(CombatInstance $combat, int $ouverture, int|null $instant = null): void
    {
        $fermeture = $instant ?? $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');
    }

    /**
     * L effectif tel que la bataille le compose, depuis la photographie d ouverture du combat.
     */
    private function theBattleRoster(CombatInstance $combat): CombatRoster
    {
        $relu = CombatInstance::query()->findOrFail($combat->id);

        return (new CombatRosterReader())->forTheBattle(
            $relu,
            OpeningStateRecorder::openingUnitsOf($relu),
            OpeningStateRecorder::openingDefenderOf($relu)
        );
    }
}
