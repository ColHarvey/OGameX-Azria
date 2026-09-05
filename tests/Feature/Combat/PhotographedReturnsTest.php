<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Un retour vers le corps assiege : eligible, il rejoint la bataille ; tardif, il attend, et rien
 * ne se perd ni ne se dedouble.
 *
 * ## Ce qu'un retour apporte
 *
 * Une flotte du proprietaire qui rentre chez elle depose **ses vaisseaux et sa cargaison** : la
 * matrice le dit (`DeliveredFleet`, `DeliveredCargo`). Elle appartient au combat si son depart a ete
 * decide avant l'ouverture et si son arrivee tombe avant la fermeture — les memes deux barrieres que
 * pour tout le reste. Un retour pris dans ces bornes se bat donc aux cotes de la garnison, et sa
 * cargaison entre dans la reserve que le butin plafonne.
 *
 * ## Ce qui ne doit jamais arriver
 *
 * Qu'une unite disparaisse ou soit comptee deux fois. Un retour eligible est applique **une fois** :
 * ses vaisseaux sont sur la planete **et** dans la photographie, sans double compte, parce que la
 * photographie part de l'effectif d'ouverture — ou ils n'etaient pas — et y ajoute exactement ce que
 * ce retour depose. Un retour tardif n'est ni applique, ni photographie, ni retenu : il arrive apres,
 * comme le monde l'a prevu.
 */
final class PhotographedReturnsTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int VAISSEAUX = 9;

    private const int CARGAISON = 12_000;

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    public function testAnEligibleReturnJoinsTheBattleAndIsAppliedExactlyOnce(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $vaisseauxAuDepart = $this->garrisonOf($cible, 'light_fighter');
        $retour = $this->aPendingReturnTowards($cible, $ouverture - 50, $ouverture + 8);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        // **Il se bat**, et ses ressources entrent dans la reserve.
        $this->assertSame($vaisseauxAuDepart + self::VAISSEAUX, $this->defenderStartOf($combat, 'light_fighter'), 'The eligible return did not fight alongside the garrison.');
        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL + self::CARGAISON), self::frozenMetalOf($combat), 'The eligible return cargo stayed out of the protected reserve.');

        // **Une fois, et une seule** : rien n'est perdu, rien n'est double.
        $retour->refresh();
        $this->assertSame(1, (int)$retour->processed, 'The eligible return was not applied.');
        $this->assertSame($vaisseauxAuDepart + self::VAISSEAUX, $this->garrisonOf($cible, 'light_fighter'), 'The returning ships were lost, or landed twice.');
        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, $this->metalOf($cible), 'The returning cargo was lost, or credited twice.');
    }

    public function testALateReturnIsNeitherPhotographedNorHeldBack(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $vaisseauxAuDepart = $this->garrisonOf($cible, 'light_fighter');
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        // Il arrive **apres** la fermeture : hors de ce combat.
        $retour = $this->aPendingReturnTowards($cible, $ouverture - 50, $fermeture + 30);

        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed);

        $this->assertSame($vaisseauxAuDepart, $this->defenderStartOf($combat, 'light_fighter'), 'A return arriving after the closure fought in this battle.');
        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL), self::frozenMetalOf($combat), 'A late return cargo entered the protected reserve.');
        $retour->refresh();
        $this->assertSame(0, (int)$retour->processed, 'The closure applied a return that had not arrived yet.');

        // **Rien n'est perdu** : il arrive ensuite, entier.
        $this->travelTo(Date::createFromTimestamp($fermeture + 31));
        resolve(\OGame\Services\FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($retour->id));
        $this->assertSame($vaisseauxAuDepart + self::VAISSEAUX, $this->garrisonOf($cible, 'light_fighter'), 'The late return never brought its ships home.');
        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, $this->metalOf($cible), 'The late return never brought its cargo home.');
    }

    /**
     * Un retour du proprietaire de la cible vers son corps : une flotte partie plus tot qui rentre.
     */
    private function aPendingReturnTowards(int $planetId, int $departure, int $arrival): FleetMission
    {
        $corps = DB::table('planets')->where('id', $planetId)->first(['galaxy', 'system', 'planet', 'user_id']);
        $this->assertNotNull($corps);

        // L'aller, deja traite : un retour sans parent n'existe pas dans ce jeu.
        $aller = FleetMission::forceCreate([
            'user_id' => (int)$corps->user_id,
            'planet_id_from' => $planetId,
            'type_from' => 1,
            'galaxy_from' => (int)$corps->galaxy,
            'system_from' => (int)$corps->system,
            'position_from' => (int)$corps->planet,
            'planet_id_to' => $planetId,
            'type_to' => 1,
            'galaxy_to' => (int)$corps->galaxy,
            'system_to' => (int)$corps->system,
            'position_to' => (int)$corps->planet,
            'mission_type' => 3,
            'time_departure' => $departure - 200,
            'time_arrival' => $departure - 100,
            'processed' => 1,
            'light_fighter' => self::VAISSEAUX,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        return FleetMission::forceCreate([
            'parent_id' => $aller->id,
            'user_id' => (int)$corps->user_id,
            'planet_id_from' => $planetId,
            'type_from' => 1,
            'galaxy_from' => (int)$corps->galaxy,
            'system_from' => (int)$corps->system,
            'position_from' => (int)$corps->planet,
            'planet_id_to' => $planetId,
            'type_to' => 1,
            'galaxy_to' => (int)$corps->galaxy,
            'system_to' => (int)$corps->system,
            'position_to' => (int)$corps->planet,
            'mission_type' => 3,
            'time_departure' => $departure,
            'time_arrival' => $arrival,
            'processed' => 0,
            'light_fighter' => self::VAISSEAUX,
            'metal' => self::CARGAISON,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    private function garrisonOf(int $planetId, string $machineName): int
    {
        return (int)DB::table('planets')->where('id', $planetId)->value($machineName);
    }

    private function metalOf(int $planetId): int
    {
        return (int)DB::table('planets')->where('id', $planetId)->value('metal');
    }

    private function defenderStartOf(CombatInstance $combat, string $machineName): int
    {
        $combat->refresh();

        return BattleResultCodec::fromStorage($combat->battle_result)->defenderUnitsStart->getAmountByMachineName($machineName);
    }

    private static function frozenMetalOf(CombatInstance $combat): int
    {
        $combat->refresh();

        return (int)BattleResultCodec::fromStorage($combat->battle_result)->loot->metal->get();
    }

    private static function shareOf(CombatInstance $combat, int $stock): int
    {
        $combat->refresh();

        return intdiv($stock * BattleResultCodec::fromStorage($combat->battle_result)->lootRateInBasisPoints, 10_000);
    }
}
