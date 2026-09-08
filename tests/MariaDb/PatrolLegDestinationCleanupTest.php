<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\PatrolLegDestinationCleanup;
use OGame\Services\InitialUserDataService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le nettoyage des destinations de patrouille tourne sur le moteur de production, et sur d anciennes lignes.
 *
 * ## Pourquoi cette epreuve vit au bac
 *
 * La regle est un `UPDATE` dont le `WHERE` porte une sous-requete correlee sur une **autre** table.
 * SQLite l accepte largement ; MySQL et MariaDB posent des restrictions propres a la table mise a
 * jour, et un `information_schema` different. Un `UPDATE` refuse ou une correlation mal formee ne se
 * verrait nulle part ailleurs : la suite ordinaire resterait verte, et la migration echouerait au
 * premier serveur reel. Le job des migrations execute deja la migration elle-meme sur MariaDB, ce
 * qui prouve son raccordement ; ce qui manquait est son **effet sur des lignes anciennes**.
 *
 * ## Ce qu elle etablit
 *
 * Les quatre cas de la regle, sur le moteur de production : le stationnement chez autrui perd son
 * corps, le stationnement chez soi aussi, le **retour en vol le garde meme si le corps a change de
 * mains**, et un segment deja traite n est pas reecrit.
 */
#[Group('mariadb')]
final class PatrolLegDestinationCleanupTest extends TestCase
{
    // **Seulement pour `requiresMariaDb()`.** Cette epreuve ne lance aucun processus : elle mesure
    // ce que le moteur accepte et ce que la regle change, pas une course.
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();
    }

    /**
     * Cree un joueur et rend l identifiant de sa premiere planete.
     */
    private function aPlayerWithAPlanet(): array
    {
        $utilisateur = User::factory()->create();

        if ($utilisateur->hasRole('admin')) {
            $utilisateur->removeRole('admin');
        }

        resolve(InitialUserDataService::class)->createFor($utilisateur);

        $planete = (int)DB::table('planets')->where('user_id', $utilisateur->id)->orderBy('id')->value('id');

        return [(int)$utilisateur->id, $planete];
    }

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
     * Sur MariaDB, la regle efface les stationnements et epargne le retour en vol.
     */
    public function testTheCleanupRunsOnMariaDbAndSparesAReturnInFlight(): void
    {
        [$joueur, $sienne] = $this->aPlayerWithAPlanet();
        [, $autrui] = $this->aPlayerWithAPlanet();

        $this->assertNotSame(0, $sienne, 'The player has no planet: the scenario would prove nothing.');
        $this->assertNotSame(0, $autrui, 'The stranger has no planet: the scenario would prove nothing.');

        $stationnementChezAutrui = $this->aLeg($joueur, $sienne, PatrolState::Stationed, $autrui, 0);
        $stationnementChezSoi = $this->aLeg($joueur, $sienne, PatrolState::Stationed, $sienne, 0);
        $retourVersUneBasePerdue = $this->aLeg($joueur, $sienne, PatrolState::Returning, $autrui, 0);
        $dejaTraite = $this->aLeg($joueur, $sienne, PatrolState::Stationed, $autrui, 1);

        $corriges = PatrolLegDestinationCleanup::run();

        $this->assertGreaterThanOrEqual(2, $corriges, 'The cleanup changed fewer rows than the two stationings it must clear.');

        $this->assertNull($this->destinationOf($stationnementChezAutrui), 'A stationing next to a stranger body keeps naming it on MariaDB.');
        $this->assertNull($this->destinationOf($stationnementChezSoi), 'A stationing next to the player own body keeps naming it on MariaDB.');
        $this->assertSame($autrui, $this->destinationOf($retourVersUneBasePerdue), 'MariaDB stripped a return in flight of the body it lands on.');
        $this->assertSame($autrui, $this->destinationOf($dejaTraite), 'MariaDB rewrote a settled leg, which decides nothing and is shown to nobody.');
    }
}
