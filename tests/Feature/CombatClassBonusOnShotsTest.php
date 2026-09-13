<?php

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\Enums\AllianceClass;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\NPCPlayerService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * **Le bonus de combat des classes arme les tirs**, et plus seulement le rapport.
 *
 * ------------------------------------------------------------------------------------
 * CE QUI A CHANGE, ET POURQUOI CES TEMOINS
 *
 * Jusqu au 12 septembre 2026, le +2 du General et le +1 d une alliance de Guerriers n entraient que
 * dans les niveaux **rapportes** par le rapport de combat : le joueur lisait « armes 7 » et tirait
 * comme un joueur d armes 5. Keven a tranche (« A — les appliquer aux tirs ») : le bonus porte
 * desormais sur la puissance de feu, les points de bouclier et la coque.
 *
 * Un essai qui se contenterait de constater « le General frappe plus fort » passerait aussi si le
 * bonus etait donne a tout le monde, ou compte deux fois. Les temoins ci-dessous sont donc **des
 * nombres exacts**, et le joueur sans classe en est un : c est lui qui separe « le bonus s applique
 * a qui le possede » de « la puissance a monte pour tout le monde ».
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI LE CHASSEUR LEGER, ET CES NIVEAUX
 *
 * Le chasseur leger porte trois valeurs de base rondes — 50 de frappe, 10 de bouclier, 4 000 de
 * coque — et chaque niveau vaut 10 %. Armes 5, boucliers 3, blindage 2 donnent donc des ecarts que
 * l on peut ecrire a la main, et un bonus compte deux fois se verrait immediatement.
 */
class CombatClassBonusOnShotsTest extends AccountTestCase
{
    private const string CHASSEUR = 'light_fighter';

