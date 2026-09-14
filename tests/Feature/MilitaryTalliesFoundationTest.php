<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\GameObjects\Models\ShipObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\Exceptions\UnknownMilitaryUnit;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryValue;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;

/**
 * **Le socle des cumuls militaires : ce qui entre, ce qui n'entre pas, et rien deux fois.**
 *
 * Ces essais ne raccordent encore aucun chemin de jeu. Ils tiennent les quatre promesses dont tous les chemins
 * dépendront :
 *
 * 1. **la frontière d'activation** — un fait antérieur à la date n'entre jamais, même traité en retard ; l'instant
 *    exact d'activation est dedans ; et un rejeu ne crédite rien deux fois ;
 * 2. **la pondération** — la même que le score militaire, sans arrondi par événement ;
 * 3. **rien d'inventé** — une unité hors catalogue met l'événement en attente, entier, sans aucune part créditée ;
 * 4. **l'activation** — elle écrit sa date une seule fois et ne touche jamais aux compteurs.
 *
 * ## Pourquoi des valeurs toutes différentes
 *
 * Les trois crédits de la frontière valent 100, 10 et 1 : le total dit exactement lesquels sont entrés. Des
 * valeurs égales laisseraient passer un « juste avant » compté à la place d'un « juste après ».
 */
