<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\Npc\NpcDestructionService;
use OGame\Services\PlanetService;
use Tests\AccountTestCase;
use Tests\SpawnsNpcBases;

/**
 * **La destruction d une base ne solde que SES flottes** (defaut repere par Keven, 20 septembre 2026).
 *
 * `groundOutboundFleets()` annonce dans son commentaire « settle every mission **this base** still has in
 * flight », mais sa requete portait sur tout le compte :
 *
 * ```php
 * FleetMission::where('user_id', $owner->getId())->where('processed', 0)->update(['processed' => 1]);
 * ```
 *
 * Tant qu un compte pirate n a qu une base, les deux formulations coincident. **L essaimage les separe** : un
 * compte peut alors posseder plusieurs colonies, et la chute de l une soldait en silence les flottes des autres,
 * encore vivantes. Le reglage `npc_swarm_enabled` est desarme par defaut, donc le defaut etait latent — mais il
 * attendait le jour ou Keven l armerait.
 *
 * Ce banc monte exactement cette situation : deux corps sur un meme compte, deux flottes en vol, une seule base
 * detruite.
 */
class NpcDestructionScopeTest extends AccountTestCase
{
    use SpawnsNpcBases;

    /**
     * Une flotte en vol partie d un corps donne, qui n est pas encore traitee.
     */
    private function uneFlotteEnVolDepuis(int $planetId, int $userId): FleetMission
    {
        // `FleetMission` n est pas remplissable en masse : on pose chaque colonne, comme les autres bancs.
        $mission = new FleetMission();
        $mission->user_id = $userId;
        $mission->planet_id_from = $planetId;
        $mission->planet_id_to = $planetId;
        $mission->galaxy_from = 1;
        $mission->system_from = 1;
        $mission->position_from = 1;
        $mission->type_from = PlanetType::Planet->value;
        $mission->galaxy_to = 1;
        $mission->system_to = 1;
        $mission->position_to = 2;
        $mission->type_to = PlanetType::Planet->value;
        $mission->mission_type = 1;
        $mission->time_departure = (int)Date::now()->subMinutes(10)->timestamp;
        $mission->time_arrival = (int)Date::now()->addMinutes(10)->timestamp;
        $mission->processed = 0;
        $mission->small_cargo = 5;
        $mission->save();

        return $mission;
    }

    /**
     * Une seconde colonie pour le meme compte pirate — ce que l essaimage produit.
     */
    private function uneSecondeBasePourLeMemeCompte(PlanetService $premiere): PlanetService
    {
        $proprietaire = $premiere->getPlayer();
        $this->assertNotNull($proprietaire);

        $modele = Planet::query()->findOrFail($premiere->getPlanetId());
        $copie = $modele->replicate();
        $copie->name = 'Seconde base du banc';
        // Une coordonnee libre, cherchee au-dela de la plage du banc pour ne bousculer personne.
        $copie->galaxy = (int)$modele->galaxy;
        $copie->system = (int)$modele->system;
        $copie->planet = (int)$modele->planet + 1;
        $copie->destroyed = 0;
        $copie->save();

        $seconde = resolve(PlanetServiceFactory::class)->make((int)$copie->id, true);
        $this->assertNotNull($seconde, 'La seconde base doit exister.');

        return $seconde;
    }

    public function testDestroyingOneBaseLeavesTheFleetsOfAnotherBaseAlone(): void
    {
        $premiere = $this->aSpawnedBase();
        $premiere = resolve(PlanetServiceFactory::class)->make($premiere->getPlanetId(), true);
        $this->assertNotNull($premiere);

        $proprietaire = $premiere->getPlayer();
        $this->assertNotNull($proprietaire);
        $seconde = $this->uneSecondeBasePourLeMemeCompte($premiere);

        $flotteDeLaPremiere = $this->uneFlotteEnVolDepuis($premiere->getPlanetId(), $proprietaire->getId());
        $flotteDeLaSeconde = $this->uneFlotteEnVolDepuis($seconde->getPlanetId(), $proprietaire->getId());

        // Premisse : les deux flottes sont bien en vol avant la destruction.
        $this->assertSame(0, (int)$flotteDeLaPremiere->refresh()->processed);
        $this->assertSame(0, (int)$flotteDeLaSeconde->refresh()->processed);

        // La premiere base tombe : plus un vaisseau, plus une defense.
        $tombee = resolve(NpcDestructionService::class)->destroy($premiere);
        $this->assertTrue($tombee, 'Premisse : la base doit bien tomber, sinon rien n est solde.');

        $this->assertSame(
            1,
            (int)$flotteDeLaPremiere->refresh()->processed,
            'La flotte partie de la base detruite n a plus de port d attache : elle est soldee.'
        );
        $this->assertSame(
            0,
            (int)$flotteDeLaSeconde->refresh()->processed,
            'La flotte d une AUTRE base du meme compte, encore vivante, ne doit pas etre soldee.'
        );
    }
}
