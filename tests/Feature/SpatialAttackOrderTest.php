<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\PatrolAttackEligibility;
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

    private function croiseurs(int $combien): UnitCollection
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), $combien);

        return $unites;
    }
}
