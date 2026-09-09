<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Trois batailles, telles que le moteur les jouait **avant** la couture d etat.
 *
 * ## Le trou que ces vecteurs ferment
 *
 * La couture d etat a decoupe `fightBattleRounds()` en trois temps sans changer une ligne du corps
 * de boucle, et la suite entiere est restee verte. **Cela n etablissait pas l identite avec le
 * moteur d avant** : rien dans le depot n epinglait une bataille complete contre une reference.
 * `DrawsTest` epingle la source de hasard, `PhpEngineReplayTest` compare deux executions du **meme**
 * moteur, le banc de parite compare PHP a Rust. Un refactor qui aurait change la bataille de facon
 * **coherente** aurait donc traverse la suite sans une rougeur.
 *
 * Defaut signale par Codex le 9 septembre 2026, et il avait raison.
 *
 * ## D ou viennent ces nombres, et ce qu il ne faut jamais en faire
 *
 * Ils ont ete releves sur le moteur du commit `08dae302` — celui d avant la couture —, avec les
 * memes graines et les memes flottes, puis **figes tels quels**. Le moteur teste ne les recalcule
 * jamais : c est toute leur valeur.
 *
 * **Si un changement legitime les invalide, ils se re-mesurent sur `08dae302`, jamais sur la version
 * courante.** Recopier ce que le moteur du jour produit reviendrait a epingler le defaut avec le
 * reste, et ce temoin ne dirait plus rien.
 *
 * ## Ce que ces trois cas exercent, et pourquoi trois
 *
 * Une seule composition jouee sous trois graines reste **un seul scenario de flotte** — remarque de
 * Codex, retenue. Les trois cas visent donc trois mecaniques distinctes :
 *
 *  - **la bataille longue** va jusqu au sixieme round **avec des survivants des deux cotes** : c est
 *    le cas ou les coques entamees traversent cinq rounds, celui qu une etape qui soignerait la
 *    flotte briserait aussitot ;
 *  - **le tir rapide** : soixante croiseurs tirent 384 coups au premier round — la chaine de tir
 *    rapide est donc bien exercee, et l essai l exige plutot que de l esperer ;
 *  - **l ecrasement** s arrete au premier round : la sortie anticipee de la boucle, celle qui ne
 *    joue pas les six rounds.
 *
 * **L egalite etablie vaut pour ces cas, pas pour toutes les batailles possibles.** Le dire est la
 * moitie du temoin.
 */
class BattleReferenceVectorsTest extends UnitTestCase
{
    /**
     * Les scenarios, et les faits releves sur le moteur de `08dae302`.
     *
     * @var array<string, array<string, mixed>>
     */
    private const array VECTEURS = [
        'longue' => [
            'graine' => 20260909,
            'garnison' => ['rocket_launcher' => 600, 'light_laser' => 200, 'heavy_laser' => 60, 'gauss_cannon' => 20],
            'attaquantes' => [['light_fighter' => 400, 'cruiser' => 120], ['heavy_fighter' => 80]],
            'renforts' => [['light_fighter' => 200], ['heavy_fighter' => 60]],
            'attendu' => [
                'rounds' => 6,
                'attacker_lost' => 530,
                'defender_lost' => 1118,
                'attacker_left' => 70,
                'defender_left' => 22,
                'hits_attacker_round1' => 819,
                'draws_count' => 9570,
                'draws_raw' => 9570,
                'draws_digest' => '68692c379c551446',
                'sha' => '72c7ea08f55e774882adf2ab477687c13b1aaf0b7f4565abcc6bcce1701c6e5f',
            ],
        ],
        'tir_rapide' => [
            'graine' => 4242,
            'garnison' => ['rocket_launcher' => 10],
            'attaquantes' => [['cruiser' => 60]],
            'renforts' => [['light_fighter' => 400]],
            'attendu' => [
                'rounds' => 3,
                'attacker_lost' => 0,
                'defender_lost' => 410,
                'attacker_left' => 60,
                'defender_left' => 0,
                'hits_attacker_round1' => 384,
                'draws_count' => 3950,
                'draws_raw' => 3950,
                'draws_digest' => 'd4a6e21c661c8416',
                'sha' => '1fd7170b12a8470f13a960e4112b7355de5b1064caafd87a4084060ca7e01cdd',
            ],
        ],
        'ecrasement' => [
            'graine' => 7,
            'garnison' => ['rocket_launcher' => 5],
            'attaquantes' => [['battle_ship' => 300]],
            'renforts' => [],
            'attendu' => [
                'rounds' => 1,
                'attacker_lost' => 0,
                'defender_lost' => 5,
                'attacker_left' => 300,
                'defender_left' => 0,
                'hits_attacker_round1' => 300,
                'draws_count' => 605,
                'draws_raw' => 605,
                'draws_digest' => '6e37b37d5a2b9f2a',
                'sha' => '1d7473ad77d10824156687f357c2f59888a6fb164fc3a7dde9266daa454847bc',
            ],
        ],
    ];

