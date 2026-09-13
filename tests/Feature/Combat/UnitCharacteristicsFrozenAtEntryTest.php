<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Exceptions\MissingEntryCharacteristics;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\CombatEntryCharacteristicsRegistry;
use OGame\Combat\Services\CombatRoster;
use OGame\Combat\Services\CombatRosterReader;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatantFrozenAtEntry;
use OGame\Combat\Support\CombatantUnderTheFirstRule;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\CombatEntryCharacteristic;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

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
 * - la garnison tire avec **sa photographie d ouverture**, et plus avec le compte vivant ;
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
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => CharacterClass::GENERAL->value]);

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
     * **Une recherche achevee exactement a l arrivee ne compte pas** : l egalite vaut « apres », comme pour
     * toutes les barrieres du combat.
     */
    public function testAResearchFinishedExactlyAtTheArrivalDoesNotCount(): void
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
        $this->assertSame($avant, (int)$ligne->weapon_level, 'A research finished exactly at the arrival was counted: equality must count as after.');
    }

    /**
     * **Un combat ouvert sous la premiere regle la garde** : niveaux vivants, aucun bonus aux tirs, bonus
     * annonce au rapport — exactement ce que le jeu faisait.
     */
    public function testACombatOpenedUnderTheFirstRuleKeepsIt(): void
    {
        [$combat, , $ouverture] = $this->anOpenRally();

        DB::table('combat_instances')->where('id', $combat->id)->update(['unit_characteristics_version' => 'v1']);
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => CharacterClass::GENERAL->value]);
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

        resolve(CombatEntryCharacteristicsRegistry::class)->recordAtEntry(
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

        resolve(CombatEntryCharacteristicsRegistry::class)->recordAtEntry(
            CombatInstance::query()->findOrFail($combat->id),
            $combat->mission_id,
            $this->currentUserId,
            1_700_000_000
        );

        $this->assertSame(0, CombatEntryCharacteristic::query()->where('combat_instance_id', $combat->id)->count(), 'The first rule wrote frozen characteristics it never reads.');
    }

    // ------------------------------------------------------------------ le montage

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
