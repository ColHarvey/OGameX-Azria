<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Replay\BattleFieldStateCodec;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use RuntimeException;
use Tests\Support\PinsSettings;
use Tests\UnitTestCase;

/**
 * Une bataille ecrite entre deux rounds, relue ailleurs, se termine exactement pareil.
 *
 * ## Ce que cette etape doit prouver, et pourquoi un autre processus
 *
 * Le moteur sait s arreter entre deux rounds depuis la couture d etat. Il ne sait pas encore
 * **survivre a l arret du processus**, et c est la difference entre un decoupage et une bataille
 * progressive : entre deux etapes, le serveur peut redemarrer.
 *
 * Un aller-retour en memoire ne prouverait pas cela — les objets se partageraient, et une part de
 * l etat pourrait continuer de vivre sans etre ecrite. Le temoin ecrit donc l etat, **lance un
 * second processus PHP**, et lui fait terminer la bataille. La comparaison porte sur la bataille
 * entiere : projection canonique, pertes, et journal des tirages.
 *
 * ## Ce que l ecriture doit porter, et que la conception n avait pas vu
 *
 * **Deux bandes de tirages, pas une.** Le moteur tire de celle de la bataille — Hamill avant les
 * rounds, la lune apres — et de celle des rounds, qui en nait. Une reprise qui ne garderait que la
 * seconde rejouerait les rounds a l identique **puis divergerait apres**. Les deux voyagent.
 *
 * ## Ce que ce temoin ne prouve pas
 *
 * L etat n est pas encore range dans le combat : ni colonne, ni index d etape, ni unicite. Ce sont
 * les pieces suivantes. Ici, l ecriture est un fichier, et c est assez pour etablir que l etat est
 * **complet et fidele** une fois sorti de la memoire.
 */
class BattleFieldStatePersistenceTest extends UnitTestCase
{
    use PinsSettings;

    /**
     * Le nom des deux variables d environnement par lesquelles le processus enfant recoit sa tache.
     */
    private const string ETAT = 'OGAMEX_REPRISE_ETAT';

    private const string SORTIE = 'OGAMEX_REPRISE_SORTIE';

    /**
     * Les reglages que le moteur lit. Le parent et l enfant doivent voir le meme monde, sinon la
     * comparaison porterait sur deux batailles differentes pour une raison etrangere a la reprise.
     *
     * @var array<string, int>
     */
    private const array REGLAGES_DU_MOTEUR = [
        'debris_field_from_ships' => 30,
        'debris_field_from_defense' => 0,
        'debris_field_deuterium_on' => 0,
        'defense_repair_rate' => 70,
        'maximum_moon_chance' => 20,
        'wreck_field_min_resources_loss' => 150000,
        'wreck_field_min_fleet_percentage' => 5,
        'hamill_manoeuvre_chance' => 1000,
    ];

    private const int GRAINE = 20260909;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinSettings(self::REGLAGES_DU_MOTEUR);