    /**
     * Les reglages que le moteur lit, poses a leur valeur par defaut.
     *
     * **Un essai pose l interrupteur qu il suppose**, et celui-ci l a appris en rougissant : les
     * trois vecteurs passaient en isolement et tombaient dans la suite, tous les trois sur la seule
     * empreinte globale, aucun fait nomme ne bougeant. La base d un processus est partagee, les
     * reglages y vivent, et une classe voisine avait laisse le sien — le depot connait deja le cas
     * de `defense_repair_rate` laisse a 100 par deux essais d attaque.
     *
     * Ce sont exactement les reglages que `BattleEngine` et ses services interrogent, a leur valeur
     * par defaut.
     *
     * **Et le premier releve de reference etait lui-meme fautif** : il a ete pris sur une base que
     * des passages precedents avaient laissee reglee autrement. Les vecteurs ont donc ete
     * **re-mesures sur le moteur de `08dae302` avec ces reglages-ci poses**, et c est cette seconde
     * mesure qui est epinglee. Une reference n a de valeur que si le monde qui l a produite est
     * decrit ; celle-la l est, ligne par ligne, juste au-dessus.
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

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::REGLAGES_DU_MOTEUR as $clef => $valeur) {
            $this->settingsService->set($clef, $valeur);
        }

        $this->createAndSetUserTechModel([]);
    }

    /**
     * La bataille longue : six rounds, et des survivants des deux cotes.
     */
    public function testTheLongBattleIsFoughtExactlyAsBefore(): void
    {
        $faits = $this->factsOf('longue');

        // **Les premisses de ce cas**, exigees et non supposees : sans elles, l egalite porterait
        // sur une bataille qui n exerce pas ce qu on croit.
        $this->assertSame(BattleEngine::MAX_ROUNDS, $faits['rounds'], 'The long battle no longer reaches the round cap: damaged hulls stop being carried that far.');
        $this->assertGreaterThan(0, $faits['attacker_left'], 'No attacker survived: no damaged hull crossed the whole battle.');
        $this->assertGreaterThan(0, $faits['defender_left'], 'No defender survived: no damaged hull crossed the whole battle.');

        $this->assertSameAsBefore('longue', $faits);
    }

    /**
     * Le tir rapide : plus de coups au premier round qu il n y a de tireurs.
     */
    public function testTheRapidfireBattleIsFoughtExactlyAsBefore(): void
    {
        $faits = $this->factsOf('tir_rapide');

        $tireurs = 60;
        $this->assertGreaterThan(
            $tireurs,
            $faits['hits_attacker_round1'],
            'The first round fired no more shots than there were ships: the rapidfire chain was not exercised.'
        );

        $this->assertSameAsBefore('tir_rapide', $faits);
    }

    /**
     * L ecrasement : la boucle sort avant le plafond.
     */
    public function testTheOneSidedBattleIsFoughtExactlyAsBefore(): void
    {
        $faits = $this->factsOf('ecrasement');

        $this->assertLessThan(BattleEngine::MAX_ROUNDS, $faits['rounds'], 'The battle went the distance: the early exit was not exercised.');
        $this->assertSame(0, $faits['defender_left'], 'The defence held: this case no longer ends by wiping a side out.');

        $this->assertSameAsBefore('ecrasement', $faits);
    }

    /**
     * Compare fait par fait, puis l empreinte de la projection entiere.
     *
     * **Les deux, et dans cet ordre.** L empreinte seule dirait « ce n est plus la meme bataille »
     * sans dire en quoi ; les nombres seuls laisseraient passer tout ce qu ils ne nomment pas.
     *
     * @param array<string, mixed> $faits
     */
    private function assertSameAsBefore(string $scenario, array $faits): void
    {
        $attendu = self::VECTEURS[$scenario]['attendu'];

        foreach ($attendu as $clef => $valeur) {
            if ($clef === 'sha') {
                continue;
            }

            $this->assertSame(
                $valeur,
                $faits[$clef],
                'The battle "' . $scenario . '" no longer matches the engine of 08dae302 on ' . $clef . '.'
            );
        }

        $this->assertSame(
            $attendu['sha'],
            $faits['sha'],
            'The battle "' . $scenario . '" matches on every named fact but not on the whole projection: '
            . 'something changed that no assertion above names.'
        );
    }

    /**
     * Joue le scenario et en tire les faits comparables.
     *
     * @return array<string, mixed>
     */
    private function factsOf(string $scenario): array
    {
        $resultat = $this->fight(self::VECTEURS[$scenario]);
        $projection = CanonicalProjection::of($resultat);
        $premier = $resultat->rounds[0] ?? null;

        $this->assertNotNull($premier, 'The battle had no round at all.');
        $this->assertNotNull($resultat->drawsConsumed, 'A seeded battle kept no journal of its draws.');

        return [
            'rounds' => count($projection['rounds']),
            'attacker_lost' => $resultat->attackerUnitsLost->getAmount(),
            'defender_lost' => $resultat->defenderUnitsLost->getAmount(),
            'attacker_left' => $resultat->attackerUnitsResult->getAmount(),
            'defender_left' => $resultat->defenderUnitsResult->getAmount(),
            'hits_attacker_round1' => $premier->hitsAttacker,
            'draws_count' => $resultat->drawsConsumed['count'],
            'draws_raw' => $resultat->drawsConsumed['raw'],
            'draws_digest' => $resultat->drawsConsumed['digest'],
            'sha' => hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function fight(array $scenario): BattleResult
    {
        $this->createAndSetPlanetModel(['metal' => 50_000, 'crystal' => 50_000, 'deuterium' => 0] + $scenario['garnison']);

        $flottes = [];
        foreach ($scenario['attaquantes'] as $rang => $composition) {
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
        foreach ($scenario['renforts'] as $rang => $composition) {
            $renfort = new DefenderFleet();
            $renfort->units = $this->units($composition);
            $renfort->player = $this->playerService;
            $renfort->fleetMissionId = 2000 + $rang;
            $renfort->ownerId = 5;
            $renfort->fleetMission = null;
            $defenseurs[] = $renfort;
        }

        $moteur = new PhpBattleEngine(
            $flottes,
            $this->planetService,
            $defenseurs,
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart())
        );

        return $moteur->withDraws(new SeededDraws($scenario['graine']))->simulateBattle();
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