class MilitaryTalliesFoundationTest extends AccountTestCase
{
    /** Un instant d'activation fixe, loin de l'horloge du banc : la frontière ne dépend d'aucune horloge. */
    private const int ACTIVATION = 1_900_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        // La base d'un processus est partagée : l'essai établit lui-même l'état de la collecte qu'il suppose.
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
    }

    protected function tearDown(): void
    {
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        // Les événements de l'essai, en attente compris : un voisin du même processus compte ceux qui attendent.
        DB::table('military_tally_events')->where('event_key', 'like', 'essai:%')->delete();

        parent::tearDown();
    }

    /**
     * **Avant l'activation, rien n'entre** — pas même un fait d'aujourd'hui, et aucune ligne n'est écrite.
     */
    public function testNothingIsCreditedBeforeTheCollectionIsActivated(): void
    {
        $registre = resolve(MilitaryTallyRecorder::class);

        $this->assertNull($registre->collectingSince(), 'La collecte passe pour activée alors que rien ne l\'a activée.');
        $this->assertFalse($registre->credit($this->clef('avant-activation'), $this->currentUserId, (int)Date::now()->timestamp, built: 500));

        $this->assertSame([0, 0, 0], $this->compteurs());
        $this->assertSame(0, DB::table('military_tally_events')->where('player_id', $this->currentUserId)->count());
    }

    /**
     * **La frontière est précise** : la seconde d'avant est dehors, l'instant exact et la seconde d'après sont
     * dedans — et le rejeu de chacun ne change rien.
     */
    public function testTheActivationInstantIsInsideAndTheSecondBeforeIsOutside(): void
    {
        $this->activer(self::ACTIVATION);
        $registre = resolve(MilitaryTallyRecorder::class);

        $avant = $this->clef('juste-avant');
        $pile = $this->clef('exactement');
        $apres = $this->clef('juste-apres');

        $this->assertFalse($registre->credit($avant, $this->currentUserId, self::ACTIVATION - 1, lost: 100), 'Un fait antérieur à l\'activation est entré.');
        $this->assertTrue($registre->credit($pile, $this->currentUserId, self::ACTIVATION, lost: 10), 'Un fait à l\'instant exact d\'activation est resté dehors.');
        $this->assertTrue($registre->credit($apres, $this->currentUserId, self::ACTIVATION + 1, lost: 1));

        $this->assertSame([0, 0, 11], $this->compteurs());

        // Le rejeu : un travailleur en retard, une reprise. Rien ne bouge, et le fait d'avant reste dehors.
        $this->assertFalse($registre->credit($avant, $this->currentUserId, self::ACTIVATION - 1, lost: 100));
        $this->assertFalse($registre->credit($pile, $this->currentUserId, self::ACTIVATION, lost: 10), 'Un rejeu a crédité une seconde fois.');
        $this->assertFalse($registre->credit($apres, $this->currentUserId, self::ACTIVATION + 1, lost: 1));

        $this->assertSame([0, 0, 11], $this->compteurs(), 'Le rejeu a changé les compteurs.');
        $this->assertSame(2, DB::table('military_tally_events')->where('player_id', $this->currentUserId)->count());
    }

    /**
     * **La pondération est celle du score militaire** : vaisseau civil pour moitié, défense et vaisseau militaire
     * pour leur valeur entière.
     *
     * Le témoin est le score militaire du jeu lui-même, sur une planète qui ne porte que les unités posées ici :
     * les deux doivent donner les mêmes points.
     */
    public function testTheWeightingIsTheOneOfTheMilitaryScore(): void
    {
        $this->assertSame(0, $this->planetService->getPlanetMilitaryScore(), 'La planète du banc porte déjà des unités : la comparaison ne prouverait rien.');

        $unites = new UnitCollection();

        foreach (['rocket_launcher' => 3, 'light_fighter' => 2, 'espionage_probe' => 8] as $nom => $nombre) {
            $this->assertGreaterThan(0, (int)ObjectService::getObjectRawPrice($nom)->sum(), "Le prix de $nom vaut zéro : la recherche d'objet a échoué en silence.");
            $this->planetAddUnit($nom, $nombre);
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        $this->planetService->reloadPlanet();

        $this->assertSame(
            $this->planetService->getPlanetMilitaryScore(),
            MilitaryValue::pointsOf(MilitaryValue::ofUnits($unites)),
            'Les cumuls ne pondèrent pas comme le score militaire : les classements ne seraient pas comparables.'
        );
    }

    /**
     * **Une sonde garde sa contribution**, même sous le point : rien n'est arrondi à l'événement.
     */
    public function testAProbeKeepsItsContributionBelowOnePoint(): void
    {
        $sonde = ObjectService::getUnitObjectByMachineName('espionage_probe');
        $prix = (int)ObjectService::getObjectRawPrice('espionage_probe')->sum();

        $this->assertGreaterThan(0, $prix);
        $this->assertSame($prix, MilitaryValue::ofObject($sonde, 1), 'Une sonde civile ne compte pas pour la moitié de sa valeur, en demi-unités.');
        $this->assertSame(0, MilitaryValue::pointsOf(MilitaryValue::ofObject($sonde, 1)), 'Prémisse : une sonde seule vaut moins d\'un point.');

        // Mille crédits d'une sonde chacun valent exactement mille sondes : aucune miette perdue en route.
        $cumul = 0;
        for ($i = 0; $i < 1000; $i++) {
            $cumul += MilitaryValue::ofObject($sonde, 1);
        }

        $this->assertSame(MilitaryValue::ofObject($sonde, 1000), $cumul);
        $this->assertGreaterThan(0, MilitaryValue::pointsOf($cumul), 'Mille sondes perdues ne valent aucun point : la contribution a été arrondie en route.');
    }

    /**
     * **Une unité hors catalogue ne reçoit pas de poids inventé** : l'évaluation refuse, et l'événement est mis en
     * attente **entier**, sans aucune de ses parts créditée, rejouable sans effet.
     */
    public function testAnUnknownUnitIsDeferredWholeAndCreditsNothing(): void
    {
        $this->activer(self::ACTIVATION);

        $inconnue = new ShipObject();
        $inconnue->machine_name = 'unite_hors_catalogue';

        $unites = new UnitCollection();
        $unites->addUnit($inconnue, 3);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 2);

        $this->assertSame(['unite_hors_catalogue'], MilitaryValue::unknownUnitsIn($unites));

        try {
            MilitaryValue::ofUnits($unites);
            $this->fail('Une unité hors catalogue a reçu une valeur au lieu d\'être refusée.');
        } catch (UnknownMilitaryUnit $refus) {
            $this->assertSame('unite_hors_catalogue', $refus->machineName);
        }

        $registre = resolve(MilitaryTallyRecorder::class);
        $enAttenteAvant = $registre->pendingCount();
        $clef = $this->clef('hors-catalogue');

        $this->assertTrue($registre->defer($clef, $this->currentUserId, self::ACTIVATION + 5, 'unknown_unit_family', ['lost' => $unites->toArray()]));
        $this->assertFalse($registre->defer($clef, $this->currentUserId, self::ACTIVATION + 5, 'unknown_unit_family', ['lost' => $unites->toArray()]), 'Un rejeu a remis l\'événement en attente une seconde fois.');

        $this->assertSame($enAttenteAvant + 1, $registre->pendingCount());
        $this->assertSame([0, 0, 0], $this->compteurs(), 'Un événement en attente a crédité une de ses parts.');

        $ligne = DB::table('military_tally_events')->where('event_key', $clef)->first();
        $this->assertNotNull($ligne);
        $this->assertSame('en_attente', $ligne->status);
        $this->assertSame(MilitaryValue::WEIGHTING_VERSION, $ligne->weighting_version);
        $this->assertSame([0, 0, 0], [(int)$ligne->built_value, (int)$ligne->destroyed_value, (int)$ligne->lost_value]);
        $this->assertSame(['lost' => ['unite_hors_catalogue' => 3, 'light_fighter' => 2]], json_decode((string)$ligne->payload, true), 'Les faits nécessaires à la reprise ne sont pas gardés.');
    }

    /**
     * **L'activation écrit sa date une fois**, et un second appel ne déplace ni la date ni aucun compteur.
     */
    public function testTheActivationWritesTheDateOnceAndNeverTouchesTheCounters(): void
    {
        DB::table('users')->where('id', $this->currentUserId)->update(['military_value_lost' => 42]);

        $this->assertSame(0, Artisan::call('ogamex:military:demarrer-cumuls'), 'La première activation a échoué.');
        $premiere = resolve(MilitaryTallyRecorder::class)->collectingSince();
        $this->assertNotNull($premiere, 'L\'activation n\'a pas écrit de date.');

        $this->travel(3)->days();
        $this->assertSame(0, Artisan::call('ogamex:military:demarrer-cumuls'), 'Le second appel a échoué au lieu de dire la date existante.');

        $this->assertSame($premiere, resolve(MilitaryTallyRecorder::class)->collectingSince(), 'Un second appel a déplacé la date : la période déjà couverte serait effacée.');
        $this->assertSame([0, 0, 42], $this->compteurs(), 'L\'activation a touché les compteurs des comptes.');
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

    private function clef(string $nom): string
    {
        return 'essai:' . $nom . ':' . $this->currentUserId;
    }

    /**
     * Les trois compteurs du joueur courant : construits, détruits, perdus.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function compteurs(): array
    {
        $ligne = DB::table('users')->where('id', $this->currentUserId)->first(['military_value_built', 'military_value_destroyed', 'military_value_lost']);
        $this->assertNotNull($ligne);

        return [(int)$ligne->military_value_built, (int)$ligne->military_value_destroyed, (int)$ligne->military_value_lost];
    }
}
