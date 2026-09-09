<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet;
use OGame\Models\SurveillanceContact;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\SurveillanceTier;
use OGame\Patrol\SurveillanceProjection;
use OGame\Patrol\SurveillanceWatch;
use Tests\AccountTestCase;

/**
 * Ce que le serveur envoie d une patrouille etrangere, et ce qu il n envoie pas.
 *
 * ## Absent, jamais masque
 *
 * Ce temoin verifie des **clefs**, non des valeurs. Un fait interdit qui voyagerait a `null` serait
 * dans la reponse, donc chez le lecteur, et le cacher a l ecran ne protegerait rien. C est la seule
 * lecture qui distingue une protection d une politesse.
 *
 * Les roles sont inverses par rapport a `SurveillanceWatchTest` : ici le joueur du banc **possede le
 * detecteur** et lit, et c est une patrouille etrangere qui est observee.
 */
class SurveillanceProjectionTest extends AccountTestCase
{
    /**
     * @var array<int, int>
     */
    private array $corpsPoses = [];

    /**
     * @var array<int, int>
     */
    private array $patrouillesPosees = [];

    protected function tearDown(): void
    {
        if ($this->patrouillesPosees !== []) {
            SurveillanceContact::query()->whereIn('patrol_id', $this->patrouillesPosees)->delete();
            FleetMission::query()->whereIn('patrol_id', $this->patrouillesPosees)->delete();
            Patrol::query()->whereIn('id', $this->patrouillesPosees)->delete();
            $this->patrouillesPosees = [];
        }

        if ($this->corpsPoses !== []) {
            SurveillanceContact::query()->whereIn('observer_planet_id', $this->corpsPoses)->delete();
            Planet::query()->whereIn('id', $this->corpsPoses)->delete();
            $this->corpsPoses = [];
        }

        parent::tearDown();
    }

    /**
     * Le detecteur du joueur du banc, dans son propre systeme.
     */
    private function monDetecteur(int $niveau): int
    {
        DB::table('planets')
            ->where('id', $this->planetService->getPlanetId())
            ->update(['surveillance_network' => $niveau]);

        return (int)$this->planetService->getPlanetId();
    }

