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
                $this->assertSame(['sequence', 'at', 'side', 'unit', 'unit_label', 'amount'], array_keys($perte));
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
