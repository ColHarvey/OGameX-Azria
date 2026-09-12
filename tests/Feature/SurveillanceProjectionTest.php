<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OGame\Events\FleetMovementChanged;
use OGame\Models\BuddyRequest;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet;
use OGame\Models\SurveillanceContact;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\SurveillanceTier;
use OGame\Patrol\FleetRelation;
use OGame\Patrol\PatrolPricing;
use OGame\Patrol\SurveillanceProjection;
use OGame\Patrol\SurveillanceWatch;
use OGame\Services\SettingsService;
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

    /**
     * Les joueurs dont l alliance ou l amitie a ete posee par un essai, a defaire.
     *
     * @var array<int, int>
     */
    private array $liensPoses = [];

    protected function setUp(): void
    {
        parent::setUp();

        // **Ces essais decrivent un chantier arme.** La veille n ouvre plus de contact quand
        // `patrols_enabled` est baisse : un interrupteur eteint ne produit plus d effet neuf chez
        // les joueurs. L essai pose donc ce qu il suppose.
        resolve(SettingsService::class)->set('patrols_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');

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

        if ($this->liensPoses !== []) {
            DB::table('users')->whereIn('id', $this->liensPoses)->update(['alliance_id' => null]);
            DB::table('buddy_requests')->whereIn('sender_user_id', $this->liensPoses)->delete();
            DB::table('alliances')->where('alliance_tag', 'SURV')->delete();
            $this->liensPoses = [];
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

    /**
     * **Des le premier palier, la route dans le systeme et la relation voyagent.**
     *
     * Decision de Keven, 12 septembre 2026 : tout ce qui se passe sur la carte en temps reel, rouge
     * pour ce qui n est ni ami ni allie. Le temps reel exige la route — les deux bouts et les deux
     * instants —, sans quoi le navigateur ne pourrait qu attendre la reponse suivante. Le temoin lit
     * les deux bouts **champ par champ** : un bout dans le systeme observe est complet, et la
     * relation d un inconnu est « etranger ».
     */
    public function testTheFirstTierCarriesTheRelationAndTheRouteInsideTheSystem(): void
    {
        $this->monDetecteur(SurveillanceTier::Contact->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20, true, false);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Contact);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $this->assertCount(1, $vues);
        $vue = $vues[0];

        $this->assertSame(FleetRelation::STRANGER, $vue['relation'] ?? null, 'Un inconnu n est pas dit etranger : la carte ne saurait pas le peindre en rouge.');
        $this->assertArrayHasKey('segment', $vue, 'Le premier palier ne livre pas la route : le navigateur ne peut pas faire avancer le contact.');

        $segment = $vue['segment'];
        $entree = (int)$patrouille->entered_system_at;
        /** @var FleetMission $mission */
        $mission = $patrouille->currentMission;

        $this->assertSame($entree - 100, $segment['time_departure']);
        $this->assertSame($entree, $segment['time_arrival']);

        // **Le corps de depart n est pas nomme au premier palier** : la vue Galaxie donnerait son
        // proprietaire par la position. Il est reduit a son point, dans la geometrie du systeme.
        $adresse = resolve(PatrolPricing::class)->geometry()->bodyPoint($coords->position);

        $this->assertSame(
            ['galaxy' => $coords->galaxy, 'system' => $coords->system, 'position' => 0, 'type' => PlanetType::SpatialPoint->value, 'x' => $adresse->x, 'y' => $adresse->y],
            $segment['from'],
            'Le bout de depart nomme un corps au premier palier : la vue Galaxie en donnerait le proprietaire.'
        );
        $this->assertSame((int)$mission->type_from, PlanetType::Planet->value, 'La premisse manque : le segment ne part pas d une planete.');
        $this->assertSame(
            ['galaxy' => $coords->galaxy, 'system' => $coords->system, 'position' => $coords->position, 'type' => (int)$mission->type_to, 'x' => 700, 'y' => 500],
            $segment['to'],
            'Le bout d arrivee, dans le systeme observe, n est pas complet.'
        );

        // Et les faits des paliers superieurs restent absents.
        foreach (['owner', 'heading', 'size_estimate', 'strength'] as $interdit) {
            $this->assertArrayNotHasKey($interdit, $vue, 'Le fait « ' . $interdit . ' » voyage au premier palier.');
        }
    }

    /**
     * **Un bout hors du systeme observe est « ailleurs », et rien d autre.**
     *
     * La route d une patrouille qui s en va s arrete au bord : sa destination n est pas livree —
     * ni galaxie, ni systeme, ni position — sous aucune clef. Le temoin exige que `to` porte la
     * seule clef `outside`, et que le systeme de destination n apparaisse nulle part dans la vue.
     */
    public function testARouteLeavingTheSystemWithholdsItsFarEnd(): void
    {
        $this->monDetecteur(SurveillanceTier::Contact->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20, true, true);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Contact);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $segment = $vues[0]['segment'];

        $this->assertSame(['outside' => true], $segment['to'], 'La destination hors couverture est livree avec la route : voir sans detecteur.');
        $this->assertArrayHasKey('galaxy', $segment['from'], 'Le bout de depart, lui, est dans le systeme et doit rester complet.');

        $this->assertStringNotContainsString(
            '"system":' . ($coords->system + 1),
            (string)json_encode($vues[0]),
            'Le systeme de destination apparait quelque part dans la vue.'
        );
    }

    /**
     * **Allie par l alliance, allie par l amitie ; etranger sinon.**
     *
     * Les trois cas dans un meme essai, sur le meme contact : la relation se lit a chaque reponse,
     * et le passage de l un a l autre prouve qu aucun cache ne la retient. L amitie n est comptee
     * qu **acceptee** — une demande en attente ne fait pas un ami.
     */
    public function testTheRelationIsAllyForAnAllianceMateOrABuddyAndStrangerOtherwise(): void
    {
        $this->monDetecteur(SurveillanceTier::Contact->value);
        [$patrouille, $proprietaire] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Contact);
        $coords = $this->planetService->getPlanetCoordinates();
        $moi = (int)$this->currentUserId;

        $relation = fn (): string => (string)$this->projection()->inSystem($moi, $coords->galaxy, $coords->system, $maintenant)[0]['relation'];

        $this->assertSame(FleetRelation::STRANGER, $relation(), 'La premisse manque : sans lien, la relation n est pas « etranger ».');

        $this->liensPoses = [$moi, $proprietaire];

        // Une demande d amitie en attente ne fait pas un ami.
        $demande = BuddyRequest::query()->create([
            'sender_user_id' => $moi,
            'receiver_user_id' => $proprietaire,
            'status' => BuddyRequest::STATUS_PENDING,
        ]);

        $this->assertSame(FleetRelation::STRANGER, $relation(), 'Une demande d amitie en attente suffit a peindre en bleu.');

        // Acceptee, dans le sens inverse de la lecture : l amitie n a pas de sens.
        $demande->forceFill(['sender_user_id' => $proprietaire, 'receiver_user_id' => $moi, 'status' => BuddyRequest::STATUS_ACCEPTED])->save();

        $this->assertSame(FleetRelation::ALLY, $relation(), 'Un ami accepte n est pas dit allie.');

        $demande->delete();

        $this->assertSame(FleetRelation::STRANGER, $relation(), 'L amitie retiree, la relation reste « allie » : quelque chose la retient.');

        // La meme alliance.
        DB::table('alliances')->where('alliance_tag', 'SURV')->delete();
        $alliance = (int)DB::table('alliances')->insertGetId([
            'alliance_tag' => 'SURV',
            'alliance_name' => 'Surveillance',
            'founder_user_id' => $moi,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
        DB::table('users')->whereIn('id', [$moi, $proprietaire])->update(['alliance_id' => $alliance]);

        $this->assertSame(FleetRelation::ALLY, $relation(), 'Un membre de ma propre alliance n est pas dit allie.');

        // Lui dans une alliance, moi dans aucune : ce n est pas la meme.
        DB::table('users')->where('id', $moi)->update(['alliance_id' => null]);

        $this->assertSame(FleetRelation::STRANGER, $relation(), 'Un joueur d une alliance qui n est pas la mienne est dit allie.');
    }

    /**
     * **Un ordre donne a une patrouille observee est annonce a ses observateurs.**
     *
     * Sans cela, la carte de l observateur ignorerait le depart jusqu a la veille suivante. Le
     * temoin exige que l annonce parte **a moi** — l observateur, qui n est ni le proprietaire ni
     * la cible — et, pour la moitie qui compte autant, qu elle ne parte pas a un joueur dont le
     * contact n est pas encore visible : annoncer ce qu on ne voit pas encore serait un renseignement.
     */
    public function testAnOrderGivenToAnObservedPatrolIsAnnouncedToItsObservers(): void
    {
        $this->monDetecteur(SurveillanceTier::Strength->value);
        // **Elle s en va vers un autre systeme** : c est ce qui rend la fuite observable — l annonce
        // du proprietaire porte cette destination, celle de l observateur ne doit pas.
        [$patrouille, $proprietaire] = $this->unePatrouilleEtrangere(20, true, true);
        $this->acquisA($patrouille, SurveillanceTier::Strength);

        // Un observateur dont le contact est **revoque** : il ne doit plus rien apprendre.
        $revoque = User::factory()->create();
        $this->corpsPoses[] = (int)Planet::factory()->create([
            'user_id' => $revoque->id,
            'galaxy' => $this->planetService->getPlanetCoordinates()->galaxy,
            'system' => $this->planetService->getPlanetCoordinates()->system,
            'planet' => 13,
            'surveillance_network' => SurveillanceTier::Strength->value,
        ])->id;

        // Un second observateur, dont le contact n est pas encore visible : un reseau de niveau 1
        // vient d etre mis en service, son acquisition court encore. **Un compte neuf** : la planete
        // « propre » du banc a le meme proprietaire que la voisine, et ce serait la patrouille elle-meme.
        $autreJoueur = User::factory()->create();
        $this->assertNotSame($proprietaire, (int)$autreJoueur->id, 'La premisse manque : le second observateur possede la patrouille.');
        $this->corpsPoses[] = (int)Planet::factory()->create([
            'user_id' => $autreJoueur->id,
            'galaxy' => $this->planetService->getPlanetCoordinates()->galaxy,
            'system' => $this->planetService->getPlanetCoordinates()->system,
            'planet' => 14,
            'surveillance_network' => SurveillanceTier::Contact->value,
        ])->id;
        // Une seule acquisition ouvre les contacts des trois observateurs ; celui du revoque est ferme.
        resolve(SurveillanceWatch::class)->acquire($patrouille, (int)$patrouille->entered_system_at);
        DB::table('surveillance_contacts')->where('observer_user_id', $revoque->id)->update(['revoked_at' => (int)$patrouille->entered_system_at]);

        Date::setTestNow(Date::createFromTimestamp((int)$patrouille->entered_system_at + 1));

        try {
            Event::fake([FleetMovementChanged::class]);

            /** @var FleetMission $segment */
            $segment = $patrouille->currentMission;
            $segment->time_arrival = (int)$segment->time_arrival + 300;
            $segment->save();

            $destinataires = [];
            $annonces = [];
            Event::assertDispatched(FleetMovementChanged::class, function (FleetMovementChanged $e) use (&$destinataires, &$annonces): bool {
                $destinataires[] = $e->playerId;
                $annonces[$e->playerId] = $e;

                return true;
            });

            $this->assertContains((int)$this->currentUserId, $destinataires, 'L observateur n est pas prevenu : sa carte ignorera l ordre jusqu a la veille suivante.');
            $this->assertContains($proprietaire, $destinataires, 'Le proprietaire n est plus prevenu.');
            $this->assertNotContains((int)$autreJoueur->id, $destinataires, 'Un joueur dont le contact n est pas encore visible est prevenu : l annonce est un renseignement.');
            $this->assertNotContains((int)$revoque->id, $destinataires, 'Un observateur dont le contact est revoque est encore prevenu : la couverture perdue continue de renseigner.');

            // **L annonce de l observateur est reduite** : le seul systeme ou il tient le contact, des
            // deux cotes, et aucun identifiant de mission — celle du proprietaire, elle, porte tout.
            $coords = $this->planetService->getPlanetCoordinates();
            $laMienne = $annonces[(int)$this->currentUserId];
            $laSienne = $annonces[$proprietaire];

            $this->assertSame([$coords->galaxy, $coords->system, $coords->galaxy, $coords->system], [$laMienne->galaxyFrom, $laMienne->systemFrom, $laMienne->galaxyTo, $laMienne->systemTo], 'L annonce de l observateur porte autre chose que le systeme ou il tient le contact : la destination fuit.');
            $this->assertSame(0, $laMienne->missionId, 'L annonce de l observateur nomme la mission etrangere.');
            $this->assertSame($coords->system + 1, $laSienne->systemTo, 'La premisse manque : l annonce du proprietaire ne porte pas la destination reelle.');
            $this->assertSame((int)$segment->id, $laSienne->missionId);
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * **A partir du palier de l identite, le corps est nomme.** La moitie qui manquait au temoin du
     * premier palier : sans elle, reduire tous les corps a leur point passerait.
     */
    public function testFromTheIdentityTierABodyIsNamedInTheRoute(): void
    {
        $this->monDetecteur(SurveillanceTier::Identity->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20, true, false);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Identity);

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);
        /** @var FleetMission $mission */
        $mission = $patrouille->currentMission;

        $this->assertSame(
            ['galaxy' => $coords->galaxy, 'system' => $coords->system, 'position' => $coords->position, 'type' => (int)$mission->type_from, 'x' => null, 'y' => null],
            $vues[0]['segment']['from'],
            'Au palier de l identite, le corps de depart n est plus nomme.'
        );
    }

    /**
     * **Un segment traite n a pas de clef `segment`** — absent, jamais a null. Et une patrouille dont
     * le vol courant est traite sans retour ne porte aucune route.
     */
    public function testAProcessedSegmentWithoutAReturnCarriesNoRouteKeyAtAll(): void
    {
        $this->monDetecteur(SurveillanceTier::Contact->value);
        [$patrouille] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Contact);

        DB::table('fleet_missions')->where('id', (int)$patrouille->current_mission_id)->update(['processed' => 1]);
        $patrouille->refresh();

        $coords = $this->planetService->getPlanetCoordinates();
        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);

        $this->assertCount(1, $vues);
        $this->assertArrayNotHasKey('segment', $vues[0], 'Un segment traite voyage — a null ou vide : envoye puis cache n est pas protege.');
        $this->assertArrayHasKey('position', $vues[0], 'La position, elle, doit rester.');
    }

    /**
     * **Le retour d un raid se publie.** Le vol courant d une patrouille qui attaque est l attaque,
     * traitee des l arrivee ; la flotte rentre par une autre mission, nee de l attaque. Sans elle, la
     * carte de l observateur laissait le glyphe au point de la patrouille pendant tout le retour.
     */
    public function testTheReturnOfARaidIsTheRouteWhileTheAttackIsAlreadyProcessed(): void
    {
        $this->monDetecteur(SurveillanceTier::Contact->value);
        [$patrouille, $proprietaire] = $this->unePatrouilleEtrangere(20);
        $maintenant = $this->acquisA($patrouille, SurveillanceTier::Contact);
        $coords = $this->planetService->getPlanetCoordinates();

        /** @var FleetMission $attaque */
        $attaque = $patrouille->currentMission;
        $attaque->forceFill(['processed' => 1, 'mission_type' => 1, 'x_to' => 900, 'y_to' => 100])->save();

        $retour = (new FleetMission())->forceFill([
            'user_id' => $proprietaire,
            'parent_id' => $attaque->id,
            'patrol_id' => $patrouille->id,
            'mission_type' => 1,
            'galaxy_from' => $coords->galaxy,
            'system_from' => $coords->system,
            'position_from' => 0,
            'type_from' => PlanetType::SpatialPoint->value,
            'x_from' => 900,
            'y_from' => 100,
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => 0,
            'type_to' => PlanetType::SpatialPoint->value,
            'x_to' => 640,
            'y_to' => 480,
            'time_departure' => $maintenant - 10,
            'time_arrival' => $maintenant + 500,
            'cruiser' => 20,
            'processed' => 0,
        ]);
        $retour->save();
        $patrouille->refresh();

        $vues = $this->projection()->inSystem((int)$this->currentUserId, $coords->galaxy, $coords->system, $maintenant);
        $segment = $vues[0]['segment'] ?? null;

        $this->assertIsArray($segment, 'Le retour du raid n est pas publie : le glyphe resterait au point pendant tout le retour.');
        $this->assertSame([900, 100], [$segment['from']['x'], $segment['from']['y']], 'La route publiee n est pas celle du retour.');
        $this->assertSame($maintenant + 500, $segment['time_arrival']);
    }
}