    /**
     * Une patrouille etrangere posee dans mon systeme, avec son segment et ses vaisseaux.
     *
     * @return array{Patrol, int}
     */
    private function unePatrouilleEtrangere(int $croiseurs, bool $enRoute = false, bool $quitteLeSysteme = false): array
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId(), 'La patrouille appartient au lecteur : le cas ne prouverait rien.');

        $coords = $this->planetService->getPlanetCoordinates();
        $entree = 1_700_100_000;

        $patrouille = Patrol::query()->create([
            'user_id' => $proprietaire->getId(),
            'home_planet_id' => $etrangere->getPlanetId(),
            'state' => ($enRoute ? PatrolState::EnRoute : PatrolState::Stationed)->value,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'x' => 640,
            'y' => 480,
            'fuel_reserve' => 5000,
            'upkeep_paid_at' => $entree,
            'stationed_since' => $entree,
            'entered_system_at' => $entree,
            'order_version' => 1,
        ]);

        $this->patrouillesPosees[] = (int)$patrouille->id;

        // `FleetMission` n est pas assignable en masse : `forceFill` est l idiome du depot.
        $segment = (new FleetMission())->forceFill([
            'user_id' => $proprietaire->getId(),
            'planet_id_from' => $etrangere->getPlanetId(),
            'mission_type' => 11,
            'time_departure' => $entree - 100,
            'time_arrival' => $entree,
            'galaxy_from' => $coords->galaxy,
            'system_from' => $coords->system,
            'position_from' => $coords->position,
            'galaxy_to' => $quitteLeSysteme ? $coords->galaxy : $coords->galaxy,
            'system_to' => $quitteLeSysteme ? $coords->system + 1 : $coords->system,
            'position_to' => $coords->position,
            'x_to' => 700,
            'y_to' => 500,
            'patrol_id' => $patrouille->id,
            'cruiser' => $croiseurs,
            'processed' => 0,
        ]);
        $segment->save();

        $patrouille->forceFill(['current_mission_id' => $segment->id])->save();
        $patrouille->refresh();

        return [$patrouille, (int)$proprietaire->getId()];
    }

    /**
     * Ouvre le contact et le rend acquis a l instant rendu.
     */
    private function acquisA(Patrol $patrouille, SurveillanceTier $palier): int
    {
        resolve(SurveillanceWatch::class)->acquire($patrouille, (int)$patrouille->entered_system_at);

        return (int)$patrouille->entered_system_at + $palier->acquisitionSeconds();
    }

    private function projection(): SurveillanceProjection
    {
        return resolve(SurveillanceProjection::class);
    }

    /**
     * Toutes les clefs de la charge utile, a toute profondeur.
     *
     * @param array<string, mixed> $charge
     * @return array<int, string>
     */
    private function toutesLesClefs(array $charge): array
    {
        $clefs = [];

        foreach ($charge as $clef => $valeur) {
            $clefs[] = (string)$clef;

            if (is_array($valeur)) {
                $clefs = array_merge($clefs, $this->toutesLesClefs($valeur));
            }
        }

        return $clefs;
    }

    /**
     * Au premier palier, tout ce que les paliers suivants donnent est **absent**, non vide.
     */
    public function testAtTheFirstTierTheHigherFactsHaveNoKeyAtAll(): void
    {
        $this->monDetecteur(SurveillanceTier::Contact->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Contact);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $this->assertCount(1, $vues, 'La patrouille etrangere acquise n apparait pas.');
        $vue = $vues[0];

        $this->assertArrayHasKey('position', $vue, 'Le premier palier ne donne meme pas la position.');

        foreach (['owner', 'heading', 'size_estimate', 'strength'] as $interdit) {
            $this->assertArrayNotHasKey(
                $interdit,
                $vue,
                'Le fait « ' . $interdit . ' » voyage au premier palier : envoye puis cache n est pas protege.'
            );
        }
    }

    /**
     * Composition, reserve et cargaison ne voyagent a aucun palier, jusqu au plus eleve.
     */
    public function testCompositionFuelAndCargoTravelAtNoTierAtAll(): void
    {
        $this->monDetecteur(SurveillanceTier::Strength->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Strength);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $this->assertCount(1, $vues);
        $clefs = $this->toutesLesClefs($vues[0]);

        foreach (['cruiser', 'units', 'composition', 'fuel', 'fuel_reserve', 'cargo', 'metal', 'crystal', 'deuterium'] as $interdit) {
            $this->assertNotContains(
                $interdit,
                $clefs,
                'Le palier maximal livre « ' . $interdit . ' » : la surveillance deviendrait un espionnage sans sonde.'
            );
        }

        // Ce que le palier maximal donne, en revanche : l effectif, sans sa composition.
        $this->assertSame(20, $vues[0]['strength']);
    }

    /**
     * Aucun detecteur, aucun renseignement : la reponse est vide, pas degradee.
     */
    public function testWithoutADetectorTheAnswerIsEmpty(): void
    {
        $this->monDetecteur(0);
        [$patrouille] = $this->unePatrouilleEtrangere(20);
        resolve(SurveillanceWatch::class)->acquire($patrouille, (int)$patrouille->entered_system_at);

        $coords = $this->planetService->getPlanetCoordinates();
        $tard = (int)$patrouille->entered_system_at + 86_400;

        $this->assertSame(
            [],
            $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $tard),
            'Un joueur sans detecteur recoit un renseignement de surveillance.'
        );
    }

    /**
     * Perdre la couverture retire le renseignement des la demande suivante, sans rechargement.
     */
    public function testLosingCoverageRemovesTheIntelligenceOnTheVeryNextCall(): void
    {
        $detecteur = $this->monDetecteur(SurveillanceTier::Strength->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Strength);

        $coords = $this->planetService->getPlanetCoordinates();
        $avant = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);
        $this->assertCount(1, $avant, 'La premisse manque : rien n etait visible avant la perte.');

        // Le reseau tombe. Aucun rechargement, aucun evenement : la demande suivante suffit.
        DB::table('planets')->where('id', $detecteur)->update(['surveillance_network' => 0]);
        resolve(SurveillanceWatch::class)->networkLevelChanged($detecteur, 0, $maintenant);

        $this->assertSame(
            [],
            $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant),
            'Le renseignement survit a la perte de la couverture qui seule l autorisait.'
        );
    }

    /**
     * Une reponse ancienne se reconnait a l instant de son calcul.
     *
     * Le serveur ne renvoie jamais ce qui est revoque ; le risque restant est qu une reponse partie
     * avant la revocation arrive apres elle. La charge porte donc l instant de son calcul, seul
     * moyen pour le lecteur de refuser une reponse plus ancienne que celle qu il affiche deja.
     */
    public function testEveryAnswerCarriesTheInstantItWasComputed(): void
    {
        $this->monDetecteur(SurveillanceTier::Identity->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Identity);

        $coords = $this->planetService->getPlanetCoordinates();
        $tot = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);
        $tard = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant + 60);

        $this->assertSame($maintenant, $tot[0]['computed_at']);
        $this->assertSame($maintenant + 60, $tard[0]['computed_at']);
        $this->assertGreaterThan($tot[0]['computed_at'], $tard[0]['computed_at'], 'Deux reponses successives ne se distinguent pas : une ancienne pourrait rehabiller ce qui vient d etre revoque.');
    }

    /**
     * Le troisieme palier ne livre pas une destination hors du systeme observe.
     */
    public function testTheThirdTierNeverDisclosesADestinationOutsideTheWatchedSystem(): void
    {
        $this->monDetecteur(SurveillanceTier::Heading->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20, true, true);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Heading);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $this->assertCount(1, $vues);
        $cap = $vues[0]['heading'];

        $this->assertTrue($cap['moving']);
        $this->assertTrue($cap['leaves_system'], 'La patrouille sort du systeme et le cap ne le dit pas.');
        $this->assertArrayNotHasKey(
            'towards',
            $cap,
            'La destination hors couverture est livree : voir sans detecteur, sous le nom de « destination ».'
        );
    }

    /**
     * Dans le systeme observe, en revanche, le troisieme palier donne le point vise.
     */
    public function testTheThirdTierGivesThePointAimedAtInsideTheWatchedSystem(): void
    {
        $this->monDetecteur(SurveillanceTier::Heading->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20, true, false);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Heading);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $cap = $vues[0]['heading'];

        $this->assertFalse($cap['leaves_system']);
        $this->assertSame(['x' => 700, 'y' => 500], $cap['towards'], 'Le point vise dans le systeme observe n est pas donne.');
    }

    /**
     * Par la vraie route de la Galaxie : la surveillance arrive, et aucune autre couche ne la double.
     *
     * ## Ce que la classe seule ne prouve pas
     *
     * Une projection juste que le service n appelle pas laisse le jeu ou il etait ; et une projection
     * appelee qui cohabite avec une autre couche revelant les memes faits ne protege rien. Ce temoin
     * demande donc la reponse au serveur et la lit entiere : la clef `surveillance` porte le contact,
     * et la couche `movements` — qui sert dans la meme reponse — n en dit pas un mot.
     */
    public function testTheRealGalaxyAnswerCarriesTheContactAndNoOtherLayerDoes(): void
    {
        $this->monDetecteur(SurveillanceTier::Identity->value);
        [$patrouille, $etranger] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Identity);

        $coords = $this->planetService->getPlanetCoordinates();
        Date::setTestNow(Date::createFromTimestamp($maintenant));

        $reponse = $this->getJson(route('galaxy.fleets', ['galaxy' => $coords->galaxy, 'system' => $coords->system]));
        $reponse->assertStatus(200);

        $charge = $reponse->json();

        $this->assertArrayHasKey('surveillance', $charge, 'La reponse de la Galaxie ne porte pas la surveillance : la projection n est pas branchee.');
        $this->assertCount(1, $charge['surveillance'], 'Le contact acquis n arrive pas au navigateur.');
        $this->assertSame($etranger, $charge['surveillance'][0]['owner']['id']);

        // **Aucune autre couche ne dit la meme chose.** La patrouille etrangere ne doit apparaitre
        // ni dans les mouvements, ni dans la liste des patrouilles du joueur.
        $enJson = json_encode($charge['movements']);
        $this->assertIsString($enJson);
        $this->assertStringNotContainsString('"' . $patrouille->id . '"', $enJson);

        foreach ($charge['patrols'] as $sienne) {
            $this->assertNotSame((int)$patrouille->id, (int)$sienne['id'], 'La patrouille etrangere figure parmi celles du joueur.');
        }
    }

    /**
     * Par la vraie route, sans detecteur : la clef existe et elle est vide.
     *
     * Vide plutot qu absente : le navigateur doit pouvoir remplacer ce qu il affiche par rien. Une
     * clef manquante le laisserait garder l ancien contenu, ce qui est exactement la faute que la
     * revocation doit empecher.
     */
    public function testTheRealGalaxyAnswerIsEmptyWithoutADetector(): void
    {
        $this->monDetecteur(0);
        [$patrouille] = $this->unePatrouilleEtrangere(20);
        resolve(SurveillanceWatch::class)->acquire($patrouille, (int)$patrouille->entered_system_at);

        $coords = $this->planetService->getPlanetCoordinates();
        Date::setTestNow(Date::createFromTimestamp((int)$patrouille->entered_system_at + 86_400));

        $reponse = $this->getJson(route('galaxy.fleets', ['galaxy' => $coords->galaxy, 'system' => $coords->system]));
        $reponse->assertStatus(200);

        $charge = $reponse->json();

        $this->assertArrayHasKey('surveillance', $charge, 'La clef disparait sans detecteur : le navigateur garderait son ancien contenu.');
        $this->assertSame([], $charge['surveillance'], 'Un joueur sans detecteur recoit un renseignement par la vraie route.');
    }

    /**
     * Le premier palier tient la position a jour, y compris quand la patrouille bouge.
     */
    public function testTheFirstTierKeepsThePositionEvenWhileTheContactMoves(): void
    {
        $this->monDetecteur(SurveillanceTier::Contact->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20, true, false);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Contact);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $this->assertSame(['galaxy' => $coords->galaxy, 'system' => $coords->system, 'x' => 640, 'y' => 480], $vues[0]['position']);

        // Elle se deplace : la position suit, sans que le palier change.
        DB::table('patrols')->where('id', $patrouille->id)->update(['x' => 900, 'y' => 100]);

        $apres = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $this->assertSame(900, $apres[0]['position']['x'], 'La position n est pas tenue a jour : suivre un contact puis lui refuser sa position serait se contredire.');
        $this->assertSame(100, $apres[0]['position']['y']);
    }
}
