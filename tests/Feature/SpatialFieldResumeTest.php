<?php

namespace Tests\Feature;

use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Replay\BattleFieldStateCodec;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\AttackerFleetSnapshot;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\LootPolicy;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Patrol\Combat\FrozenCombatant;
use OGame\Patrol\Combat\SpatialCombatSite;
use OGame\Patrol\Combat\SpatialFieldOpening;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * L ouverture d un combat spatial se persiste, et se reprend **ailleurs**, sans rien rejouer.
 *
 * ## Zero round ne veut pas dire zero effet
 *
 * L ouverture joue la manoeuvre de Hamill : un General attaquant avec des chasseurs legers peut y
 * detruire une Etoile de la Mort **avant le premier tir**. Elle consomme donc un tirage de la bande
 * de bataille, et peut retirer une unite.
 *
 * Deux choses distinctes doivent donc survivre a la persistance, et cet essai les mesure separement :
 *
 *   - l **effet** — l Etoile detruite ne revient pas ;
 *   - la **position des deux bandes** — la suite reprend ou elle s est arretee, jamais au mot initial
 *     de la graine, sans quoi tous les tirages suivants se decalent.
 *
 * ## Pourquoi un second processus, et pas un aller-retour en memoire
 *
 * Une reprise eprouvee dans le processus qui vient d ouvrir peut passer sans rien prouver : un objet
 * encore vivant porterait ce que la persistance a perdu. Le lecteur lance ici ne connait que le
 * document ; il n a **aucun moyen** de rejouer la manoeuvre, et c est ce qui rend la mesure
 * concluante.
 *
 * ## Le reglage est epingle, et remis
 *
 * La manoeuvre a une chance sur mille par defaut : mesuree, elle ne se declenche pas en soixante
 * graines. L essai la rend certaine — sans quoi le juste et le faux coincideraient — et **remet la
 * valeur trouvee**, y compris si l essai echoue.
 */
class SpatialFieldResumeTest extends AccountTestCase
{
    private string|null $chanceAvant = null;

    private bool $chanceExistait = false;

    protected function setUp(): void
    {
        parent::setUp();

        $reglages = resolve(SettingsService::class);
        $valeur = $reglages->get('hamill_manoeuvre_chance', '');

        // **L existence est memorisee, pas seulement la valeur.** Poser un reglage qui n existait
        // pas, puis le « remettre » a son defaut, laisse une ligne que la base n avait pas — et la
        // classe voisine lit alors un monde different du notre.
        $this->chanceExistait = $valeur !== '';
        $this->chanceAvant = $this->chanceExistait ? (string)$valeur : null;

        $reglages->set('hamill_manoeuvre_chance', '1');
    }

    protected function tearDown(): void
    {
        $reglages = resolve(SettingsService::class);

        if ($this->chanceExistait && $this->chanceAvant !== null) {
            $reglages->set('hamill_manoeuvre_chance', $this->chanceAvant);
        } else {
            $reglages->set('hamill_manoeuvre_chance', '1000');
        }

        parent::tearDown();
    }

    /**
     * @param array<string, int> $composition
     */
    private static function effectif(array $composition): UnitCollection
    {
        $unites = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $unites;
    }

