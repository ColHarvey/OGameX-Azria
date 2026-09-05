<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Enums\CharacterClass;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * En attaque groupee, la collecte des Faucheurs entre dans un retour, ou reste dans le champ.
 *
 * ## Le defaut que la revue 92 a lu
 *
 * `CombatResolutionService` creait les retours d'une attaque groupee avec le fret survivant et la
 * part de butin seulement, puis calculait la collecte des Faucheurs, la retirait du champ de debris
 * et la comptait au rapport — sans jamais l'ajouter a un retour. Des debris quittaient le jeu. Et la
 * collecte se decidait sur l'initiateur seul : un allie aux Faucheurs ne comptait pas.
 *
 * ## La regle
 *
 * La collecte s'attribue flotte par flotte, avant la creation des retours, avec les capacites gelees
 * a la cloture : au prorata des Faucheurs survivants de chaque flotte collectrice, plafonnee par sa
 * part de la capacite Faucheur et par la place libre de son fret. **Conservation** : ce qui quitte le
 * champ est exactement ce qui entre dans les retours — place libre ou place saturee.
 */
final class AcsReaperCollectionTest extends FleetDispatchTestCase
{
    use OpensAPersistentAcsBattle {
        createAcsAllyPlayer as createAcsAllyPlayerBase;
        createAcsTargetPlayer as createAcsTargetPlayerBase;
    }

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /** Le stock de la cible : bas, le butin laisse de la place au retour ; haut, il la sature. */
    private int $targetStock = 2_000;

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

    /**
     * Place libre : l'allie aux Faucheurs rapporte sa collecte, l'initiateur sans Faucheur rien, et
     * le champ perd exactement ce que l'allie a pris.
     */
    public function testACollectingAllyReceivesItsShareAndTheFieldLosesExactlyThat(): void
    {
        $this->targetStock = 2_000;
        [$combat, $cible, $initiatrice, $alliee] = $this->anAcsBattleReadyToSettle(
            // Un recycleur ralentit l'initiateur : l'allie aux Faucheurs, plus lents que les chasseurs,
            // doit pouvoir rejoindre l'union dans les 30 % de vol restant que la regle accorde.
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 60, 'reaper' => 10],
            ['rocket_launcher' => 60, 'light_fighter' => 80]
        );

        $resultat = $this->frozenResultOf($combat);
        $debris = $resultat->debris;
        $this->assertGreaterThan(0, $debris->metal->get() + $debris->crystal->get(), 'The battle produced no debris: nothing to collect.');
        $sansCollecte = $this->frozenReturnCargoWithoutCollection($combat, $alliee);

        $this->settle($combat);

        $retourAllie = FleetMission::query()->where('parent_id', $alliee->id)->first();
        $this->assertNotNull($retourAllie, 'The ally has no return.');
        $collecteMetal = (int)$retourAllie->metal - (int)$sansCollecte->metal->get();
        $collecteCristal = (int)$retourAllie->crystal - (int)$sansCollecte->crystal->get();
        $this->assertGreaterThan(0, $collecteMetal + $collecteCristal, 'The collecting ally brought no debris home: the collection left the field without entering any return.');

        $retourInitiateur = FleetMission::query()->where('parent_id', $initiatrice->id)->first();
        $this->assertNotNull($retourInitiateur);
        $sansCollecteInitiateur = $this->frozenReturnCargoWithoutCollection($combat, $initiatrice);
        $this->assertSame((int)$sansCollecteInitiateur->metal->get(), (int)$retourInitiateur->metal, 'The initiator, without Reapers, received a collection.');

