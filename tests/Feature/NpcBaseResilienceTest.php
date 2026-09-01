<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Combien de vagues faut-il pour abattre une base ?
 *
 * La question n'est pas rhetorique : le moteur remet debout 70 % des defenses detruites apres
 * chaque bataille, et de cette seule valeur depend qu'une base soit une cible consommable ou
 * un objectif pratiquement immortel. Elle se mesure, elle ne se devine pas.
 *
 * La mesure porte sur le moteur reel, pas sur une formule : c'est lui qui decidera en jeu.
 */
class NpcBaseResilienceTest extends AccountTestCase
{
    private SettingsService $settings;

    /**
     * Taux de reparation trouve avant ce test, a remettre en place ensuite.
     */
    private string $previousRepairRate = '70';

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_seed_min_distance', '0');

        // Le taux est pose explicitement, et c'est indispensable : plusieurs tests de la
        // suite amont le basculent a 0 ou a 100 sans jamais le remettre, si bien qu'une
        // mesure qui se fierait a la valeur trouvee en base dirait n'importe quoi selon
        // l'ordre d'execution. On mesure a 70 %, la valeur du jeu.
        $this->previousRepairRate = (string)$this->settings->get('defense_repair_rate', '70');
        $this->settings->set('defense_repair_rate', '70');
    }

    protected function tearDown(): void
    {
        $npcIds = DB::table('users')->where('is_npc', true)->pluck('id')->all();

        if ($npcIds !== []) {
            Schema::disableForeignKeyConstraints();

            $planetIds = DB::table('planets')->whereIn('user_id', $npcIds)->pluck('id')->all();
            DB::table('fleet_missions')->whereIn('user_id', $npcIds)->delete();
            DB::table('building_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('unit_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('highscores')->whereIn('player_id', $npcIds)->delete();
            DB::table('users_tech')->whereIn('user_id', $npcIds)->delete();
            DB::table('planets')->whereIn('user_id', $npcIds)->delete();
            DB::table('users')->whereIn('id', $npcIds)->delete();

            Schema::enableForeignKeyConstraints();
        }

        DB::table('npc_threats')->delete();
        $this->settings->set('npc_enabled', '0');
        $this->settings->set('defense_repair_rate', $this->previousRepairRate);

        parent::tearDown();
    }

    /**
     * Measure how many waves a base with a hundred launchers survives.
     *
     * Le resultat attendu tient a une arithmetique simple : si une vague detruit toute la
     * defense et que 70 % revient, il en reste 30 % apres chaque passage. La decroissance est
     * donc geometrique et le nombre de vagues croit de facon logarithmique avec la taille de
     * la base — ce qui est exactement le comportement souhaitable. Cent lanceurs ne demandent
     * pas dix fois plus de vagues que dix.
     *
     * Le test verifie que le moteur se comporte bien ainsi, et affiche la courbe pour que la
     * valeur puisse etre recalibree sur des mesures et non sur une intuition.
     */
    public function testAnOverwhelmingForceTakesABaseInOneWave(): void
    {
        $courbe = $this->measureWaves(100, 500);

        fwrite(STDERR, sprintf(
            "\n  Force ecrasante (500 chasseurs contre 100 lanceurs) : %s\n",
            implode(' -> ', $courbe)
        ));

        $this->assertEquals(
            0,
            $courbe[count($courbe) - 1],
            'An overwhelming fleet could not take a base at all.'
        );

        $this->assertCount(
            2,
            $courbe,
            'An overwhelming fleet needed more than one wave, so committing a real armada buys nothing.'
        );
    }

    /**
     * Measure what a proportionate force achieves against the same base.
     *
     * C'est le regime qui produit le jeu interessant, et celui que la reparation gouverne.
     * Une flotte qui ne balaie pas toute la defense d'un coup laisse des ruines, dont 70 %
     * se relevent : le joueur doit revenir. La base cesse d'etre une cible consommable et
     * devient un objectif qu'on entame, qu'on laisse, et qu'on revient finir.
     */
    public function testWhereTheMultiWaveRegimeBegins(): void
    {
        $mesures = [];

        foreach ([20, 35, 50, 80] as $chasseurs) {
            $courbe = $this->measureWaves(100, $chasseurs, 6);
            $mesures[$chasseurs] = $courbe;
        }

        fwrite(STDERR, sprintf(
            "\n  Cent lanceurs, reparation a %d %% :\n",
            $this->settings->defenseRepairRate()
        ));

        foreach ($mesures as $chasseurs => $courbe) {
            $abattue = $courbe[count($courbe) - 1] === 0;
            fwrite(STDERR, sprintf(
                "    %3d chasseurs : %-34s %s\n",
                $chasseurs,
                implode(' -> ', $courbe),
                $abattue ? sprintf('abattue en %d vague(s)', count($courbe) - 1) : 'tient encore'
            ));
        }

        // La question posee etait : la reparation cree-t-elle un regime a plusieurs vagues ?
        // Il suffit qu'une des forces essayees n'y parvienne pas d'un coup pour que la
        // reponse soit oui, et que la base soit un objectif plutot qu'une cible.
        $enPlusieursVagues = array_filter(
            $mesures,
            static fn (array $courbe): bool => count($courbe) > 2
        );

        $this->assertNotEmpty(
            $enPlusieursVagues,
            'Every force tried settled the base in one pass, so the repair rule never creates a campaign.'
        );
    }

    /**
     * Measure how long each kind of player needs against each kind of base.
     *
     * La question qui decide si le systeme est amusant n'est pas « une base peut-elle
     * tomber », mais « combien de temps faut-il, selon qui attaque ». Un joueur faible doit
     * pouvoir se dire « je ne peux pas encore la battre », jamais « je ne pourrai jamais ».
     * Un gros joueur doit pouvoir se dire « je peux la battre, mais ca va me couter ».
     *
     * La matrice le montre directement.
     */
    public function testHowLongEachPlayerNeedsAgainstEachBase(): void
    {
        $bases = ['faible' => 30, 'moyenne' => 100, 'forte' => 250];
        $joueurs = ['faible' => 40, 'moyen' => 120, 'fort' => 400];

        fwrite(STDERR, sprintf(
            "\n  Vagues necessaires, reparation a %d %% (— : tient encore apres 10 vagues)\n\n",
            $this->settings->defenseRepairRate()
        ));
        fwrite(STDERR, sprintf("    %-10s %12s %12s %12s\n", 'joueur', 'base faible', 'base moyenne', 'base forte'));

        // Le tableau est declare complet des le depart : les cases sont lues nommement plus
        // bas, et une construction au fil des boucles ne garantirait pas leur presence.
        $resultats = [
            'faible' => ['faible' => null, 'moyenne' => null, 'forte' => null],
            'moyen' => ['faible' => null, 'moyenne' => null, 'forte' => null],
            'fort' => ['faible' => null, 'moyenne' => null, 'forte' => null],
        ];

        foreach ($joueurs as $nomJoueur => $chasseurs) {
            $ligne = ['faible' => null, 'moyenne' => null, 'forte' => null];

            foreach ($bases as $nomBase => $defenses) {
                $courbe = $this->measureWaves($defenses, $chasseurs, 10);
                $abattue = $courbe[count($courbe) - 1] === 0;
                $ligne[$nomBase] = $abattue ? count($courbe) - 1 : null;
                $resultats[$nomJoueur][$nomBase] = $ligne[$nomBase];
            }

            fwrite(STDERR, sprintf(
                "    %-10s %12s %12s %12s\n",
                $nomJoueur,
                $ligne['faible'] ?? '—',
                $ligne['moyenne'] ?? '—',
                $ligne['forte'] ?? '—'
            ));
        }

        fwrite(STDERR, "\n");

        // Le joueur faible doit pouvoir entamer quelque chose : sans cela, rien du contenu
        // pirate ne lui est accessible et le systeme ne lui apporte que des ennuis.
        $this->assertNotNull(
            $resultats['faible']['faible'],
            'The weakest player could not take even the smallest base, so nothing here is for them.'
        );

        // La grosse base doit resister au petit joueur : sans cela il n y a pas de
        // progression, et toutes les bases se valent.
        $this->assertNull(
            $resultats['faible']['forte'],
            'The weakest player took the strongest base, so base size means nothing.'
        );

        // Mais rien ne doit rester definitivement hors de portee : ce que le petit joueur
        // ne peut pas encore faire, le gros joueur le fait. C est la difference entre
        // « pas encore » et « jamais ».
        $this->assertNotNull(
            $resultats['fort']['forte'],
            'Even the strongest player could not take the strongest base: it is out of reach for good.'
        );
    }

    /**
     * Assert that the repair rate really is what decides the number of waves.
     *
     * Point important pour le reglage : ce taux n'est pas propre aux PNJ. C'est le meme pour
     * toutes les defenses du serveur, celles des joueurs comprises. On ne peut donc pas
     * durcir les bases pirates par ce levier sans durcir aussi la defense de chacun.
     */
    public function testTheRepairRateIsWhatDecides(): void
    {
        $repairRate = $this->settings->get('defense_repair_rate', '70');
        $this->settings->set('defense_repair_rate', '0');

        $base = $this->placeBaseNextDoor();
        $base->addUnit('rocket_launcher', 100);

        $planetId = $base->getPlanetId();
        $factory = resolve(PlanetServiceFactory::class);

        $this->planetAddUnit('light_fighter', 3000);
        $this->planetAddResources(new Resources(0, 0, 5000000, 0));

        $this->sendWave($base->getPlanetCoordinates(), 500);

        $restant = $this->defenceCountOf($factory, $planetId);

        $this->settings->set('defense_repair_rate', $repairRate);

        $this->assertEquals(
            0,
            $restant,
            'Without repair a single wave should clear the base, so the repair rule is what creates the waves.'
        );
    }

    /**
     * Attack a base of a given size with a given fleet, wave after wave, and report the curve.
     *
     * @return array<int, int> Le nombre de defenses debout avant la premiere vague, puis
     *                         apres chacune.
     */
    private function measureWaves(int $defences, int $fighters, int $maxWaves = 12): array
    {
        $base = $this->placeBaseNextDoor();
        $base->addUnit('rocket_launcher', $defences);

        $planetId = $base->getPlanetId();
        $coordinate = $base->getPlanetCoordinates();
        $factory = resolve(PlanetServiceFactory::class);

        $courbe = [$this->defenceCountOf($factory, $planetId)];

        while ($courbe[count($courbe) - 1] > 0 && count($courbe) <= $maxWaves) {
            $this->sendWave($coordinate, $fighters);
            $courbe[] = $this->defenceCountOf($factory, $planetId);

            // La base peut avoir ete rasee : la position n'existe plus, inutile d'insister.
            if ($factory->make($planetId, true)?->isDestroyed() ?? true) {
                break;
            }
        }

        return $courbe;
    }

    /**
     * Send one wave at a coordinate and process its arrival.
     */
    private function sendWave(Coordinate $target, int $fighters): void
    {
        // Chaque vague est ravitaillee sur place : la precedente est encore en vol de retour,
        // et le test mesure la resilience de la base, pas la logistique de l'attaquant.
        $this->planetAddUnit('light_fighter', $fighters + 100);
        $this->planetAddResources(new Resources(0, 0, 1000000, 0));

        $fleet = new UnitCollection();
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), $fighters);

        $mission = resolve(FleetMissionService::class)->createNewFromPlanet(
            $this->planetService,
            $target,
            PlanetType::Planet,
            1,
            $fleet,
            new Resources(0, 0, 0, 0),
            10
        );

        $this->travelTo(Date::createFromTimestamp($mission->time_arrival + 10));
        $this->reloadApplication();
        $this->get('/overview');
    }

    /**
     * Count the defences still standing on a base.
     */
    private function defenceCountOf(PlanetServiceFactory $factory, int $planetId): int
    {
        $planet = $factory->make($planetId, true);

        return $planet?->getDefenseUnits()->getAmount() ?? 0;
    }

    /**
     * Put a pirate base in the test player's own system.
     */
    private function placeBaseNextDoor(): PlanetService
    {
        $own = $this->planetService->getPlanetCoordinates();
        $factory = resolve(PlanetServiceFactory::class);

        for ($position = 1; $position <= 15; $position++) {
            $candidate = new Coordinate($own->galaxy, $own->system, $position);

            if ($factory->planetExistsAtCoordinate($candidate)) {
                continue;
            }

            $base = resolve(NpcBaseService::class)->createBase(NpcBaseService::TYPE_PIRATE, $candidate);
            $this->assertNotNull($base, 'The base could not be created at the chosen position.');

            return $base;
        }

        $this->fail('No free position was available in the test player system.');
    }
}
