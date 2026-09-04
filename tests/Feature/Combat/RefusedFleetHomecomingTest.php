<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Exceptions\ContradictoryRefusalNotice;
use OGame\Combat\Exceptions\CorruptedResourceAmount;
use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Exceptions\ReturnDoesNotMatchTheOrder;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Combat\Services\RefusedFleetHomecoming;
use OGame\Combat\Services\ReturnPlanner;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\ExpectedReturn;
use OGame\Combat\Support\RefusedFleetNotice;
use OGame\Combat\Support\ReturnOrder;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
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

        // **L'horloge vit a l'epoque des faits.** Un retour dont l'arrivee est deja passee a sa
        // creation est traite aussitot par `startReturn()`, et la projection l'impose. Les missions
        // de cette classe partent a 1 700 000 000 ; une horloge d'aujourd'hui les ferait toutes
        // « arriver » avant d'exister, et chaque retour serait attendu traite.
        $this->travelTo(Date::createFromTimestamp(1_700_000_650));

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
            // **L'origine, les rattachements, le stationnement, le carburant** : un enfant unique
            // qui part d'un autre corps, reste inscrit quelque part, stationne ou recredite du
            // carburant n'est pas le retour demande.
            'planet_id_from' => fn (ReturnOrder $ordre): array => ['planet_id_from' => null],
            'time_holding' => fn (ReturnOrder $ordre): array => ['time_holding' => 5],
            'deuterium_consumption' => fn (ReturnOrder $ordre): array => ['deuterium_consumption' => 1],
            'processed' => fn (ReturnOrder $ordre): array => ['processed' => 1],
            // Une fraction n'est pas un entier : `0.5` n'est pas `0`, et une conversion entiere
            // l'y aurait ramenee en silence — exactement la valeur attendue, par accident.
            'crystal' => fn (ReturnOrder $ordre): array => ['crystal' => 0.5],
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
                $this->assertStringStartsWith($champ . ' ', $refus->ecart, "The refusal did not name {$champ}.");
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
     * Le retour attendu se fige avant l'appel : amputer l'aller puis creer un enfant ampute ne passe pas.
     *
     * ## Ce que relire l'aller apres l'appel laissait passer
     *
     * Le verificateur comparait l'enfant a l'aller relu **apres** la fermeture. Une fermeture
     * defectueuse pouvait donc reduire l'aller, creer un enfant a sa nouvelle mesure, et passer :
     * les deux lignes se ressemblaient, et les vaisseaux avaient disparu. La projection se fige
     * avant l'appel ; la transaction ramene l'aller et l'enfant en arriere.
     */
    public function testAmputatingTheOutboundLegThenReturningAnAmputatedFleetIsRefusedAndUndone(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        $registre = new FleetDispositionRegistry();
        $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

        $this->assertSame(10, (int)$mission->light_fighter);

        try {
            (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre): void {
                DB::table('fleet_missions')->where('id', $tenue->id)->update(['light_fighter' => 5]);
                $this->aReturnOf($tenue, $ordre, ['light_fighter' => 5]);
            });
            $this->fail('An amputated fleet was accepted because the outbound leg had been amputated to match.');
        } catch (ReturnDoesNotMatchTheOrder $refus) {
            $this->assertStringStartsWith('light_fighter ', $refus->ecart);
        }

        $this->assertSame(10, (int)$mission->refresh()->light_fighter, 'The amputation of the outbound leg survived the rollback.');
        $this->assertSame(0, (int)$mission->processed);
        $this->assertNotNull($registre->pendingFor($mission));
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
    }

    /**
     * Un retour rattache a une union n'est pas le retour demande.
     */
    public function testAReturnStillBoundToAUnionIsRefused(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        (new FleetDispositionRegistry())->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

        $union = FleetUnion::create([
            'user_id' => $mission->user_id,
            'name' => null,
            'galaxy_to' => $mission->galaxy_to,
            'system_to' => $mission->system_to,
            'position_to' => $mission->position_to,
            'planet_type_to' => $mission->type_to,
            'time_arrival' => $mission->time_arrival,
            'max_fleets' => 16,
            'max_players' => 5,
        ]);

        try {
            (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre) use ($union): void {
                $this->aReturnOf($tenue, $ordre, ['union_id' => $union->id, 'union_slot' => 2, 'mission_type' => 2]);
            });
            $this->fail('A return still bound to a union was accepted.');
        } catch (ReturnDoesNotMatchTheOrder $refus) {
            $this->assertMatchesRegularExpression('/^(mission_type|union_id|union_slot) /', $refus->ecart);
        }

        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
    }

    /**
     * La projection est fermee : une colonne que personne n'a classee fait refuser le retour.
     *
     * Une liste de champs oublie le prochain champ. La projection compare chaque colonne de la table
     * a une valeur imposee, ou la declare sans effet ; une colonne ajoutee demain sans etre classee
     * rougit ici, au lieu d'ouvrir un trou.
     */
    public function testEveryColumnOfAFleetMissionIsEitherImposedOrDeclaredWithoutEffect(): void
    {
        [, $mission, $origine] = $this->aCombatAndAFleet();
        $ordre = new ReturnOrder($this->theOriginAsDestinationOf($mission), 1_700_000_600);

        $projection = ExpectedReturn::of($mission, $ordre);
        $sansEffet = ['id', 'created_at', 'updated_at', 'target_priority', 'retreat_after_defender_retreat'];

        foreach (Schema::getColumnListing('fleet_missions') as $colonne) {
            $this->assertTrue(
                in_array($colonne, $sansEffet, true) || array_key_exists($colonne, $projection->imposees),
                "The column {$colonne} is neither imposed nor declared without effect: a return could differ on it and pass."
            );
        }

        unset($origine);
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
    /**
     * Une colonne economique abimee sur l'aller arrete le renvoi avant tout effet.
     *
     * ## Ce que le transtypage entier laissait passer
     *
     * La projection convertissait la cargaison par `(int)`, et `startReturn()` faisait exactement la
     * meme chose sur l'enfant. Une cargaison de `10.9` devenait donc `10` **des deux cotes** : la
     * comparaison etait satisfaite, et neuf dixiemes d'unite disparaissaient sans que rien ne le
     * dise. Une valeur juste et une valeur fausse coincidaient, et l'essai qui les comparait ne
     * prouvait rien.
     *
     * La frontiere economique refuse maintenant la colonne. Rien n'est appele, rien n'est cree, et
     * la disposition reste a consommer : la flotte attend plutot que de rentrer amputee.
     */
    public function testAnEconomicColumnCarryingAFractionStopsTheHomecomingBeforeAnything(): void
    {
        $abimees = [
            'metal' => 10.9,
            'crystal' => 0.5,
            'deuterium' => 7.25,
            // Le carburant consomme entre dans le calcul du retour : une fraction dessus se
            // propagerait au demi rendu, et le plancher la ferait disparaitre pareillement.
            'deuterium_consumption' => 3.5,
            // Une dette d'une unite ou plus n'est pas un artefact d'arrondi.
            'light_fighter_cargo_negative' => -2.0,
        ];

        foreach ($abimees as $nom => $valeur) {
            $colonne = $nom === 'light_fighter_cargo_negative' ? 'metal' : $nom;

            [$combat, $mission] = $this->aCombatAndAFleet();
            DB::table('fleet_missions')->where('id', $mission->id)->update([$colonne => $valeur]);
            $mission->refresh();

            $registre = new FleetDispositionRegistry();
            $registre->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

            $appele = false;

            try {
                (new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre) use (&$appele): void {
                    $appele = true;
                    $this->aFaithfulReturnOf($tenue, $ordre);
                });
                $this->fail("A fleet whose {$colonne} column held {$valeur} was sent home anyway.");
            } catch (CorruptedResourceAmount $refus) {
                $this->assertStringContainsString($colonne, $refus->getMessage(), "The refusal did not name the {$colonne} column.");
            }

            $this->assertFalse($appele, "The creator ran although the {$colonne} column was already unusable.");
            $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count(), 'A return was created from a broken column.');
            $this->assertSame(0, (int)$mission->refresh()->processed, 'The outbound leg was marked although nothing came home.');
            $this->assertNotNull($registre->pendingFor($mission), 'The decision was consumed although nothing came home.');
        }
    }

    /**
     * La moitie du carburant rendue reste un demi legitime, et le plancher ne refuse pas le retour.
     *
     * ## Pourquoi ce demi-la n'est pas une donnee abimee
     *
     * `FleetMissionService::getResources()` rend la cargaison **plus la moitie du carburant
     * consomme** — une regle du jeu, appliquee a tous les genres de mission depuis l'amont. Une
     * consommation impaire y produit donc `x,5` legitimement. Refuser cette fraction refuserait un
     * retour sur deux et laisserait des flottes posees sur le corps qu'elles doivent quitter : le
     * controle porte sur ce que la base **porte**, pas sur ce que le calcul produit.
     */
    public function testTheHalfFuelGivenBackIsFlooredAndDoesNotRefuseTheReturn(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        DB::table('fleet_missions')->where('id', $mission->id)->update(['deuterium' => 100, 'deuterium_consumption' => 639]);
        $mission->refresh();

        // La valeur que le jeu fabrique ici est bien fractionnaire : sans cela l'essai ne prouverait rien.
        $this->assertSame(419.5, resolve(FleetMissionService::class)->getResources($mission)->deuterium->get());

        (new FleetDispositionRegistry())->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

        $this->assertTrue((new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre): void {
            $this->aFaithfulReturnOf($tenue, $ordre);
        }));

        $retour = FleetMission::query()->where('parent_id', $mission->id)->firstOrFail();
        $this->assertSame(419, (int)$retour->deuterium, 'The half unit of fuel was not floored the way the game floors it.');
        $this->assertSame(0, (int)$retour->deuterium_consumption, 'The return re-credited fuel of its own.');
    }

    /**
     * Une fortune au-dela de deux puissance cinquante-trois rentre : sa precision est degradee, pas fausse.
     *
     * Le refus de fraction ne mord jamais la : dans cette zone, tout flottant est deja entier. Une
     * planete assez riche ne gagne donc pas une immunite au renvoi.
     */
    public function testACargoInTheDegradedPrecisionZoneStillComesHome(): void
    {
        [$combat, $mission] = $this->aCombatAndAFleet();
        DB::table('fleet_missions')->where('id', $mission->id)->update(['metal' => 9007199254740992.0]);
        $mission->refresh();

        $porte = (float)$mission->metal;
        $this->assertGreaterThanOrEqual(9007199254740992.0, $porte, 'The column did not keep a value in the degraded zone.');
        $this->assertSame(floor($porte), $porte, 'The scenario is not conclusive: the stored value is not a whole number.');

        (new FleetDispositionRegistry())->record($combat, $mission->id, CombatReasonCode::RallyClosed, 1_700_000_600, FleetDispositionKind::ReturnToOrigin);

        $this->assertTrue((new RefusedFleetHomecoming())->sendHome($mission, 1_700_000_900, function (FleetMission $tenue, ReturnOrder $ordre): void {
            $this->aFaithfulReturnOf($tenue, $ordre);
        }));

        $retour = FleetMission::query()->where('parent_id', $mission->id)->firstOrFail();
        $this->assertSame($porte, (float)$retour->metal, 'The fortune lost units on the way home.');
    }

    private function theOriginAsDestinationOf(FleetMission $mission): ResolvedReturnDestination
    {
        return ResolvedReturnDestination::from((new ReturnPlanner())->planFor($mission), $mission);
    }

    /**
     * Le retour tel qu'un genre de mission correct le cree, avec ce qu'il aurait pu ecrire de travers.
     *
     * @param array<string, int|float|null> $ecarts Ce que le genre de mission aurait ecrit de travers.
     */
    private function aReturnOf(FleetMission $parent, ReturnOrder $ordre, array $ecarts = []): void
    {
        FleetMission::forceCreate($ecarts + [
            'parent_id' => $parent->id,
            'user_id' => $parent->user_id,
            'planet_id_from' => $parent->planet_id_to,
            'type_from' => $parent->type_to,
            'galaxy_from' => $parent->galaxy_to,
            'system_from' => $parent->system_to,
            'position_from' => $parent->position_to,
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
     * Le retour tel qu'un genre de mission correct le cree, ressources comprises.
     *
     * Il lit les ressources par le service, comme `startReturn()`, et les transtype comme lui : ce
     * createur ne falsifie rien, et c'est justement ce qui rend le controle de la projection
     * significatif — la troncature qu'il applique doit etre celle que la projection attend.
     */
    private function aFaithfulReturnOf(FleetMission $parent, ReturnOrder $ordre): void
    {
        $ressources = resolve(FleetMissionService::class)->getResources($parent);

        $this->aReturnOf($parent, $ordre, [
            'metal' => (int)$ressources->metal->get(),
            'crystal' => (int)$ressources->crystal->get(),
            'deuterium' => (int)$ressources->deuterium->get(),
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
