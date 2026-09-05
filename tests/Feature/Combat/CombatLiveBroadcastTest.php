<?php

namespace Tests\Feature\Combat;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Presentation\CombatBroadcasterLease;
use OGame\Combat\Presentation\CombatPanelService;
use OGame\Combat\Presentation\CombatPresentationBroadcaster;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Events\CombatLossesPublished;
use OGame\Events\CombatStateChanged;
use OGame\Models\CombatInstance;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\User;
use OGame\Services\SettingsService;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Tests\FleetDispatchTestCase;

/**
 * La diffusion immediate des pertes : le producteur, ce qu'il envoie, et a qui.
 *
 * ## Ce que ces essais prouvent
 *
 * Qu'une perte part **des qu'elle devient visible**, pas a la minute suivante ; qu'elle part une
 * seule fois ; qu'elle part au joueur que l'inscription designe et sur son canal prive ; qu'aucune
 * perte future ni aucune echeance ne l'accompagne ; et qu'une reprise apres panne repart de ce qui
 * n'est pas encore parti, sans rejouer ce qui l'est.
 *
 * L'abonnement seul ne prouverait rien : ces essais tiennent le producteur.
 */
class CombatLiveBroadcastTest extends FleetDispatchTestCase
{
    use EngagesAPersistentCombat;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();

