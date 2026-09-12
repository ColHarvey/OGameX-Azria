<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\AllianceClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Alliance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\InitialUserDataService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Le +1 niveau d espionnage d une alliance de Guerriers, mesure sur ce que le rapport revele.
 *
 * ## Le seuil choisi, et pourquoi celui-la
 *
 * `canRevealData()` ouvre une section quand `sondes >= seuilDeSondes` **ou**
 * `niveauAttaquant - seuilDeNiveau >= niveauDefenseur`. Avec **une** sonde, aucun seuil de sondes
 * n est atteint : seul le niveau decide. Le banc pose l attaquant a 2 et la cible a 0 ; la section
 * **batiments** (seuil de niveau 3) demande alors `2 - 3 >= 0`, qui est faux. Le bonus d alliance
 * porte l attaquant a 3, et `3 - 3 >= 0` devient vrai.
 *
 * C est le seul seuil que ce +1 fasse basculer dans cette configuration : les vaisseaux et les
 * defenses sont deja reveles avant lui, la recherche ne l est toujours pas apres. **Un essai qui
 * viserait une section deja ouverte ne distinguerait pas le juste du faux.**
 *
 * ## Chaque essai fabrique sa cible, et pose ses deux premisses
 *
 * Le voisin etranger du banc est **partage entre les essais d un meme processus, et la base survit
 * aux passages** : un essai qui donne une alliance a un joueur laisse cette alliance derriere lui, et
 * le voisin le plus proche de l essai suivant peut etre celui-la. C est arrive, trois passages de
 * suite : la section restait fermee parce que la cible etait devenue une Guerriere, et rien dans
 * l essai ne le disait. La cible est donc creee ici, et l essai **exige** qu elle soit sans alliance
 * et sans technologie d espionnage — les deux valeurs dont depend le seuil.
 *
 * ## La cible reste propre, et ce n est pas un detail
 *
 * La chance de contre-espionnage se calcule sur le nombre de vaisseaux du defenseur. Une cible sans
 * vaisseau ne declenche aucune bataille, et la sonde unique arrive : sans cela, la section absente
 * s expliquerait par une sonde detruite autant que par un niveau trop bas.
 */
class AllianceClassEspionageTest extends FleetDispatchTestCase
{
    protected int $missionType = 6; // Espionnage

    protected string $missionName = 'Espionage';

    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('espionage_probe', 5);
        $this->planetAddResources(new Resources(0, 0, 100000, 0));

        // Deux niveaux : le seuil des batiments en demande trois, et le bonus d'alliance en donne un.
        $this->playerSetResearchLevel('espionage_technology', 2);

