<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Hull\HullRepairService;
use OGame\Models\FleetMission;
use OGame\Models\HullRepairOrder;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\JumpGateService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * Le second service du chantier spatial : reparer des survivants, contre paiement.
 *
 * Chaque essai de cette classe **exige** ce qu il etablit plutot que de le chercher : les degats
 * sont poses, le dock est monte, les ressources sont donnees. Un essai qui trouverait par hasard un
 * corps deja abime ne prouverait rien.
 */
class HullRepairTest extends AccountTestCase
{
    private HullRepairService $reparations;

    protected function setUp(): void
    {
        parent::setUp();

        // **L interrupteur est pose par l essai, jamais suppose.** La base d un processus est
        // partagee entre classes voisines : une classe qui l aurait laisse a 1 ferait passer un
        // essai qui, seul, echouerait.
        resolve(SettingsService::class)->set('hull_damage_enabled', '1');

        // **La base d un processus garde les ordres des essais precedents.** Sans ce vidage,
        // `settleDue()` reglait aussi les ordres laisses en cours par les essais voisins et rendait
        // 3 la ou l essai en attendait 1 : le compte devenait celui de la classe, pas celui de
        // l essai. C est le piege que ce depot connait deja pour les tables de combat.
        HullRepairOrder::query()->delete();

        $this->reparations = resolve(HullRepairService::class);
    }

    protected function tearDown(): void
    {
        // Et il est rabaisse : le laisser arme ferait mentir les essais des classes suivantes.
        resolve(SettingsService::class)->set('hull_damage_enabled', '0');

        parent::tearDown();
    }

    /**
     * Monte un corps qui porte des croiseurs, dont une part endommagee, et un dock.
     */
    private function unCorpsAvecDesCroiseursAbimes(int $total, int $abimes, int $degats, int $niveauDock = 5): void
    {
        $this->planetService->addUnit('cruiser', $total);
        $this->planetService->setObjectLevel(36, $niveauDock, true);
        $this->planetService->writeDamagedHulls(DamagedHulls::of(['cruiser' => [$degats => $abimes]]));
        $this->planetService->addResources(new Resources(5_000_000, 5_000_000, 5_000_000, 0));
    }

