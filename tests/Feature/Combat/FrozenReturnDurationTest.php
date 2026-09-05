<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Application\FrozenCombatApplicationContext;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * La duree du retour d'une attaquante est un fait gele a la cloture, pas une lecture du joueur vivant.
 *
 * ## Le defaut que la revue 92 a lu
 *
 * Au reglement d'une attaque groupee, `CombatResolutionService` appelait
 * `calculateFleetMissionDuration()` sur la planete d'origine, donc sur les technologies de
 * propulsion **du joueur tel qu'il est a ce moment**. Une recherche achevee entre la cloture et
 * l'echeance changeait l'heure d'arrivee d'un retour d'un combat deja calcule ; deux applications
 * des memes faits geles ne rendaient pas le meme retour.
 *
 * ## La regle
 *
 * La duree est calculee a la cloture, sur les survivants, et gelee dans le contexte d'application
 * (schema 4, `return_durations`). Le reglement la lit ; il ne recalcule rien.
 */
final class FrozenReturnDurationTest extends FleetDispatchTestCase
{
    use OpensAPersistentAcsBattle;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function basicSetup(): void
    {
        $this->basicSetupForAnAcsBattle();
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

    public function testTheReturnLeavesWithTheDurationFrozenAtTheClosureWhateverThePlayerResearchesMeanwhile(): void
    {
        [$combat, $cible, $initiatrice, $alliee] = $this->anAcsBattleReadyToSettle(
            // Un recycleur ralentit l'initiateur : l'allie doit rejoindre l'union dans les 30 % de vol
            // restant que la regle accorde, quelle que soit la distance que le montage tire.
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 80],
            ['rocket_launcher' => 40]
        );

        $contexte = FrozenCombatApplicationContext::fromStorage($combat->frozen_settings);
        $gelee = $contexte->returnDurationOf((int)$alliee->id, static fn (): int => -1);
        $this->assertGreaterThan(0, $gelee, 'The closure froze no return duration for the ally.');

        // **Le monde avance pendant la bataille** : l'allie termine trois niveaux de propulsion a
        // combustion, dont ses chasseurs legers dependent. Un retour calcule sur le joueur vivant
        // serait plus court.
        $allie = resolve(PlayerServiceFactory::class)->make((int)$alliee->user_id, true);
        $allie->setResearchLevel('combustion_drive', $allie->getResearchLevel('combustion_drive') + 3);

        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);

        $retour = FleetMission::query()->where('parent_id', $alliee->id)->first();
        $this->assertNotNull($retour, 'The ally has no return mission after the settlement.');
        $this->assertSame($gelee, (int)$retour->time_arrival - (int)$retour->time_departure, 'The return does not leave with the duration frozen at the closure: the settlement recomputed it on the living player.');

        // Le temoin inverse : le joueur vivant donnerait une autre duree. Sans lui, un reglement qui
        // recalcule passerait des que les technologies n'ont pas bouge.
        $vivante = $this->livingDurationFor($alliee, $cible, $retour);
        $this->assertNotSame($gelee, $vivante, 'The living player and the frozen facts agree here: this test would pass without the freeze.');
    }

    private function livingDurationFor(FleetMission $alliee, int $cible, FleetMission $retour): int
    {
        $survivants = new UnitCollection();
        foreach (ObjectService::getShipObjects() as $vaisseau) {
            $nombre = (int)($retour->{$vaisseau->machine_name} ?? 0);
            if ($nombre > 0) {
                $survivants->addUnit($vaisseau, $nombre);
            }
        }
        $joueur = resolve(PlayerServiceFactory::class)->make((int)$alliee->user_id, true);
        $origine = resolve(PlanetServiceFactory::class)->makeForPlayer($joueur, (int)$alliee->planet_id_from);
        $arrivee = DB::table('planets')->where('id', $cible)->first(['galaxy', 'system', 'planet']);
        $this->assertNotNull($arrivee);
        $genre = resolve(GameMissionFactory::class)->getMissionById((int)$alliee->mission_type, [
            'fleetMissionService' => resolve(FleetMissionService::class),
            'messageService' => resolve(MessageService::class),
        ]);

        return resolve(FleetMissionService::class)->calculateFleetMissionDuration(
            $origine,
            new Coordinate((int)$arrivee->galaxy, (int)$arrivee->system, (int)$arrivee->planet),
            $survivants,
            $genre,
            10
        );
    }
}