        $this->createAndSetUserTechModel([]);
    }

    protected function tearDown(): void
    {
        $this->restorePinnedSettings();

        parent::tearDown();
    }

    /**
     * L etat ecrit puis relu **dans ce processus** donne la meme bataille.
     *
     * Le plus faible des deux temoins, et il a sa raison d etre : quand il rougit avec l autre,
     * c est l encodage ; quand il passe et que l autre tombe, c est ce qui ne survit pas au
     * processus.
     */
    public function testAStateWrittenAndReadBackHereFinishesTheSameBattle(): void
    {
        $entiere = $this->factsOf($this->fightWholeBattle(), sansLesPremiers: 1);

        $ecrit = $this->stateAfterOneRound();
        $reprise = $this->factsOf($this->resumeFrom($ecrit));

        $this->assertGreaterThan(1, $entiere['rounds'], 'The battle had no rounds left after the first: stopping there would prove nothing.');
        $this->assertSame($entiere, $reprise, 'The battle resumed from its written state is not the battle played in one go.');
    }

    /**
     * **Le temoin de cette etape** : l etat traverse un autre processus PHP.
     */
    public function testAStateReadBackInAnotherProcessFinishesTheSameBattle(): void
    {
        $entiere = $this->factsOf($this->fightWholeBattle(), sansLesPremiers: 1);

        $dossier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ogamex-reprise-' . getmypid() . '-' . random_int(1000, 9999);
        $fichierEtat = $dossier . '-etat.json';
        $fichierSortie = $dossier . '-faits.json';

        file_put_contents($fichierEtat, json_encode($this->stateAfterOneRound(), JSON_THROW_ON_ERROR));

        try {
            $this->runTheResumeInItsOwnProcess($fichierEtat, $fichierSortie);

            $ecrit = file_get_contents($fichierSortie);
            $this->assertIsString($ecrit, 'The child process wrote no facts at all.');

            $reprise = json_decode($ecrit, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($fichierEtat);
            @unlink($fichierSortie);
        }

        $this->assertSame(
            $entiere,
            $reprise,
            'A battle resumed in another process is not the battle played in one go: something of the state lives outside the writing.'
        );
    }

    /**
     * La reprise elle-meme — lancee par le temoin ci-dessus dans un processus a elle.
     *
     * **Sans les variables d environnement, elle n est pas inerte** : elle refait l aller-retour
     * ici. Un essai qui se sauterait laisserait la suite avec un ignore, et la CI en exige zero.
     */
    public function testResumesFromTheStateNamedByTheEnvironment(): void
    {
        $chemin = getenv(self::ETAT);
        $sortie = getenv(self::SORTIE);

        if (!is_string($chemin) || $chemin === '' || !is_string($sortie) || $sortie === '') {
            $this->testAStateWrittenAndReadBackHereFinishesTheSameBattle();

            return;
        }

        $ecrit = file_get_contents($chemin);
        $this->assertIsString($ecrit, 'The state file named by the environment could not be read.');

        $faits = $this->factsOf($this->resumeFrom(json_decode($ecrit, true, 512, JSON_THROW_ON_ERROR)));

        file_put_contents($sortie, json_encode($faits, JSON_THROW_ON_ERROR));

        $this->assertNotSame([], $faits);
    }

    /**
     * Lance la reprise dans un processus PHP a part, et exige qu il finisse bien.
     */
    private function runTheResumeInItsOwnProcess(string $fichierEtat, string $fichierSortie): void
    {
        $racine = dirname(__DIR__, 3);

        $commande = [
            PHP_BINARY,
            $racine . '/vendor/phpunit/phpunit/phpunit',
            '--filter',
            'testResumesFromTheStateNamedByTheEnvironment',
            $racine . '/tests/Unit/BattleEngine/BattleFieldStatePersistenceTest.php',
        ];

        $environnement = getenv();
        $environnement[self::ETAT] = $fichierEtat;
        $environnement[self::SORTIE] = $fichierSortie;

        // **Le verrou de la suite est deja tenu par ce processus-ci.** Sans ce drapeau, l enfant
        // essaierait de le prendre a son tour et attendrait son propre parent.
        $environnement['OGAMEX_SUITE_LOCK_HELD'] = '1';

        $tuyaux = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processus = proc_open($commande, $tuyaux, $flux, $racine, $environnement);

        if (!is_resource($processus)) {
            $this->fail('The resuming process could not be started.');
        }

        $dit = (string)stream_get_contents($flux[1]) . (string)stream_get_contents($flux[2]);
        fclose($flux[1]);
        fclose($flux[2]);
        $code = proc_close($processus);

        $this->assertSame(0, $code, 'The resuming process failed: ' . $dit);
    }

    /**
     * La bataille jouee d un seul tenant.
     */
    private function fightWholeBattle(): BattleResult
    {
        return $this->engine()->simulateBattle();
    }

    /**
     * L etat du champ apres le premier round, tel qu il s ecrit.
     *
     * @return array<string, mixed>
     */
    private function stateAfterOneRound(): array
    {
        $moteur = $this->engine();
        $moteur->roundsBeforeWriting = 1;
        $moteur->simulateBattle();

        if ($moteur->etatEcrit === null) {
            throw new RuntimeException('The engine wrote no state after the first round.');
        }

        return $moteur->etatEcrit;
    }

    /**
     * La bataille reprise depuis un etat ecrit.
     *
     * @param array<string, mixed> $etat
     */
    private function resumeFrom(array $etat): BattleResult
    {
        $moteur = $this->engine();
        $moteur->etatARepris = $etat;

        return $moteur->simulateBattle();
    }

    /**
     * Ce qui doit etre identique : la bataille entiere, pas quelques nombres choisis.
     *
     * ## Les rounds deja livres ne reviennent pas, et c est une propriete
     *
     * Un processus qui reprend une bataille ne rejoue pas les rounds passes : il ne les a pas, et
     * il ne doit pas les inventer. Sa liste de rounds est donc la **queue** de celle d une bataille
     * jouee d un trait. Comparer les deux listes entieres ferait rougir le temoin sur une
     * difference qui est le comportement voulu — et masquerait celles qui n en sont pas.
     *
     * Ce que le depot en retient : la chronologie des rounds deja livres se persiste ailleurs, avec
     * la presentation. L etat du champ ne la porte pas et n a pas a la porter.
     *
     * @param int $sansLesPremiers Combien de rounds de tete retirer avant de comparer.
     * @return array<string, mixed>
     */
    private function factsOf(BattleResult $resultat, int $sansLesPremiers = 0): array
    {
        $projection = CanonicalProjection::of($resultat);
        $projection['rounds'] = array_values(array_slice($projection['rounds'], $sansLesPremiers));

        return [
            'rounds' => count($projection['rounds']),
            'attacker_lost' => $resultat->attackerUnitsLost->getAmount(),
            'defender_lost' => $resultat->defenderUnitsLost->getAmount(),
            'draws' => $resultat->drawsConsumed,
            'sha' => hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR)),
        ];
    }

    private function engine(): EngineWritingAndResumingItsField
    {
        $this->createAndSetPlanetModel([
            'metal' => 50_000,
            'crystal' => 50_000,
            'deuterium' => 0,
            'rocket_launcher' => 600,
            'light_laser' => 200,
            'heavy_laser' => 60,
            'gauss_cannon' => 20,
        ]);

        $flottes = [];
        foreach ([['light_fighter' => 400, 'cruiser' => 120], ['heavy_fighter' => 80]] as $rang => $composition) {
            $flotte = new AttackerFleet();
            $flotte->units = $this->units($composition);
            $flotte->player = $this->playerService;
            $flotte->fleetMissionId = 1000 + $rang;
            $flotte->ownerId = $this->playerService->getId();
            $flotte->cargoResources = new Resources(0, 0, 0, 0);
            $flotte->isInitiator = $rang === 0;
            $flotte->fleetMission = null;
            $flottes[] = $flotte;
        }

        $defenseurs = [DefenderFleet::fromPlanet($this->planetService)];
        foreach ([['light_fighter' => 200], ['heavy_fighter' => 60]] as $rang => $composition) {
            $renfort = new DefenderFleet();
            $renfort->units = $this->units($composition);
            $renfort->player = $this->playerService;
            $renfort->fleetMissionId = 2000 + $rang;
            $renfort->ownerId = 5;
            $renfort->fleetMission = null;
            $defenseurs[] = $renfort;
        }

        $moteur = new EngineWritingAndResumingItsField(
            $flottes,
            $this->planetService,
            $defenseurs,
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart())
        );

        $moteur->withDraws(new SeededDraws(self::GRAINE));

        return $moteur;
    }

    /**
     * @param array<string, int> $composition
     */
    private function units(array $composition): UnitCollection
    {
        $unites = new UnitCollection();

        foreach ($composition as $nom => $montant) {
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $montant);
        }

        return $unites;
    }
}