    /**
     * Le montage : le moteur d ouverture et le contexte de butin qui va avec.
     *
     * La reprise a besoin du second — c est lui qui porte la photographie des flottes attaquantes
     * a l ouverture, et c est de la, jamais du monde vivant, que leurs identites se reconstruisent.
     *
     * @return array{0: SpatialFieldOpening, 1: LootContext}
     */
    private function montage(int $graine): array
    {
        $general = new FrozenCombatant($this->currentUserId, 5, 3, 2, 2, CharacterClass::GENERAL->value);
        $defenseur = new FrozenCombatant($this->currentUserId, 4, 3, 2, 0, null);

        $attaquant = new AttackerFleet();
        $attaquant->units = self::effectif(['light_fighter' => 30]);
        $attaquant->player = $general;
        $attaquant->fleetMissionId = 101;
        $attaquant->ownerId = $general->getId();
        $attaquant->cargoResources = new Resources(0, 0, 0, 0);
        $attaquant->isInitiator = true;
        $attaquant->fleetMission = null;

        $site = new SpatialCombatSite(
            resolve(PlayerServiceFactory::class),
            resolve(SettingsService::class),
            $defenseur,
            new SpatialPoint(100, 100),
            1,
            1,
        );

        $flotteDefenseuse = new DefenderFleet();
        $flotteDefenseuse->units = self::effectif(['deathstar' => 1, 'light_fighter' => 2]);
        $flotteDefenseuse->player = $defenseur;
        $flotteDefenseuse->fleetMissionId = 202;
        $flotteDefenseuse->ownerId = $defenseur->getId();
        $flotteDefenseuse->fleetMission = null;

        $contexte = LootContext::fromObservedFacts(
            new LootPolicy(false, new AttackerCargoShare(0, 0)),
            [AttackerFleetSnapshot::of($attaquant, ActorKind::Player, false, 0)],
            ['body_key' => CombatParticipantKey::UNIDENTIFIED_BODY, 'owner_id' => $defenseur->getId()],
            1_700_000_000,
            LootAllocatorRegistry::default()->currentVersion(),
        );

        $ouverture = new SpatialFieldOpening(
            [$attaquant],
            $site,
            [DefenderFleet::fromPlanet($site), $flotteDefenseuse],
            resolve(SettingsService::class),
            $contexte,
        );

        return [$ouverture->withDraws(new SeededDraws($graine)), $contexte];
    }

    /**
     * Les memes faits que le lecteur rapporte, pris sur un champ vivant.
     *
     * Les mesurer par le meme jeu de champs des deux cotes est ce qui rend la comparaison
     * significative : un releve plus riche d un cote laisserait passer ce qu il ne regarde pas.
     *
     * @return array<string, mixed>
     */
    private static function relevesDe(BattleFieldState $champ): array
    {
        $bataille = $champ->battleDraws;
        $rounds = $champ->roundDraws;

        if (!$bataille instanceof SeededDraws || !$rounds instanceof SeededDraws) {
            throw new RuntimeException('Une bande sans graine ne se compare pas.');
        }

        return [
            'rounds_joues' => $champ->roundsPlayed,
            'unites_defenseuses' => count($champ->defenderUnits),
            'unites_attaquantes' => count($champ->attackerUnits),
            'etoiles_de_la_mort' => self::etoilesDe($champ),
            'mot_bataille' => $bataille->rawState(),
            'mot_rounds' => $rounds->rawState(),
            'tirages_rounds' => $rounds->journal()->rawCount(),
        ];
    }

    /**
     * Le champ ouvert : un General attaquant, une Etoile de la Mort defenseuse.
     */
    private function champOuvert(int $graine): BattleFieldState
    {
        $general = new FrozenCombatant($this->currentUserId, 5, 3, 2, 2, CharacterClass::GENERAL->value);
        $defenseur = new FrozenCombatant($this->currentUserId, 4, 3, 2, 0, null);

        $attaquant = new AttackerFleet();
        $attaquant->units = self::effectif(['light_fighter' => 30]);
        $attaquant->player = $general;
        $attaquant->fleetMissionId = 101;
        $attaquant->ownerId = $general->getId();
        $attaquant->cargoResources = new Resources(0, 0, 0, 0);
        $attaquant->isInitiator = true;
        $attaquant->fleetMission = null;

        $site = new SpatialCombatSite(
            resolve(PlayerServiceFactory::class),
            resolve(SettingsService::class),
            $defenseur,
            new SpatialPoint(100, 100),
            1,
            1,
        );

        $flotteDefenseuse = new DefenderFleet();
        $flotteDefenseuse->units = self::effectif(['deathstar' => 1, 'light_fighter' => 2]);
        $flotteDefenseuse->player = $defenseur;
        $flotteDefenseuse->fleetMissionId = 202;
        $flotteDefenseuse->ownerId = $defenseur->getId();
        $flotteDefenseuse->fleetMission = null;

        $contexte = LootContext::fromObservedFacts(
            new LootPolicy(false, new AttackerCargoShare(0, 0)),
            [AttackerFleetSnapshot::of($attaquant, ActorKind::Player, false, 0)],
            ['body_key' => CombatParticipantKey::UNIDENTIFIED_BODY, 'owner_id' => $defenseur->getId()],
            1_700_000_000,
            LootAllocatorRegistry::default()->currentVersion(),
        );

        $ouverture = new SpatialFieldOpening(
            [$attaquant],
            $site,
            [DefenderFleet::fromPlanet($site), $flotteDefenseuse],
            resolve(SettingsService::class),
            $contexte,
        );

        return $ouverture->withDraws(new SeededDraws($graine))->initialField();
    }