        $this->assertDebrisConserved($debris, $collecteMetal, $collecteCristal);
    }

    /**
     * Place saturee : le butin remplit le retour de l'allie ; rien n'est collecte, et le champ garde
     * tout. Avant la correction, la collecte quittait quand meme le champ.
     */
    public function testWhenTheReturnIsFullNothingIsCollectedAndTheFieldKeepsEverything(): void
    {
        $this->targetStock = 100_000;
        [$combat, $cible, $initiatrice, $alliee] = $this->anAcsBattleReadyToSettle(
            // Un recycleur ralentit l'initiateur : l'allie aux Faucheurs, plus lents que les chasseurs,
            // doit pouvoir rejoindre l'union dans les 30 % de vol restant que la regle accorde.
            ['light_fighter' => 150, 'recycler' => 1],
            ['light_fighter' => 60, 'reaper' => 10],
            ['rocket_launcher' => 60, 'light_fighter' => 80]
        );

        $resultat = $this->frozenResultOf($combat);
        $debris = $resultat->debris;
        $this->assertGreaterThan(0, $debris->metal->get(), 'The battle produced no debris: nothing to keep.');
        $sansCollecte = $this->frozenReturnCargoWithoutCollection($combat, $alliee);
        foreach ($resultat->attackerFleetResults as $flotte) {
            if ((int)$flotte->fleetMissionId === (int)$alliee->id) {
                $this->assertSame((int)$flotte->survivingCargoCapacity, (int)$sansCollecte->sum(), 'The ally return is not saturated: this scenario does not test the cap.');
            }
        }

        $this->settle($combat);

        $retourAllie = FleetMission::query()->where('parent_id', $alliee->id)->first();
        $this->assertNotNull($retourAllie);
        $this->assertSame((int)$sansCollecte->metal->get(), (int)$retourAllie->metal, 'A saturated return received a collection.');
        $this->assertDebrisConserved($debris, 0, 0);
    }

    protected function createAcsAllyPlayer(): User
    {
        $allie = $this->createAcsAllyPlayerBase();
        DB::table('users')->where('id', $allie->id)->update(['character_class' => CharacterClass::GENERAL->value]);

        return $allie;
    }

    protected function createAcsTargetPlayer(): User
    {
        $cible = $this->createAcsTargetPlayerBase();
        DB::table('planets')->where('id', $this->acsTargetPlanet()->getPlanetId())->update([
            'metal' => $this->targetStock,
            'crystal' => $this->targetStock,
            'deuterium' => $this->targetStock,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        return $cible;
    }

    private function assertDebrisConserved(Resources $debris, int $collecteMetal, int $collecteCristal): void
    {
        $coordonnees = $this->acsTargetPlanet()->getPlanetCoordinates();
        $champ = DB::table('debris_fields')->where('galaxy', $coordonnees->galaxy)->where('system', $coordonnees->system)->where('planet', $coordonnees->position)->first(['metal', 'crystal']);
        $this->assertNotNull($champ, 'No debris field was created.');
        $this->assertSame((int)$debris->metal->get(), (int)$champ->metal + $collecteMetal, 'Metal debris left the game: field plus collection is not what the battle produced.');
        $this->assertSame((int)$debris->crystal->get(), (int)$champ->crystal + $collecteCristal, 'Crystal debris left the game: field plus collection is not what the battle produced.');
    }

    private function settle(CombatInstance $combat): void
    {
        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);
    }

    private function frozenResultOf(CombatInstance $combat): BattleResult
    {
        $combat->refresh();

        return BattleResultCodec::fromStorage($combat->battle_result);
    }

    /**
     * Le fret survivant plus la part de butin de cette flotte, tels que le resultat gele les porte.
     */
    private function frozenReturnCargoWithoutCollection(CombatInstance $combat, FleetMission $mission): Resources
    {
        foreach ($this->frozenResultOf($combat)->attackerFleetResults as $flotte) {
            if ((int)$flotte->fleetMissionId === (int)$mission->id) {
                return new Resources(
                    $flotte->survivingCargo->metal->get() + $flotte->lootShare->metal->get(),
                    $flotte->survivingCargo->crystal->get() + $flotte->lootShare->crystal->get(),
                    $flotte->survivingCargo->deuterium->get() + $flotte->lootShare->deuterium->get(),
                    0
                );
            }
        }
        $this->fail('The frozen result carries no entry for mission ' . $mission->id . '.');
    }
}
