<?php

namespace Tests\Feature\Combat;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OGame\Combat\Presentation\CombatPresentationBroadcaster;
use OGame\Events\CombatLossesPublished;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\User;
use OGame\Services\SettingsService;
use ReflectionProperty;
use RuntimeException;
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
        $premiere = (int)$combat->started_at + $this->secondsPerRoundOf($combat)[0];
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
