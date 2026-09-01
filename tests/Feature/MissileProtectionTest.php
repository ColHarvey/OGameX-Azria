<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\MissileMission;
use OGame\GameMissions\Models\MissionPossibleStatus;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;

/**
 * L'attaque de missiles applique-t-elle les protections que les neuf autres missions portent ?
 *
 * Elle etait seule a ne pas les appliquer, alors qu'elle vise la planete d'un autre joueur
 * exactement comme une attaque : un joueur parti en conge pouvait etre bombarde, et la planete
 * de l'administrateur aussi. Le defaut vient du depot amont et n'avait aucun test.
 *
 * Les deux controles s'executent avant celui de la portee : ces tests n'ont donc besoin ni de
 * missiles ni d'une cible voisine, et ne mesurent que ce qu'ils annoncent.
 */
class MissileProtectionTest extends AccountTestCase
{
    /**
     * Assert that a player on holiday cannot be shelled.
     */
    public function testAPlayerOnHolidayCannotBeShelled(): void
    {
        $cible = $this->uneCibleEtrangere();
        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire);

        // Le tir doit etre possible avant le depart en conge, sinon le refus d'apres ne
        // prouverait rien : il faut donc des missiles, un silo, et de la portee.
        $this->armerLeTireur($cible->getPlanetCoordinates());

        $avant = $this->missionStatus($cible->getPlanetCoordinates());

        DB::table('users')->where('id', $proprietaire->getId())->update([
            'vacation_mode' => true,
            'vacation_mode_activated_at' => now(),
        ]);

        // La garde lit le proprietaire via PlayerServiceFactory, qui met en cache. Sans
        // ce rechargement, elle interrogerait la copie chargee avant le depart en conge
        // et le test conclurait a tort que la protection ne marche pas.
        resolve(PlayerServiceFactory::class)->make($proprietaire->getId(), true);

        $apres = $this->missionStatus($cible->getPlanetCoordinates());

        DB::table('users')->where('id', $proprietaire->getId())->update([
            'vacation_mode' => false,
            'vacation_mode_activated_at' => null,
        ]);

        resolve(PlayerServiceFactory::class)->make($proprietaire->getId(), true);

        // On compare les deux etats : sans cela, un refus pour une tout autre raison — hors
        // de portee, par exemple — passerait pour une protection qui fonctionne.
        $this->assertTrue(
            $avant->possible,
            'The scenario is not conclusive: the shot was refused before the holiday even began — ' . $avant->error
        );
        $this->assertFalse($apres->possible, 'A missile attack was allowed against a player in vacation mode.');
    }

    /**
     * Assert that the administrator's planet cannot be shelled.
     */
    public function testTheAdministratorPlanetCannotBeShelled(): void
    {
        $legor = DB::table('users')
            ->join('planets', 'planets.user_id', '=', 'users.id')
            ->where('users.username', 'Legor')
            ->select('planets.galaxy', 'planets.system', 'planets.planet')
            ->first();

        $this->assertNotNull($legor, 'The administrator account is missing from this universe.');

        $cible = new Coordinate((int)$legor->galaxy, (int)$legor->system, (int)$legor->planet);

        // Sans armer le tireur, le tir serait refuse faute de missiles et le test passerait
        // meme sans la protection : il ne prouverait rien du tout.
        $this->armerLeTireur($cible);

        $statut = $this->missionStatus($cible);

        $this->assertFalse($statut->possible, 'A missile attack was allowed against the administrator.');
        $this->assertStringContainsString('administrator', $statut->error, 'The shot was refused, but not for the reason this test is about — ' . $statut->error);
    }

    /**
     * Give the attacker missiles, a silo, and enough range to reach the target.
     *
     * La portee vaut (niveau de propulsion a impulsion x 5) - 1 systemes : on la calcule
     * depuis la distance reelle plutot que de choisir un grand nombre au hasard, pour que le
     * test reste valable si la cible change de systeme.
     */
    private function armerLeTireur(Coordinate $cible): void
    {
        $depuis = $this->planetService->getPlanetCoordinates();
        $distance = $depuis->galaxy === $cible->galaxy ? abs($depuis->system - $cible->system) : 999;

        $niveau = (int)ceil(($distance + 2) / 5) + 1;

        DB::table('users_tech')
            ->where('user_id', $this->currentUserId)
            ->update(['impulse_drive' => max(2, $niveau)]);

        $this->planetService->setObjectLevel(ObjectService::getObjectByMachineName('missile_silo')->id, 5, true);
        $this->planetService->addUnit('interplanetary_missile', 20);
    }

    /**
     * Ask the mission itself whether it would accept the shot.
     */
    private function missionStatus(Coordinate $cible): MissionPossibleStatus
    {
        $planetServiceFactory = resolve(PlanetServiceFactory::class);
        $planet = $planetServiceFactory->make($this->planetService->getPlanetId(), true);
        $this->assertNotNull($planet);

        return resolve(MissileMission::class)->isMissionPossible(
            $planet,
            $cible,
            PlanetType::Planet,
            new UnitCollection()
        );
    }

    /**
     * Find any planet in this universe that belongs to somebody else.
     */
    private function uneCibleEtrangere(): \OGame\Services\PlanetService
    {
        $depuis = $this->planetService->getPlanetCoordinates();

        // Dans la meme galaxie, et le plus pres possible : une cible a l'autre bout de
        // l'univers serait refusee pour distance, et le test ne prouverait plus rien.
        $ligne = DB::table('planets')
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('planets.user_id', '!=', $this->currentUserId)
            ->where('users.username', '!=', 'Legor')
            ->where('users.vacation_mode', false)
            ->where('planets.destroyed', 0)
            ->where('planets.galaxy', $depuis->galaxy)
            ->orderByRaw('ABS(planets.system - ?)', [$depuis->system])
            ->select('planets.id')
            ->first();

        $this->assertNotNull($ligne, 'This universe holds no foreign planet to shell.');

        $planet = resolve(PlanetServiceFactory::class)->make((int)$ligne->id, true);
        $this->assertNotNull($planet);

        return $planet;
    }
}