    private function croiseurs(int $combien): UnitCollection
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), $combien);

        return $unites;
    }

    public function testLeDevisSuitLesDegatsEtNonLeNombre(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $aMoitie = $this->reparations->quoteFor($this->planetService, DamagedHulls::of(['cruiser' => [5000 => 8]]));
        $aQuart = $this->reparations->quoteFor($this->planetService, DamagedHulls::of(['cruiser' => [2500 => 8]]));

        // **Le meme nombre d unites, deux fois moins de degats, deux fois moins cher.** C est
        // l exigence du cahier des charges : le cout depend de l ampleur des degats, pas du compte.
        $this->assertEqualsWithDelta(
            $aMoitie->cost->metal->get() / 2,
            $aQuart->cost->metal->get(),
            1.0,
            'Reparer des degats de moitie moindres doit couter moitie moins.'
        );

        // Et il depend de la valeur de l unite : un croiseur coute plus qu un chasseur leger.
        $this->planetService->addUnit('light_fighter', 8);
        $chasseurs = $this->reparations->quoteFor($this->planetService, DamagedHulls::of(['light_fighter' => [5000 => 8]]));

        $this->assertGreaterThan(
            $chasseurs->cost->metal->get(),
            $aMoitie->cost->metal->get(),
            'Reparer huit croiseurs doit couter plus que reparer huit chasseurs legers.'
        );
    }

    public function testUneReparationPayeeImmobiliseLesUnitesSansLesFaireDisparaitre(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $avant = $this->planetService->getResources();
        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);

        $ordre = $this->reparations->confirm(
            $this->planetService,
            $selection,
            $devis->fingerprint(),
            (int)Date::now()->timestamp
        );

        $this->planetService->reloadPlanet();

        // **Les unites n ont pas quitte le corps** : elles sont physiquement la, et se battront si
        // le corps est attaque. Le dock ne doit pas etre un abri.
        $this->assertSame(
            20,
            $this->planetService->getShipUnits()->getAmountByMachineName('cruiser'),
            'Une unite en reparation reste presente sur le corps.'
        );

        // Mais elle est immobilisee : le depart la refuse.
        $this->assertSame(
            ['cruiser' => 8],
            $this->planetService->unitsHeldAtDock(),
            'Les huit unites confiees doivent etre tenues par le dock.'
        );

        // Et ses degats ont quitte le corps pour l ordre : sans quoi ils seraient comptes deux fois.
        $this->assertTrue(
            $this->planetService->damagedHulls()->isEmpty(),
            'Les degats confies au dock ne restent pas sur le corps.'
        );

        // Le paiement a bien eu lieu.
        $apres = $this->planetService->getResources();
        $this->assertEqualsWithDelta(
            $devis->cost->metal->get(),
            $avant->metal->get() - $apres->metal->get(),
            1.0,
            'Le metal du devis doit avoir ete debite.'
        );

        $this->assertSame(HullRepairOrder::STATUS_REPAIRING, $ordre->status);
    }

    public function testUneFlotteNePartPasAvecCeQueLeDockRepare(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);
        $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), (int)Date::now()->timestamp);
        $this->planetService->reloadPlanet();

        // Douze unites sont libres : douze peuvent partir.
        $this->assertNotNull(
            $this->planetService->detachUnitsForDeparture(new Resources(0, 0, 0, 0), $this->croiseurs(12)),
            'Les douze unites libres doivent pouvoir partir.'
        );

        $this->planetService->reloadPlanet();
        $this->planetService->addUnit('cruiser', 12);
        $this->planetService->reloadPlanet();

        // La treizieme mordrait sur le dock : refus nomme, pas un depart silencieux.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/held at the space dock/');

        $this->planetService->detachUnitsForDeparture(new Resources(0, 0, 0, 0), $this->croiseurs(13));
    }

    public function testUneReparationTermineeRendDesUnitesIntactes(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);
        $maintenant = (int)Date::now()->timestamp;

        $ordre = $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), $maintenant);

        // Rien avant l echeance.
        $this->assertSame(0, $this->reparations->settleDue($ordre->completed_at - 1));

        // Et tout a l echeance.
        $this->assertSame(1, $this->reparations->settleDue($ordre->completed_at));

        $this->planetService->reloadPlanet();

        $this->assertTrue(
            $this->planetService->damagedHulls()->isEmpty(),
            'Une reparation menee a son terme rend des unites intactes.'
        );

        $this->assertSame(
            [],
            $this->planetService->unitsHeldAtDock(),
            'Le dock ne tient plus rien une fois la reparation terminee.'
        );

        $this->assertSame(
            20,
            $this->planetService->getShipUnits()->getAmountByMachineName('cruiser'),
            'Aucune unite ne doit avoir disparu ni ete dupliquee.'
        );
    }

    public function testUneFinDeReparationTraiteeDeuxFoisNeRendLesUnitesQuUneFois(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);
        $ordre = $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), (int)Date::now()->timestamp);

        $this->assertSame(1, $this->reparations->settleDue($ordre->completed_at), 'Le premier passage regle.');

        // **Le second passage ne doit rien faire.** C est la garde d idempotence : elle ne repose pas
        // sur une lecture suivie d une ecriture — deux processus la passeraient tous les deux — mais
        // sur une mise a jour conditionnelle sur le statut.
        $this->assertSame(0, $this->reparations->settleDue($ordre->completed_at), 'Le second passage ne regle rien.');

        // Et l appel direct non plus : la garde ne depend pas du chemin emprunte.
        $relu = HullRepairOrder::find($ordre->id);
        $this->assertNotNull($relu, 'L ordre regle reste en base, marque comme tel.');
        $this->assertFalse($this->reparations->settle($relu, $ordre->completed_at));

        $this->planetService->reloadPlanet();

        $this->assertSame(
            20,
            $this->planetService->getShipUnits()->getAmountByMachineName('cruiser'),
            'Un reglement joue deux fois ne cree pas d unites.'
        );
    }

    public function testUneConfirmationRepeteeNePaiePasDeuxFois(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);
        $maintenant = (int)Date::now()->timestamp;

        $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), $maintenant);
        $this->planetService->reloadPlanet();

        $apresLePremier = $this->planetService->getResources()->metal->get();

        // Le second essai bute sur le verrou du dock — une colonne unique, pas une verification.
        try {
            $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), $maintenant);
            $this->fail('Une seconde confirmation aurait du etre refusee.');
        } catch (RuntimeException $refus) {
            $this->assertStringContainsString('dock_busy', $refus->getMessage());
        }

        $this->planetService->reloadPlanet();

        $this->assertEqualsWithDelta(
            $apresLePremier,
            $this->planetService->getResources()->metal->get(),
            1.0,
            'Une confirmation refusee ne debite rien.'
        );

        $this->assertSame(
            1,
            HullRepairOrder::where('planet_id', $this->planetService->getPlanetId())->count(),
            'Un seul ordre doit exister.'
        );
    }

    public function testUnDevisPerimeEstRefuse(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);

        // Le dock grandit entre l affichage et le clic : le prix affiche n est plus le prix du.
        $this->planetService->setObjectLevel(36, 12, true);
        $this->planetService->reloadPlanet();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/quote_stale/');

        $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), (int)Date::now()->timestamp);
    }

    public function testUneAnnulationRendLeTravailFaitEtRembourseLeReste(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);
        $debut = (int)Date::now()->timestamp;

        $ordre = $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), $debut);

        $this->planetService->reloadPlanet();
        $apresPaiement = $this->planetService->getResources()->metal->get();

        // A mi-parcours exactement.
        $miParcours = $debut + (int)(($ordre->completed_at - $debut) / 2);

        $this->assertTrue($this->reparations->endEarly($ordre, HullRepairOrder::BECAUSE_PLAYER, $miParcours));

        $this->planetService->reloadPlanet();

        // **La moitie du prix revient**, et la moitie du travail reste acquise.
        $rembourse = $this->planetService->getResources()->metal->get() - $apresPaiement;

        $this->assertEqualsWithDelta(
            $devis->cost->metal->get() / 2,
            $rembourse,
            2.0,
            'Une annulation a mi-parcours rembourse la moitie du prix.'
        );

        $degats = $this->planetService->damagedHulls();

        $this->assertSame(
            8,
            $degats->damagedCountOf('cruiser'),
            'Les huit unites reviennent au corps.'
        );

        // Elles reviennent **a moitie reparees** : 5 000 points de base devenus 2 500.
        $this->assertSame(
            [2500 => 8],
            $degats->levelsOf('cruiser'),
            'Le travail deja fait reste acquis : la moitie des degats a disparu.'
        );
    }

    public function testUneAnnulationJoueeDeuxFoisNeRembourseQuUneFois(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);
        $debut = (int)Date::now()->timestamp;
        $ordre = $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), $debut);

        $miParcours = $debut + (int)(($ordre->completed_at - $debut) / 2);

        $this->assertTrue($this->reparations->endEarly($ordre, HullRepairOrder::BECAUSE_PLAYER, $miParcours));

        $this->planetService->reloadPlanet();
        $apresLaPremiere = $this->planetService->getResources()->metal->get();
        $degatsApresLaPremiere = $this->planetService->damagedHulls()->levelsOf('cruiser');

        // Le second appel relit l ordre sous verrou et le trouve deja clos.
        $this->assertFalse($this->reparations->endEarly($ordre, HullRepairOrder::BECAUSE_PLAYER, $miParcours));

        $this->planetService->reloadPlanet();

        $this->assertEqualsWithDelta(
            $apresLaPremiere,
            $this->planetService->getResources()->metal->get(),
            1.0,
            'Une annulation rejouee ne rembourse pas une seconde fois.'
        );

        $this->assertSame(
            $degatsApresLaPremiere,
            $this->planetService->damagedHulls()->levelsOf('cruiser'),
            'Une annulation rejouee ne rend pas les unites une seconde fois.'
        );
    }

    public function testUneSelectionQuiDemandePlusQueCeQuiExisteEstRefusee(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        // Douze unites a ce palier, alors que le corps n en porte que huit.
        $trop = DamagedHulls::of(['cruiser' => [5000 => 12]]);
        $devis = $this->reparations->quoteFor($this->planetService, $trop);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/units_gone/');

        $this->reparations->confirm($this->planetService, $trop, $devis->fingerprint(), (int)Date::now()->timestamp);
    }

    public function testSansDockAucuneReparation(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000, 0);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no_dock/');

        $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), (int)Date::now()->timestamp);
    }

    /**
     * **La porte de saut ne repare pas.**
     *
     * Sans les degats qui suivent, sauter sa flotte abimee vers une seconde lune la rendrait neuve :
     * un contournement complet du dock, et **invisible** — les effectifs restent justes des deux
     * cotes, seule la sante change.
     */
    public function testUnSautNeSoignePersonne(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        $source = $this->planetService;
        $cible = $this->secondPlanetService ?? null;

        if ($cible === null) {
            $this->markTestSkipped('Ce compte n a qu un corps : le saut ne peut pas etre eprouve ici.');
        }

        // La cible peut deja porter des abimees : on compte en ecart, jamais a zero.
        $avantCible = $cible->damagedHulls()->damagedCountOf('cruiser');

        // Quinze partent : les douze intacts, puis trois des abimes.
        $this->assertTrue(
            resolve(JumpGateService::class)->transferShips($source, $cible, ['cruiser' => 15]),
            'Le transfert doit aboutir.'
        );

        $source->reloadPlanet();
        $cible->reloadPlanet();

        $restees = $source->damagedHulls()->damagedCountOf('cruiser');
        $arrivees = $cible->damagedHulls()->damagedCountOf('cruiser') - $avantCible;

        // **Aucune unite abimee n a disparu ni ete soignee** : les huit se repartissent entre les
        // deux corps. C est la seule assertion qui ferme l exploit.
        $this->assertSame(
            8,
            $restees + $arrivees,
            'Le saut a soigne ou perdu des unites endommagees : ' . $restees . ' restees, ' . $arrivees . ' arrivees.'
        );

        // Et la regle de depart vaut aussi ici : les intactes partent d abord, donc cinq abimees
        // restent et trois seulement voyagent.
        $this->assertSame(5, $restees, 'Les intactes doivent partir avant les abimees.');
        $this->assertSame(3, $arrivees);

        $this->assertSame(
            [5000 => 5],
            $source->damagedHulls()->levelsOf('cruiser'),
            'Le palier des restantes doit etre celui d origine, inchange.'
        );
    }

    /**
     * **Un retrait d unites ne laisse jamais plus d abimees que d unites presentes.**
     *
     * L invariant se casserait en silence : rien ne rougirait avant le combat suivant, ou la
     * composition du champ refuserait de se faire. Le filet de `removeUnitsAtomic()` le tient pour
     * tous les chemins qui ne savent rien des coques.
     */
    public function testUnRetraitNeLaissePlusDAbimeesQueDUnites(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        // Quinze disparaissent sans que personne ne s occupe des degats — un demenagement, une
        // perte de defense, un chemin d administration.
        $this->planetService->removeUnits($this->croiseurs(15), true);
        $this->planetService->reloadPlanet();

        $restants = $this->planetService->getShipUnits()->getAmountByMachineName('cruiser');
        $abimees = $this->planetService->damagedHulls()->damagedCountOf('cruiser');

        $this->assertSame(5, $restants);
        $this->assertLessThanOrEqual(
            $restants,
            $abimees,
            'L histogramme compte plus d unites abimees que le corps n en porte.'
        );

        // Et la composition d un champ de bataille reste possible — c est ce que l invariant protege.
        $this->assertCount(
            $restants,
            $this->planetService->damagedHulls()->damageSequenceFor('cruiser', $restants)
        );
    }

    /**
     * **Une flotte abimee repart au combat abimee.**
     *
     * C est la ligne qui relie tout le reste : le reglement ecrit les degats sur la mission, le
     * retour les herite, l atterrissage les fusionne — et si la construction de la flotte de combat
     * ne les relisait pas, **tout cela ne servirait a rien**. Le defaut serait invisible de bout en
     * bout : effectifs justes partout, colonne correcte, et seule l issue des batailles fausse.
     */
    public function testUneFlotteAbimeeRepartAuCombatAbimee(): void
    {
        $mission = new FleetMission();
        $mission->damaged_hulls = DamagedHulls::of(['cruiser' => [5000 => 8, 2500 => 4]])->toStorage();
        $mission->id = 4242;
        $mission->user_id = $this->currentUserId;
        $mission->cruiser = 20;

        // La cargaison est lue au montage de la flotte : sans elle, `Resources` refuse un `null`.
        $mission->metal = 0;
        $mission->crystal = 0;
        $mission->deuterium = 0;

        $attaquante = AttackerFleet::fromFleetMission(
            $mission,
            resolve(FleetMissionService::class),
            resolve(PlayerServiceFactory::class),
            true
        );

        $this->assertSame(
            12,
            $attaquante->damagedHulls()->damagedCountOf('cruiser'),
            'La flotte attaquante entre au combat sans les degats que sa mission transporte.'
        );

        // Et l ordre d entree suit la regle : les intactes en tete, puis les moins abimees.
        $this->assertSame(
            [0, 0, 0, 0, 0, 0, 0, 0, 2500, 2500, 2500, 2500, 5000, 5000, 5000, 5000, 5000, 5000, 5000, 5000],
            $attaquante->damagedHulls()->damageSequenceFor('cruiser', 20)
        );

        $defensive = DefenderFleet::fromFleetMission(
            $mission,
            resolve(FleetMissionService::class),
            resolve(PlayerServiceFactory::class)
        );

        $this->assertSame(
            12,
            $defensive->damagedHulls()->damagedCountOf('cruiser'),
            'Un renfort defensif tient la position sans ses degats.'
        );
    }

    public function testInterrupteurDesarmeAucuneReparationEtAucuneDestruction(): void
    {
        $this->unCorpsAvecDesCroiseursAbimes(20, 8, 5000);

        resolve(SettingsService::class)->set('hull_damage_enabled', '0');

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);

        try {
            $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), (int)Date::now()->timestamp);
            $this->fail('Une reparation ne doit pas etre possible interrupteur desarme.');
        } catch (RuntimeException $refus) {
            $this->assertStringContainsString('disabled', $refus->getMessage());
        }

        $this->planetService->reloadPlanet();

        // **Desarmer n efface rien** : c est une exigence explicite du cahier des charges.
        $this->assertSame(
            8,
            $this->planetService->damagedHulls()->damagedCountOf('cruiser'),
            'Les degats acquis survivent au desarmement de l interrupteur.'
        );
    }
}
