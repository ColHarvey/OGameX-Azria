<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\LootReservationState;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Exceptions\UnknownSnapshotProjection;
use OGame\Combat\Projection\SnapshotProjectionV1;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatLootReservation;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatSnapshotInclusion;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * La fermeture du ralliement : la photographie se prend, une fois.
 *
 * ## Ce que ces essais protegent
 *
 * Le ralliement est une phase d'admission, pas un combat commence. A sa fermeture, les candidates
 * sont arbitrees et plus rien ne bouge. Trois choses doivent tenir :
 *
 *     fermer avant l'echeance exclurait des flottes qu'on avait promis d'attendre
 *     fermer deux fois ne doit rien faire de plus
 *     ce qui est admis vient des faits geles, pas du monde courant
 *
 * ## Ce qu'ils ne prouvent pas encore
 *
 * Ce qui reste : la borne de reservation ne se releve pas encore pour les Decouvreurs admis ni
 * pour une cargaison livree pendant le ralliement. Le dire evite de croire la fermeture terminee.
 *
 * Les inclusions sont desormais prouvees — y compris qu'elles portent la projection **de
 * l instance** et non la version courante. Les avis de refus le sont aussi : leur presence pour
 * une flotte renvoyee, et leur **absence** pour une flotte admise.
 */
class RallyClosureServiceTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    private CombatOpeningService $ouverture;

    private RallyClosureService $fermeture;

    /**
     * Le nombre de corps deja crees, pour en donner un different a chacun.
     */
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ouverture = new CombatOpeningService();
        $this->fermeture = new RallyClosureService();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Fermer avant l'echeance ne ferme rien.
     *
     * L'echeance a ete calculee a l'ouverture, sur les flottes qui seraient admises. Fermer avant
     * elle exclurait celles qu'on avait promis d'attendre.
     */
    public function testClosingBeforeTheDeadlineDoesNothing(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 30, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $issue = $this->fermeture->close($combat->id, self::OPENING + 10);

        $this->assertFalse($issue->closed);
        $this->assertSame('trop tot', $issue->reason);

        $combat->refresh();
        $this->assertSame(CombatState::Rallying, $combat->status);
    }

    /**
     * A l'echeance, la photographie se prend et les vagues admises deviennent participantes.
     */
    public function testAtTheDeadlineTheAdmittedWavesBecomeParticipants(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $vague = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $issue = $this->fermeture->close($combat->id, self::OPENING + 19);

        $this->assertTrue($issue->closed, 'The rally did not close at its own deadline.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);

        $cles = CombatParticipant::where('combat_instance_id', $combat->id)
            ->pluck('participant_key')
            ->all();

        $this->assertContains(
            CombatParticipantKey::forFleet($vague->id),
            $cles,
            'A wave admitted by the selector was not registered as a participant.'
        );
    }

    /**
     * Fermer deux fois ne fait rien de plus.
     *
     * Un message de file peut etre livre deux fois, un worker reprendre apres un redemarrage. La
     * seconde tentative doit constater et s'arreter, sans lever ni dupliquer.
     */
    public function testClosingTwiceDoesNothingMore(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $premiere = $this->fermeture->close($combat->id, self::OPENING + 19);
        $avant = CombatParticipant::where('combat_instance_id', $combat->id)->count();

        $seconde = $this->fermeture->close($combat->id, self::OPENING + 30);

        $this->assertTrue($premiere->closed);
        $this->assertFalse($seconde->closed, 'A second closure claimed to have closed an already closed rally.');
        $this->assertSame('deja fermee', $seconde->reason);

        $this->assertSame(
            $avant,
            CombatParticipant::where('combat_instance_id', $combat->id)->count(),
            'Closing twice registered the same participants a second time.'
        );
    }

    /**
     * Les budgets consommes sont ecrits avec la photographie.
     */
    public function testTheConsumedBudgetsAreWrittenWithTheSnapshot(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 19);

        $combat->refresh();

        $this->assertGreaterThan(
            0,
            $combat->fleets_admitted,
            'The closure wrote no fleet count: nothing would know how full the side is.'
        );

        $this->assertSame(
            1,
            $combat->players_admitted,
            'Several waves of one player were counted as several players.'
        );
    }

    /**
     * Les verrous se prennent dans l'ordre fixe par la migration de barriere.
     *
     * ## Le defaut que cet essai ferme
     *
     * L'ordre global est ecrit dans la migration : **corps, puis combat, puis union, puis missions
     * par identifiant trie**. La fermeture verrouillait l'instance en premier et la barriere ensuite,
     * pendant que son propre commentaire affirmait l'inverse.
     *
     * Le desaccord n'etait pas documentaire. Une jointure ou une resolution qui suivrait l'ordre
     * ecrit aurait attendu la barriere pendant que la fermeture attendait l'instance : deux
     * transactions, deux verrous, chacune tenant celui que l'autre demande.
     *
     * ## Ce que cet essai prouve, et ce qu'il ne prouve pas
     *
     * Il observe l'ordre reel des requetes, pas le texte du commentaire — c'est pour cela qu'il
     * ecoute la connexion au lieu de lire le source.
     *
     * **Il ne prouve pas l'absence d'interblocage.** SQLite ignore `for update` : seul MariaDB
     * pose de vrais verrous de ligne, et l'epreuve a deux connexions reste a faire. Ce qu'il
     * garantit, c'est que l'ordre d'acquisition ne repartira pas a l'envers sans que rien ne le
     * dise.
     */
    public function testTheLocksAreTakenInTheOrderTheBarrierMigrationFixes(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $tables = [];

        DB::listen(function (QueryExecuted $requete) use (&$tables): void {
            foreach (['celestial_body_combat_barriers', 'combat_instances', 'fleet_missions'] as $table) {
                if (str_contains($requete->sql, '"' . $table . '"') && !in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        });

        $this->fermeture->close($combat->id, self::OPENING + 1);

        $rangs = array_flip($tables);

        $this->assertArrayHasKey('celestial_body_combat_barriers', $rangs, 'The closure never touched the barrier.');
        $this->assertArrayHasKey('combat_instances', $rangs, 'The closure never touched the instance.');

        $this->assertLessThan(
            $rangs['combat_instances'],
            $rangs['celestial_body_combat_barriers'],
            'The instance is locked before the barrier: the reverse of the order the migration fixes.'
        );

        $this->assertLessThan(
            $rangs['fleet_missions'] ?? PHP_INT_MAX,
            $rangs['combat_instances'],
            'Candidate missions are read before the instance is held.'
        );
    }

    /**
     * Celle qui a ouvert le combat s'y bat.
     *
     * ## Le defaut que cet essai ferme
     *
     * Le selecteur ne rend pas le groupe fondateur dans son verdict, et il a raison : le fondateur
     * n'est pas admis, il ouvre — il n'y a rien a decider sur lui. La fermeture s'appuyait pourtant
     * sur ce verdict seul.
     *
     * **L'attaquant qui avait lance la bataille n'etait donc ni participant, ni dans la
     * photographie, ni compte dans les budgets consommes.** Un combat ouvert par une flotte unique
     * se serait ferme avec zero attaquant : le defenseur aurait gagne contre personne.
     *
     * Le defaut ne s'est vu qu'en ecrivant les inclusions, parce qu'un essai comptait enfin **tout
     * le monde** au lieu de chercher une vague nommee.
     */
    public function testTheFleetThatOpenedTheCombatFightsInIt(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $issue = $this->fermeture->close($combat->id, self::OPENING + 1);

        $this->assertTrue($issue->closed);

        $this->assertContains(
            CombatParticipantKey::forFleet($ouvreur->id),
            CombatParticipant::where('combat_instance_id', $combat->id)->pluck('participant_key')->all(),
            'The fleet that opened the combat was not registered as a participant.'
        );

        $combat->refresh();

        $this->assertSame(
            1,
            $combat->fleets_admitted,
            'The opening fleet was not counted in the consumed budgets.'
        );
        $this->assertSame(1, $combat->players_admitted);
    }

    /**
     * Chaque flotte admise entre une fois dans la photographie, avec ce qu'elle apporte.
     *
     * Une arrivee appliquee au monde mais absente de la photographie serait perdue pour la
     * bataille : ses vaisseaux ne combattraient pas alors qu'ils sont arrives.
     */
    public function testEveryAdmittedFleetEntersTheSnapshotOnce(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $vague = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 19);

        $inclusions = CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->get();

        $this->assertSame(
            2,
            $inclusions->count(),
            'The admitted fleets did not each enter the snapshot exactly once.'
        );

        foreach ([$ouvreur, $vague] as $mission) {
            $ligne = $inclusions->firstWhere(
                'event_identity',
                CombatEventIdentity::forFleetArrival($mission->id)
            );

            $this->assertNotNull($ligne, 'An admitted fleet is missing from the snapshot.');
            $this->assertSame(SnapshotContribution::AttackingFleet, $ligne->contribution);
        }
    }

    /**
     * L'inclusion est ecrite sous la projection de l'instance, pas sous la version courante.
     *
     * L'unicite porte sur combat / evenement / version. Lire la constante courante deux heures
     * apres l'ouverture ferait entrer le meme evenement une seconde fois apres une bascule — et
     * l'unicite ne verrait rien, puisqu'elle separe justement les versions.
     */
    public function testTheInclusionIsWrittenUnderTheProjectionOfTheInstance(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertSame(
            SnapshotProjectionV1::VERSION,
            $combat->projection_version,
            'The opening did not freeze the projection version with the instance.'
        );

        $this->fermeture->close($combat->id, self::OPENING + 1);

        $this->assertSame(
            [$combat->projection_version],
            CombatSnapshotInclusion::where('combat_instance_id', $combat->id)
                ->pluck('projection_version')
                ->unique()
                ->values()
                ->all()
        );
    }

    /**
     * Une projection inconnue arrete la fermeture au lieu de deviner.
     *
     * Un combat ouvert sous une projection que ce code ne sait plus lire ne se ferme pas « au
     * mieux » : ses inclusions signifieraient autre chose que ce qu'elles disent.
     */
    public function testAnUnknownProjectionStopsTheClosure(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $combat->projection_version = 'v-inconnue';
        $combat->save();

        $this->expectException(UnknownSnapshotProjection::class);

        $this->fermeture->close($combat->id, self::OPENING + 1);
    }

    /**
     * Fermer deux fois n'inclut pas deux fois.
     */
    public function testClosingTwiceDoesNotIncludeTwice(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->fermeture->close($combat->id, self::OPENING + 19);
        $avant = CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->count();

        $this->fermeture->close($combat->id, self::OPENING + 30);

        $this->assertSame(
            $avant,
            CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->count(),
            'A second closure added the same events to the snapshot again.'
        );
    }

    /**
     * Une flotte renvoyee apprend pourquoi, et une seule fois.
     *
     * ## Ce que cet essai protege
     *
     * Une flotte qui rentre sans explication ressemble a une panne : le joueur a paye le carburant,
     * attendu l'arrivee, et rien ne s'est passe. La raison d'admission est precisement ce qui
     * distingue une regle d'un bogue, de son point de vue.
     *
     * Le message est ecrit dans la transaction de la fermeture, pas envoye depuis elle : si la
     * transaction etait annulee, l'avis partirait avec elle plutot que d'annoncer un renvoi qui n'a
     * pas eu lieu.
     */
    public function testARefusedFleetIsToldWhy(): void
    {
        $ouvreurJoueur = $this->aPlayer();
        $etranger = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $ouvreurJoueur);

        // Sans alliance gouvernante, un joueur exterieur ne rejoint pas le groupe fondateur.
        $intruse = $this->anAttackAt($corps, self::OPENING + 10, $etranger);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $issue = $this->fermeture->close($combat->id, self::OPENING + 60);

        $this->assertTrue($issue->closed);

        $avis = CombatOutboxMessage::where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($intruse->id))
            ->get();

        $this->assertCount(1, $avis, 'The refused fleet was told nothing, or told twice.');

        $message = $avis->first();

        $this->assertNotNull($message);
        $this->assertSame(CombatOutboxKind::RallyRefused->value, $message->kind);
        $this->assertSame(
            CombatReasonCode::AllianceNotEligible->value,
            $message->payload['reason'] ?? null,
            'The refusal reason did not reach the player.'
        );
        $this->assertSame($corps, $message->payload['target_body_id'] ?? null);
        $this->assertNull($message->dispatched_at, 'The closure sent the message instead of queuing it.');
    }

    /**
     * L'heure du message est celle de l'echeance, pas celle du worker.
     *
     * Prendre l'instant du traitement ferait dependre la boite du moment ou le worker s'est
     * reveille : deux fermetures du meme combat ecriraient deux heures differentes, et l'unicite ne
     * les distinguerait pas pour autant.
     */
    public function testTheMessageCarriesTheDeadlineAndNotTheWorkerClock(): void
    {
        $ouvreurJoueur = $this->aPlayer();
        $etranger = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $ouvreurJoueur);
        $intruse = $this->anAttackAt($corps, self::OPENING + 10, $etranger);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $barriere = CelestialBodyCombatBarrier::where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($barriere);

        // Le worker se reveille tres en retard.
        $this->fermeture->close($combat->id, self::OPENING + 9_999);

        $message = CombatOutboxMessage::where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($intruse->id))
            ->first();

        $this->assertNotNull($message);
        $this->assertSame(
            $barriere->owned_through_effect_at,
            $message->available_at,
            'The message took the worker clock instead of the deadline that governs the combat.'
        );
    }

    /**
     * Fermer deux fois n'ecrit pas deux avis.
     */
    public function testClosingTwiceDoesNotAnnounceTwice(): void
    {
        $ouvreurJoueur = $this->aPlayer();
        $etranger = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $ouvreurJoueur);
        $this->anAttackAt($corps, self::OPENING + 10, $etranger);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->fermeture->close($combat->id, self::OPENING + 60);
        $avant = CombatOutboxMessage::where('combat_instance_id', $combat->id)->count();

        $this->fermeture->close($combat->id, self::OPENING + 120);

        $this->assertSame(1, $avant, 'Exactly one fleet was refused, so exactly one notice was due.');
        $this->assertSame(
            $avant,
            CombatOutboxMessage::where('combat_instance_id', $combat->id)->count(),
            'A second closure queued the same notice again.'
        );
    }

    /**
     * Une flotte admise ne recoit aucun avis de refus.
     *
     * **La contrepartie de l'essai precedent.** Ecrire un avis a tout le monde passerait le premier
     * essai tout aussi bien, et annoncerait a des joueurs admis que leur flotte repart.
     */
    public function testAnAdmittedFleetReceivesNoRefusalNotice(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $vague = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 19);

        $this->assertSame(
            0,
            CombatOutboxMessage::where('combat_instance_id', $combat->id)->count(),
            'An admitted fleet was told it had been turned away.'
        );

        // Et la vague est bien admise : sans cela l'essai ci-dessus ne prouverait rien.
        $this->assertContains(
            CombatParticipantKey::forFleet($vague->id),
            CombatParticipant::where('combat_instance_id', $combat->id)->pluck('participant_key')->all()
        );
    }

    /**
     * Un renfort defensif refuse est prevenu, lui aussi.
     *
     * ## La mutation qui a rendu cet essai necessaire
     *
     * Supprimer l'appel qui previent le **camp defenseur** a survecu a tous les autres essais : ils
     * ne refusaient que des attaquants. Un allie venu defendre aurait donc vu sa flotte repartir
     * sans un mot, et rien ne l'aurait signale.
     *
     * Le refus employe ici est `NotAlreadyInFlight` : une Defense ACS partie **a** l'instant de
     * l'ouverture ne volait pas encore quand le combat s'est ouvert — l'egalite avec une barriere
     * compte pour « apres », ici comme partout ailleurs.
     */
    public function testARefusedDefensiveReinforcementIsToldToo(): void
    {
        $defenseur = $this->aPlayer();
        $corps = $this->aPlanetOwnedBy($defenseur)->id;
        $attaquant = $this->aPlayer();
        $allie = $this->aPlayer();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $attaquant);

        $renfort = FleetMission::forceCreate([
            'user_id' => $allie->id,
            'planet_id_to' => $corps,
            'mission_type' => 5,
            // **Partie a l'ouverture, pas avant.** Elle ne volait donc pas encore.
            'time_departure' => self::OPENING,
            'time_arrival' => self::OPENING + 20,
            'galaxy_to' => 6,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 5,
        ]);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 60);

        $avis = CombatOutboxMessage::where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($renfort->id))
            ->first();

        $this->assertNotNull($avis, 'A refused defensive reinforcement was told nothing.');
        $this->assertSame(
            CombatReasonCode::NotAlreadyInFlight->value,
            $avis->payload['reason'] ?? null
        );
    }

    /**
     * L'ouverture immobilise la part pillable, et rien de plus.
     *
     * ## Les deux exces que cet essai encadre
     *
     * Ne rien immobiliser laisserait le defenseur vider ses caisses pendant les deux heures de
     * bataille : l'attaquant repartirait avec un butin calcule sur des ressources qui n'existent
     * plus.
     *
     * Tout immobiliser le punirait d'avoir ete attaque : il ne pourrait plus rien construire, pas
     * meme des defenses. **Ce qui est produit pendant la bataille lui appartient.**
     */
    public function testTheOpeningImmobilisesTheLootableShareAndNoMore(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $planete = Planet::find($corps);
        $this->assertNotNull($planete);

        $planete->metal = 100_000;
        $planete->crystal = 50_000;
        $planete->deuterium = 20_000;
        $planete->save();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $reservation = CombatLootReservation::where('combat_instance_id', $combat->id)->first();

        $this->assertNotNull($reservation, 'The opening immobilised nothing: the defender can empty his vaults.');
        $this->assertSame(LootReservationState::Open, $reservation->state);
        $this->assertSame($corps, $reservation->target_body_id);
        $this->assertSame(self::OPENING, $reservation->opened_at);

        // La moitie, taux de base : ni rien, ni tout.
        $this->assertSame(50_000, $reservation->metal);
        $this->assertSame(25_000, $reservation->crystal);
        $this->assertSame(10_000, $reservation->deuterium);
    }

    /**
     * Une seconde ouverture n'immobilise pas deux fois les memes ressources.
     *
     * Deux reservations sur un meme combat feraient distribuer le double a la resolution.
     */
    public function testASecondOpeningDoesNotImmobiliseTwice(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $premier = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $second = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertSame($premier->id, $second->id, 'A second opening created a second combat.');

        $this->assertSame(
            1,
            CombatLootReservation::where('combat_instance_id', $premier->id)->count(),
            'The same resources were immobilised twice.'
        );
    }

    /**
     * La fermeture scelle la reservation : la borne ne bouge plus.
     */
    public function testTheClosureSealsTheReservation(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $barriere = CelestialBodyCombatBarrier::where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($barriere);

        $this->fermeture->close($combat->id, self::OPENING + 500);

        $reservation = CombatLootReservation::where('combat_instance_id', $combat->id)->first();

        $this->assertNotNull($reservation);
        $this->assertSame(LootReservationState::Sealed, $reservation->state);
        $this->assertSame(
            $barriere->owned_through_effect_at,
            $reservation->sealed_at,
            'The seal took the worker clock instead of the deadline that governs the combat.'
        );
    }

    /**
     * Une reservation deja reglee ne se refait pas sceller.
     *
     * `SETTLED → SEALED` n'existe pas : le butin a ete preleve, et refiger la borne porterait sur
     * des ressources qui ont deja change de mains. Une reprise de la fermeture ne doit pas
     * l'ecraser.
     */
    public function testASettledReservationIsNotResealed(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $reservation = CombatLootReservation::where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($reservation);

        $reservation->state = LootReservationState::Settled;
        $reservation->settled_at = self::OPENING + 10;
        $reservation->save();

        $this->fermeture->close($combat->id, self::OPENING + 500);

        $reservation->refresh();

        $this->assertSame(
            LootReservationState::Settled,
            $reservation->state,
            'A settled reservation was sealed again: the loot would be handed over twice.'
        );
        $this->assertNull($reservation->sealed_at);
    }

    /**
     * Un combat inconnu ne fait pas lever la fermeture.
     */
    public function testAnUnknownCombatIsReportedRatherThanThrown(): void
    {
        $issue = $this->fermeture->close(999_999, self::OPENING);

        $this->assertFalse($issue->closed);
        $this->assertSame('combat introuvable', $issue->reason);
    }

    /**
     * Une attaque en vol vers ce corps.
     */
    private function anAttackAt(int $targetBodyId, int $arrivesAt, User|null $owner = null): FleetMission
    {
        $proprietaire = $owner ?? $this->aPlayer();

        return FleetMission::forceCreate([
            'user_id' => $proprietaire->id,
            'planet_id_to' => $targetBodyId,
            'mission_type' => 1,
            'time_departure' => self::OPENING - 600,
            'time_arrival' => $arrivesAt,
            'galaxy_to' => 6,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);
    }

    /**
     * Un joueur, avec une planete.
     */
    private function aPlayer(): User
    {
        $utilisateur = User::factory()->create();

        $this->aPlanetOwnedBy($utilisateur);

        return $utilisateur;
    }

    /**
     * Un corps celeste reel : `planet_id_to` porte une cle etrangere.
     */
    private function aBodyId(): int
    {
        return $this->aPlanetOwnedBy(User::factory()->create())->id;
    }

    /**
     * Une planete a des coordonnees libres, deterministes.
     */
    private function aPlanetOwnedBy(User $owner): Planet
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
