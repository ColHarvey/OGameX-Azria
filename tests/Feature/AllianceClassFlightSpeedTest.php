<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\AllianceClass;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Alliance;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\FleetMissionService;
use OGame\Services\InitialUserDataService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Les deux bonus de vitesse d une classe d alliance, mesures sur la duree d un vol.
 *
 * ## Ce que ce banc mesure
 *
 * Pas le multiplicateur rendu par le service — un essai qui demande 1,10 et verifie qu il recoit
 * 1,10 ne prouve que lui-meme. Il mesure **la duree**, celle que la ligne de mission porte pour une
 * expedition, celle que le jeu calcule pour un transport vers un corps donne.
 *
 * ## Le faux est rendu observable
 *
 * Le vol entre membres depend de la **destination** : le meme joueur, la meme flotte, le meme corps
 * vise — seule change l appartenance du proprietaire de ce corps a l alliance. Un code qui donnerait
 * le bonus a tous les vols passerait un essai qui n eprouverait que le cas allie ; celui-ci exige la
 * duree naturelle tant que la cible est etrangere, puis la duree reduite une fois qu elle ne l est
 * plus.
 */
class AllianceClassFlightSpeedTest extends FleetDispatchTestCase
{
    protected int $missionType = 15; // Expedition

    protected string $missionName = 'Expedition';

    protected function setUp(): void
    {
        parent::setUp();

        // **L essai pose l interrupteur qu il suppose.**
        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('large_cargo', 20);

        // Astrophysique 4 : deux creneaux d expedition, donc deux envois sans attendre le retour du
        // premier. Avec un seul creneau, le second envoi serait refuse et la mesure n aurait pas lieu.
        $this->playerSetResearchLevel('astrophysics', 4);
        $this->playerSetResearchLevel('computer_technology', 10);

        $settings = resolve(SettingsService::class);
        $settings->set('economy_speed', 1);
        $settings->set('fleet_speed_war', 1);
        $settings->set('fleet_speed_holding', 1);
        $settings->set('fleet_speed_peaceful', 1);

        $this->planetAddResources(new Resources(0, 0, 1000000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
        // Sans objet ici : ce banc mesure des durees, pas des messages.
    }

    protected function messageCheckMissionReturn(): void
    {
        // Sans objet ici.
    }

    /**
     * **Une expedition d une alliance de Chercheurs part 10 % plus vite.**
     *
     * La mesure est prise sur la ligne de mission, la ou le joueur la lit : `time_arrival` moins
     * `time_departure`. Deux envois identiques, seule l alliance change entre les deux.
     */
    public function testAResearchersAllianceShortensTheExpeditionFlightByTenPercent(): void
    {
        $this->basicSetup();

        $this->envoyerUneExpedition();
        $naturelle = $this->dureeDeLaDerniereMission();

        $this->uneAllianceDeClasse(AllianceClass::RESEARCHERS);

        $this->envoyerUneExpedition();
        $avecBonus = $this->dureeDeLaDerniereMission();

        $this->assertLessThan($naturelle, $avecBonus, 'L expedition d une alliance de Chercheurs dure autant qu une expedition ordinaire.');
        $this->assertEqualsWithDelta(
            $naturelle / 1.10,
            $avecBonus,
            1.0,
            'Le raccourcissement de l expedition ne vaut pas 10 % : naturelle ' . $naturelle . ' s, avec bonus ' . $avecBonus . ' s.'
        );
    }

    /**
     * **Un vol vers un membre de l alliance est 10 % plus rapide ; un vol vers un etranger ne l est
     * pas.**
     *
     * Les deux moities comptent. La premiere seule laisserait passer un code qui accelererait tous
     * les vols d un membre d une alliance de Guerriers, ce que la page ne promet pas.
     */
    public function testAWarriorsAllianceShortensAFlightOnlyTowardsAFellowMember(): void
    {
        $this->basicSetup();

        $etrangere = $this->unePlaneteDUnJoueurNeuf();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire, 'La planete etrangere du banc n a pas de proprietaire.');

        $cible = $etrangere->getPlanetCoordinates();
        $naturelle = $this->dureeDUnTransportVers($cible);

        $alliance = $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        $this->assertSame(
            $naturelle,
            $this->dureeDUnTransportVers($cible),
            'Le vol vers un joueur qui n est pas dans l alliance a change de duree : le bonus ne regarde pas la destination.'
        );

        $this->faireEntrerDansLAlliance($proprietaire->getId(), $alliance);

        $avecBonus = $this->dureeDUnTransportVers($cible);

        $this->assertLessThan($naturelle, $avecBonus, 'Le vol vers un membre de l alliance dure autant qu avant qu il la rejoigne.');
        $this->assertEqualsWithDelta(
            $naturelle / 1.10,
            $avecBonus,
            1.0,
            'Le raccourcissement du vol entre membres ne vaut pas 10 % : naturel ' . $naturelle . ' s, avec bonus ' . $avecBonus . ' s.'
        );
    }

    /**
     * **Chaque classe ne donne que son propre bonus.**
     *
     * Les Guerriers n accelerent pas une expedition, les Chercheurs n accelerent pas un vol vers un
     * membre. Sans cet essai, un multiplicateur pose au mauvais endroit resterait invisible : les
     * deux valent 1,10 et la duree serait juste par accident.
     */
    public function testEachClassOnlyGrantsItsOwnSpeedBonus(): void
    {
        $this->basicSetup();

        $etrangere = $this->unePlaneteDUnJoueurNeuf();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire, 'La planete etrangere du banc n a pas de proprietaire.');

        $cible = $etrangere->getPlanetCoordinates();
        $coordonneesDuPlanete = $this->planetService->getPlanetCoordinates();
        $case16 = new Coordinate($coordonneesDuPlanete->galaxy, $coordonneesDuPlanete->system, 16);

        $transportNaturel = $this->dureeDUnTransportVers($cible);
        $expeditionNaturelle = $this->dureeDUneExpeditionVers($case16);

        $alliance = $this->uneAllianceDeClasse(AllianceClass::WARRIORS);
        $this->faireEntrerDansLAlliance($proprietaire->getId(), $alliance);

        $this->assertLessThan(
            $transportNaturel,
            $this->dureeDUnTransportVers($cible),
            'Le banc ne mesure rien : le bonus des Guerriers ne s applique meme pas a son propre vol.'
        );
        $this->assertSame(
            $expeditionNaturelle,
            $this->dureeDUneExpeditionVers($case16),
            'Une alliance de Guerriers accelere une expedition, que sa classe ne promet pas.'
        );
    }

