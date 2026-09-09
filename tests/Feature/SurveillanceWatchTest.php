<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Models\Patrol;
use OGame\Models\Planet;
use OGame\Models\SurveillanceContact;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\SurveillanceTier;
use OGame\Patrol\SurveillanceWatch;
use OGame\Services\PlanetService;
use Tests\AccountTestCase;

/**
 * Ce que les reseaux apprennent d une patrouille etrangere, et ce qu ils oublient.
 *
 * ## Le monde est fabrique, jamais emprunte
 *
 * La base d un processus est partagee : chercher « une planete equipee dans ce systeme » rendrait
 * l essai dependant de ses voisins, et il passerait ou echouerait par accident de position. Chaque
 * epreuve pose donc ses propres corps a des positions qu elle verifie libres, et les retire.
 */
class SurveillanceWatchTest extends AccountTestCase
{
    /**
     * Les corps que cet essai a poses, retires au demontage.
     *
     * @var array<int, int>
     */
    private array $corpsPoses = [];

    protected function tearDown(): void
    {
        if ($this->corpsPoses !== []) {
            SurveillanceContact::query()->whereIn('observer_planet_id', $this->corpsPoses)->delete();
            Planet::query()->whereIn('id', $this->corpsPoses)->delete();
            $this->corpsPoses = [];
        }

        parent::tearDown();
    }

    /**
     * Une position libre de ce systeme, ou l essai s arrete en le disant.
     */
    private function positionLibreDans(int $galaxie, int $systeme): int
    {
        $prises = DB::table('planets')
            ->where('galaxy', $galaxie)
            ->where('system', $systeme)
            ->pluck('planet')
            ->map(static fn ($position): int => (int)$position)
            ->all();

        for ($position = 1; $position <= 15; $position++) {
            if (!in_array($position, $prises, true)) {
                return $position;
            }
        }

        $this->fail('The system is full: this test cannot place the body it needs.');
    }

    /**
     * Pose un corps de ce joueur dans ce systeme, avec ce niveau de reseau.
     */
    private function unCorpsEquipe(int $userId, int $galaxie, int $systeme, int $niveau): int
    {
        $planete = Planet::factory()->create([
            'user_id' => $userId,
            'galaxy' => $galaxie,
            'system' => $systeme,
            'planet' => $this->positionLibreDans($galaxie, $systeme),
            'surveillance_network' => $niveau,
        ]);

        $this->corpsPoses[] = (int)$planete->id;

        return (int)$planete->id;
    }

    /**
     * Une patrouille de ce joueur, entree dans ce systeme a cet instant.
     */
    private function unePatrouilleEntree(int $userId, int $galaxie, int $systeme, int $entree): Patrol
    {
        return Patrol::query()->create([
            'user_id' => $userId,
            'home_planet_id' => $this->planetService->getPlanetId(),
            'state' => PatrolState::Stationed->value,
            'galaxy' => $galaxie,
            'system' => $systeme,
            'x' => 0,
            'y' => 0,
            'fuel_reserve' => 1000,
            'upkeep_paid_at' => $entree,
            'stationed_since' => $entree,
            'entered_system_at' => $entree,
            'order_version' => 1,
        ]);
    }

