<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryValue;
use OGame\Models\User;
use OGame\Services\HalvingService;
use OGame\Services\ObjectService;
use ReflectionProperty;
use Tests\AccountTestCase;

/**
 * **Le cumul « construits » suit exactement les unités que la file livre.**
 *
 * 1. Chaque tranche livrée est un événement, et les tranches couvrent l'avancement sans chevauchement.
 * 2. Un seul passage donne le même compteur que plusieurs.
 * 3. Seules les unités achevées à partir de l'activation comptent, l'instant exact compris.
 * 4. Un demi-temps crédite ce qu'il livre, et la progression reprend après lui sans rien recompter.
 * 5. Le demi-temps n'écrase plus une livraison faite entre le chargement du corps et lui.
 * 6. Une unité qu'aucune famille ne pondère met la tranche en attente, entière — et ses unités sont livrées.
 * 7. Avant l'activation, la file livre et n'inscrit rien.
 *
 * ## Le lot de référence
 *
 * Douze lance-missiles, un toutes les cinq secondes : l'unité `k` s'achève à `début + 5k`. Les instants de passage
 * (12, 33, 100) tombent entre deux unités : le nombre livré dit sans ambiguïté quelle formule a servi.
 */
class MilitaryTalliesBuildTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
    }

    protected function tearDown(): void
    {
        // La table des poids est reconstruite au prochain usage : un essai qui l'a amputée ne la laisse pas aux voisins.
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('military_tally_events')->where('player_id', $this->currentUserId)->delete();
        DB::table('military_tallies')->where('player_id', $this->currentUserId)->delete();

        parent::tearDown();
    }

    public function testEachDeliveredSliceIsOneEventAndTheSlicesCoverTheProgressExactly(): void
    {
        $debut = $this->maintenant() + 100;
        $this->activer($debut - 1_000);
        $lot = $this->unLot('rocket_launcher', 12, $debut, $debut + 60);
        $avant = $this->unites('rocket_launcher');

        foreach ([12, 33, 100] as $decalage) {
            $this->travelTo(Date::createFromTimestamp($debut + $decalage));
            $this->planetService->updateUnitQueue();
        }

        $this->assertSame(["build:$lot:0-2", "build:$lot:2-6", "build:$lot:6-12"], $this->clefsDe($lot), 'Les tranches ne couvrent pas l’avancement exactement, ou elles se chevauchent.');
        $this->assertSame([$this->valeur('rocket_launcher', 2), $this->valeur('rocket_launcher', 4), $this->valeur('rocket_launcher', 6)], $this->valeursDe($lot), 'Une tranche ne porte pas la valeur de ses propres unités.');
        $this->assertSame($avant + 12, $this->unites('rocket_launcher'), 'Le corps ne porte pas les douze unités livrées.');
        $this->assertSame($this->valeur('rocket_launcher', 12), $this->construits(), 'Le compteur ne vaut pas les unités livrées.');
    }

    public function testOnePassGivesTheSameCounterAsSeveral(): void
    {
        $debut = $this->maintenant() + 100;
        $this->activer($debut - 1_000);
        $lot = $this->unLot('rocket_launcher', 12, $debut, $debut + 60);

        $this->travelTo(Date::createFromTimestamp($debut + 100));
        $this->planetService->updateUnitQueue();
        $this->planetService->updateUnitQueue();

        $this->assertSame(["build:$lot:0-12"], $this->clefsDe($lot), 'Un second passage sans unité due a inscrit une tranche.');
        $this->assertSame($this->valeur('rocket_launcher', 12), $this->construits());
    }

    /**
     * La quatrième unité s'achève **exactement** à l'activation : elle compte, les trois d'avant non.
     */
    public function testOnlyTheUnitsFinishedFromTheActivationOnCount(): void
    {
        $debut = $this->maintenant() + 100;
        $this->activer($debut + 20);
        $lot = $this->unLot('rocket_launcher', 12, $debut, $debut + 60);
        $avant = $this->unites('rocket_launcher');

        $this->travelTo(Date::createFromTimestamp($debut + 32));
        $this->planetService->updateUnitQueue();

        $this->assertSame($avant + 6, $this->unites('rocket_launcher'), 'Prémisse : six unités sont achevées à +32.');
        $this->assertSame(["build:$lot:3-6"], $this->clefsDe($lot), 'La tranche ne commence pas à la première unité achevée à partir de l’activation.');
        $this->assertSame($this->valeur('rocket_launcher', 3), $this->construits(), 'Des unités achevées avant l’activation sont entrées, ou celle de l’instant exact est restée dehors.');
    }

    /**
     * Cent chasseurs, dix secondes chacun. À +305, le demi-temps retire 500 secondes et livre cinquante unités ; à
     * +400, la progression en livre quarante de plus (quatre-vingt-dix achevées sur le lot décalé).
     */
    public function testTheHalvingCreditsWhatItDeliversAndTheProgressionResumesWithoutRecounting(): void
    {
        $debut = $this->maintenant() + 100;
        $this->activer($debut - 1_000);
        $lot = $this->unLot('light_fighter', 100, $debut, $debut + 1_000);
        $this->donnerDeLaMatiereNoire();
        $avant = $this->unites('light_fighter');

        $this->travelTo(Date::createFromTimestamp($debut + 305));
        $resultat = resolve(HalvingService::class)->halveUnit($this->compte(), $lot, $this->planetService);

        $this->assertTrue($resultat['success']);
        $this->assertSame($avant + 50, $this->unites('light_fighter'), 'Prémisse : le demi-temps livre cinquante unités.');
        $this->assertSame(["build:$lot:0-50"], $this->clefsDe($lot), 'Le demi-temps n’a pas inscrit exactement les unités qu’il livre.');

        $this->travelTo(Date::createFromTimestamp($debut + 400));
        $this->planetService->updateUnitQueue();

        $this->assertSame($avant + 90, $this->unites('light_fighter'));
        $this->assertSame(["build:$lot:0-50", "build:$lot:50-90"], $this->clefsDe($lot), 'La progression a recompté des unités déjà livrées par le demi-temps.');
        $this->assertSame($this->valeur('light_fighter', 90), $this->construits());
    }

    /**
     * Une autre requête livre sept chasseurs **après** le chargement du corps : le demi-temps ne doit pas les effacer.
     */
    public function testTheHalvingDoesNotOverwriteADeliveryMadeAfterTheBodyWasLoaded(): void
    {
        $debut = $this->maintenant() + 100;
        $lot = $this->unLot('light_fighter', 100, $debut, $debut + 1_000);
        $this->donnerDeLaMatiereNoire();

        $this->travelTo(Date::createFromTimestamp($debut + 305));
        $this->planetService->reloadPlanet();
        $avant = $this->unites('light_fighter');

        DB::table('planets')->where('id', $this->planetService->getPlanetId())->increment('light_fighter', 7);

        resolve(HalvingService::class)->halveUnit($this->compte(), $lot, $this->planetService);

        $this->assertSame($avant + 7 + 50, $this->unites('light_fighter'), 'Le demi-temps a sauvegardé le corps chargé avant la livraison : sept unités payées ont disparu.');
        $this->assertSame($avant + 7 + 50, (int)$this->planetService->getObjectAmount('light_fighter'), 'Le corps en mémoire ne suit pas la ligne : une sauvegarde ultérieure réécrirait un état périmé.');
    }

    /**
     * La même promesse pour la progression : la vidange d'une fermeture ou un appelant qui a chargé le corps plus tôt
     * ne doit pas effacer une livraison faite entre-temps.
     */
    public function testTheProgressionDoesNotOverwriteADeliveryMadeAfterTheBodyWasLoaded(): void
    {
        $debut = $this->maintenant() + 100;
        $this->unLot('rocket_launcher', 12, $debut, $debut + 60);

        // En cours de lot : six unités sont achevées à +32, livrées par la branche progressive.
        $this->travelTo(Date::createFromTimestamp($debut + 32));
        $this->planetService->reloadPlanet();
        $avant = $this->unites('rocket_launcher');

        DB::table('planets')->where('id', $this->planetService->getPlanetId())->increment('rocket_launcher', 7);
        $this->planetService->updateUnitQueue();

        $this->assertSame($avant + 7 + 6, $this->unites('rocket_launcher'), 'En cours de lot, la progression a sauvegardé le corps chargé avant la livraison : sept unités payées ont disparu.');

        // Fin de lot : les six dernières unités sont livrées d'un coup, par l'autre branche.
        $this->travelTo(Date::createFromTimestamp($debut + 100));
        $this->planetService->reloadPlanet();

        DB::table('planets')->where('id', $this->planetService->getPlanetId())->increment('rocket_launcher', 3);
        $this->planetService->updateUnitQueue();

        $this->assertSame($avant + 7 + 6 + 3 + 6, $this->unites('rocket_launcher'), 'En fin de lot, la progression a sauvegardé le corps chargé avant la livraison : trois unités payées ont disparu.');
    }

    public function testAUnitNoFamilyWeighsDefersItsSliceWholeAndItsUnitsAreStillDelivered(): void
    {
        $debut = $this->maintenant() + 100;
        $this->activer($debut - 1_000);

        // La table des poids est construite, puis privée du lance-missiles : une famille manquante au catalogue.
        MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);
        $poids = new ReflectionProperty(MilitaryValue::class, 'poids');
        $table = (array)$poids->getValue();
        unset($table['rocket_launcher']);
        $poids->setValue(null, $table);

        $lot = $this->unLot('rocket_launcher', 12, $debut, $debut + 60);
        $avant = $this->unites('rocket_launcher');

        $this->travelTo(Date::createFromTimestamp($debut + 100));
        $this->planetService->updateUnitQueue();

        $this->assertSame($avant + 12, $this->unites('rocket_launcher'), 'Une unité non pondérée a empêché la livraison : le cumul ne décide pas du jeu.');

        $evenement = DB::table('military_tally_events')->where('event_key', "build:$lot:0-12")->first();
        $this->assertNotNull($evenement, 'La tranche n’a laissé aucune trace à reprendre.');
        $this->assertSame(MilitaryTallyRecorder::PENDING, $evenement->status);
        $this->assertSame('unknown_unit_family', $evenement->reason);
        $this->assertSame([0, 0, 0], [(int)$evenement->built_value, (int)$evenement->destroyed_value, (int)$evenement->lost_value], 'Une tranche en attente porte une valeur inventée.');
        $this->assertSame(['queue_id' => $lot, 'object' => 'rocket_launcher', 'from' => 0, 'to' => 12], json_decode((string)$evenement->payload, true), 'Les faits nécessaires à la reprise ne sont pas gardés.');
        $this->assertSame(0, $this->construits(), 'Une tranche en attente a été comptée.');
    }

    public function testBeforeTheActivationTheQueueDeliversAndRecordsNothing(): void
    {
        $debut = $this->maintenant() + 100;
        $lot = $this->unLot('rocket_launcher', 12, $debut, $debut + 60);
        $avant = $this->unites('rocket_launcher');

        $this->travelTo(Date::createFromTimestamp($debut + 100));
        $this->planetService->updateUnitQueue();

        $this->assertSame($avant + 12, $this->unites('rocket_launcher'));
        $this->assertSame([], $this->clefsDe($lot), 'Une tranche a été inscrite avant l’activation.');
    }

    private function maintenant(): int
    {
        return (int)Date::now()->timestamp;
    }

    private function activer(int $instant): void
    {
        DB::table('settings')->insert([
            'key' => MilitaryTallyRecorder::SINCE_KEY,
            'value' => (string)$instant,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }

    private function unLot(string $nom, int $quantite, int $debut, int $fin): int
    {
        return (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $this->planetService->getPlanetId(),
            'object_id' => ObjectService::getUnitObjectByMachineName($nom)->id,
            'object_amount' => $quantite,
            'time_duration' => $fin - $debut,
            'time_start' => $debut,
            'time_end' => $fin,
            'time_progress' => 0,
            'object_amount_progress' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
        ]);
    }

    private function donnerDeLaMatiereNoire(): void
    {
        DB::table('users')->where('id', $this->currentUserId)->update(['dark_matter' => 1_000_000]);
    }

    private function compte(): User
    {
        $compte = User::query()->find($this->currentUserId);
        $this->assertInstanceOf(User::class, $compte);

        return $compte;
    }

    private function unites(string $nom): int
    {
        return (int)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value($nom);
    }

    private function valeur(string $nom, int $nombre): int
    {
        return MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName($nom), $nombre);
    }

    /**
     * @return list<string>
     */
    private function clefsDe(int $lot): array
    {
        return array_values(array_map(
            static fn (mixed $clef): string => (string)$clef,
            DB::table('military_tally_events')->where('event_key', 'like', 'build:' . $lot . ':%')->orderBy('event_key')->pluck('event_key')->all()
        ));
    }

    /**
     * @return list<int>
     */
    private function valeursDe(int $lot): array
    {
        return array_values(array_map(
            static fn (mixed $valeur): int => (int)$valeur,
            DB::table('military_tally_events')->where('event_key', 'like', 'build:' . $lot . ':%')->orderBy('event_key')->pluck('built_value')->all()
        ));
    }

    private function construits(): int
    {
        resolve(MilitaryTallyAggregator::class)->aggregate();

        return (int)(DB::table('military_tallies')->where('player_id', $this->currentUserId)->value('built_value') ?? 0);
    }
}