        $settings = resolve(SettingsService::class);
        $settings->set('economy_speed', 8);
        $settings->set('fleet_speed_war', 1);
        $settings->set('fleet_speed_holding', 1);
        $settings->set('fleet_speed_peaceful', 1);
    }

    protected function messageCheckMissionArrival(): void
    {
        // Sans objet ici : ce banc lit le rapport, pas les messages.
    }

    protected function messageCheckMissionReturn(): void
    {
        // Sans objet ici.
    }

    /**
     * **Sans alliance, le rapport n ouvre pas la section des batiments ; avec une alliance de
     * Guerriers, il l ouvre.**
     *
     * Les deux moities sont dans le meme essai, sur **la meme cible** : la premiere etablit que la
     * section manque bien pour la raison qu on croit, la seconde que c est le bonus qui la fait
     * apparaitre — et rien d autre n a change entre les deux.
     */
    public function testAWarriorsAllianceRevealsOneMoreSectionOfTheReport(): void
    {
        $this->basicSetup();

        $cible = $this->uneCibleNeuve();

        $this->assertNull(
            $this->rapportDUnEspionnageVers($cible)->buildings,
            'Le rapport revele deja les batiments sans bonus d alliance : le seuil choisi ne distingue plus rien.'
        );

        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        $this->assertNotNull(
            $this->rapportDUnEspionnageVers($cible)->buildings,
            'Le +1 niveau d espionnage de l alliance de Guerriers n ouvre pas la section des batiments.'
        );
    }

    /**
     * **Une alliance de Commercants n ouvre rien.**
     *
     * Sans ce temoin, un bonus rendu par toutes les classes passerait le premier essai.
     */
    public function testATradersAllianceAddsNoEspionageLevel(): void
    {
        $this->basicSetup();

        $cible = $this->uneCibleNeuve();
        $this->uneAllianceDeClasse(AllianceClass::TRADERS);

        $this->assertNull(
            $this->rapportDUnEspionnageVers($cible)->buildings,
            'Une alliance de Commercants donne un niveau d espionnage, que sa classe ne promet pas.'
        );
    }

    /**
     * **Le bonus vaut aussi pour le defenseur** : une cible dans une alliance de Guerriers referme
     * une section que l attaquant ouvrait.
     *
     * L attaquant est pose a 3 niveaux, la cible a 0. Le seuil des batiments demande
     * `3 - 3 >= niveauDefenseur` : vrai contre 0, faux contre 1. Le seul changement entre les deux
     * mesures est l alliance de la cible.
     */
    public function testADefenderInAWarriorsAllianceClosesASectionOfTheReport(): void
    {
        $this->basicSetup();
        $this->playerSetResearchLevel('espionage_technology', 3);

        $cible = $this->uneCibleNeuve();

        $this->assertNotNull(
            $this->rapportDUnEspionnageVers($cible)->buildings,
            'La section des batiments est deja fermee contre une cible sans alliance : le seuil ne distingue rien.'
        );

        $this->faireFonderUneAllianceDeGuerriersA($cible);

        $this->assertNull(
            $this->rapportDUnEspionnageVers($cible)->buildings,
            'Le +1 niveau d espionnage ne protege pas le defenseur : sa section reste ouverte.'
        );
    }

    /**
     * Envoyer une sonde sur la cible, laisser arriver, et rendre le rapport ecrit.
     */
    private function rapportDUnEspionnageVers(PlanetService $cible): EspionageReport
    {
        $avant = (int)(EspionageReport::query()->max('id') ?? 0);

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 1);

        $this->dispatchFleet($cible->getPlanetCoordinates(), $flotte, new Resources(0, 0, 0, 0), PlanetType::Planet);
        $this->exigerUneCibleSansEspionnage($cible);

        // **Avancer depuis l horloge, pas depuis la planete.** La cible est la meme d un envoi a
        // l autre : son horodatage est celui de sa creation, et repartir de lui ferait reculer le
        // temps au second passage — la sonde ne serait jamais arrivee.
        $this->travelTo(now()->copy()->addHours(10));
        $this->get('/overview')->assertStatus(200);

        $rapport = EspionageReport::query()->where('id', '>', $avant)->orderByDesc('id')->first();

        $this->assertNotNull($rapport, 'Aucun rapport d espionnage n a ete ecrit : la sonde n est pas arrivee.');

        return $rapport;
    }

    /**
     * **La premisse est posee, pas esperee.**
     *
     * Le seuil ne depend, du cote de la cible, que de sa technologie d espionnage et du bonus que lui
     * donnerait son alliance. L essai exige la valeur qu il suppose, sinon un echec dirait « le bonus
     * ne marche pas » alors que la cible aurait simplement change.
     */
    private function exigerUneCibleSansEspionnage(PlanetService $cible): void
    {
        $defenseur = $cible->getPlayer();

        $this->assertNotNull($defenseur, 'La planete visee n a pas de proprietaire.');
        $this->assertSame(0, $defenseur->getResearchLevel('espionage_technology'), 'La cible a une technologie d espionnage.');
    }

    /**
     * Une planete neuve, appartenant a un joueur cree pour cet essai et sans alliance.
     */
    private function uneCibleNeuve(): PlanetService
    {
        $etranger = User::factory()->create(['username' => 'espion_' . Str::random(16)]);

        // Le crochet `created` du modele promeut le premier utilisateur d'une transaction en admin,
        // et une planete d'administrateur ne s'espionne pas.
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
        $this->assertNull($etranger->alliance_id, 'La cible neuve appartient deja a une alliance.');

        return $planete;
    }

    /**
     * La cible fonde son alliance et lui donne la classe des Guerriers.
     */
    private function faireFonderUneAllianceDeGuerriersA(PlanetService $cible): void
    {
        $defenseur = $cible->getPlayer();

        $this->assertNotNull($defenseur, 'La planete visee n a pas de proprietaire.');

        $alliance = resolve(AllianceService::class)->createAlliance(
            $defenseur->getId(),
            'DE' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Defense ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->assertNotNull($alliance);

        DB::table('users')->where('id', $defenseur->getId())->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);

        resolve(AllianceClassService::class)->choose(
            User::query()->findOrFail($defenseur->getId()),
            $alliance,
            AllianceClass::WARRIORS
        );
    }

    private function uneAllianceDeClasse(AllianceClass $classe): Alliance
    {
        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'ES' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Espionnage ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->assertNotNull($alliance);

        // Une alliance fondee a l instant n'a pas les quatorze jours qui offrent le premier choix.
        DB::table('users')->where('id', $this->currentUserId)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);

        resolve(AllianceClassService::class)->choose(
            User::query()->findOrFail($this->currentUserId),
            $alliance,
            $classe
        );

        return $alliance;
    }
}