        CombatPresentationEvent::query()->delete();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
    }

    /**
     * Rien ne part avant l'instant de visibilite ; tout part des qu'il arrive, une seule fois.
     */
    public function testALossIsBroadcastAsSoonAsItBecomesVisibleAndOnlyOnce(): void
    {
        $combat = $this->anEngagedCombat();
        $debut = (int)$combat->started_at;
        $premiere = $debut + $this->secondsPerRoundOf($combat)[0];

        $diffuseur = new CombatPresentationBroadcaster();

        // **Precondition** : le fil porte des pertes, et aucune n'est encore visible a la cloture.
        $this->assertGreaterThan(0, CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->count(), 'The timeline is empty: the scenario would prove nothing.');
        $this->assertSame(0, CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $debut)->count());

        Event::fake([CombatLossesPublished::class]);

        $this->assertSame(0, $diffuseur->publish($debut), 'A loss was broadcast before it became visible.');
        Event::assertNotDispatched(CombatLossesPublished::class);

        // **A la seconde ou la premiere periode s'acheve**, ce qui est devenu visible part.
        $attendues = CombatPresentationEvent::query()
            ->where('combat_instance_id', $combat->id)
            ->where('visible_at', '<=', $premiere)
            ->count();
        $this->assertGreaterThan(0, $attendues);

        $this->assertSame($attendues, $diffuseur->publish($premiere), 'The losses that just became visible were not broadcast.');
        Event::assertDispatched(CombatLossesPublished::class);

        // **Une seule fois** : un second passage au meme instant n'envoie rien de plus.
        Event::fake([CombatLossesPublished::class]);
        $this->assertSame(0, $diffuseur->publish($premiere), 'The same losses were broadcast twice.');
        Event::assertNotDispatched(CombatLossesPublished::class);

        $this->assertSame(
            0,
            CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->whereNull('broadcast_at')->where('visible_at', '<=', $premiere)->count(),
            'A visible loss was left unmarked and would be broadcast again.'
        );
    }

    /**
     * Ce qui part va au joueur que l'inscription designe, sur son canal prive, et ne porte que du passe.
     */
    public function testWhatIsBroadcastCarriesOnlyThePastAndGoesToThePlayerTheEnrolmentNames(): void
    {
        $combat = $this->anEngagedCombat();
        $debut = (int)$combat->started_at;
        $premiere = $debut + $this->secondsPerRoundOf($combat)[0];
        $echeance = (int)$combat->ends_at;
        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');

        // **Precondition : il reste du futur.**
        $this->assertGreaterThan(
            CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->count(),
            CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->count(),
            'Everything is already visible: withholding the future could not be observed.'
        );

        Event::fake([CombatLossesPublished::class]);
        (new CombatPresentationBroadcaster())->publish($premiere);

        // Chaque camp recoit les siennes : on tient ici l'envoi du proprietaire de la cible.
        Event::assertDispatched(CombatLossesPublished::class, function (CombatLossesPublished $envoi) use ($combat, $proprietaire, $premiere, $echeance): bool {
            if ($envoi->playerId !== $proprietaire) {
                return false;
            }

            $this->assertSame((int)$combat->id, $envoi->combatInstanceId);
            $this->assertSame(['private-combat.player.' . $proprietaire], array_map('strval', $envoi->broadcastOn()), 'The losses left on another channel than the player private one.');

            $charge = $envoi->broadcastWith();
            $this->assertSame(['combatId', 'losses'], array_keys($charge));

            foreach ($charge['losses'] as $perte) {
                $this->assertSame(['key', 'sequence', 'at', 'side', 'unit', 'unit_label', 'amount'], array_keys($perte));
                $this->assertLessThanOrEqual($premiere, $perte['at'], 'A loss from the future was broadcast.');
                $this->assertNotSame($echeance, $perte['at']);
            }

            // Aucune echeance, sous aucune forme, dans ce qui part.
            $valeurs = [];
            array_walk_recursive($charge, static function (mixed $valeur) use (&$valeurs): void {
                $valeurs[] = $valeur;
            });
            $this->assertNotContains($echeance, $valeurs, 'The battle deadline was broadcast.');

            return true;
        });
    }

    /**
     * Une diffusion perdue repart au passage suivant ; ce qui est parti ne repart pas.
     */
    public function testAFailedBroadcastIsRetriedAndASuccessfulOneIsNot(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = (int)$combat->started_at + $this->secondsPerRoundOf($combat)[0];
        $diffuseur = new CombatPresentationBroadcaster();

        // Une premiere perte part.
        Event::fake([CombatLossesPublished::class]);
        $parties = $diffuseur->publish($premiere);
        $this->assertGreaterThan(0, $parties);

        // On defait la marque d'une seule : elle doit repartir, les autres non.
        $rejouee = CombatPresentationEvent::query()
            ->where('combat_instance_id', $combat->id)
            ->whereNotNull('broadcast_at')
            ->orderBy('sequence')
            ->firstOrFail();
        $rejouee->broadcast_at = null;
        $rejouee->save();

        Event::fake([CombatLossesPublished::class]);
        $this->assertSame(1, $diffuseur->publish($premiere), 'The unmarked loss was not retried, or the others were replayed with it.');

        Event::assertDispatched(CombatLossesPublished::class, function (CombatLossesPublished $envoi) use ($rejouee): bool {
            $this->assertCount(1, $envoi->losses);
            $this->assertSame((int)$rejouee->sequence, $envoi->losses[0]['sequence']);

            return true;
        });
    }

    /**
     * La commande planifiee diffuse ce que son instant permet, et sort proprement.
     */
    public function testTheScheduledCommandBroadcastsWhatItsInstantAllows(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = (int)$combat->started_at + $this->secondsPerRoundOf($combat)[0];

        $this->travelTo(Date::createFromTimestamp($premiere));
        Event::fake([CombatLossesPublished::class]);

        // Une seule veille, sans attente : c'est le passage, pas la boucle, que cet essai tient.
        // La commande est appelee par le noyau : `artisan()` du banc rend une commande differee ou
        // un code de sortie selon le contexte, et l'une comme l'autre masquerait le verdict.
        $this->assertSame(0, $this->app->make(Kernel::class)->call('ogamex:combat:diffuser', ['--duree' => 0]), 'The scheduled broadcaster did not finish cleanly.');

        Event::assertDispatched(CombatLossesPublished::class);
        $this->assertSame(
            0,
            CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->whereNull('broadcast_at')->where('visible_at', '<=', $premiere)->count(),
            'The command left a visible loss unbroadcast.'
        );
    }

    /**
     * Un transport qui refuse ne perd rien : la perte repart au passage suivant.
     *
     * C'est la moitie de la garantie « au moins une fois ». L'autre — la repetition possible — est
     * eprouvee par l'essai suivant.
     */
    public function testATransportFailureLosesNothingAndIsRetried(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = (int)$combat->started_at + $this->secondsPerRoundOf($combat)[0];
        $diffuseur = new CombatPresentationBroadcaster();

        $dues = CombatPresentationEvent::query()
            ->where('combat_instance_id', $combat->id)
            ->where('visible_at', '<=', $premiere)
            ->count();
        $this->assertGreaterThan(0, $dues, 'Nothing is due: the scenario would prove nothing.');

        // Le transport refuse tout.
        Event::listen(CombatLossesPublished::class, static function (): void {
            throw new RuntimeException('le transport a refuse');
        });

        $this->assertSame(0, $diffuseur->publish($premiere), 'A refused broadcast was counted as sent.');

        // **Rien n'est marque** : tout reste a faire, et rien n'est perdu.
        $this->assertSame(
            $dues,
            CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->whereNull('broadcast_at')->where('visible_at', '<=', $premiere)->count(),
            'A loss the transport refused was marked as attempted, and would never be sent again.'
        );

        // Le transport revient : tout repart.
        Event::forget(CombatLossesPublished::class);
        Event::fake([CombatLossesPublished::class]);

        $this->assertSame($dues, $diffuseur->publish($premiere), 'The refused losses did not leave once the transport came back.');
        Event::assertDispatched(CombatLossesPublished::class);
    }

    /**
     * Un envoi accepte dont la marque se perd repart — et le navigateur ne le montre qu'une fois.
     *
     * ## Ce que cet essai etablit, et qui n'est pas confortable
     *
     * La garantie est **au moins une fois**, pas exactement une fois : entre l'envoi accepte par le
     * transport et l'ecriture de la marque, un processus peut mourir. La perte repart alors, et le
     * joueur la recevrait deux fois — si le navigateur ne la reconnaissait pas. C'est pourquoi
     * chaque perte porte une identite stable : la bataille **et** le rang.
     */
    public function testAnAcknowledgedSendWhoseMarkIsLostIsRepeatedButCarriesAStableIdentity(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = (int)$combat->started_at + $this->secondsPerRoundOf($combat)[0];
        $diffuseur = new CombatPresentationBroadcaster();

        $recus = [];
        Event::listen(CombatLossesPublished::class, static function (CombatLossesPublished $envoi) use (&$recus): void {
            foreach ($envoi->broadcastWith()['losses'] as $perte) {
                $recus[] = $perte['key'];
            }
        });

        $diffuseur->publish($premiere);
        $this->assertNotSame([], $recus, 'Nothing was broadcast: the scenario would prove nothing.');
        $premierEnvoi = $recus;

        // La marque se perd — le processus est mort entre l'envoi et l'ecriture.
        CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->update(['broadcast_at' => null]);

        $recus = [];
        $diffuseur->publish($premiere);

        $this->assertSame($premierEnvoi, $recus, 'The repeated send does not carry the same losses.');

        // **L'identite est stable et porte la bataille** : c'est elle qui rend la repetition
        // invisible au joueur, et elle ne confond pas deux batailles de meme rang.
        // Les deux envois portent les memes clefs : on lit celles du premier, dont l assertion
        // ci-dessus vient d etablir qu il n est pas vide.
        foreach ($premierEnvoi as $clef) {
            $this->assertMatchesRegularExpression('/^' . $combat->id . ':\d+$/', (string)$clef, 'A loss identity does not name its battle.');
        }

        $this->assertSame(count($premierEnvoi), count(array_unique($premierEnvoi)), 'Two losses of the same battle share an identity.');
    }

    /**
     * Une panne sur un destinataire n'emporte pas le sort de l'autre.
     */
    public function testAFailureForOneRecipientDoesNotMarkTheOther(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = $this->firstInstantWhereBothSidesLost($combat);
        $attaquant = (int)DB::table('fleet_missions')->where('id', $combat->mission_id)->value('user_id');
        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');

        // **Precondition** : les deux camps ont des pertes dues au meme instant.
        $parJoueur = [];

        foreach (CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->get() as $evenement) {
            $joueur = (int)DB::table('combat_participants')
                ->where('combat_instance_id', $combat->id)
                ->where('participant_key', $evenement->participant_key)
                ->value('player_id');
            $parJoueur[$joueur] = ($parJoueur[$joueur] ?? 0) + 1;
        }

        $this->assertArrayHasKey($attaquant, $parJoueur, 'The attacker lost nothing in the first period.');
        $this->assertArrayHasKey($proprietaire, $parJoueur, 'The target owner lost nothing in the first period.');

        // Le transport refuse pour le proprietaire seulement.
        Event::listen(CombatLossesPublished::class, static function (CombatLossesPublished $envoi) use ($proprietaire): void {
            if ($envoi->playerId === $proprietaire) {
                throw new RuntimeException('le transport a refuse pour ce destinataire');
            }
        });

        $this->assertSame($parJoueur[$attaquant], (new CombatPresentationBroadcaster())->publish($premiere), 'The failure of one recipient changed what the other received.');

        // L'attaquant est marque, le proprietaire non : chacun son sort.
        $restant = CombatPresentationEvent::query()
            ->where('combat_instance_id', $combat->id)
            ->where('visible_at', '<=', $premiere)
            ->whereNull('broadcast_at')
            ->count();

        $this->assertSame($parJoueur[$proprietaire], $restant, 'The refused recipient losses were marked, or the accepted ones were not.');
    }

    /**
     * L'emission ne se fait dans aucune transaction : un verrou tenu pendant un aller-retour reseau
     * ferait attendre une bataille sur la disponibilite d'un serveur de diffusion.
     */
    public function testTheBroadcastRunsOutsideAnyTransaction(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = (int)$combat->started_at + $this->secondsPerRoundOf($combat)[0];

        $niveaux = [];
        Event::listen(CombatLossesPublished::class, static function () use (&$niveaux): void {
            $niveaux[] = DB::transactionLevel();
        });

        (new CombatPresentationBroadcaster())->publish($premiere);

        $this->assertNotSame([], $niveaux, 'Nothing was broadcast: the scenario would prove nothing.');
        $this->assertSame([0], array_values(array_unique($niveaux)), 'The broadcast happened inside a transaction.');
    }

    /**
     * Le debut d'une bataille est annonce a toutes ses parties, avant la moindre perte.
     *
     * Un canal qui ne porterait que des pertes laisserait le premier combat attendre le
     * rafraichissement de secours : l'attaquant et la cible ne sauraient rien avant la fin de la
     * premiere periode. L'annonce part a la cloture, quand rien n'est encore visible.
     */
    public function testTheStartOfABattleIsAnnouncedToEveryPartyBeforeAnyLoss(): void
    {
        $combat = $this->anEngagedCombat();
        $debut = (int)$combat->started_at;
        $attaquant = (int)DB::table('fleet_missions')->where('id', $combat->mission_id)->value('user_id');
        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');
        $diffuseur = new CombatPresentationBroadcaster();

        // **Precondition : aucune perte n'est encore visible**, et rien n'a ete annonce.
        $this->assertSame(0, CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $debut)->count());
        $this->assertNull(CombatInstance::query()->whereKey($combat->id)->value('broadcast_status'));

        // La base du processus porte les batailles des essais precedents, jamais annoncees : on ne
        // retient que les annonces de **cette** bataille, et on exige qu'elles aillent a ses deux
        // parties, a elles seules, une fois chacune.
        $annonces = [];
        Event::listen(CombatStateChanged::class, static function (CombatStateChanged $annonce) use (&$annonces, $combat): void {
            if ($annonce->combatInstanceId === (int)$combat->id) {
                $annonces[] = $annonce;
            }
        });

        $this->assertGreaterThanOrEqual(2, $diffuseur->publishStateChanges($debut));

        $destinataires = array_map(static fn (CombatStateChanged $annonce): int => $annonce->playerId, $annonces);
        sort($destinataires);
        $attendus = [$attaquant, $proprietaire];
        sort($attendus);
        $this->assertSame($attendus, $destinataires, 'The start was not announced to exactly the two parties, once each.');

        foreach ($annonces as $annonce) {
            $this->assertSame(CombatState::Active->value, $annonce->status);
            $this->assertFalse($annonce->reportAvailable);

            // Ce qui part ne dit pas quand la bataille finit.
            $charge = $annonce->broadcastWith();
            $valeurs = [];
            array_walk_recursive($charge, static function (mixed $valeur) use (&$valeurs): void {
                $valeurs[] = $valeur;
            });
            $this->assertNotContains((int)$combat->ends_at, $valeurs, 'The battle deadline was announced with its start.');
            $this->assertSame(['combatId', 'status', 'status_label', 'report_available'], array_keys($charge));
        }

        $this->assertSame(CombatState::Active->value, CombatInstance::query()->whereKey($combat->id)->value('broadcast_status'));

        // **Une seule fois** : rien ne repart tant que l'etat ne change pas.
        $annonces = [];
        $diffuseur->publishStateChanges($debut + 30);
        $this->assertSame([], $annonces, 'An unchanged state was announced again.');
    }

    /**
     * La fin d'une bataille est annoncee avec son rapport, et la carte le propose.
     */
    public function testTheEndOfABattleIsAnnouncedWithItsReportAndTheCardOffersIt(): void
    {
        $combat = $this->anEngagedCombat();
        $echeance = (int)$combat->ends_at;
        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');
        $diffuseur = new CombatPresentationBroadcaster();

        // Le debut est deja annonce : seule la fin doit partir ensuite.
        $diffuseur->publishStateChanges((int)$combat->started_at);

        // Le bandeau du proprietaire compte cette bataille parmi celles en cours — et peut-etre
        // d'autres, laissees par les essais voisins du meme processus : on mesure l'ecart, pas zero.
        $this->actingAs(User::query()->findOrFail($proprietaire));
        $avant = (int)$this->get('/ajax/fleet/eventbox/fetch')->json('combats');
        $this->assertGreaterThanOrEqual(1, $avant, 'The running battle is not counted: the scenario would prove nothing.');

        $this->travelTo(Date::createFromTimestamp($echeance));
        (new PersistentCombatAdvancer())->advance($echeance);

        $regle = CombatInstance::query()->findOrFail($combat->id);
        $this->assertSame(CombatState::Resolved, $regle->status, 'The battle was not settled: the scenario would prove nothing.');
        $this->assertNotNull($regle->battle_report_id, 'The settlement wrote no report: the scenario would prove nothing.');

        Event::fake([CombatStateChanged::class]);

        $this->assertGreaterThanOrEqual(2, $diffuseur->publishStateChanges($echeance), 'The end was not announced to the parties.');
        Event::assertDispatched(CombatStateChanged::class, static fn (CombatStateChanged $annonce): bool => $annonce->playerId === $proprietaire
            && $annonce->status === CombatState::Resolved->value
            && $annonce->reportAvailable === true);

        // **La carte reste, et propose le rapport** — parce qu'il existe.
        $deroulant = $this->get('/ajax/fleet/eventlist/fetch');
        $deroulant->assertStatus(200);
        $deroulant->assertSee('id="combatRow-' . $combat->id . '"', false);
        $deroulant->assertSee(__('t_ingame.combat.status_resolved'));
        $deroulant->assertSee(__('t_ingame.combat.report_link'));

        // Mais le bandeau ne la compte plus parmi les batailles en cours : une de moins qu'avant.
        $this->assertSame($avant - 1, (int)$this->get('/ajax/fleet/eventbox/fetch')->json('combats'), 'The settled battle is still counted as running in the banner.');

        // Et une demi-heure plus tard, la carte a disparu : le deroulant n'est pas une archive.
        $this->travelTo(Date::createFromTimestamp($echeance + CombatPanelService::FINISHED_STAYS_FOR + 1));
        $this->get('/ajax/fleet/eventlist/fetch')->assertDontSee('combatRow-' . $combat->id, false);
    }

    /**
     * Une annonce d'etat qui echoue pour la seconde partie ne laisse pas la premiere marquer l'etat.
     *
     * `broadcast_status` est global a la bataille : s'il etait pose des que la premiere partie est
     * servie, la seconde ne saurait jamais que la bataille a commence. La marque ne se pose que
     * lorsque **toutes** les parties ont ete jointes, et le passage suivant reprend tout le monde —
     * une annonce repetee ne coute rien au navigateur.
     */
    public function testAStateAnnouncementThatFailsForOnePartyIsRetriedForEveryone(): void
    {
        $combat = $this->anEngagedCombat();
        $debut = (int)$combat->started_at;
        $attaquant = (int)DB::table('fleet_missions')->where('id', $combat->mission_id)->value('user_id');
        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');
        $diffuseur = new CombatPresentationBroadcaster();

        // Les autres batailles du processus sont annoncees d'abord, pour ne tenir que celle-ci.
        CombatInstance::query()->whereKeyNot($combat->id)->update(['broadcast_status' => DB::raw('status')]);

        $recus = [];
        // Un objet, pas un booleen capture par reference : l'analyse statique ne peut pas le tenir
        // pour constant, et c'est bien un etat que l'essai fait changer en cours de route.
        $transport = new stdClass();
        $transport->refuse = true;
        Event::listen(CombatStateChanged::class, static function (CombatStateChanged $annonce) use (&$recus, $transport, $proprietaire, $combat): void {
            if ($annonce->combatInstanceId !== (int)$combat->id) {
                return;
            }

            if ($transport->refuse && $annonce->playerId === $proprietaire) {
                throw new RuntimeException('le transport a refuse pour cette partie');
            }

            $recus[] = $annonce->playerId;
        });

        $diffuseur->publishStateChanges($debut);

        // **Precondition** : la premiere partie a bien ete servie, la seconde refusee.
        $this->assertSame([$attaquant], $recus, 'The first party was not served, or the second was: the scenario would prove nothing.');

        // **Rien n'est marque** : la seconde partie n'est pas oubliee.
        $this->assertNull(CombatInstance::query()->whereKey($combat->id)->value('broadcast_status'), 'The state was marked although one party was never told.');

        // Le transport revient : tout le monde est repris, la seconde partie comprise.
        $transport->refuse = false;
        $recus = [];
        $diffuseur->publishStateChanges($debut + 1);

        sort($recus);
        $attendus = [$attaquant, $proprietaire];
        sort($attendus);
        $this->assertSame($attendus, $recus, 'The retry did not reach every party.');
        $this->assertSame(CombatState::Active->value, CombatInstance::query()->whereKey($combat->id)->value('broadcast_status'));
    }

    /**
     * Le bail : un seul detenteur, une releve quand le battement cesse, jamais deux a la fois.
     */
    public function testTheLeaseHasOneHolderAndIsTakenOverOnlyWhenTheHeartbeatStops(): void
    {
        DB::table('combat_broadcaster_leases')->delete();
        $maintenant = 1_700_000_000;

        $premier = new CombatBroadcasterLease('banc:1');
        $second = new CombatBroadcasterLease('banc:2');

        $this->assertTrue($premier->acquire($maintenant), 'The first candidate could not take a free lease.');
        $this->assertSame('banc:1', CombatBroadcasterLease::currentHolder());

        // Un second candidat trouve un battement recent : il s'efface.
        $this->assertFalse($second->acquire($maintenant + 1), 'A second broadcaster started while the first was still beating.');
        $this->assertFalse($second->acquire($maintenant + CombatBroadcasterLease::TOLERANCE), 'The lease was taken over at the tolerance instead of after it.');
        $this->assertSame('banc:1', CombatBroadcasterLease::currentHolder());

        // Le premier bat : le bail reste a lui.
        $this->assertTrue($premier->heartbeat($maintenant + 5));
        $this->assertFalse($second->acquire($maintenant + 5 + CombatBroadcasterLease::TOLERANCE), 'A fresh heartbeat did not protect the lease.');

        // Le battement cesse : la releve est prise, et l'ancien detenteur le decouvre a son battement.
        $mort = $maintenant + 5 + CombatBroadcasterLease::TOLERANCE + 1;
        $this->assertTrue($second->acquire($mort), 'A dead broadcaster kept the lease.');
        $this->assertSame('banc:2', CombatBroadcasterLease::currentHolder());
        $this->assertFalse($premier->heartbeat($mort + 1), 'The ousted holder still believes it holds the lease.');

        // Rendre le bail libere aussitot, sans attendre la tolerance.
        $second->release();
        $this->assertNull(CombatBroadcasterLease::currentHolder());
        $this->assertTrue($premier->acquire($mort + 2));
    }

    /**
     * En mode continu, un diffuseur qui trouve un bail vivant ne diffuse rien ; un bail libre est
     * pris, servi, puis rendu.
     */
    public function testAContinuousBroadcasterYieldsToALivingLeaseAndReleasesItsOwn(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = (int)$combat->started_at + $this->secondsPerRoundOf($combat)[0];
        $this->travelTo(Date::createFromTimestamp($premiere));
        DB::table('combat_broadcaster_leases')->delete();

        $dues = CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->whereNull('broadcast_at')->count();
        $this->assertGreaterThan(0, $dues, 'Nothing is due: the scenario would prove nothing.');

        // Un autre diffuseur bat : celui-ci s'efface sans rien diffuser.
        (new CombatBroadcasterLease('ailleurs:7'))->acquire($premiere);
        Event::fake([CombatLossesPublished::class, CombatStateChanged::class]);

        $this->assertSame(0, $this->app->make(Kernel::class)->call('ogamex:combat:diffuser', ['--continu' => true, '--tours' => 1]));
        Event::assertNotDispatched(CombatLossesPublished::class);
        $this->assertSame($dues, CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->whereNull('broadcast_at')->count(), 'A yielding broadcaster still broadcast.');
        $this->assertSame('ailleurs:7', CombatBroadcasterLease::currentHolder(), 'The yielding broadcaster took the lease anyway.');

        // Le bail est libre : il est pris, la passe est faite, le bail est rendu.
        DB::table('combat_broadcaster_leases')->delete();
        Event::fake([CombatLossesPublished::class, CombatStateChanged::class]);

        $this->assertSame(0, $this->app->make(Kernel::class)->call('ogamex:combat:diffuser', ['--continu' => true, '--tours' => 1]));
        Event::assertDispatched(CombatLossesPublished::class);
        $this->assertSame(0, CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->whereNull('broadcast_at')->count(), 'The continuous broadcaster left due losses unsent.');
        $this->assertNull(CombatBroadcasterLease::currentHolder(), 'The lease was not released on exit.');
    }

    /**
     * A est suspendu pendant un lot, B prend la releve, A revient : A n'emet plus rien, ne libere
     * pas le bail de B, et rien n'est perdu.
     *
     * ## Le contrat que cet essai fixe
     *
     * Le bail prouve un detenteur en base, pas un seul emetteur. Un diffuseur suspendu plus longtemps
     * que la tolerance — un appel reseau qui traine, un processus mis en pause — peut voir un autre
     * prendre la releve. Ce qui est admis : le lot deja engage par A part deux fois, une par
     * diffuseur, et le navigateur deduplique. Ce qui ne l'est jamais : qu'A commence un lot de plus
     * apres la releve, ou qu'il libere le bail de B en sortant.
     */
    public function testASuspendedBroadcasterYieldsToItsSuccessorWithoutLosingOrReleasingAnything(): void
    {
        $combat = $this->anEngagedCombat();
        $premiere = $this->firstInstantWhereBothSidesLost($combat);
        DB::table('combat_broadcaster_leases')->delete();

        // **Precondition** : deux lots sont dus — un par camp — sinon « pas de lot de plus » ne se
        // distingue pas de « rien a faire ».
        $parJoueur = [];
        foreach (CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->get() as $evenement) {
            $joueur = (int)DB::table('combat_participants')->where('combat_instance_id', $combat->id)->where('participant_key', $evenement->participant_key)->value('player_id');
            $parJoueur[$joueur] = ($parJoueur[$joueur] ?? 0) + 1;
        }
        $this->assertCount(2, $parJoueur, 'Fewer than two lots are due: the scenario would prove nothing.');

        $a = new CombatBroadcasterLease('banc:A');
        $b = new CombatBroadcasterLease('banc:B');
        $this->assertTrue($a->acquire($premiere));

        // Le transport « suspend » A pendant son premier lot : le temps passe au-dela de la
        // tolerance, et B prend la releve pendant que A est encore dans l'appel.
        $horloge = new stdClass();
        $horloge->maintenant = $premiere;
        $emisParA = [];
        $releve = new stdClass();
        $releve->faite = false;
        Event::listen(CombatLossesPublished::class, static function (CombatLossesPublished $envoi) use (&$emisParA, $horloge, $releve, $b): void {
            $emisParA[] = $envoi->playerId;

            if (!$releve->faite) {
                $horloge->maintenant += CombatBroadcasterLease::TOLERANCE + 1;
                $releve->faite = $b->acquire($horloge->maintenant);
            }
        });

        // La garde de A : son battement, a l'horloge qui a avance pendant l'appel.
        $garde = static fn (): bool => $a->heartbeat($horloge->maintenant);

        $envoyees = (new CombatPresentationBroadcaster())->publish($premiere, 500, $garde);

        // **A a engage un seul lot, puis s'est arrete** : la garde a refuse le second.
        $this->assertTrue($releve->faite, 'B could not take over: the scenario would prove nothing.');
        $this->assertCount(1, $emisParA, 'A went on with another lot after losing its lease.');
        $this->assertSame($parJoueur[$emisParA[0]], $envoyees, 'A counted more than its single lot as sent.');
        $this->assertSame('banc:B', CombatBroadcasterLease::currentHolder());

        // **A ne libere pas le bail de B en sortant.**
        $a->release();
        $this->assertSame('banc:B', CombatBroadcasterLease::currentHolder(), 'The ousted broadcaster released its successor lease.');

        // **Rien n'est perdu** : ce que A n'a pas emis reste du, et B l'emet.
        $restant = CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->whereNull('broadcast_at')->count();
        $this->assertGreaterThan(0, $restant, 'Nothing was left for the successor: A sent everything, so the guard did not stop it.');

        Event::forget(CombatLossesPublished::class);
        $emisParB = [];
        Event::listen(CombatLossesPublished::class, static function (CombatLossesPublished $envoi) use (&$emisParB): void {
            $emisParB[] = $envoi->playerId;
        });
        $gardeB = static fn (): bool => $b->heartbeat($horloge->maintenant);
        // B publie a l'instant de A : a son horloge, plus tard, d'autres periodes seraient devenues
        // visibles et le compte ne dirait plus « ce que A a laisse ».
        $this->assertSame($restant, (new CombatPresentationBroadcaster())->publish($premiere, 500, $gardeB), 'The successor did not send what A left.');
        $this->assertSame(0, CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->where('visible_at', '<=', $premiere)->whereNull('broadcast_at')->count());
        $this->assertNotSame($emisParA, $emisParB, 'The successor resent the very lot A had already sent, and only that one.');
    }

    /**
     * Le canal d'un joueur n'est ouvert qu'a lui.
     *
     * ## Deux preuves, et pourquoi elles different
     *
     * Le **refus** passe par l'entree HTTP reelle : c'est la garantie qui compte, et elle doit tenir
     * de bout en bout. Le pilote des essais (`log`) n'interroge aucun callback et repondrait 200 a
     * n'importe quel canal ; l'essai monte donc le pilote reel, dont le refus se prononce avant
     * toute signature.
     *
     * L'**acceptation** se prouve sur le callback lui-meme. La reponse d'acceptation, elle, exige
     * une configuration de diffusion complete pour etre signee — ce n'est pas la regle d'acces, et
     * la faire passer par la signerait un essai sur autre chose que ce qu'il annonce.
     */
    public function testAPlayerOnlyListensToItsOwnChannel(): void
    {
        $combat = $this->anEngagedCombat();
        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');
        $moi = User::query()->findOrFail($this->currentUserId);
        $this->assertNotSame($proprietaire, (int)$moi->id, 'The scenario compares a player with itself.');

        // La regle, telle que `routes/channels.php` l'enregistre.
        $regle = $this->channelRule('combat.player.{playerId}');

        $this->assertTrue((bool)$regle($moi, (string)$moi->id), 'A player is refused its own channel.');
        $this->assertFalse((bool)$regle($moi, (string)$proprietaire), 'A player is allowed on someone else channel.');

        // Et le refus tient de bout en bout, par l'entree HTTP.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'clef-de-banc',
            'broadcasting.connections.reverb.secret' => 'secret-de-banc',
            'broadcasting.connections.reverb.app_id' => 'banc',
        ]);

        $this->actingAs($moi);
        $this->post('/broadcasting/auth', ['channel_name' => 'private-combat.player.' . $proprietaire, 'socket_id' => '1234.5678'])
            ->assertStatus(403);
    }

    /**
     * Le callback d'autorisation enregistre pour ce motif de canal.
     */
    /**
     * Le premier instant ou les deux camps ont au moins une perte visible.
     *
     * La bataille est tiree au sort : la premiere periode ne coute pas toujours aux deux camps. Un
     * essai qui exige deux lots doit chercher l'instant qui les rend dus, pas le supposer.
     */
    private function firstInstantWhereBothSidesLost(CombatInstance $combat): int
    {
        $camps = [];

        foreach (CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->orderBy('visible_at')->get() as $evenement) {
            $camps[(string)$evenement->side] = true;

            if (count($camps) === 2) {
                return (int)$evenement->visible_at;
            }
        }

        $this->fail('Only one side ever loses anything in this battle: the scenario would prove nothing.');
    }

    private function channelRule(string $motif): callable
    {
        $diffuseur = Broadcast::driver('log');
        $propriete = new ReflectionProperty(Broadcaster::class, 'channels');
        $propriete->setAccessible(true);
        $canaux = $propriete->getValue($diffuseur);

        $this->assertArrayHasKey($motif, $canaux, 'No authorisation rule is registered for ' . $motif . '.');

        return $canaux[$motif];
    }
}
