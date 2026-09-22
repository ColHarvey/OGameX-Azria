<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Presentation\BattleReportParticipants;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Factories\GameMessageFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Research\LifeformSlotRules;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\BattleReport;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\BuddyService;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\FleetDispatchTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Le rapport de combat gele chaque participant avec ses propres caracteristiques** (revue de Codex, journal §161) :
 * une attaque contre une garnison renforcee par une Defense ACS d un troisieme joueur, trois niveaux de technologie
 * differents, et une technologie de formes de vie chez l attaquant. Le rapport porte un bloc par flotte — garnison
 * comprise — avec ses niveaux, ses unites, les caracteristiques que la bataille a employees et la part des formes de
 * vie a part ; la page complete offre le choix du participant et ecrit ces lignes ; un rapport anterieur au bloc se
 * lit encore, sans rien inventer.
 */
final class BattleReportParticipantsTest extends FleetDispatchTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int GENERAL_OVERHAUL_LIGHT_FIGHTER = 13205;

    protected int $missionType = 1;

    protected string $missionName = 'Attack';

    protected function basicSetup(): void
    {
        // Le montage est dans l essai lui-meme : trois joueurs, trois niveaux, une technologie de formes de vie.
    }

    /**
     * @var array<int, int>
     */
    private array $comptesCrees = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8, 'fleet_speed_war' => 1, 'fleet_speed_holding' => 1, 'fleet_speed_peaceful' => 1, 'persistent_combat_enabled' => 0]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        foreach ($this->comptesCrees as $id) {
            DB::table('buddy_requests')->where('sender_user_id', $id)->orWhere('receiver_user_id', $id)->delete();
        }
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * **Un nom lu dans la charge JSON se compare avec l echappement du JSON, pas avec celui du HTML.**
     *
     * La page ecrit ce nom deux fois : en HTML, ou l apostrophe devient `&#039;`, et dans `var combatData = @json(...)`,
     * ou elle devient `'` — `@json` pose `JSON_HEX_APOS` et ses voisins. Comparer la seconde occurrence a la
     * premiere ne marchait que tant que le nom tire au hasard par le montage n avait pas d apostrophe : « Cayla
     * O'Conner V » a fait tomber l essai un passage sur beaucoup, sans que rien du jeu ait change.
     */
    private function commeDansLeJson(string $valeur): string
    {
        return trim((string)json_encode($valeur, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), '"');
    }

    public function testEachParticipantIsFrozenWithItsOwnCharacteristicsAndTheReportShowsThem(): void
    {
        // L attaquant : armes 5, et la Revision generale (chasseur leger) niveau 3 des Mechas : +0,9 % sur le chasseur.
        $this->playerSetResearchLevel('weapon_technology', 5);
        $this->playerSetResearchLevel('shielding_technology', 5);
        $this->playerSetResearchLevel('armor_technology', 5);
        $this->planetAddUnit('light_fighter', 40);
        $this->planetAddResources(new Resources(0, 0, 1000000, 0));
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
        // Une population stationnaire qui ouvre l emplacement 5 : l horloge demographique la relit a l arrivee de l attaque.
        $this->sustainLifeformPopulation($this->currentPlanetId, Species::Mechas, LifeformSlotRules::populationRequired(5), (int)Date::now()->timestamp);
        $this->placeLifeformTechnology($this->currentPlanetId, Species::Mechas, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 3);
        LifeformBonusCache::invalidate();

        // La cible : un ami, armes 1, dix chasseurs et vingt lanceurs de missiles.
        $cible = $this->aBuddyWithAPlanet($this->currentUserId);
        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire);
        $proprietaire->setResearchLevel('weapon_technology', 1);
        $proprietaire->setResearchLevel('shielding_technology', 1);
        $proprietaire->setResearchLevel('armor_technology', 1);
        $cible->addUnit('light_fighter', 10);
        $cible->addUnit('rocket_launcher', 20);

        // Le renfort : un troisieme joueur, armes 10, quinze chasseurs en Defense ACS chez la cible.
        $renfort = $this->aBuddyWithAPlanet((int)$proprietaire->getId(), (int)$cible->getPlanetCoordinates()->system + 1);
        $joueurRenfort = $renfort->getPlayer();
        $this->assertNotNull($joueurRenfort);
        $joueurRenfort->setResearchLevel('weapon_technology', 10);
        $joueurRenfort->setResearchLevel('shielding_technology', 10);
        $joueurRenfort->setResearchLevel('armor_technology', 10);
        $renfort->addUnit('light_fighter', 15);
        $renfort->addResources(new Resources(0, 0, 1000000, 0));
        $flotteRenfort = new UnitCollection();
        $flotteRenfort->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 15);
        $missionRenfort = resolve(FleetMissionService::class, ['player' => $joueurRenfort])->createNewFromPlanet($renfort, $cible->getPlanetCoordinates(), PlanetType::Planet, 5, $flotteRenfort, new Resources(0, 0, 0, 0), 10, 2);
        $this->travelTo(Date::createFromTimestamp($missionRenfort->time_arrival - $missionRenfort->time_holding + 10));
        $this->reloadApplication();
        $this->get('/overview');

        // L attaque : trente chasseurs.
        $attaque = new UnitCollection();
        $attaque->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 30);
        $this->dispatchFleet($cible->getPlanetCoordinates(), $attaque, new Resources(0, 0, 0, 0), PlanetType::Planet);
        $missionAttaque = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()])->getActiveFleetMissionsForCurrentPlayer()->first();
        $this->assertNotNull($missionAttaque);
        $this->travelTo(Date::createFromTimestamp($missionAttaque->time_arrival + 10));
        $this->reloadApplication();
        $this->playerSetAllMessagesRead();
        $this->get('/overview');

        $rapport = BattleReport::query()->orderByDesc('id')->first();
        $this->assertNotNull($rapport);
        $bloc = BattleReportParticipants::fromStorage($rapport->participants);
        $this->assertNotNull($bloc, 'Le rapport porte le bloc des participants.');
        $this->assertSame(BattleReportParticipants::SCHEMA, $bloc['schema']);

        // Un attaquant : ma flotte, mes niveaux, mes trente chasseurs, et les caracteristiques que la bataille a employees.
        $this->assertCount(1, $bloc['attackers']);
        $moi = $bloc['attackers'][0];
        $this->assertSame(CombatParticipantKey::forFleet((int)$missionAttaque->id), $moi['key']);
        $this->assertSame($missionAttaque->id, $moi['fleet_mission_id']);
        $this->assertSame($this->currentUserId, $moi['player_id']);
        $this->assertSame([5, 5, 5], [$moi['weapon_technology'], $moi['shielding_technology'], $moi['armor_technology']]);
        $this->assertSame(['light_fighter' => 30], $moi['units_start']);
        $this->assertSame($this->planetService->getPlanetCoordinates()->galaxy, $moi['origin']['galaxy']);
        $chasseur = $moi['unit_characteristics']['light_fighter'];
        $this->assertSame(0.9, $chasseur['lifeform_percent'], 'Trois niveaux de Revision generale : 0,9 %.');
        $this->assertSame(75, $chasseur['weapon'], '50 × 1,5 ; 0,9 % de 50 arrondi vers le bas vaut 0.');
        $this->assertSame(15, $chasseur['shield'], '10 × 1,5.');
        $this->assertSame(603, $chasseur['armor'], '(4 000 × 1,5 + 36) / 10.');
        $this->assertSame(['weapon' => 0, 'shield' => 0, 'armor' => 3], $chasseur['lifeform_points']);

        // Deux defenseurs : la garnison avec les niveaux du proprietaire, le renfort avec les siens — pas ceux de la planete.
        $this->assertCount(2, $bloc['defenders']);
        $garnison = $bloc['defenders'][0];
        $this->assertSame(BattleReportParticipants::GARRISON_KEY, $garnison['key']);
        $this->assertSame('garrison', $garnison['kind']);
        $this->assertSame((int)$proprietaire->getId(), $garnison['player_id']);
        $this->assertSame([1, 1, 1], [$garnison['weapon_technology'], $garnison['shielding_technology'], $garnison['armor_technology']]);
        $this->assertSame(['light_fighter' => 10, 'rocket_launcher' => 20], $garnison['units_start']);
        $this->assertSame(55, $garnison['unit_characteristics']['light_fighter']['weapon'], '50 × 1,1.');
        $this->assertSame(0.0, (float)$garnison['unit_characteristics']['rocket_launcher']['lifeform_percent'], 'Le JSON rend 0 pour 0,0 : aucun bonus.');
        $acs = $bloc['defenders'][1];
        $this->assertSame(CombatParticipantKey::forFleet((int)$missionRenfort->id), $acs['key']);
        $this->assertSame('fleet', $acs['kind']);
        $this->assertSame((int)$joueurRenfort->getId(), $acs['player_id']);
        $this->assertSame([10, 10, 10], [$acs['weapon_technology'], $acs['shielding_technology'], $acs['armor_technology']]);
        $this->assertSame(['light_fighter' => 15], $acs['units_start']);
        $this->assertSame(100, $acs['unit_characteristics']['light_fighter']['weapon'], '50 × 2 : les niveaux du renfort, pas ceux de la planete.');
        $this->assertSame(800, $acs['unit_characteristics']['light_fighter']['armor'], '4 000 × 2 / 10.');
        $this->assertSame((int)$renfort->getPlanetCoordinates()->system, $acs['origin']['system']);

        // Les pertes de chaque round, par participant, recouvrent exactement les pertes additionnees du rapport.
        $roundsDuRapport = $rapport->rounds;
        $this->assertIsArray($roundsDuRapport);
        $this->assertCount(count($roundsDuRapport), $bloc['rounds']);
        $survivantsGarnison = $garnison['units_start']['light_fighter'] - ($garnison['units_lost']['light_fighter'] ?? 0);
        $this->assertSame($garnison['units_result']['light_fighter'] ?? 0, $survivantsGarnison, 'Depart − perdus = survivants, par participant.');
        $pertesAcsCumulees = 0;
        foreach ($roundsDuRapport as $i => $round) {
            $pertesDuRound = $bloc['rounds'][$i]['losses'];
            $this->assertSame([$moi['key'], $garnison['key'], $acs['key']], array_keys($pertesDuRound), "Round $i : les trois participants, sous leur clef, et personne d autre.");
            // **Une egalite de pertes, pas d ordre.** Sous le moteur Rust, les types d unite d une carte suivent l ordre de
            // sa table de hachage : la CI l a montre sur `8ad71216` (« rocket_launcher » avant « light_fighter », memes
            // nombres). L affichage lit les pertes par nom et ordonne les lignes par les unites de DEPART, qui ne dependent
            // pas du moteur : le joueur voit la meme chose. Ce qui est affirme ici est une egalite de valeurs, et c est elle
            // qu on compare (journal §166.3).
            $this->assertSame(self::parNom($round['attacker_losses_in_this_round']), self::parNom($pertesDuRound[$moi['key']]), "Round $i : les pertes de l attaquant sont les miennes.");
            $sommeDefense = [];
            foreach ([$garnison['key'], $acs['key']] as $clef) {
                foreach ($pertesDuRound[$clef] as $machine => $n) {
                    $sommeDefense[$machine] = ($sommeDefense[$machine] ?? 0) + $n;
                }
            }
            $this->assertSame(self::parNom(array_filter($round['defender_losses_in_this_round'])), self::parNom(array_filter($sommeDefense)), "Round $i : garnison + renfort = pertes de la defense.");
            $pertesAcsCumulees += (int)($pertesDuRound[$acs['key']]['light_fighter'] ?? 0);
        }
        // Les survivants et les pertes de chaque flotte sont les siens : le renfort ne prend pas ceux de la garnison.
        $this->assertSame($pertesAcsCumulees, (int)($acs['units_lost']['light_fighter'] ?? 0), 'Les pertes du renfort sont la somme de ses rounds.');
        $this->assertSame(15 - $pertesAcsCumulees, (int)($acs['units_result']['light_fighter'] ?? 0), 'Les survivants du renfort : quinze moins ses pertes.');
        $this->assertNotSame($garnison['units_result'], $acs['units_result'], 'Premisse : la garnison et le renfort ne finissent pas avec le meme effectif.');

        // La page complete : le choix du participant cote defense (tous, garnison, renfort), aucun choix cote attaque, les
        // lignes par participant avec leurs niveaux et la part des formes de vie, le JSON du script avec une entree par flotte
        // et les caracteristiques gelees — et plus rien d invente.
        app()->setLocale('fr');
        $message = Message::query()->where('user_id', $this->currentUserId)->where('battle_report_id', $rapport->id)->first();
        $this->assertNotNull($message);
        $page = GameMessageFactory::createGameMessage($message)->getBodyFull();
        $this->assertMatchesRegularExpression('/<select id="defender_select_combatreport" class="participant_select"[^>]*>\s*<option value="all">/', $page);
        $this->assertSame(3, preg_match_all('/<option value="/', $page), 'Tous, la garnison, le renfort.');
        $this->assertStringContainsString('<span id="attacker_select_combatreport" data-member-name="' . e($this->planetService->getPlayer()?->getUsername(false)) . '">', $page);
        $this->assertStringContainsString('(garnison)', $page);
        $this->assertStringContainsString('Armes: 100%', $page);
        $this->assertStringContainsString('Bonus des formes de vie', $page);
        $this->assertStringContainsString('Chasseur léger : +0,9 % (arme +0, bouclier +0, coque +3)', $page);
        $this->assertStringContainsString('var combatData = {', $page);
        $this->assertMatchesRegularExpression('/setCombatLoca\(\s*"Armes:",\s*"Boucliers:",\s*"Armure:",\s*"Classe:"/', $page, 'Les libelles que le script reecrit sont traduits.');
        $this->assertStringNotContainsString("'Weapons:'", $page);
        $clefScript = static fn (string $clef): string => str_replace(':', '_', $clef);
        $this->assertStringContainsString('"' . $clefScript(CombatParticipantKey::forFleet((int)$missionRenfort->id)) . '":{"ownerName":"' . $this->commeDansLeJson($joueurRenfort->getUsername(false)), $page);
        $this->assertStringContainsString('"weaponPercentage":100', $page);
        $this->assertStringContainsString('"shipDetails":{"204":{"armor":800,"weapon":100,"shield":20,"count":15}}', $page);
        $this->assertStringContainsString('"shipDetails":{"204":{"armor":603,"weapon":75,"shield":15,"count":30}}', $page);
        // Le choix du renfort porte l origine de SA flotte, pas la planete attaquee ; et les vaisseaux du dernier round du
        // script sont les survivants geles de chaque participant.
        // **Le script officiel decoupe la valeur d une option** : `nom|id1:id2` (`loadDataBySelectedRound`). Une valeur
        // sans « | » levait une TypeError au premier clic sur un round, et le rapport se figeait (journal §165).
        $this->assertStringContainsString('<option value="' . e($joueurRenfort->getUsername(false)) . '|' . $clefScript(CombatParticipantKey::forFleet((int)$missionRenfort->id)) . '" data-coords="' . $renfort->getPlanetCoordinates()->asString() . '" data-planettype="1">', $page);
        $this->assertSame(1, preg_match('/var combatData = (\{.*?\});\s*
/s', $page, $json), 'Le script du rapport porte combatData.');
        $donnees = json_decode($json[1] ?? '', true);
        $this->assertIsArray($donnees);
        $dernier = $donnees['combatRounds'][count($donnees['combatRounds']) - 1];
        $this->assertSame((int)($moi['units_result']['light_fighter'] ?? 0), $dernier['attackerShips'][$clefScript($moi['key'])]['204'], 'Les vaisseaux du dernier round sont mes survivants.');
        $this->assertSame(15 - $pertesAcsCumulees, $dernier['defenderShips'][$clefScript($acs['key'])]['204'], 'Les vaisseaux du dernier round du renfort sont ses survivants.');
        $this->assertSame($acs['units_start']['light_fighter'], $donnees['combatRounds'][0]['defenderShips'][$clefScript($acs['key'])]['204'], 'Le round de depart porte l effectif de depart.');

        // Chaque option nomme un membre que le script sait retrouver : « nom|clef », et la clef existe dans le JSON de son camp.
        preg_match_all('/<option value="([^"]+)"/', $page, $options);
        foreach ($options[1] as $valeur) {
            $valeur = html_entity_decode($valeur, ENT_QUOTES);
            if ($valeur === 'all') {
                continue;
            }
            $this->assertStringContainsString('|', $valeur, 'Une option sans « | » fait lever une TypeError au script officiel.');
            [$nom, $clefs] = explode('|', $valeur, 2);
            $this->assertNotSame('', $nom);
            foreach (explode(':', $clefs) as $clef) {
                $this->assertTrue(
                    isset($donnees['defenderJSON']['member'][$clef]) || isset($donnees['attackerJSON']['member'][$clef]),
                    'La clef « ' . $clef . ' » de l option n existe dans aucun camp du JSON.'
                );
            }
        }
        $this->assertStringContainsString('data-combatreportid="' . $rapport->id . '"', $page);
        $this->assertStringContainsString('data-message-id="' . $message->id . '"', $page);
        $this->assertStringContainsString('sendShipsWithPopup(6,' . $cible->getPlanetCoordinates()->galaxy . ',' . $cible->getPlanetCoordinates()->system . ',' . $cible->getPlanetCoordinates()->position . ',1,0)', $page);
        foreach (['115473', '4492924', '2:488:1', 'PTL', '8196210', 'gameforge.com', 'Lieutenant Cupid', '"armor": 1160', 'data-raw-fleets'] as $invente) {
            $this->assertStringNotContainsString($invente, $page, "« $invente » est une donnee inventee du gabarit officiel.");
        }

        // Un rapport anterieur au bloc : un membre par camp, l effectif additionne, aucune caracteristique ni ligne inventee.
        $rapport->participants = null;
        $rapport->save();
        $ancien = GameMessageFactory::createGameMessage($message->fresh() ?? $message)->getBodyFull();
        $this->assertStringNotContainsString('<select id="defender_select_combatreport"', $ancien, 'Un seul membre par camp : le choix n existe pas.');
        $this->assertStringNotContainsString('Bonus des formes de vie', $ancien);
        $this->assertStringContainsString('"shipDetails":{"204":{"count":30}}', $ancien, 'Aucune caracteristique inventee pour un rapport ancien.');
        $this->assertStringContainsString('"garrison":{"ownerName":"' . $this->commeDansLeJson($proprietaire->getUsername(false)) . '"', $ancien);
        $this->assertStringContainsString('"ownerCoordinates":"' . $cible->getPlanetCoordinates()->asString() . '"', $ancien);
        foreach (['115473', '4492924', '2:488:1', 'PTL', '8196210', '"armor": 1160'] as $invente) {
            $this->assertStringNotContainsString($invente, $ancien);
        }
    }

    /**
     * Une carte de pertes rangee par nom d unite : ce que deux cartes egales ont de commun, quel que soit le moteur qui les
     * a remplies. Les valeurs restent comparees strictement ; seul l ordre des clefs, qui n est pas une donnee, disparait.
     *
     * @param array<string, int> $pertes
     * @return array<string, int>
     */
    private static function parNom(array $pertes): array
    {
        ksort($pertes);

        return $pertes;
    }

    /**
     * Un ami avec une planete a portee, lie au compte donne.
     */
    private function aBuddyWithAPlanet(int $friendOfUserId, int|null $system = null): PlanetService
    {
        /*
         * **Une apostrophe dans le pseudonyme, toujours.** La fabrique en tire un au hasard, et le rapport ecrit ce nom
         * dans deux echappements — `&#039;` en HTML, `'` dans la charge JSON. Tant que le tirage n en donnait pas,
         * les deux coincidaient et l essai passait sans rien etablir ; le jour ou « Cayla O'Conner V » est sorti, il est
         * tombe. Le cas est donc pose, pas espere.
         */
        $utilisateur = User::factory()->create();
        $utilisateur->username = "O'" . $utilisateur->username;
        $utilisateur->save();
        $this->comptesCrees[] = $utilisateur->id;
        if ($system === null) {
            $planete = $this->createPlanetAtSafeCoordinate($utilisateur->id);
        } else {
            $galaxie = $this->planetService->getPlanetCoordinates()->galaxy;
            $systeme = min(499, $system);
            $position = collect([13, 14, 15, 1, 2, 3])->first(fn (int $p): bool => !Planet::query()->where('galaxy', $galaxie)->where('system', $systeme)->where('planet', $p)->exists());
            $ligne = Planet::factory()->create(['user_id' => $utilisateur->id, 'galaxy' => $galaxie, 'system' => $systeme, 'planet' => $position]);
            $planete = resolve(PlanetServiceFactory::class)->makeForPlayer(resolve(PlayerService::class, ['player_id' => $utilisateur->id]), $ligne->id);
        }
        $amis = resolve(BuddyService::class);
        $demande = $amis->sendRequest($utilisateur->id, $friendOfUserId);
        $amis->acceptRequest($demande->id, $friendOfUserId);

        return $planete;
    }
}