    /**
     * Le systeme d une planete etrangere, et son proprietaire.
     *
     * @return array{PlanetService, int, int, int}
     */
    private function unSystemeEtranger(): array
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId(), 'The « foreign » system belongs to the patrol owner.');

        $coordonnees = $etrangere->getPlanetCoordinates();

        return [$etrangere, $proprietaire->getId(), $coordonnees->galaxy, $coordonnees->system];
    }

    /**
     * Un corps etranger equipe ouvre un contact ; l echeance derive de son palier.
     */
    public function testAnEquippedForeignBodyOpensAContactWhoseDeadlineComesFromItsTier(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Heading->value);
        $entree = 1_700_000_000;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        resolve(SurveillanceWatch::class)->acquire($patrouille, $entree);

        $contact = SurveillanceContact::query()
            ->where('observer_planet_id', $observateur)
            ->where('patrol_id', $patrouille->id)
            ->first();

        $this->assertNotNull($contact, 'An equipped foreign body opened no contact.');
        $this->assertSame($entree, (int)$contact->entered_system_at);
        $this->assertSame(
            $entree + SurveillanceTier::Heading->acquisitionSeconds(),
            (int)$contact->visible_from,
            'The deadline does not come from the tier of the observing body.'
        );
        $this->assertSame($etranger, (int)$contact->observer_user_id);

        // Acquis plus tard, pas tout de suite : une patrouille qui traverse ne se voit pas.
        $this->assertFalse($contact->isVisibleAt($entree));
        $this->assertFalse($contact->isVisibleAt($entree + SurveillanceTier::Heading->acquisitionSeconds() - 1));
        $this->assertTrue($contact->isVisibleAt($entree + SurveillanceTier::Heading->acquisitionSeconds()));
    }

    /**
     * Aucun detecteur, aucun renseignement — et un corps sans reseau n en est pas un.
     */
    public function testABodyWithoutANetworkLearnsNothing(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $sansReseau = $this->unCorpsEquipe($etranger, $galaxie, $systeme, 0);
        $entree = 1_700_000_100;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        resolve(SurveillanceWatch::class)->acquire($patrouille, $entree);

        $this->assertSame(
            0,
            SurveillanceContact::query()->where('observer_planet_id', $sansReseau)->count(),
            'A body with no network opened a contact: the intelligence does not depend on the building.'
        );

        $this->assertNull(
            resolve(SurveillanceWatch::class)->bestTierOf($etranger, $galaxie, $systeme),
            'A player with no network in the system is granted a tier.'
        );
    }

    /**
     * Le joueur ne s observe pas lui-meme : ses propres patrouilles ne dependent d aucun batiment.
     */
    public function testTheOwnerDoesNotOpenAContactOnHisOwnPatrol(): void
    {
        [, , $galaxie, $systeme] = $this->unSystemeEtranger();

        $sien = $this->unCorpsEquipe((int)$this->currentUserId, $galaxie, $systeme, SurveillanceTier::Strength->value);
        $entree = 1_700_000_200;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        resolve(SurveillanceWatch::class)->acquire($patrouille, $entree);

        $this->assertSame(
            0,
            SurveillanceContact::query()->where('observer_planet_id', $sien)->count(),
            'The owner opened a contact on his own patrol: a fact that depends on no building would start depending on one.'
        );
    }

    /**
     * Deux acquisitions pour la meme entree n ouvrent qu un contact.
     */
    public function testAcquiringTwiceOpensNothingMore(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Contact->value);
        $entree = 1_700_000_300;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        $veille = resolve(SurveillanceWatch::class);
        $veille->acquire($patrouille, $entree);
        $veille->acquire($patrouille, $entree + 60);

        $this->assertSame(
            1,
            SurveillanceContact::query()->where('observer_planet_id', $observateur)->where('patrol_id', $patrouille->id)->count(),
            'A second acquisition opened a second contact: the deadline would restart at every pass.'
        );
    }

    /**
     * Perdre le reseau retire le renseignement qu il seul autorisait.
     */
    public function testLosingTheNetworkRevokesWhatItAlonePermitted(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Estimate->value);
        $entree = 1_700_000_400;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        $veille = resolve(SurveillanceWatch::class);
        $veille->acquire($patrouille, $entree);

        $apres = $entree + SurveillanceTier::Estimate->acquisitionSeconds() + 10;
        $veille->networkLevelChanged($observateur, 0, $apres);

        $contact = SurveillanceContact::query()->where('observer_planet_id', $observateur)->firstOrFail();

        $this->assertSame($apres, (int)$contact->revoked_at, 'The contact was not revoked when the network fell.');
        $this->assertFalse($contact->isVisibleAt($apres), 'A revoked contact is still readable: what the demolition must take back stayed.');
    }

    /**
     * Une amelioration peut reveler un contact immediatement.
     */
    public function testAnUpgradeCanRevealAContactAtOnce(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Contact->value);
        $entree = 1_700_000_500;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        $veille = resolve(SurveillanceWatch::class);
        $veille->acquire($patrouille, $entree);

        // Cinq minutes plus tard : trop tot pour le palier 1, deja tard pour le palier 5.
        $maintenant = $entree + 5 * 60;
        $this->assertFalse(SurveillanceContact::query()->where('observer_planet_id', $observateur)->firstOrFail()->isVisibleAt($maintenant));

        DB::table('planets')->where('id', $observateur)->update(['surveillance_network' => SurveillanceTier::Strength->value]);
        $veille->networkLevelChanged($observateur, SurveillanceTier::Strength->value, $maintenant);

        $contact = SurveillanceContact::query()->where('observer_planet_id', $observateur)->firstOrFail();

        $this->assertSame(
            $entree + SurveillanceTier::Strength->acquisitionSeconds(),
            (int)$contact->visible_from,
            'The upgrade did not recompute the deadline from the entry.'
        );
        $this->assertTrue($contact->isVisibleAt($maintenant), 'The upgrade did not reveal the contact at once.');
    }

    /**
     * La patrouille qui s en va cesse d etre vue, et ce qui a ete su reste lisible.
     */
    public function testAPatrolThatLeavesStopsBeingSeen(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Identity->value);
        $entree = 1_700_000_600;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        $veille = resolve(SurveillanceWatch::class);
        $veille->acquire($patrouille, $entree);

        $depart = $entree + SurveillanceTier::Identity->acquisitionSeconds() + 30;
        $this->assertSame(1, $veille->revokeAllFor($patrouille, $depart));

        $contact = SurveillanceContact::query()->where('observer_planet_id', $observateur)->firstOrFail();

        $this->assertSame($depart, (int)$contact->revoked_at);
        $this->assertFalse($contact->isVisibleAt($depart));

        // Revoque, pas efface : la ligne reste lisible pour un audit.
        $this->assertSame(1, SurveillanceContact::query()->where('observer_planet_id', $observateur)->count());
    }

    /**
     * Un reseau construit apres l arrivee acquiert depuis sa mise en service, jamais depuis l entree.
     *
     * Sans cette regle, batir un capteur a onze heures revelerait d emblee une patrouille posee a
     * dix : l entree plus le delai serait deja echue, et l acquisition n aurait jamais eu lieu.
     */
    public function testANetworkBuiltAfterThePatrolAcquiresFromItsCommissioning(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $entree = 1_700_001_000;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);

        // Le corps n a rien au moment ou la patrouille se pose.
        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, 0);
        resolve(SurveillanceWatch::class)->acquire($patrouille, $entree);

        $this->assertSame(0, SurveillanceContact::query()->where('observer_planet_id', $observateur)->count(), 'Un corps sans reseau a ouvert un contact.');

        // Une heure plus tard, le reseau entre en service.
        $miseEnService = $entree + 3600;
        DB::table('planets')->where('id', $observateur)->update(['surveillance_network' => SurveillanceTier::Contact->value]);
        $ouverts = resolve(SurveillanceWatch::class)->commission($observateur, SurveillanceTier::Contact->value, $miseEnService);

        $this->assertSame(1, $ouverts, 'La mise en service n a ouvert aucun contact sur une patrouille pourtant presente.');

        $contact = SurveillanceContact::query()->where('observer_planet_id', $observateur)->firstOrFail();

        $this->assertSame($entree, (int)$contact->entered_system_at, 'Le contact a oublie quand la patrouille est entree.');
        $this->assertSame($miseEnService, (int)$contact->acquisition_from, 'L acquisition ne part pas de la mise en service.');
        $this->assertSame(
            $miseEnService + SurveillanceTier::Contact->acquisitionSeconds(),
            (int)$contact->visible_from,
            'L echeance a ete calculee depuis l entree : le capteur aurait observe avant d exister.'
        );

        // Et elle n est pas deja echue au moment ou le capteur s allume.
        $this->assertFalse($contact->isVisibleAt($miseEnService), 'Le contact est visible des l allumage du capteur.');
    }

    /**
     * Une amelioration recalcule depuis le depart d acquisition, et ne relance pas l horloge.
     */
    public function testAnUpgradeRecomputesFromTheAcquisitionStartNotTheEntry(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $entree = 1_700_002_000;
        $patrouille = $this->unePatrouilleEntree((int)$this->currentUserId, $galaxie, $systeme, $entree);
        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, 0);

        $miseEnService = $entree + 3600;
        DB::table('planets')->where('id', $observateur)->update(['surveillance_network' => SurveillanceTier::Contact->value]);
        resolve(SurveillanceWatch::class)->commission($observateur, SurveillanceTier::Contact->value, $miseEnService);

        // L amelioration survient plus tard encore : elle raccourcit le delai, elle ne redemarre rien.
        $amelioration = $miseEnService + 600;
        DB::table('planets')->where('id', $observateur)->update(['surveillance_network' => SurveillanceTier::Strength->value]);
        resolve(SurveillanceWatch::class)->networkLevelChanged($observateur, SurveillanceTier::Strength->value, $amelioration);

        $contact = SurveillanceContact::query()->where('observer_planet_id', $observateur)->firstOrFail();

        $this->assertSame(
            $miseEnService + SurveillanceTier::Strength->acquisitionSeconds(),
            (int)$contact->visible_from,
            'L amelioration a relance l horloge depuis elle-meme au lieu de partir du depart d acquisition.'
        );
        $this->assertTrue($contact->isVisibleAt($amelioration), 'L amelioration n a pas revele le contact aussitot.');
    }

    /**
     * Le meilleur reseau du systeme gouverne, jamais la somme de plusieurs.
     */
    public function testTheBestNetworkOfTheSystemGoverns(): void
    {
        [, $etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Identity->value);
        $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Identity->value);

        // Deux reseaux de niveau 2 valent un niveau 2, pas un niveau 4.
        $this->assertSame(
            SurveillanceTier::Identity,
            resolve(SurveillanceWatch::class)->bestTierOf($etranger, $galaxie, $systeme),
            'Two networks added up instead of the best one governing.'
        );

        $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Estimate->value);

        $this->assertSame(
            SurveillanceTier::Estimate,
            resolve(SurveillanceWatch::class)->bestTierOf($etranger, $galaxie, $systeme),
            'The best network does not govern.'
        );
    }
}