    private static function etoilesDe(BattleFieldState $champ): int
    {
        $etoiles = 0;

        foreach ($champ->defenderUnits as $unite) {
            if ($unite->unitObject->machine_name === 'deathstar') {
                $etoiles++;
            }
        }

        return $etoiles;
    }

    /**
     * **L ouverture consomme un tirage et retire une unite, sans jouer un seul round.**
     *
     * C est la mesure qui rend l essai suivant possible : si la manoeuvre ne se declenchait pas, une
     * reprise « qui ne rejoue pas » ne prouverait rien.
     */
    public function testTheOpeningSpendsADrawAndRemovesAUnitWithoutPlayingARound(): void
    {
        $graine = 12345;
        $champ = $this->champOuvert($graine);

        $this->assertSame(0, $champ->roundsPlayed, 'A round was played at the opening.');

        $this->assertSame(
            0,
            self::etoilesDe($champ),
            'The Hamill manoeuvre did not destroy the Deathstar although its chance was certain.'
        );
        $this->assertCount(2, $champ->defenderUnits, 'The defender roster does not show the manoeuvre.');

        // **La bande a bouge**, et une bande neuve de la meme graine dit ou elle serait restee.
        //
        // Le type declare du champ est l interface `BattleDraws` : une suite dictee par un essai
        // n a ni graine ni mot courant. On exige donc une suite a graine avant de lire sa position,
        // plutot que de supposer qu il y en a une.
        $bataille = $champ->battleDraws;
        $this->assertInstanceOf(SeededDraws::class, $bataille);

        $neuve = new SeededDraws($graine);

        $this->assertNotSame(
            $neuve->rawState(),
            $bataille->rawState(),
            'The battle band did not move: the opening consumed nothing, and a resume would prove nothing.'
        );
        $this->assertSame(1, $bataille->journal()->rawCount(), 'The opening consumed more than the one Hamill draw.');
    }

