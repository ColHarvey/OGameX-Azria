<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformAvailability;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\FleetMission;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Les deux effets que Keven a tranchés le 19 septembre 2026** (journal §165) : le Bio-modificateur des Kaelesh agrandit
 * la planète de deux cases par niveau (règle officielle, documentée par les forums Gameforge), et le Pilote automatique à
 * fronde des Mechas rend une part du carburant au rappel.
 *
 * Le remboursement suit la décision, mot pour mot : Azria rend déjà la moitié de la consommation à tout retour, la fronde
 * ne porte donc que sur la moitié restante, et d autant moins que le trajet est déjà fait —
 * `part = consommation / 2 × taux × (trajet restant)`. Le deutérium est crédité quand la flotte rentre, avec le retour.
 */
final class LifeformPlanetFieldsAndRecallRefundTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    /** Bio-modificateur (Kaelesh) : 2 cases de planete par niveau. */
    private const int BIO_MODIFIER = 14109;

    /** Pilote automatique a fronde (Mechas) : 0,15 % du carburant par niveau, plafond 90 %. */
    private const int SLINGSHOT_AUTOPILOT = 13210;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->forgetHeldLifeformPlanets();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * Le Bio-modificateur ajoute deux cases par niveau a SA planete : le maximum de la planete les porte, la file des
     * batiments classiques les accepte, une lune du compte n en gagne aucune, et la fiche les annonce en cases, pas en
     * pour cent.
     */
    public function testTheBioModifierAddsTwoPlanetFieldsPerLevel(): void
    {
        $planete = $this->planetService;
        $avant = $planete->getPlanetFieldMax();
        $this->assertGreaterThan(0, $avant);
        $lune = resolve(PlanetServiceFactory::class)->createMoonForPlanet($planete, 2000000, 20);
        $lunaireAvant = $lune->getPlanetFieldMax();

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
        $this->assertSame($avant, resolve(PlanetServiceFactory::class)->make($this->currentPlanetId, true)?->getPlanetFieldMax(), 'Une espece sans Bio-modificateur n ajoute aucune case.');

        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::BIO_MODIFIER, 10);
        LifeformBonusCache::invalidate();
        $this->assertSame(20, resolve(LifeformBonusResolver::class)->planetFieldsOf($this->currentPlanetId), 'Niveau 10 : vingt cases.');
        $this->assertSame($avant + 20, resolve(PlanetServiceFactory::class)->make($this->currentPlanetId, true)?->getPlanetFieldMax(), 'Le maximum de la planete porte les cases.');
        $this->assertSame($lunaireAvant, resolve(PlanetServiceFactory::class)->make($lune->getPlanetId(), true)?->getPlanetFieldMax(), 'Une lune ne porte aucune forme de vie : aucune case.');

        // **Et elle ne lit meme pas** : une lune n ayant jamais de ligne de formes de vie, un maximum sans la garde
        // rendrait le meme nombre — juste et faux coincideraient. Ce qui les separe est la requete, une par lune et par
        // page. Memoire videe d abord : on mesure une lecture froide.
        LifeformBonusCache::invalidate();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $maximumFroid = resolve(PlanetServiceFactory::class)->make($lune->getPlanetId(), true)->getPlanetFieldMax();
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($lunaireAvant, $maximumFroid, 'La lecture froide rend le meme maximum.');
        foreach ($requetes as $requete) {
            $this->assertStringNotContainsString('lifeform_planets', (string)$requete['query'], 'Le maximum d une lune ne consulte pas les formes de vie.');
        }

        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::BIO_MODIFIER, 1);
        LifeformBonusCache::invalidate();
        $this->assertSame(2, resolve(LifeformBonusResolver::class)->planetFieldsOf($this->currentPlanetId), 'Niveau 1 : deux cases.');

        // La fiche du batiment annonce des cases, jamais un pour cent.
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::BIO_MODIFIER, 10);
        LifeformBonusCache::invalidate();
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 500000000.0]);
        $fiche = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::BIO_MODIFIER]))->json('content.technologydetails');
        $this->assertSame(1, preg_match('#data-effect="planet_fields">\s*<td>[^<]*</td>\s*<td[^>]*>20</td>\s*<td[^>]*>22</td>#', $fiche), 'La fiche : 20 cases aujourd hui, 22 au niveau suivant — sans « % ».');
    }

    /**
     * Le Bio-modificateur se construit et ouvre le palier 3 des Kaelesh : l objet n est plus ferme.
     */
    public function testTheBioModifierIsAvailableAgain(): void
    {
        $this->assertSame([], LifeformBonusResolver::NOT_YET_APPLIED, 'Plus aucun effet du catalogue n attend de decision (journal §165).');
        foreach ([self::BIO_MODIFIER, self::SLINGSHOT_AUTOPILOT] as $id) {
            $this->assertTrue(LifeformAvailability::isAvailable(LifeformCatalogue::byId($id)), 'L objet ' . $id . ' est disponible.');
        }
    }

    /**
     * Le Pilote automatique a fronde : la part du carburant que le retour ne rend pas deja, au prorata du trajet restant.
     * Consommation 100 000, niveau 20 (3 %) : rappel immediat, 1 500 de plus ; a mi-chemin, 750 ; sans la technologie, rien.
     */
    public function testTheSlingshotAutopilotRefundsPartOfTheFuelTheRecallDoesNotAlreadyReturn(): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
        $consommation = 100000;

        $sans = $this->leRetourDUnRappel($consommation, 0);
        $this->assertSame((float)intdiv($consommation, 2), (float)$sans->deuterium, 'Sans la technologie : la moitie du carburant, comme tout retour.');

        $this->technologie(self::SLINGSHOT_AUTOPILOT, 20); // 0,15 % par niveau : 3 %
        $this->assertEqualsWithDelta(0.03, resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::RECALL_FUEL_REFUND), 1e-9, 'Niveau 20 : 3 %.');

        $immediat = $this->leRetourDUnRappel($consommation, 0);
        $this->assertSame((float)(intdiv($consommation, 2) + 1500), (float)$immediat->deuterium, 'Rappel immediat : 50 000 + 3 % des 50 000 non rendus.');

        $aMiChemin = $this->leRetourDUnRappel($consommation, 5000);
        $this->assertSame((float)(intdiv($consommation, 2) + 750), (float)$aMiChemin->deuterium, 'A mi-chemin : la moitie du supplement.');

        $arriveeProche = $this->leRetourDUnRappel($consommation, 10000);
        $this->assertSame((float)intdiv($consommation, 2), (float)$arriveeProche->deuterium, 'Le trajet entier fait : plus rien a rendre.');

        // Une consommation impaire : le supplement vaut 1 500,03 — il se tronque, il ne s arrondit pas vers le haut.
        $impair = $this->leRetourDUnRappel(100001, 0);
        $this->assertSame((float)(intdiv(100001, 2) + 1500), (float)$impair->deuterium, '3 % de 50 001 vaut 1 500,03 : le joueur recoit 1 500.');

        // Le plafond du catalogue (90 %) tient : mille niveaux ne rendent pas plus de 90 % de la moitie.
        $this->technologie(self::SLINGSHOT_AUTOPILOT, 1000);
        $this->assertEqualsWithDelta(0.9, resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::RECALL_FUEL_REFUND), 1e-9);
        $plafonne = $this->leRetourDUnRappel($consommation, 0);
        $this->assertSame((float)(intdiv($consommation, 2) + 45000), (float)$plafonne->deuterium, 'Plafond : 90 % des 50 000 non rendus.');
    }

    /**
     * Une colonisation refusee n est pas un rappel : elle ne rend aucun supplement.
     */
    public function testAFailedColonisationRefundsNoSlingshotFuel(): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
        $this->technologie(self::SLINGSHOT_AUTOPILOT, 20);
        $aller = $this->unAllerEnVol(100000, (int)Date::now()->timestamp - 600, (int)Date::now()->timestamp + 600, 7);

        // Le chemin d une colonisation qui echoue : `cancel()` sans rappel joueur.
        GameMissionFactory::getMissionById(7, ['fleetMissionService' => resolve(FleetMissionService::class), 'messageService' => resolve(MessageService::class)])->cancel($aller);
        $retour = FleetMission::query()->where('parent_id', (int)$aller->id)->firstOrFail();
        $this->assertSame((float)intdiv(100000, 2), (float)$retour->deuterium, 'Une colonisation refusee rend la moitie, et rien de plus.');
    }

    /**
     * Le retour qu un RAPPEL joueur cree, la flotte etant a cette part du trajet (en dix-milliemes).
     */
    private function leRetourDUnRappel(int $consommation, int $partDuTrajet): FleetMission
    {
        $maintenant = (int)Date::now()->timestamp;
        $duree = 1000;
        $ecoule = (int)round($duree * $partDuTrajet / 10000);
        $aller = $this->unAllerEnVol($consommation, $maintenant - $ecoule, $maintenant + ($duree - $ecoule));
        resolve(FleetMissionService::class)->cancelMission($aller);

        return FleetMission::query()->where('parent_id', (int)$aller->id)->firstOrFail();
    }

    private function unAllerEnVol(int $consommation, int $depart, int $arrivee, int $genre = 3): FleetMission
    {
        $corps = $this->planetService;
        $cible = $this->getNearbyForeignPlanet();
        $id = (int)DB::table('fleet_missions')->insertGetId([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $corps->getPlanetId(),
            'galaxy_from' => $corps->getPlanetCoordinates()->galaxy,
            'system_from' => $corps->getPlanetCoordinates()->system,
            'position_from' => $corps->getPlanetCoordinates()->position,
            'type_from' => 1,
            'planet_id_to' => $cible->getPlanetId(),
            'galaxy_to' => $cible->getPlanetCoordinates()->galaxy,
            'system_to' => $cible->getPlanetCoordinates()->system,
            'position_to' => $cible->getPlanetCoordinates()->position,
            'type_to' => 1,
            'mission_type' => $genre,
            'time_departure' => $depart,
            'time_arrival' => $arrivee,
            'processed' => 0,
            'canceled' => 0,
            'small_cargo' => 5,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'deuterium_consumption' => $consommation,
        ]);

        return FleetMission::query()->findOrFail($id);
    }

    private function technologie(int $objectId, int $level): void
    {
        $espece = resolve(LifeformInstallationService::class)->speciesOf($this->currentUserId);
        $this->assertNotNull($espece);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 500000000.0]);
        $this->placeLifeformTechnology($this->currentPlanetId, $espece, $objectId, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, $objectId, $level);
        LifeformBonusCache::invalidate();
    }
}
