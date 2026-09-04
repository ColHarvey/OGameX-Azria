<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\Combat\Allocation\CappedLoot;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\LootAllocator;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Exceptions\ContradictorySnapshotInclusion;
use OGame\Combat\Exceptions\UnknownSnapshotProjection;
use OGame\Combat\Models\CombatDurationEstimate;
use OGame\Combat\Projection\SnapshotProjectionV1;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\CombatDurationEstimator;
use OGame\Combat\Services\CombatEngagementService;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatLootReservation;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatSnapshotInclusion;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use RuntimeException;
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
 * **Aucune ressource n'est immobilisee**, et c'est une decision de jeu, pas une lacune : le
 * defenseur peut depenser pendant le combat, et le reglement se fait a la resolution par
 * `min(butin potentiel, ressources restantes)`.
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

        // **Personne n'est attendu : l'ouverture ferme le ralliement elle-meme.** C'est la
        // protection contre le harcelement — une fenetre nulle ne fait pas attendre le corps.
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'A rally nobody was expected at stayed open.');

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
            $this->assertSame(
                [SnapshotContribution::AttackingFleet->value],
                $ligne->contributions,
                'The inclusion does not carry the canonical set of what this event brought.'
            );
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
        // **Une seconde vague, pour que la fenetre ne soit pas nulle.** Sans personne a
        // attendre, l'ouverture ferme le ralliement dans sa propre transaction : cet essai
        // n'aurait plus de cloture a gouverner.
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $combat->projection_version = 'v-inconnue';
        $combat->save();

        $this->expectException(UnknownSnapshotProjection::class);

        $this->fermeture->close($combat->id, self::OPENING + 19);
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

        // Sans alliance gouvernante, un joueur exterieur ne rejoint pas le groupe fondateur —
        // et n'est donc pas attendu : c'est la seconde vague de l'ouvreur qui tient la fenetre
        // ouverte assez longtemps pour que l'intruse arrive et soit jugee.
        $intruse = $this->anAttackAt($corps, self::OPENING + 10, $etranger);
        $this->anAttackAt($corps, self::OPENING + 18, $ouvreurJoueur);

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
     * Aucune ressource n'est immobilisee : le defenseur peut depenser pendant le combat.
     *
     * ## La regle, et pourquoi elle a ete rappelee
     *
     * Une reservation avait ete branchee ici : la part pillable etait immobilisee des l'ouverture,
     * puis scellee a la fermeture. Elle contredisait une **decision de jeu deja arretee**, et je
     * l'avais mise en oeuvre sans m'en apercevoir.
     *
     * La regle de premiere version est celle-ci, composante par composante :
     *
     *     a l arrivee    le combat gele son issue et son butin potentiel
     *     pendant        production et depenses normales continuent
     *     a la fin       butin applique = min(butin potentiel, ressources restantes)
     *
     * Elle empeche deux abus a la fois : l'attaquant ne prend jamais la production arrivee apres
     * l'ouverture au-dela de son potentiel gele, et **le defenseur peut sauver ce qui reste en le
     * depensant legalement**.
     *
     * La difference n'est pas technique. Avec une reservation, un defenseur qui vide ses caisses ne
     * sauve rien ; sans elle, il sauve ce qu'il a eu le temps de depenser. Ce sont deux jeux, et
     * c'est le second qui a ete choisi.
     *
     * ## Pourquoi cet essai existe plutot que rien
     *
     * Retirer du code ne laisse aucune trace : rien n'empeche le raccordement de revenir, et il
     * reviendrait sous la meme bonne intention. Cet essai est ce qui reste de la decision une fois
     * le code parti.
     */
    public function testNoResourceIsImmobilisedByAnOpeningOrAClosure(): void
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

        $this->assertSame(
            0,
            CombatLootReservation::where('combat_instance_id', $combat->id)->count(),
            'The opening immobilised resources: the defender can no longer spend during the combat.'
        );

        $this->fermeture->close($combat->id, self::OPENING + 60);

        $this->assertSame(
            0,
            CombatLootReservation::where('combat_instance_id', $combat->id)->count(),
            'The closure immobilised resources.'
        );

        // Et le stock du corps est intact : rien n'a ete deduit, ni marque.
        $planete->refresh();

        $this->assertSame(100_000, (int)$planete->metal);
        $this->assertSame(50_000, (int)$planete->crystal);
        $this->assertSame(20_000, (int)$planete->deuterium);
    }

    /**
     * Le meme evenement avec un autre ensemble s'arrete : c'est deux verites sur un meme fait.
     *
     * ## Le defaut que cet essai ferme
     *
     * `updateOrCreate()` ecrasait en silence. Sur un rejeu qui aurait calcule autre chose — une
     * regression, une projection mal lue — la derniere tentative l'emportait, et rien ne le disait.
     *
     * Rejouer a l'identique doit etre sans effet ; rejouer autre chose n'est pas un rejeu.
     */
    public function testTheSameEventWithADifferentSetIsRefused(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->fermeture->close($combat->id, self::OPENING + 1);

        $inclusion = CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($inclusion);

        // Quelqu'un a inscrit autre chose sous la meme identite.
        $inclusion->contributions = [SnapshotContribution::DefendingFleet->value];
        $inclusion->save();

        // Le combat repasse en ralliement pour que la fermeture recommence son travail.
        //
        // **`refresh()` d abord.** L instance en memoire porte encore `Rallying` : lui reaffecter
        // la meme valeur ne la rend pas modifiee, et `save()` n ecrit alors rien du tout.
        $combat->refresh();
        $combat->status = CombatState::Rallying;
        $combat->save();

        $this->expectException(ContradictorySnapshotInclusion::class);

        $this->fermeture->close($combat->id, self::OPENING + 1);
    }

    /**
     * Une projection etrangere a l'instance est refusee **avant** toute ecriture.
     *
     * La projection ne fait plus partie de la clef d'unicite — c'etait une erreur de l'y mettre,
     * puisqu'une instance n'en a qu'une. Mais du coup, plus rien d'autre n'attraperait une inclusion
     * ecrite sous une version que le combat ne porte pas : le controle doit venir en amont.
     */
    public function testAProjectionForeignToTheInstanceIsRefusedBeforeAnyWrite(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        // **Une seconde vague, pour que la fenetre ne soit pas nulle.** Sans personne a
        // attendre, l'ouverture ferme le ralliement dans sa propre transaction : cet essai
        // n'aurait plus de cloture a gouverner.
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $combat->projection_version = 'projection_que_ce_code_ignore';
        $combat->save();

        try {
            $this->fermeture->close($combat->id, self::OPENING + 19);

            $this->fail('A combat under an unknown projection was closed anyway.');
        } catch (UnknownSnapshotProjection $arret) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            0,
            CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->count(),
            'An inclusion was written before the projection was checked.'
        );
    }

    /**
     * Deux combats peuvent inclure le meme evenement sans se confondre.
     *
     * L'unicite porte sur combat **et** evenement. Deux batailles successives sur la meme planete
     * lisent toutes deux sa garnison : une unicite sur l'evenement seul aurait fait disparaitre la
     * garnison de la seconde.
     */
    public function testTwoCombatsCanIncludeTheSameEventWithoutConfusion(): void
    {
        $joueur = $this->aPlayer();
        $premierCorps = $this->aBodyId();
        $secondCorps = $this->aBodyId();

        $ouvreurA = $this->anAttackAt($premierCorps, self::OPENING, $joueur);
        $ouvreurB = $this->anAttackAt($secondCorps, self::OPENING, $joueur);

        $combatA = $this->ouverture->openOrJoin($ouvreurA, $premierCorps, self::OPENING);
        $combatB = $this->ouverture->openOrJoin($ouvreurB, $secondCorps, self::OPENING);

        $this->assertNotSame($combatA->id, $combatB->id);

        $this->fermeture->close($combatA->id, self::OPENING + 1);
        $this->fermeture->close($combatB->id, self::OPENING + 1);

        // Le meme identifiant d'evenement, inscrit a la main dans les deux photographies.
        $partage = CombatEventIdentity::forFleetArrival(999_001);

        foreach ([$combatA, $combatB] as $combat) {
            CombatSnapshotInclusion::query()->create([
                'combat_instance_id' => $combat->id,
                'event_identity' => $partage,
                'projection_version' => $combat->projection_version,
                'contributions' => [SnapshotContribution::DefendingFleet->value],
                'included_at' => self::OPENING,
            ]);
        }

        $this->assertSame(
            2,
            CombatSnapshotInclusion::where('event_identity', $partage)->count(),
            'The same event could not enter two different snapshots.'
        );
    }

    /**
     * Un rappel termine avant la fermeture exclut la flotte.
     *
     * ## La premiere des deux issues admises
     *
     * La fermeture relit deliberement les rappels dans le monde courant — c'est l'un des deux seuls
     * faits qu'elle ne prend pas de l'ouverture. Une flotte rappelee a fait demi-tour : elle
     * n'arrive pas, elle ne se bat pas, elle n'entre pas dans la photographie.
     */
    public function testARecallFinishedBeforeTheClosureExcludesTheFleet(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $rappelee = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        // Le rappel aboutit avant que la fermeture ne commence.
        $rappelee->canceled = 1;
        $rappelee->save();

        $this->fermeture->close($combat->id, self::OPENING + 19);

        $cles = CombatParticipant::where('combat_instance_id', $combat->id)
            ->pluck('participant_key')
            ->all();

        $this->assertNotContains(
            CombatParticipantKey::forFleet($rappelee->id),
            $cles,
            'A recalled fleet was registered as a participant: it turned back and never arrived.'
        );

        $this->assertSame(
            0,
            CombatSnapshotInclusion::where('combat_instance_id', $combat->id)
                ->where('event_identity', CombatEventIdentity::forFleetArrival($rappelee->id))
                ->count(),
            'A recalled fleet entered the snapshot.'
        );

        // L'ouvreur, lui, reste : le rappel d'une vague ne dissout pas le combat.
        $this->assertContains(CombatParticipantKey::forFleet($ouvreur->id), $cles);
    }

    /**
     * Un rappel arrive apres la fermeture ne retire rien de la photographie.
     *
     * ## La seconde des deux issues admises
     *
     * La photographie est prise ; elle ne se reecrit pas. Laisser un rappel tardif retirer une
     * flotte deja inscrite donnerait au joueur un moyen de defaire une bataille engagee, apres avoir
     * vu qui y participait.
     *
     * **Il n'existe pas de troisieme resultat.** Une lecture qui verrait la mission a moitie
     * rappelee — inscrite ici, absente la — n'est admise dans aucun des deux sens.
     */
    public function testARecallArrivingAfterTheClosureRemovesNothing(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $vague = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 19);

        $avant = CombatParticipant::where('combat_instance_id', $combat->id)->count();

        // Trop tard : la photographie est prise.
        $vague->canceled = 1;
        $vague->save();

        $this->assertSame(
            $avant,
            CombatParticipant::where('combat_instance_id', $combat->id)->count(),
            'A late recall unmade a snapshot that was already taken.'
        );

        $this->assertContains(
            CombatParticipantKey::forFleet($vague->id),
            CombatParticipant::where('combat_instance_id', $combat->id)->pluck('participant_key')->all(),
            'A fleet recalled after the closure left the battle it had already joined.'
        );
    }

    /**
     * La lecture des candidates demande un verrou.
     *
     * ## Pourquoi une garde de source, et non une observation
     *
     * J'ai d'abord ecrit cet essai en ecoutant la connexion, pour voir passer un `for update`. Il a
     * echoue — non parce que le verrou manquait, mais parce que **SQLite n'emet rien** : sa
     * grammaire ignore `lockForUpdate()`, et la requete sort identique a une lecture ordinaire.
     *
     * L'essai ne pouvait donc pas distinguer un verrou pris d'un verrou oublie. Le garder sous cette
     * forme aurait laisse croire a une preuve qu'il ne donnait pas.
     *
     * Cette garde lit le source, comme celles qui surveillent les versions courantes ou les cles de
     * participant. Elle prouve qu'un futur passage n'enlevera pas le verrou sans que rien ne le
     * dise. **Elle ne prouve pas que le verrou tient** : cela demande deux connexions MariaDB, et
     * cette epreuve reste a faire.
     *
     * ## Ce que le verrou protege
     *
     * La fermeture relit les rappels dans le monde courant — c'est voulu. Sans verrou, un rappel
     * concurrent peut se glisser entre cette lecture et l'inscription des participants : la flotte
     * serait inscrite au combat alors qu'elle a fait demi-tour. C'est la troisieme issue, celle
     * qu'aucun des deux sens n'admet.
     */
    public function testTheCandidateReaderAsksForALock(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/app/Combat/Services/RallyCandidateReader.php'
        );

        $this->assertIsString($source);

        // **Les fins de ligne d abord.** Le depot est en CRLF sur le disque : un motif ecrit
        // avec des sauts de ligne simples ne correspond a rien, et la garde echouerait en
        // accusant le code plutot que sa propre lecture.
        $source = str_replace("\r\n", "\n", $source);

        $this->assertStringContainsString(
            '->lockForUpdate()',
            $source,
            'The candidate reader no longer asks for a lock: a concurrent recall could slip between the read and the registration.'
        );

        // **Par identifiant croissant**, parce que c'est l'ordre global que la migration de barriere
        // fixe. Deux transactions qui verrouillent les memes lignes dans le meme ordre ne s'attendent
        // jamais en rond.
        $this->assertStringContainsString(
            "->orderBy('id')\n            ->lockForUpdate()",
            $source,
            'The reader locks candidate missions in an order other than ascending identifier.'
        );
    }

    /**
     * La cloture calcule la bataille et fixe l'echeance, dans la meme transaction que la photographie.
     *
     * Le resultat ecrit se relit par le codec ; l'echeance se compte depuis l'instant de cloture,
     * pas depuis l'horloge du travailleur ; les parametres de duree sont figes avec elle.
     */
    public function testTheClosureComputesTheBattleAndFixesItsEnd(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        // Une defense sur la cible : sans elle la bataille n'a aucun round, dure zero seconde, et
        // l'echeance se confondrait avec l'instant de cloture.
        DB::table('planets')->where('id', $corps)->update(['rocket_launcher' => 20]);

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        // **Une seconde vague, pour que la fenetre ne soit pas nulle.** Sans personne a
        // attendre, l'ouverture ferme le ralliement dans sa propre transaction : cet essai
        // n'aurait plus de cloture a gouverner.
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertNull($combat->battle_result, 'The opening already wrote a result: nothing was computed at closure.');

        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($barriere);

        // Le travailleur passe bien apres l'echeance : le combat doit quand meme commencer a l'echeance.
        $issue = $this->fermeture->close($combat->id, (int)$barriere->owned_through_effect_at + 19);
        $this->assertTrue($issue->closed);

        $combat->refresh();

        $this->assertNotNull($combat->battle_result, 'The closure wrote no battle result.');
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);
        $this->assertSame((int)$ouvreur->planet_id_from, $resultat->attackerPlanetId);
        // Deux flottes : l'ouvreur et la vague qui tient la fenetre ouverte. L'initiatrice mene.
        $this->assertCount(2, $resultat->attackerFleetResults);
        $this->assertSame($ouvreur->id, $resultat->attackerFleetResults[0]->fleetMissionId);
        $this->assertNotSame([], $resultat->rounds, 'The battle had no round: the duration would prove nothing.');

        $this->assertNotNull($combat->duration_seconds);
        $this->assertGreaterThan(0, $combat->duration_seconds);
        $this->assertNotNull($combat->ends_at);
        $this->assertSame((int)$barriere->owned_through_effect_at + $combat->duration_seconds, $combat->ends_at, 'The end is not counted from the rally deadline.');
        $this->assertSame(CombatDurationEstimator::DEFAULT_RATE, $combat->duration_rate);
        $this->assertSame(CombatDurationEstimator::DEFAULT_MINIMUM_SECONDS, $combat->duration_minimum_seconds);
        $this->assertIsArray($combat->round_schedule);
        $this->assertCount(count($resultat->rounds), $combat->round_schedule, 'The round schedule does not follow the rounds of the result.');
    }

    /**
     * Un engagement qui echoue annule la cloture entiere : ni participants, ni resultat, ni `Active`.
     */
    public function testAFailedEngagementRollsTheWholeClosureBack(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        // **Une seconde vague, pour que la fenetre ne soit pas nulle.** Sans personne a
        // attendre, l'ouverture ferme le ralliement dans sa propre transaction : cet essai
        // n'aurait plus de cloture a gouverner.
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        // La panne frappe tard : la bataille est deja calculee quand l'estimation de duree leve.
        // C'est le pire moment — tout le travail est fait, rien ne doit pourtant rester.
        $estimateurEnPanne = new class () extends CombatDurationEstimator {
            public function estimate(
                BattleResult $result,
                float $rate = self::DEFAULT_RATE,
                int $minimumSeconds = self::DEFAULT_MINIMUM_SECONDS,
                float $damping = self::DEFAULT_DAMPING,
            ): CombatDurationEstimate {
                throw new RuntimeException('panne injectee dans l engagement');
            }
        };

        $enPanne = new RallyClosureService(engagement: new CombatEngagementService(estimator: $estimateurEnPanne));

        try {
            $enPanne->close($combat->id, self::OPENING + 19);
            $this->fail('The injected failure did not propagate.');
        } catch (RuntimeException $panne) {
            $this->assertSame('panne injectee dans l engagement', $panne->getMessage());
        }

        $combat->refresh();
        $this->assertSame(CombatState::Rallying, $combat->status, 'The combat became active without a battle.');
        $this->assertNull($combat->battle_result);
        $this->assertSame(0, CombatParticipant::query()->where('combat_instance_id', $combat->id)->count(), 'Participants survived the rolled-back closure.');
    }

    /**
     * Un combat ouvert sous V1 s'engage sous V1, meme quand une V2 est devenue courante.
     *
     * Le registre injecte porte une V2 courante qui leve des qu'on lui demande quoi que ce soit :
     * si l'engagement la choisissait, la cloture s'arreterait la.
     */
    public function testAV1CombatIsEngagedUnderV1WhenV2IsCurrent(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        // Une seconde vague, sans quoi l'ouverture fermerait le ralliement elle-meme et cet
        // essai n'aurait plus de cloture a gouverner.
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $v1 = new ExactLootAllocationV1();
        $v2 = $v1->version() . '_but_newer';
        $registre = LootAllocatorRegistry::of([$v1, $this->anAllocatorThatRefusesToWork($v2)], $v2);
        $this->assertSame($v2, $registre->currentVersion(), 'The fake V2 is not current: the test would prove nothing.');

        $sousV2 = new RallyClosureService(engagement: new CombatEngagementService(allocators: $registre));
        $issue = $sousV2->close($combat->id, self::OPENING + 19);

        $this->assertTrue($issue->closed);

        $combat->refresh();
        $this->assertNotNull($combat->battle_result);
        $this->assertSame($v1->version(), BattleResultCodec::fromStorage($combat->battle_result)->lootAllocatorVersion, 'The battle was computed under an allocator the combat never began with.');
    }

    /**
     * Un allocateur qui ne sait que dire sa version : l'appeler est la preuve qu'on l'a choisi.
     */
    private function anAllocatorThatRefusesToWork(string $version): LootAllocator
    {
        return new class ($version) implements LootAllocator {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }

            public function lootableAmount(
                float $inStock,
                int $rateInBasisPoints,
                string $phase,
                ResourceNormalizationDiagnostics &$diagnostics,
            ): int {
                throw new LogicException('La V2 courante a ete choisie a la place de la V1 gelee.');
            }

            public function capByCargo(Resources $loot, int $totalCargoCapacity, string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT, string $subject = ''): CappedLoot
            {
                throw new LogicException('La V2 courante a ete choisie a la place de la V1 gelee.');
            }

            /**
             * @param array<int, int> $weights
             * @param array<int, int> $remainingCapacity
             * @return array<int, int>
             */
            public function shareBetweenFleets(
                int $amount,
                array $weights,
                array $remainingCapacity,
                int $initiatorFleetMissionId,
            ): array {
                throw new LogicException('La V2 courante a ete choisie a la place de la V1 gelee.');
            }
        };
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
     * Une place liberee par un rappel n'admet pas une vague qui arrive apres l'echeance.
     *
     * ## Le defaut, cote attaquant
     *
     * Le selecteur jugeait contre le plafond de la fenetre — soixante secondes — aux deux passages.
     * A l'ouverture c'est juste : on cherche qui pourrait fixer l'echeance. A la fermeture c'est
     * faux, et il suffit qu'une place se libere entre les deux pour qu'une vague entre dans une
     * photographie prise bien avant son arrivee.
     *
     * Seize flottes au plus, l'ouvreur compris : l'ouvreur et quinze vagues remplissent le budget,
     * une seizieme vague prevue a `+50` est donc refusee a l'ouverture et ne prolonge rien.
     * L'echeance vaut `+6`. Un rappel libere ensuite une place.
     */
    public function testAPlaceFreedByARecallDoesNotAdmitAWaveArrivingAfterTheDeadline(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $vagues = [];
        for ($i = 0; $i < 15; $i++) {
            $vagues[] = $this->anAttackAt($corps, self::OPENING + 5, $joueur);
        }

        $tardive = $this->anAttackAt($corps, self::OPENING + 50, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $barriere = CelestialBodyCombatBarrier::where('combat_instance_id', $combat->id)->first();
        $this->assertNotNull($barriere);
        $this->assertSame(
            self::OPENING + 6,
            (int)$barriere->owned_through_effect_at,
            'The deadline is not one tick after the last admitted wave: the sixteenth wave was counted, though the budget was full.'
        );

        // Un rappel libere une place avant la fermeture.
        $vagues[0]->canceled = 1;
        $vagues[0]->save();

        $this->fermeture->close($combat->id, self::OPENING + 6);

        $cles = CombatParticipant::where('combat_instance_id', $combat->id)->pluck('participant_key')->all();

        $this->assertNotContains(
            CombatParticipantKey::forFleet($tardive->id),
            $cles,
            'A wave arriving forty-four seconds after the photograph entered it, because a recall had freed a place.'
        );
        $this->assertNotContains(CombatParticipantKey::forFleet($vagues[0]->id), $cles, 'The recalled wave was registered.');
        $this->assertContains(CombatParticipantKey::forFleet($ouvreur->id), $cles);
    }

    /**
     * Une attaque en vol vers ce corps.
     */
    private function anAttackAt(int $targetBodyId, int $arrivesAt, User|null $owner = null): FleetMission
    {
        $proprietaire = $owner ?? $this->aPlayer();

        return FleetMission::forceCreate([
            'user_id' => $proprietaire->id,
            // L'engagement exige une planete d'origine : le retour y revient, et le moteur la nomme.
            'planet_id_from' => $this->aPlanetIdOf($proprietaire),
            'type_from' => 1,
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
     * La planete d'un joueur cree par ces fixtures.
     */
    private function aPlanetIdOf(User $owner): int
    {
        $id = Planet::query()->where('user_id', $owner->id)->value('id');

        if (!is_int($id)) {
            $this->fail('The player ' . $owner->id . ' owns no planet: no attack could leave from anywhere.');
        }

        return $id;
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
