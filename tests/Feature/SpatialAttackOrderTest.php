<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\FrozenPatrolTarget;
use OGame\Patrol\PatrolAttackEligibility;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolPricing;
use OGame\Patrol\SpatialAttackOrder;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Lancer une attaque contre une patrouille, et surtout : ne pas pouvoir en lancer une.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CES ESSAIS PROTEGENT
 *
 * La surveillance existe pour qu on ne puisse **pas** viser ce qu on ne detecte pas. Le lancement est
 * l endroit ou cette promesse se tient ou se perd : si un joueur pouvait nommer une patrouille par
 * son identifiant, il saurait qu elle existe rien qu en essayant de l attaquer, et toute la
 * mecanique de detection deviendrait decorative.
 *
 * Les refus comptent donc plus que le succes, et chacun a son essai.
 */
class SpatialAttackOrderTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('patrols_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');

        parent::tearDown();
    }

    private function unePatrouilleDe(int $proprietaire, int $x = 160, int $y = 120): Patrol
    {
        return Patrol::forceCreate([
            'user_id' => $proprietaire,
            'home_planet_id' => null,
            'state' => PatrolState::Stationed,
            'galaxy' => 5,
            'system' => 88,
            'x' => $x,
            'y' => $y,
            'fuel_reserve' => 3000.0,
            'upkeep_paid_at' => null,
            'order_version' => 1,
        ]);
    }

    /**
     * Pose un contact acquis : le joueur courant detecte cette patrouille.
     */
    private function unContactAcquisSur(Patrol $cible): int
    {
        return (int)DB::table('surveillance_contacts')->insertGetId([
            'observer_user_id' => $this->currentUserId,
            'patrol_id' => (int)$cible->id,
            'observer_planet_id' => $this->planetService->getPlanetId(),
            'entered_system_at' => (int)now()->timestamp - 600,
            'acquisition_from' => (int)now()->timestamp - 600,
            'visible_from' => (int)now()->timestamp - 60,
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function testOnNePeutPasAttaquerUnePatrouilleQuOnNeDetectePas(): void
    {
        $cible = $this->unePatrouilleDe($this->getSecondPlayerId());

        // Aucun contact pose : la patrouille existe, mais pas pour ce joueur.
        $this->expectException(PatrolOrderRefused::class);
        $this->expectExceptionMessageMatches('/not_detected/');

        resolve(PatrolAttackEligibility::class)
            ->frozenTargetFor($this->currentUserId, $cible, (int)now()->timestamp);
    }

    public function testOnNePeutPasAttaquerSaProprePatrouille(): void
    {
        $sienne = $this->unePatrouilleDe($this->currentUserId);
        $this->unContactAcquisSur($sienne);

        $this->expectException(PatrolOrderRefused::class);
        $this->expectExceptionMessageMatches('/your_own_patrol/');

        resolve(PatrolAttackEligibility::class)
            ->frozenTargetFor($this->currentUserId, $sienne, (int)now()->timestamp);
    }

    public function testUnContactRevoqueNePermetPlusDAttaquer(): void
    {
        $cible = $this->unePatrouilleDe($this->getSecondPlayerId());
        $contact = $this->unContactAcquisSur($cible);

        DB::table('surveillance_contacts')->where('id', $contact)->update(['revoked_at' => (int)now()->timestamp]);

        $reponse = $this->post(route('galaxy.patrol.attack'), [
            'contact_id' => $contact,
            'am204' => 5,
        ]);

        // **La meme reponse que « non detectee »** : distinguer les deux apprendrait quelque chose
        // sur une cible qu on n a pas le droit de connaitre.
        $reponse->assertStatus(409);
    }

    public function testUnContactDUnAutreJoueurNeSertARien(): void
    {
        $cible = $this->unePatrouilleDe($this->getSecondPlayerId());

        // Le contact appartient a quelqu un d autre : le joueur courant ne peut pas s en servir,
        // meme en devinant son identifiant.
        $contactDUnTiers = (int)DB::table('surveillance_contacts')->insertGetId([
            'observer_user_id' => $this->getSecondPlayerIdFor($this->currentUserId),
            'patrol_id' => (int)$cible->id,
            'observer_planet_id' => $this->planetService->getPlanetId(),
            'entered_system_at' => (int)now()->timestamp - 600,
            'acquisition_from' => (int)now()->timestamp - 600,
            'visible_from' => (int)now()->timestamp - 60,
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reponse = $this->post(route('galaxy.patrol.attack'), [
            'contact_id' => $contactDUnTiers,
            'am204' => 5,
        ]);

        $reponse->assertStatus(409);
    }

    public function testUneAttaqueRefuseeNeRetireAucunVaisseau(): void
    {
        $this->planetService->addUnit('cruiser', 30);
        $this->planetService->reloadPlanet();

        $avant = $this->planetService->getShipUnits()->getAmountByMachineName('cruiser');

        $this->post(route('galaxy.patrol.attack'), [
            'contact_id' => 999999,
            'am204' => 5,
        ])->assertStatus(409);

        $this->planetService->reloadPlanet();

        // **Un refus ne coute rien.** Le retrait vit dans la transaction du lancement, apres tous
        // les jugements : un refus la laisse intacte.
        $this->assertSame(
            $avant,
            $this->planetService->getShipUnits()->getAmountByMachineName('cruiser'),
            'Une attaque refusee a quand meme retire des vaisseaux.'
        );
    }

    /**
     * **Ce que le joueur lit est une phrase, jamais une clef.**
     *
     * `PatrolController::refused()` prefixe ce qu on lui donne par `t_ingame.patrol.refusal_`. La
     * moitie du chantier lui passait une clef **deja complete** : le prefixe s ajoutait au prefixe,
     * la traduction n existait pas, et `__()` rend alors la clef elle-meme — une longue chaine
     * technique affichee au joueur, sans la moindre erreur.
     *
     * Les essais qui interrogeaient le service et comparaient sa clef restaient verts : ils
     * verifiaient la **forme** de la regle, pas son **effet**. Celui-ci lit la reponse.
     */
    public function testUnRefusDeLaRouteEstUnePhraseEtNonUneClef(): void
    {
        $cible = $this->unePatrouilleDe($this->getSecondPlayerId());
        $contact = $this->unContactAcquisSur($cible);

        DB::table('surveillance_contacts')->where('id', $contact)->update(['revoked_at' => (int)now()->timestamp]);

        $reponse = $this->post(route('galaxy.patrol.attack'), [
            'contact_id' => $contact,
            'am204' => 5,
        ]);

        $reponse->assertStatus(409);

        $this->assertSame('target_not_detected', $reponse->json('reason_key'));
        $this->assertStringNotContainsString(
            't_ingame.',
            (string)$reponse->json('reason'),
            'Le refus rendu au joueur porte encore une clef de traduction : ' . (string)$reponse->json('reason')
        );
    }

    /**
     * Le meme controle sur l autre famille de refus — celle de l eligibilite, qui portait toutes
     * ses clefs sous leur forme complete.
     */
    public function testUnRefusDEligibiliteEstUnePhraseAussi(): void
    {
        $sienne = $this->unePatrouilleDe($this->currentUserId);
        $contact = $this->unContactAcquisSur($sienne);

        $reponse = $this->post(route('galaxy.patrol.attack'), [
            'contact_id' => $contact,
            'am204' => 5,
        ]);

        $reponse->assertStatus(409);

        $this->assertSame('target_is_your_own_patrol', $reponse->json('reason_key'));
        $this->assertStringNotContainsString(
            't_ingame.',
            (string)$reponse->json('reason'),
            'Le refus rendu au joueur porte encore une clef de traduction : ' . (string)$reponse->json('reason')
        );
    }

    /**
     * **Une defense ne part pas attaquer.**
     *
     * La composition arrive sous la forme `am<id>`, et `ObjectService::getUnitObjectById()` resout
     * aussi bien un lance-missiles qu un croiseur. Tous les autres departs du chantier refusent une
     * unite sans vitesse par `PatrolOrders::hasImmobileUnit()` ; celui-ci ne le faisait pas, et
     * seule une division par zero au fond du calcul de duree l arretait — en rendant une erreur 500
     * la ou le joueur attend un refus, et en ne tenant que tant qu aucune defense n a de vitesse.
     */
    public function testUneDefenseNeDecollePasEnAttaque(): void
    {
        $this->planetService->addUnit('rocket_launcher', 10);
        $this->planetService->reloadPlanet();

        $avant = $this->planetService->getDefenseUnits()->getAmountByMachineName('rocket_launcher');
        $missionsAvant = (int)DB::table('fleet_missions')->where('user_id', $this->currentUserId)->count();

        // **Un proprietaire ordinaire, fabrique et non cherche.** `getSecondPlayerId()` rend le
        // premier autre compte par identifiant croissant : sur une base neuve, c est le compte
        // systeme, et la cible serait refusee comme protegee avant d atteindre la regle visee.
        $proprietaire = (int)User::factory()->create()->id;

        $cible = $this->unePatrouilleDe($proprietaire);
        $contact = $this->unContactAcquisSur($cible);

        // **Le detecteur est pose, pas suppose** : sans reseau de surveillance sur le corps
        // observateur, le contact ne vaut rien et le refus viendrait de la detection — l essai
        // passerait sans jamais atteindre la regle qu il vise.
        DB::table('planets')
            ->where('id', $this->planetService->getPlanetId())
            ->update(['surveillance_network' => 3]);

        $reponse = $this->post(route('galaxy.patrol.attack'), [
            'contact_id' => $contact,
            'am401' => 5,
        ]);

        $reponse->assertStatus(409);
        $this->assertSame('immobile_unit', $reponse->json('reason_key'));

        $this->planetService->reloadPlanet();

        $this->assertSame(
            $avant,
            $this->planetService->getDefenseUnits()->getAmountByMachineName('rocket_launcher'),
            'Une defense a quitte le corps pour aller attaquer.'
        );

        // **Et aucune mission ne nait.** Un refus qui laisserait une flotte en vol serait pire que
        // l erreur 500 qu il remplace : la 500 ne creait rien.
        $this->assertSame(
            $missionsAvant,
            (int)DB::table('fleet_missions')->where('user_id', $this->currentUserId)->count(),
            'Une attaque refusee a quand meme cree une mission.'
        );
    }

    /**
     * **Une attaque part vraiment**, et paie ce qu elle annonce.
     *
     * ## Pourquoi cet essai est le plus important de la classe
     *
     * Les six qui precedent eprouvent des refus. Ils sont necessaires — la surveillance existe pour
     * qu on ne puisse pas viser ce qu on ne detecte pas — mais aucun ne demande a une attaque de
     * partir, et cette forme laisse passer un blocage **total**.
     *
     * C est ce qui s est produit : `SpatialAttackOrder::launch()` demande son devis avec une reserve
     * de zero — une attaque ne stationne pas —, et `PatrolPricing::quote()` refusait des que
     * `reserve - cout` etait negatif, ce qui est le cas des que le trajet coute quelque chose.
     * **Aucune attaque spatiale ne pouvait aboutir**, et la classe restait verte.
     *
     * ## La regle du carburant, epinglee
     *
     * Une attaque paie **l aller et le retour au depart**, comme toute attaque du jeu. Ne prendre que
     * l aller rendrait le retour gratuit ; la flotte ne stationnant pas, il n y a aucune reserve pour
     * le payer plus tard.
     */
    public function testUneAttaquePartVraimentEtPaieAllerEtRetour(): void
    {
        $proprietaire = (int)User::factory()->create()->id;

        // **Dans le systeme de l attaquant** : le seul cas reel, et le seul trajet payable.
        $chezMoi = $this->planetService->getPlanetCoordinates();
        $cible = $this->unePatrouilleDe($proprietaire);
        $cible->forceFill(['galaxy' => $chezMoi->galaxy, 'system' => $chezMoi->system])->save();

        $this->planetService->addUnit('battle_ship', 30);
        $this->planetService->addResources(new Resources(0, 0, 5_000_000, 0));
        $this->planetService->reloadPlanet();

        $vaisseauxAvant = $this->planetService->getShipUnits()->getAmountByMachineName('battle_ship');
        $deuteriumAvant = (int)$this->planetService->deuterium()->get();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('battle_ship'), 30);

        $gelee = FrozenPatrolTarget::of($cible);
        $tarif = resolve(PatrolPricing::class);
        $devis = $tarif->quote(
            resolve(PlayerServiceFactory::class)->make($this->currentUserId, true),
            $flotte,
            0.0,
            $chezMoi->galaxy,
            $chezMoi->system,
            $tarif->geometry()->bodyPoint($chezMoi->position),
            PatrolDestination::spatialPoint($tarif->geometry(), $gelee->galaxy, $gelee->system, $gelee->point()),
            10.0,
            0,
            $chezMoi,
            false,
        );

        $this->assertTrue($devis->isPossible(), 'Le devis d une attaque refuse encore : ' . (string)$devis->refusal);

        $mission = resolve(SpatialAttackOrder::class)->launch(
            $this->planetService,
            $flotte,
            $gelee,
            10.0,
            (int)now()->timestamp
        );

        $this->assertSame(1, (int)$mission->mission_type, 'Une attaque spatiale est une attaque de genre 1.');
        $this->assertNull($mission->planet_id_to, 'Elle ne vise aucun corps.');
        $this->assertSame(PlanetType::SpatialPoint->value, (int)$mission->type_to, 'Sans cette colonne, l arrivee la prend pour une planete deplacee.');
        $this->assertSame((int)$cible->id, (int)$mission->target_patrol_id);

        $this->planetService->reloadPlanet();

        $this->assertSame(
            $vaisseauxAvant - 30,
            $this->planetService->getShipUnits()->getAmountByMachineName('battle_ship'),
            'Les vaisseaux doivent avoir quitte le corps.'
        );

        // **Aller plus retour, preleve au depart.**
        $attendu = $devis->fuelCost + $devis->safetyReturnCost;

        $this->assertGreaterThan(0, $attendu, 'Un trajet gratuit ferait coincider le juste et le faux.');
        $this->assertSame($attendu, (int)$mission->deuterium_consumption, 'La mission doit porter le carburant de l aller et du retour.');
        $this->assertSame(
            $deuteriumAvant - $attendu,
            (int)$this->planetService->deuterium()->get(),
            'Le corps doit avoir paye exactement l aller et le retour.'
        );
    }

    public function testInterrupteurDesarmeAucuneAttaqueSpatiale(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');

        $cible = $this->unePatrouilleDe($this->getSecondPlayerId());
        $contact = $this->unContactAcquisSur($cible);

        $this->post(route('galaxy.patrol.attack'), [
            'contact_id' => $contact,
            'am204' => 5,
        ])->assertStatus(409);
    }
}