    /**
     * **Le coeur : l etat persiste se relit dans un autre processus, effet et bandes intacts.**
     */
    public function testAResumeInAnotherProcessKeepsTheEffectAndBothBands(): void
    {
        $graine = 12345;
        $champ = $this->champOuvert($graine);

        $this->assertSame(0, self::etoilesDe($champ), 'The manoeuvre did not fire: nothing would be proven.');

        $bataille = $champ->battleDraws;
        $rounds = $champ->roundDraws;
        $this->assertInstanceOf(SeededDraws::class, $bataille);
        $this->assertInstanceOf(SeededDraws::class, $rounds);

        $document = BattleFieldStateCodec::toStorage($champ);
        $fichier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'champ-spatial-' . getmypid() . '.json';

        file_put_contents($fichier, json_encode(['champ' => $document], JSON_THROW_ON_ERROR));

        $lecteur = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'relire-champ-spatial.php';

        $sortie = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lecteur) . ' ' . escapeshellarg($fichier) . ' 2>&1', $sortie, $code);

        @unlink($fichier);

        $this->assertSame(0, $code, 'The second process failed: ' . implode("\n", $sortie));

        $releve = json_decode(implode("\n", $sortie), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($releve, 'The second process did not report a document.');

        // **C est bien un autre processus.** Sans ce controle, l essai pourrait mesurer une reprise
        // faite chez lui, et ne prouverait rien de la persistance.
        $this->assertNotSame(getmypid(), $releve['processus'], 'The reader ran inside this very process.');

        // --- L effet a traverse ---
        $this->assertSame(0, $releve['etoiles_de_la_mort'], 'The Deathstar came back on resume: the manoeuvre was undone.');
        $this->assertSame(2, $releve['unites_defenseuses'], 'The defender roster changed across the process boundary.');
        $this->assertSame(30, $releve['unites_attaquantes'], 'The attacker roster changed across the process boundary.');
        $this->assertSame(0, $releve['rounds_joues'], 'The resumed field claims a round was played.');

        // --- Et les deux bandes ont repris ou elles s etaient arretees ---
        $neuve = new SeededDraws($graine);

        $this->assertSame(
            $bataille->rawState(),
            $releve['bande_bataille']['mot'],
            'The battle band did not resume where it stopped.'
        );
        $this->assertNotSame(
            $neuve->rawState(),
            $releve['bande_bataille']['mot'],
            'The battle band restarted from the seed: the Hamill draw would be spent a second time.'
        );
        $this->assertSame(1, $releve['bande_bataille']['tirages_bruts'], 'The resumed battle band lost its draw count.');

        $this->assertSame(
            $rounds->rawState(),
            $releve['bande_rounds']['mot'],
            'The round band did not resume where it stopped.'
        );
        $this->assertSame($graine, $releve['bande_rounds']['graine'], 'The round band lost the seed it was born from.');
    }

    /**
     * **Une bataille interrompue puis reprise joue le meme round qu une bataille d une traite.**
     *
     * ## Ce que cet essai ajoute au precedent
     *
     * Le precedent etablit que l effet de Hamill et la position des bandes traversent la
     * persistance. Il ne dit rien de la **suite** : un document fidele peut ne pas suffire a
     * continuer, et c est justement ce que la lecture du moteur a montre — la boucle des rounds
     * demande aussi les flottes.
     *
     * ## Deux chemins differents, un seul etat attendu
     *
     * La reference est menee par le moteur **qui a ouvert**, d une traite. L autre est interrompue,
     * persistee, relue **ailleurs**, et sa suite est jouee par une reprise qui reconstruit les
     * identites depuis les faits geles. Comparer une reprise a elle-meme ne dirait rien ; ce sont
     * deux chemins qui doivent tomber sur le meme etat.
     */
    public function testAResumedFieldPlaysTheSameRoundAsAnUninterruptedRun(): void
    {
        $graine = 12345;

        // --- La reference : ouvrir et jouer, sans jamais rien ecrire ---
        [$ouvertureA] = $this->montage($graine);
        $champA = $ouvertureA->initialField();
        $ouvertureA->play($champA, 1);
        $reference = self::relevesDe($champA);

        $this->assertSame(1, $reference['rounds_joues'], 'The reference did not play its round.');
        $this->assertGreaterThan(0, $reference['tirages_rounds'], 'The round consumed no draw: the comparison would be empty.');

        // --- L interrompue : ouvrir, persister, reprendre ailleurs, jouer la-bas ---
        [$ouvertureB, $contexteB] = $this->montage($graine);
        $champB = $ouvertureB->initialField();

        $fichier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suite-spatiale-' . getmypid() . '.json';
        file_put_contents($fichier, json_encode([
            'champ' => BattleFieldStateCodec::toStorage($champB),
            'butin' => $contexteB->toFrozenFacts(),
            'proprietaire' => $this->currentUserId,
            'galaxie' => 1,
            'systeme' => 1,
        ], JSON_THROW_ON_ERROR));

        $lecteur = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'relire-champ-spatial.php';

        $sortie = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lecteur) . ' ' . escapeshellarg($fichier) . ' 1 2>&1', $sortie, $code);

        @unlink($fichier);

        $this->assertSame(0, $code, 'The second process failed: ' . implode("
", $sortie));

        // **Le releve se decode, ou l essai dit ce que le processus a repondu.** Sans cela, un
        // second processus qui echoue fait rougir sur une erreur d encodage, et le message ne dit
        // rien de la cause — une mutation l a montre.
        $releve = json_decode(implode("
", $sortie), true);

        $this->assertIsArray(
            $releve,
            'The second process did not report a document. What it said:' . "
" . implode("
", $sortie)
        );
        $this->assertNotSame(getmypid(), $releve['processus'], 'The reader ran inside this very process.');

        // --- Champ par champ : les deux chemins doivent dire la meme chose ---
        foreach ($reference as $champ => $attendu) {
            $this->assertSame(
                $attendu,
                $releve[$champ] ?? null,
                'After one round, « ' . $champ . ' » differs between an uninterrupted battle and a resumed one.'
            );
        }
    }
}