    protected function setUp(): void
    {
        parent::setUp();

        // **L essai pose ce qu il suppose.** Le montage ne donne aucune classe de personnage, mais
        // l ecrire rend la mesure independante de ce qu un voisin aurait laisse sur ce compte.
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => null]);

        $this->playerSetResearchLevel('weapon_technology', 5);
        $this->playerSetResearchLevel('shielding_technology', 3);
        $this->playerSetResearchLevel('armor_technology', 2);

        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Le temoin de reference : sans classe, rien ne bouge.**
     *
     * Sans lui, un bonus accorde a tout le monde passerait tous les autres essais de ce fichier.
     */
    public function testAPlayerWithoutAnyClassFiresAtItsOwnResearchLevels(): void
    {
        $this->assertShots($this->joueur(), 75, 13, 4800);
    }

    /**
     * **Le General tire deux niveaux plus haut, sur les trois caracteristiques.**
     */
    public function testAGeneralFiresTwoLevelsHigherOnAllThreeCharacteristics(): void
    {
        $this->uneClasseDePersonnage(CharacterClass::GENERAL);

        // Armes 5+2, boucliers 3+2, blindage 2+2.
        $this->assertShots($this->joueur(), 85, 15, 5600);
    }

    /**
     * **Une alliance de Guerriers vaut un niveau, meme sans classe de personnage.**
     */
    public function testAWarriorsAllianceAddsOneLevelToEveryShot(): void
    {
        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        // Armes 5+1, boucliers 3+1, blindage 2+1.
        $this->assertShots($this->joueur(), 80, 14, 5200);
    }

    /**
     * **Les deux classes s additionnent sur le meme tir.**
     */
    public function testTheCharacterAndTheAllianceClassesStackOnTheSameShot(): void
    {
        $this->uneClasseDePersonnage(CharacterClass::GENERAL);
        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        // Armes 5+3, boucliers 3+3, blindage 2+3.
        $this->assertShots($this->joueur(), 90, 16, 6000);
    }

    /**
     * **Une alliance de Chercheurs ne change aucun tir.**
     *
     * Le pendant, cote alliance, du joueur sans classe : une somme posee sur toutes les classes
     * d alliance passerait l essai des Guerriers.
     */
    public function testAResearchersAllianceChangesNoShot(): void
    {
        $this->uneAllianceDeClasse(AllianceClass::RESEARCHERS);

        $this->assertShots($this->joueur(), 75, 13, 4800);
    }

    /**
     * **Les pirates d une expedition n heritent d aucune classe.**
     *
     * Ils entrent au combat par un `NPCPlayerService`, dont l utilisateur fictif n a ni classe ni
     * alliance. Le temoin ferme la porte a un bonus qui se glisserait dans les rencontres.
     */
    public function testTheExpeditionPiratesGetNoClassBonus(): void
    {
        $this->assertShots(new NPCPlayerService('pirate', 5, 3, 2), 75, 13, 4800);
    }

    /**
     * **Le champ ouvert porte le bonus dans chaque unite**, et le rapport dit le meme niveau.
     *
     * Deux affirmations distinctes, et il faut les deux : le rapport pouvait deja annoncer « armes 7 »
     * pendant que les unites tiraient a 5. Ici, l unite du champ porte 85 — 50 x (1 + 7/10) — et le
     * rapport annonce 7. Un bonus compte deux fois donnerait 95 d un cote ou 9 de l autre.
     */
    public function testTheShotsAndTheReportedLevelsCarryTheBonusExactlyOnce(): void
    {
        // **Sans garnison, il n y a pas de bataille** : le moteur clot avant le premier round et la
        // puissance de feu n est jamais mesuree.
        $this->planetAddUnit('rocket_launcher', 50);

        $this->uneClasseDePersonnage(CharacterClass::GENERAL);

        $resultat = $this->bataille($this->joueur(), 30);

        $this->assertSame(7, $resultat->attackerWeaponLevel, 'Le rapport n annonce pas les armes du General.');
        $this->assertSame(5, $resultat->attackerShieldLevel);
        $this->assertSame(4, $resultat->attackerArmorLevel);

        $this->assertNotEmpty($resultat->rounds);
        $this->assertSame(
            30 * 85,
            $resultat->rounds[0]->fullStrengthAttacker,
            'La puissance de feu du premier round n est pas celle de trente chasseurs a 85 : le bonus manque aux tirs, ou il y est deux fois.'
        );
    }

    /**
     * **A tirages identiques, la meme bataille est plus meurtriere pour un General.**
     *
     * Les deux batailles partent de la meme graine, du meme effectif et de la meme garnison : le seul
     * ecart est la classe. Sans cet essai, le bonus pourrait n exister que dans les nombres affiches
     * a l ouverture sans jamais changer une issue.
     */
    public function testTheSameSeededBattleIsDeadlierForAGeneral(): void
    {
        // **La mesure se prend au premier round, et la garnison doit lui survivre.** Comparer les
        // survivants de fin de bataille ne dirait rien : quand les deux camps s aneantissent, la
        // regle juste et la fausse rendent zero toutes les deux.
        $this->planetAddUnit('rocket_launcher', 200);

        $sansClasse = $this->pertesDuPremierRound($this->bataille($this->joueur(), 400));

        $this->uneClasseDePersonnage(CharacterClass::GENERAL);

        $general = $this->pertesDuPremierRound($this->bataille($this->joueur(), 400));

        // Les deux premisses : le premier round tue, et il ne tue pas tout — sans quoi l ecart
        // n aurait nulle part ou se voir.
        $this->assertGreaterThan(0, $sansClasse, 'Le premier round ne tue aucun lanceur : l essai ne peut mesurer aucun ecart.');
        $this->assertLessThan(200, $sansClasse, 'Le premier round aneantit deja la garnison : le bonus n a plus de place pour se voir.');

        $this->assertGreaterThan(
            $sansClasse,
            $general,
            'Le premier round du General ne tue pas plus de lanceurs : son bonus ne change aucune issue.'
        );
    }

    /**
     * **La page Recherche dit les deux niveaux, pas seulement celui du personnage.**
     *
     * Elle n affichait que la classe de personnage : un membre d une alliance de Guerriers combattait
     * avec un niveau que sa propre page taisait.
     */
    public function testTheResearchPageShowsBothClassLevels(): void
    {
        $this->uneClasseDePersonnage(CharacterClass::GENERAL);

        $this->get('/research')->assertStatus(200)->assertSee('(+2)');

        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        $this->get('/research')->assertStatus(200)->assertSee('(+3)');
    }

    /**
     * **L arbre technologique montre la valeur reelle** (decision de Keven : « valeurs reelles »).
     *
     * La ligne lue est celle de la frappe, jamais la page entiere : un nombre cherche au hasard dans
     * la page pourrait venir d une autre propriete.
     */
    public function testTheTechTreeShowsTheRealAttackOfAGeneral(): void
    {
        $chasseur = ObjectService::getUnitObjectByMachineName(self::CHASSEUR);

        $this->assertSame(75, $this->frappeLueDansLArbre($chasseur->id));

        $this->uneClasseDePersonnage(CharacterClass::GENERAL);

        $this->assertSame(85, $this->frappeLueDansLArbre($chasseur->id));
    }

    /**
     * **L infobulle dit d ou vient le bonus, sur deux lignes** (decision de Keven, 12 septembre 2026).
     *
     * La recherche d un cote, les classes de l autre, et les deux lignes somment au total que les tirs
     * emploient : 25 + 10 = 35, soit 85 de frappe. Le joueur sans classe n a qu une ligne — une ligne de
     * classe a zero serait du bruit.
     */
    public function testTheTechTreeTooltipShowsTheClassBonusOnItsOwnLine(): void
    {
        $chasseur = ObjectService::getUnitObjectByMachineName(self::CHASSEUR);

        $this->assertSame(
            [['libelle' => 'Research bonus', 'pourcentage' => 50, 'valeur' => 25]],
            $this->lignesDeBonusDeLInfobulle($chasseur->id),
            'Un joueur sans classe voit autre chose que sa seule recherche dans l infobulle.'
        );

        $this->uneClasseDePersonnage(CharacterClass::GENERAL);

        $this->assertSame(
            [
                ['libelle' => 'Research bonus', 'pourcentage' => 50, 'valeur' => 25],
                ['libelle' => 'Class bonus', 'pourcentage' => 20, 'valeur' => 10],
            ],
            $this->lignesDeBonusDeLInfobulle($chasseur->id),
            'L infobulle ne montre pas le bonus de classe sur sa ligne, ou ses deux lignes ne somment pas aux tirs.'
        );

        $this->assertSame(85, $this->frappeLueDansLArbre($chasseur->id));
    }

    /**
     * **La photographie d un corps porte le niveau de l alliance**, pas seulement celui du personnage.
     *
     * La bataille applique ce nombre aux tirs de la garnison : une photographie qui ne lirait que la
     * classe de personnage ferait combattre un defenseur de Guerriers sans le niveau qu il a paye. Le
     * joueur n a ici aucune classe de personnage : seul le niveau d alliance peut rendre 1.
     */
    public function testTheBodyPhotographCarriesTheAllianceLevel(): void
    {
        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        $corps = $this->planetService;
        $coordonnees = $corps->getPlanetCoordinates();

        $combat = new CombatInstance();
        $combat->forceFill([
            'status' => CombatState::Rallying,
            'mission_id' => 1,
            'target_planet_id' => $corps->getPlanetId(),
            'target_type' => PlanetType::Planet->value,
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'position' => $coordonnees->position,
        ]);
        $combat->save();

        (new OpeningStateRecorder())->capture($combat, $corps->getPlanetId(), 1_700_000_000);

        $this->assertSame(
            1,
            OpeningStateRecorder::openingDefenderOf($combat)->classCombatBonus,
            'La photographie du corps ne porte pas le niveau de l alliance de Guerriers.'
        );
    }

    /**
     * **Les quatre libelles d infobulle existent en francais**, et n y rendent pas leur clef.
     *
     * La vue les traduit a l affichage : une clef absente s afficherait telle quelle, sans erreur.
     */
    public function testTheTooltipLabelsAreTranslatedInFrench(): void
    {
        $attendus = [
            'tooltip_research_bonus' => 'Bonus de recherche',
            'tooltip_class_bonus' => 'Bonus de classe',
            'tooltip_character_class_bonus' => 'Bonus de classe de personnage',
            'tooltip_alliance_class_bonus' => 'Bonus de classe d’alliance',
        ];

        foreach ($attendus as $clef => $libelle) {
            $this->assertSame($libelle, __('t_ingame.techtree.' . $clef, [], 'fr'), 'Le libelle « ' . $clef . ' » n est pas traduit en francais.');
        }
    }

    // ------------------------------------------------------------------ le montage

    /**
     * Les trois caracteristiques du chasseur leger, telles que le moteur les calculera.
     */
    private function assertShots(PlayerService $joueur, int $frappe, int $bouclier, int $coque): void
    {
        $chasseur = ObjectService::getUnitObjectByMachineName(self::CHASSEUR);

        $this->assertSame($frappe, $chasseur->properties->attack->calculate($joueur)->totalValue, 'La puissance de feu n est pas celle attendue.');
        $this->assertSame($bouclier, $chasseur->properties->shield->calculate($joueur)->totalValue, 'Le bouclier n est pas celui attendu.');
        $this->assertSame($coque, $chasseur->properties->structural_integrity->calculate($joueur)->totalValue, 'La coque n est pas celle attendue.');
    }

    /**
     * Le joueur, relu **frais** : une instance gardee par la fabrique porterait le service de classes
     * resolu avant que l alliance n existe.
     */
    private function joueur(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function uneClasseDePersonnage(CharacterClass $classe): void
    {
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => $classe->value]);
    }

    /**
     * Une alliance fondee par ce joueur, et sa classe payee.
     */
    private function uneAllianceDeClasse(AllianceClass $classe): void
    {
        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'BC' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Bonus ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->assertNotNull($alliance);

        // Une alliance fondee a l instant n a pas les quatorze jours qui offrent le premier choix.
        DB::table('users')->where('id', $this->currentUserId)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);

        resolve(AllianceClassService::class)->choose(
            User::query()->findOrFail($this->currentUserId),
            $alliance,
            $classe
        );
    }

    /**
     * Une bataille de ce joueur contre la garnison de sa propre planete, a tirages fixes.
     */
    private function bataille(PlayerService $joueur, int $chasseurs): BattleResult
    {
        $effectif = new UnitCollection();
        $effectif->addUnit(ObjectService::getUnitObjectByMachineName(self::CHASSEUR), $chasseurs);

        $attaquante = new AttackerFleet();
        $attaquante->units = $effectif;
        $attaquante->player = $joueur;
        $attaquante->fleetMissionId = 0;
        $attaquante->ownerId = $joueur->getId();
        $attaquante->cargoResources = new Resources(0, 0, 0, 0);
        $attaquante->isInitiator = true;
        $attaquante->fleetMission = null;

        $cible = $this->planetService;

        $moteur = new PhpBattleEngine(
            [$attaquante],
            $cible,
            [DefenderFleet::fromPlanet($cible)],
            resolve(SettingsService::class),
            LiveLootContextFactory::forBattle([$attaquante], $cible, FrozenLootAllocation::atOperationStart())
        );

        return $moteur->withDraws(new SeededDraws(20260912))->simulateBattle();
    }

    /**
     * Les lanceurs que le **premier round** a detruits.
     */
    private function pertesDuPremierRound(BattleResult $resultat): int
    {
        $this->assertNotEmpty($resultat->rounds, 'La bataille n a joue aucun round.');

        return $resultat->rounds[0]->defenderLossesInRound->getAmountByMachineName('rocket_launcher');
    }

    /**
     * La frappe du chasseur telle que l arbre technologique l affiche, lue **dans sa ligne**.
     */
    private function frappeLueDansLArbre(int $objectId): int
    {
        return (int)preg_replace('/\D/', '', $this->valeurDeFrappe($objectId)->textContent);
    }

    /**
     * Les lignes de bonus de l infobulle de frappe : libelle, pourcentage et valeur de chacune.
     *
     * @return array<int, array{libelle: string, pourcentage: int, valeur: int}>
     */
    private function lignesDeBonusDeLInfobulle(int $objectId): array
    {
        // « Attack Strength|<table>… » : le nom de la propriete, puis la table de l infobulle.
        $titre = $this->valeurDeFrappe($objectId)->getAttribute('title');
        $separateur = strpos($titre, '|');

        $this->assertNotFalse($separateur, 'L infobulle de frappe n a pas la forme « nom|table ».');

        $xpath = $this->analyser(substr($titre, $separateur + 1));

        // Une ligne de bonus est la seule qui porte une formule entre parentheses.
        $lignes = $xpath->query('//tr[th/span[contains(@class, "formula")]]');
        $this->assertNotFalse($lignes);

        $bonus = [];

        foreach ($lignes as $ligne) {
            $this->assertInstanceOf(DOMElement::class, $ligne, 'Une ligne de l infobulle n est pas un element.');

            $entete = $this->premierElement($xpath, 'th', $ligne);
            $formule = $this->premierElement($xpath, 'th/span', $ligne);
            $montant = $this->premierElement($xpath, 'td', $ligne);

            $bonus[] = [
                'libelle' => rtrim(trim(str_replace($formule->textContent, '', $entete->textContent)), ': '),
                'pourcentage' => (int)preg_replace('/\D/', '', $formule->textContent),
                'valeur' => (int)preg_replace('/\D/', '', $montant->textContent),
            ];
        }

        return $bonus;
    }

    /**
     * Le premier element que ce chemin designe sous cette ligne, ou un echec qui le nomme.
     */
    private function premierElement(DOMXPath $xpath, string $chemin, DOMElement $contexte): DOMElement
    {
        $noeuds = $xpath->query($chemin, $contexte);

        $this->assertNotFalse($noeuds, 'Le chemin « ' . $chemin . ' » ne s evalue pas dans l infobulle.');

        $noeud = $noeuds->item(0);

        $this->assertInstanceOf(DOMElement::class, $noeud, 'Une ligne de l infobulle n a pas de « ' . $chemin . ' ».');

        return $noeud;
    }

    /**
     * L element qui porte la frappe dans la fiche technique, et son infobulle en attribut.
     */
    private function valeurDeFrappe(int $objectId): DOMElement
    {
        $reponse = $this->get('ajax/techtree?tab=2&object_id=' . $objectId);
        $reponse->assertStatus(200);

        $html = $reponse->getContent();

        $this->assertIsString($html);

        $noeuds = $this->analyser($html)->query('//tr[contains(@class, "attack_strength")]//span[contains(@class, "tooltipHTML")]');

        $this->assertNotFalse($noeuds, 'La page de l arbre technologique n a pas pu etre analysee.');
        $this->assertSame(1, $noeuds->length, 'La ligne de frappe est introuvable, ou en plusieurs exemplaires.');

        $valeur = $noeuds->item(0);

        // Un element, pas n importe quel noeud : c est son texte affiche et son attribut qui sont lus.
        $this->assertInstanceOf(DOMElement::class, $valeur, 'La valeur de frappe n est pas un element de la page.');

        return $valeur;
    }

    /**
     * **Une page se lit par un analyseur, jamais par un motif.** L attribut `title` de la ligne de frappe
     * porte l infobulle entiere : une table HTML avec ses propres `</tr>`, ses propres nombres et meme un
     * `</span>`. Tout motif y trebuche — deux essais successifs ont lu la mauvaise ligne. Un analyseur,
     * lui, sait qu un attribut est un attribut.
     */
    private function analyser(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $erreurs = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($erreurs);

        return new DOMXPath($document);
    }
}