    /**
     * La planete d'un joueur cree pour cet essai, et pour lui seul.
     *
     * **Le voisin etranger du banc est partage entre les essais d'un meme processus** : le faire
     * entrer dans une alliance le laisse engage pour l'essai suivant, qui se voit alors refuser sa
     * candidature. Un joueur neuf n'appartient a aucune alliance, et c'est la premisse de la mesure.
     */
    private function unePlaneteDUnJoueurNeuf(): PlanetService
    {
        $etranger = User::factory()->create(['username' => 'vitesse_' . Str::random(16)]);

        // Le crochet `created` du modele promeut le premier utilisateur d'une transaction en admin ;
        // un administrateur serait ensuite ecarte des recherches de voisinage.
        if ($etranger->hasRole('admin')) {
            $etranger->removeRole('admin');
            $etranger->save();
        }

        resolve(InitialUserDataService::class)->createFor($etranger);

        $joueur = resolve(PlayerServiceFactory::class)->make((int)$etranger->id, true);
        $planete = resolve(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer(
            $joueur,
            $this->getNearbyEmptyCoordinate()
        );

        $this->assertNotNull($planete, 'La planete de la cible neuve n a pas ete creee.');
        $this->assertNull($etranger->alliance_id, 'Le joueur neuf appartient deja a une alliance.');

        return $planete;
    }

    /**
     * Une alliance fondee par le joueur courant, qui prend la classe demandee.
     */
    private function uneAllianceDeClasse(AllianceClass $classe): Alliance
    {
        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'VI' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Vitesse ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->assertNotNull($alliance);

        // Une alliance fondee a l instant n a pas les quatorze jours qui offrent le premier choix.
        DB::table('users')->where('id', $this->currentUserId)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);

        resolve(AllianceClassService::class)->choose(
            User::query()->findOrFail($this->currentUserId),
            $alliance,
            $classe
        );

        return $alliance;
    }

    /**
     * Faire entrer un joueur dans l alliance, par le chemin du jeu : il postule, le fondateur accepte.
     */
    private function faireEntrerDansLAlliance(int $userId, Alliance $alliance): void
    {
        $alliances = resolve(AllianceService::class);
        $candidature = $alliances->applyToAlliance($userId, (int)$alliance->id);
        $alliances->acceptApplication((int)$candidature->id, $this->currentUserId);

        $this->assertTrue(
            $alliances->arePlayersInSameAlliance($this->currentUserId, $userId),
            'Le proprietaire de la planete visee n est pas entre dans l alliance.'
        );
    }

    /**
     * Envoyer une expedition d un grand transporteur sur la case 16 du systeme.
     */
    private function envoyerUneExpedition(): void
    {
        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('large_cargo'), 1);

        $this->sendMissionToPosition16($flotte, new Resources(0, 0, 0, 0));
    }

    /**
     * La duree de l aller de la derniere mission creee.
     *
     * Pour une expedition, `time_arrival` est l arrivee physique : le sejour vit dans `time_holding`
     * et ne s y ajoute pas.
     */
    private function dureeDeLaDerniereMission(): int
    {
        $mission = FleetMission::query()->orderByDesc('id')->first();

        $this->assertNotNull($mission, 'Aucune mission n a ete creee par l envoi.');

        return (int)$mission->time_arrival - (int)$mission->time_departure;
    }

    private function dureeDUnTransportVers(Coordinate $to): int
    {
        return $this->dureeVers($to, 3);
    }

    private function dureeDUneExpeditionVers(Coordinate $to): int
    {
        return $this->dureeVers($to, 15);
    }

    /**
     * La duree que le jeu calculerait pour ce vol, maintenant.
     *
     * **La planete est relue a chaque mesure.** `PlanetService` porte le joueur, et c est par lui que
     * l appartenance a l alliance se lit : une planete gardee d avant la fondation repondrait « sans
     * alliance » a jamais.
     */
    private function dureeVers(Coordinate $to, int $genre): int
    {
        $planete = resolve(PlanetServiceFactory::class)->make($this->planetService->getPlanetId(), true);

        $this->assertNotNull($planete, 'La planete du banc a disparu.');

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('large_cargo'), 1);

        return resolve(FleetMissionService::class)->calculateFleetMissionDuration(
            $planete,
            $to,
            $flotte,
            GameMissionFactory::getMissionById($genre, []),
            10
        );
    }
}
