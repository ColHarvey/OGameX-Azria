<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\PatrolLegDestinationCleanup;
use Tests\AccountTestCase;

/**
 * La migration qui efface les destinations de stationnement ne touche jamais un retour en vol.
 *
 * ## Ce qu elle doit distinguer, et ce qui rendait la premiere regle fausse
 *
 * Un segment de patrouille ne doit nommer un corps d arrivee que s il s y **pose**. Une premiere
 * version reconnaissait le stationnement au fait que le corps appartenait a quelqu un d autre —
 * puisqu un retour se pose sur sa propre base. **Cette regle n est vraie qu au depart** : entre
 * l ecriture du segment et la migration, la base peut avoir ete detruite ou avoir change de mains,
 * et le retour, bien reel, se serait alors vu effacer sa destination.
 *
 * La regle retenue ne consulte aucune propriete : un retour est celui qu une patrouille dans l etat
 * `returning` a **en vol**. C est vrai au moment ou la migration s execute, et c est ce que cet
 * essai epingle — y compris le cas exact que la premiere regle abimait.
 */
class PatrolLegDestinationCleanupTest extends AccountTestCase
{
    /**
     * Ecrit une patrouille et son segment, et rend l identifiant du segment.
     */
    private function aLeg(int $ownerId, int $baseId, PatrolState $state, int|null $destinationBodyId, int $processed): int
    {
        $maintenant = (int)Date::now()->timestamp;

        $patrouille = (int)DB::table('patrols')->insertGetId([
            'user_id' => $ownerId,
            'home_planet_id' => $baseId,
            'state' => $state->value,
            'galaxy' => 1,
            'system' => 1,
            'x' => 600,
            'y' => -600,
            'current_mission_id' => null,
            'fuel_reserve' => 1000,
            'upkeep_paid_at' => $maintenant,
            'stationed_since' => $maintenant,
            'order_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $segment = (int)DB::table('fleet_missions')->insertGetId([
            'user_id' => $ownerId,
            'patrol_id' => $patrouille,
            'planet_id_from' => $baseId,
            'mission_type' => 11,
            'type_from' => 1,
            'type_to' => 1,
            'galaxy_from' => 1,
            'system_from' => 1,
            'position_from' => 4,
            'planet_id_to' => $destinationBodyId,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 8,
            'x_to' => 600,
            'y_to' => -600,
            'time_departure' => $maintenant - 3600,
            'time_arrival' => $maintenant + 3600,
            'cruiser' => 7,
            'processed' => $processed,
            'canceled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patrols')->where('id', $patrouille)->update(['current_mission_id' => $segment]);

        return $segment;
    }

    private function destinationOf(int $segment): int|null
    {
        $lu = DB::table('fleet_missions')->where('id', $segment)->value('planet_id_to');

        return $lu === null ? null : (int)$lu;
    }

    /**
     * Un retour en vol garde son corps, meme si ce corps ne lui appartient plus.
     */
    public function testTheCleanupSparesAReturnInFlightAndClearsEveryStationing(): void
    {
        $mienne = (int)$this->planetService->getPlanetId();

        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire, 'The foreign planet has no owner.');
        $this->assertNotSame($this->currentUserId, $proprietaire->getId(), 'The « foreign » planet belongs to the bench player.');
        $autrui = (int)$etrangere->getPlanetId();

        $stationnementChezAutrui = $this->aLeg($this->currentUserId, $mienne, PatrolState::Stationed, $autrui, 0);
        $stationnementChezSoi = $this->aLeg($this->currentUserId, $mienne, PatrolState::Stationed, $mienne, 0);

        // **Le cas que la premiere regle abimait** : un retour bien reel, dont la base a change de
        // mains depuis le depart. Le corps vise appartient desormais a un autre joueur.
        $retourVersUneBasePerdue = $this->aLeg($this->currentUserId, $mienne, PatrolState::Returning, $autrui, 0);

        // Un segment deja traite ne decide plus d aucune arrivee et n est rendu par aucune liste.
        $dejaTraite = $this->aLeg($this->currentUserId, $mienne, PatrolState::Stationed, $autrui, 1);

        // Premisse : sans la migration, les quatre portent bien leur corps.
        $this->assertSame($autrui, $this->destinationOf($stationnementChezAutrui));
        $this->assertSame($mienne, $this->destinationOf($stationnementChezSoi));
        $this->assertSame($autrui, $this->destinationOf($retourVersUneBasePerdue));
        $this->assertSame($autrui, $this->destinationOf($dejaTraite));

        PatrolLegDestinationCleanup::run();

        $this->assertNull($this->destinationOf($stationnementChezAutrui), 'A stationing next to a stranger body keeps naming it: the leak survives the migration.');
        $this->assertNull($this->destinationOf($stationnementChezSoi), 'A stationing next to the player own body keeps naming it: the ambiguity survives.');
        $this->assertSame($autrui, $this->destinationOf($retourVersUneBasePerdue), 'The migration stripped a return in flight of the body it lands on, because that body changed hands.');
        $this->assertSame($autrui, $this->destinationOf($dejaTraite), 'The migration rewrote a settled leg, which decides nothing and is shown to nobody.');
    }
}
