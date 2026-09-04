<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Combat\Services\RefusedFleetHomecoming;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Le protocole commun de renvoi : ce qu'il refuse de faire, et ce qu'il ne laisse jamais a moitie.
 *
 * ## Les deux garanties eprouvees ici
 *
 * **Rien deux fois.** Une flotte deja traitee ne repart pas, quelle que soit la disposition qu'elle
 * porte encore. L'etat « traitee mais mouvement en attente » ne se produit pas par le jeu normal —
 * il vient d'une reparation manuelle ou d'une corruption —, et c'est precisement pour cela qu'il
 * doit etre tolere sans creer un second retour.
 *
 * **Rien a moitie.** Quand la destination refuse — plus aucun recours —, il ne doit rester ni avis
 * annonce, ni aller marque, ni disposition consommee : la flotte reste exactement comme avant,
 * visible et recuperable. Une flotte marquee traitee sans retour serait une disparition.
 */
class RefusedFleetHomecomingTest extends TestCase
{
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Une flotte deja traitee ne repart pas, meme si son mouvement est encore en attente.
     */
    public function testAFleetAlreadyProcessedIsNotSentHomeAgain(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        $registre = new FleetDispositionRegistry();

        $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_100, FleetDispositionKind::ReturnToOrigin);

        // Le modele que l'appelant tient decrit une flotte encore en vol ; la ligne, non.
        $perime = FleetMission::query()->findOrFail($mission->id);
        DB::table('fleet_missions')->where('id', $mission->id)->update(['processed' => 1]);

        $fait = (new RefusedFleetHomecoming())->sendHome($perime, 1_700_000_500, function (): void {
            $this->fail('A fleet already marked as processed was sent home a second time.');
        });

        $this->assertFalse($fait, 'The protocol claimed to have moved a fleet that had already left.');
        $this->assertNotNull($registre->pendingFor($mission), 'The decision was consumed without being carried out.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
    }

    /**
     * Une flotte sans aucun recours ne laisse ni avis, ni marquage, ni decision consommee.
     *
     * ## Ce que la transaction garantit, et ce que l'ordre du code ne garantit pas
     *
     * Le refus de destination survient au milieu du protocole : avis, marquage et retour viennent
     * apres. Ce n'est pas cet ordre qui protege — c'est la transaction, qui ramene tout en arriere
     * quel que soit l'endroit ou la panne se produit. Cet essai eprouve la garantie, pas l'ordre.
     */
    public function testAFleetWithNowhereToReturnLeavesNothingBehind(): void
    {
        [$combat, $mission, $origine] = $this->aCombatAndAFleet();
        $registre = new FleetDispositionRegistry();

        $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_100, FleetDispositionKind::ReturnToOrigin);

        // Le proprietaire n'a plus un seul corps vivant : aucun recours ne reste.
        DB::table('planets')->where('id', $origine->id)->update(['destroyed' => 1_700_000_050]);

        try {
            (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_500, function (): void {
                $this->fail('A return was created for a fleet that has nowhere to go.');
            });
            $this->fail('The protocol carried out a movement with no destination.');
        } catch (FleetHasNowhereToReturn $refus) {
            $this->assertSame($mission->id, $refus->fleetMissionId);
        }

        $mission->refresh();
        $this->assertSame(0, (int)$mission->processed, 'The fleet was marked as processed without a return: it disappeared.');
        $this->assertNotNull($registre->pendingFor($mission), 'The decision was consumed though nothing was carried out.');
        // **Pour cette flotte**, pas dans toute la table : d'autres classes d'essais laissent des
        // avis derriere elles dans le meme processus, et un compte global passait ou echouait selon
        // ses voisins.
        $this->assertSame(
            0,
            CombatOutboxMessage::query()->where('participant_key', CombatParticipantKey::forFleet($mission->id))->count(),
            'A refusal was announced for a movement that never happened.'
        );
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
    }

    /**
     * @return array{0: CombatInstance, 1: FleetMission, 2: Planet}
     */
    private function aCombatAndAFleet(): array
    {
        $joueur = User::factory()->create();
        $origine = $this->aBodyOf($joueur);
        $corps = $this->aBodyOf(User::factory()->create());

        $mission = FleetMission::forceCreate([
            'user_id' => $joueur->id,
            'planet_id_from' => $origine->id,
            'type_from' => 1,
            'galaxy_from' => $origine->galaxy,
            'system_from' => $origine->system,
            'position_from' => $origine->planet,
            'planet_id_to' => $corps->id,
            'type_to' => 1,
            'galaxy_to' => $corps->galaxy,
            'system_to' => $corps->system,
            'position_to' => $corps->planet,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        $combat = CombatInstance::create([
            'status' => CombatState::Active,
            'mission_id' => $mission->id,
            'target_planet_id' => $corps->id,
            'target_type' => 1,
            'galaxy' => $corps->galaxy,
            'system' => $corps->system,
            'position' => $corps->planet,
            'started_at' => 1_700_000_000,
        ]);

        return [$combat, $mission, $origine];
    }

    private function aBodyOf(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 6,
            'system' => 500 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
