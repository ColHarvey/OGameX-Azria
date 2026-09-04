<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Exceptions\ContradictoryRefusalNotice;
use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Exceptions\ReturnDoesNotMatchTheOrder;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Combat\Services\RefusedFleetHomecoming;
use OGame\Combat\Services\ReturnPlanner;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\RefusedFleetNotice;
use OGame\Combat\Support\ReturnOrder;
use OGame\GameMissions\Models\ResolvedReturnDestination;
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
     * Un avis deja ecrit qui ne raconte pas la decision arrete le renvoi.
     *
     * La fermeture ecrit l'avis, le renvoi l'ecrit a son tour : « la premiere ligne a raison » ne
     * doit pas reapparaitre dans la boite d'envoi apres avoir ete retire du registre. Les deux
     * ecrivains derivent le meme contenu de la meme decision, ou la transaction s'arrete.
     */
    public function testANoticeThatDoesNotTellTheDecisionStopsTheHomecoming(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        $registre = new FleetDispositionRegistry();

        // La decision dit « ralliement ferme » ; un avis anterieur dit « limite de joueurs ».
        $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);
        RefusedFleetNotice::write($combat, $mission, CombatReasonCode::PlayerLimitReached, 1_700_000_600);

        $appele = false;

        try {
            (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function () use (&$appele): void {
                $appele = true;
            });
            $this->fail('The homecoming accepted a notice that contradicts the decision.');
        } catch (ContradictoryRefusalNotice $refus) {
            $this->assertSame($mission->id, $refus->fleetMissionId);
            $this->assertSame('reason', $refus->champ);
        }

        $this->assertFalse($appele, 'A return was created under a contradictory notice.');
        $this->assertSame(0, (int)$mission->refresh()->processed);
        $this->assertNotNull($registre->pendingFor($mission), 'The decision was consumed though nothing was carried out.');
    }

    /**
     * Un avis lisible a un autre instant que celui de la decision est aussi une contradiction.
     *
     * L'instant fait partie de ce que l'avis raconte : « ta flotte est repartie a telle heure ».
     * Deux ecrivains qui ne s'accordent pas sur lui ne racontent pas la meme decision.
     */
    public function testANoticeReadableAtAnotherInstantIsAContradiction(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();

        // Meme raison, mais l'avis anterieur date la decision de +600 quand la disposition la date
        // de +650 : posee depuis +600, la flotte repart a +650, et l'avis dit +600.
        (new FleetDispositionRegistry())->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_650, FleetDispositionKind::ReturnToOrigin);
        RefusedFleetNotice::write($combat, $mission, CombatReasonCode::RallyClosed, 1_700_000_600);

        try {
            (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre): void {
                $this->aReturnOf($tenue, $ordre);
            });
            $this->fail('The homecoming accepted a notice dated from another instant than the decision.');
        } catch (ContradictoryRefusalNotice $refus) {
            $this->assertSame('available_at', $refus->champ);
        }

        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
    }

    /**
     * Le meme avis, ecrit deux fois depuis la meme decision, n'est pas une contradiction.
     */
    public function testTheSameNoticeWrittenTwiceFromTheSameDecisionIsNotAContradiction(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();

        RefusedFleetNotice::write($combat, $mission, CombatReasonCode::RallyClosed, 1_700_000_600, 3);
        RefusedFleetNotice::write($combat, $mission, CombatReasonCode::RallyClosed, 1_700_000_600);

        $avis = CombatOutboxMessage::query()->where('participant_key', CombatParticipantKey::forFleet($mission->id))->get();
        $this->assertCount(1, $avis);
        $this->assertSame(3, (int)($avis->first()?->payload['group_fleets'] ?? 0), 'The narration detail known only to the closure was lost.');
    }

    /**
     * Le protocole exige exactement un nouveau retour du genre de mission, et le verifie.
     *
     * La creation appartient au genre de mission ; une fermeture arbitraire peut en creer zero —
     * la flotte disparait — ou deux — elle existe deux fois. Ni l'un ni l'autre ne sort de la
     * transaction.
     */
    public function testTheHomecomingRequiresExactlyOneReturn(): void
    {
        foreach ([0, 2] as $combien) {
            [$combat, $mission] = $this->aCombatAndAFleet();
            $registre = new FleetDispositionRegistry();
            $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

            try {
                (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre) use ($combien): void {
                    for ($i = 0; $i < $combien; $i++) {
                        $this->aReturnOf($tenue, $ordre);
                    }
                });
                $this->fail("A homecoming that created {$combien} returns was accepted.");
            } catch (ReturnDoesNotMatchTheOrder $refus) {
                $this->assertStringContainsString((string)$combien, $refus->ecart);
            }

            $this->assertSame(0, (int)$mission->refresh()->processed, "With {$combien} returns the fleet was still marked as processed.");
            $this->assertNotNull($registre->pendingFor($mission), "With {$combien} returns the decision was consumed.");
            $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count(), "With {$combien} returns something survived the rollback.");
        }
    }

    /**
     * Un retour deja present avant l'appel n'est pas pris pour celui que l'ordre demande.
     *
     * ## Ce que compter les enfants laissait passer
     *
     * Un enfant preexistant et une fermeture qui ne fait rien donnaient un total de un : l'aller
     * etait marque, la decision consommee, et le retour « execute » etait une ligne d'avant. Le
     * protocole photographie les retours avant l'appel et exige un seul **nouveau**.
     */
    public function testAReturnThatExistedBeforeTheCallIsNotTakenForTheOneOrdered(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        $registre = new FleetDispositionRegistry();
        $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

        // Un retour d'avant, de forme parfaitement plausible.
        $this->aReturnOf($mission, new ReturnOrder($this->theOriginAsDestinationOf($mission), 1_700_000_600));

        try {
            (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (): void {
                // La fermeture ne cree rien : elle compte sur ce qui existe.
            });
            $this->fail('A pre-existing return was accepted as the one the order asked for.');
        } catch (ReturnDoesNotMatchTheOrder $refus) {
            $this->assertStringContainsString('existe deja', $refus->ecart);
        }

        $this->assertSame(0, (int)$mission->refresh()->processed);
        $this->assertNotNull($registre->pendingFor($mission), 'The decision was consumed on a return that predates it.');
    }

    /**
     * Un retour unique qui ne suit pas l'ordre est refuse, champ par champ.
     *
     * Le genre de mission recoit un ordre — destination, depart — et l'aller dit ce que la flotte
     * porte. Un retour qui en differe sur l'un de ces points n'est pas le retour demande : la flotte
     * se poserait ailleurs, plus tot, ou amputee.
     */
    public function testASingleReturnThatDoesNotFollowTheOrderIsRefused(): void
    {
        $ecarts = [
            'time_departure' => fn (ReturnOrder $ordre): array => ['time_departure' => $ordre->departureAt + 1],
            'time_arrival' => fn (ReturnOrder $ordre): array => ['time_arrival' => $ordre->departureAt + 601],
            'planet_id_to' => fn (ReturnOrder $ordre): array => ['planet_id_to' => $ordre->destination->bodyId + 1],
            'galaxy_to' => fn (ReturnOrder $ordre): array => ['galaxy_to' => $ordre->destination->coordinate->galaxy + 1],
            'light_fighter' => fn (ReturnOrder $ordre): array => ['light_fighter' => 5],
            'metal' => fn (ReturnOrder $ordre): array => ['metal' => 1],
        ];

        foreach ($ecarts as $champ => $ecart) {
            [$combat, $mission] = $this->aCombatAndAFleet();
            $registre = new FleetDispositionRegistry();
            $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

            try {
                (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre) use ($ecart): void {
                    $this->aReturnOf($tenue, $ordre, $ecart($ordre));
                });
                $this->fail("A return with a wrong {$champ} was accepted.");
            } catch (ReturnDoesNotMatchTheOrder $refus) {
                $this->assertStringStartsWith($champ . ' vaut ', $refus->ecart, "The refusal did not name {$champ}.");
            }

            $this->assertSame(0, (int)$mission->refresh()->processed, "A return with a wrong {$champ} marked the outbound leg.");
            $this->assertNotNull($registre->pendingFor($mission), "A return with a wrong {$champ} consumed the decision.");
            $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
        }

        // Une flotte dupliquee est aussi un ecart : ses vaisseaux existeraient en plus grand nombre.
        [$combat, $mission] = $this->aCombatAndAFleet();
        (new FleetDispositionRegistry())->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

        try {
            (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre): void {
                $this->aReturnOf($tenue, $ordre, ['light_fighter' => 20]);
            });
            $this->fail('A return carrying twice the ships was accepted.');
        } catch (ReturnDoesNotMatchTheOrder $refus) {
            $this->assertStringStartsWith('light_fighter vaut 20', $refus->ecart);
        }
    }

    /**
     * Une fermeture rejouee avec une autre taille de groupe est une contradiction.
     *
     * La taille n'est pas un fait du mouvement, mais c'est un contenu que le joueur lit : « ta
     * vague de trois » et « ta vague de quatre » ne racontent pas le meme refus. Quand l'ecrivain la
     * connait, elle est comparee ; quand il ne la connait pas, elle est preservee.
     */
    public function testAReplayedClosureWithAnotherGroupSizeIsAContradiction(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();

        RefusedFleetNotice::write($combat, $mission, CombatReasonCode::RallyClosed, 1_700_000_600, 3);

        try {
            RefusedFleetNotice::write($combat, $mission, CombatReasonCode::RallyClosed, 1_700_000_600, 4);
            $this->fail('A replayed closure changed the group size the player will read.');
        } catch (ContradictoryRefusalNotice $refus) {
            $this->assertSame('group_fleets', $refus->champ);
        }

        $avis = CombatOutboxMessage::query()->where('participant_key', CombatParticipantKey::forFleet($mission->id))->first();
        $this->assertSame(3, (int)($avis?->payload['group_fleets'] ?? 0), 'The persisted size was replaced.');
    }

    /**
     * Le depart et la destination viennent de l'ordre, et l'ordre vient de la decision.
     *
     * Un travailleur ponctuel et un travailleur en retard recoivent le meme ordre : le protocole ne
     * regarde jamais l'horloge pour dater le depart.
     */
    public function testTheOrderDatesTheDepartureFromTheDecisionNotTheClock(): void
    {
        [$combat, $mission, $origine] = $this->aCombatAndAFleet();
        (new FleetDispositionRegistry())->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_650, FleetDispositionKind::ReturnToOrigin);

        $recu = null;
        (new RefusedFleetHomecoming())->sendHome($mission, 1_700_009_999, function (FleetMission $tenue, ReturnOrder $ordre) use (&$recu): void {
            $recu = $ordre;
            $this->aReturnOf($tenue, $ordre);
        });

        $this->assertNotNull($recu, 'The mission kind never received its order.');
        // Decidee a +650 alors que la flotte est posee depuis +600 : elle repart a +650, et
        // l'horloge du travailleur — bien plus tard — n'apparait nulle part.
        $this->assertSame(1_700_000_650, $recu->departureAt);
        $this->assertSame($origine->id, $recu->destination->bodyId);
    }

    /**
     * La destination que l'ordre donnerait a une flotte dont l'origine est intacte.
     */
    private function theOriginAsDestinationOf(FleetMission $mission): ResolvedReturnDestination
    {
        return ResolvedReturnDestination::from((new ReturnPlanner())->planFor($mission), $mission);
    }

    /**
     * @param array<string, int> $ecarts Ce que le genre de mission aurait ecrit de travers.
     */
    private function aReturnOf(FleetMission $parent, ReturnOrder $ordre, array $ecarts = []): void
    {
        FleetMission::forceCreate($ecarts + [
            'parent_id' => $parent->id,
            'user_id' => $parent->user_id,
            'planet_id_from' => $parent->planet_id_to,
            'type_from' => 1,
            'planet_id_to' => $ordre->destination->bodyId,
            'type_to' => $ordre->destination->type->value,
            'galaxy_to' => $ordre->destination->coordinate->galaxy,
            'system_to' => $ordre->destination->coordinate->system,
            'position_to' => $ordre->destination->coordinate->position,
            'mission_type' => 1,
            'time_departure' => $ordre->departureAt,
            'time_arrival' => $ordre->departureAt + 600,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
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