/**
 * Le moteur du jeu, qui sait ecrire son champ apres un round et le reprendre.
 *
 * Il ne redefinit aucune regle : il emploie la couture — ouvrir, jouer, fermer — et le codec. Un
 * banc qui ecrirait sa propre boucle ne prouverait rien du moteur.
 */
class EngineWritingAndResumingItsField extends PhpBattleEngine
{
    /**
     * Apres combien de rounds l etat est ecrit. Zero : on ne l ecrit pas.
     */
    public int $roundsBeforeWriting = 0;

    /**
     * L etat ecrit, tel que le codec le rend.
     *
     * @var array<string, mixed>|null
     */
    public array|null $etatEcrit = null;

    /**
     * L etat depuis lequel reprendre, au lieu d ouvrir un champ neuf.
     *
     * @var array<string, mixed>|null
     */
    public array|null $etatARepris = null;

    /**
     * @return array<BattleResultRound>
     */
    protected function fightBattleRounds(BattleResult $result): array
    {
        if ($this->etatARepris !== null) {
            $etat = BattleFieldStateCodec::fromStorage($this->etatARepris);

            // **La bande de la bataille revient elle aussi.** Ce qui se tire apres les rounds — la
            // lune — en depend, et une reprise qui l oublierait divergerait la, pas avant.
            $this->draws = $etat->battleDraws;

            $rounds = $this->playRounds($etat, self::MAX_ROUNDS);
            $this->closeTheField($result, $etat);

            return $rounds;
        }

        $etat = $this->openTheField($result);
        $rounds = [];

        if ($this->roundsBeforeWriting > 0) {
            $rounds = $this->playRounds($etat, $this->roundsBeforeWriting);
            $this->etatEcrit = BattleFieldStateCodec::toStorage($etat);
        }

        foreach ($this->playRounds($etat, self::MAX_ROUNDS) as $round) {
            $rounds[] = $round;
        }

        $this->closeTheField($result, $etat);

        return $rounds;
    }
}
