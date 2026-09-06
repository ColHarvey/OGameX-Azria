<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use OGame\Events\UnreadMessageCountChanged;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Services\MessageService;
use ReflectionProperty;
use Tests\AccountTestCase;

/**
 * La pastille de courrier suit l'etat reel, sans rechargement.
 *
 * ## Le defaut que ces temoins ferment
 *
 * Le nombre de courriers non lus etait calcule une seule fois, au rendu de la page, et ecrit en
 * dur dans le HTML. Un courrier arrive pendant qu'on joue restait invisible jusqu'au rechargement
 * suivant ; un courrier lu laissait sa pastille allumee tout aussi longtemps.
 *
 * ## Ce que ces essais etablissent
 *
 * Que l'annonce part **des deux cotes** — arrivee et lecture —, qu'elle porte le total calcule par
 * le serveur et non un increment, qu'elle ne part pas pour rien, et que le canal n'est ouvert qu'a
 * son proprietaire.
 */
class UnreadMessageBadgeTest extends AccountTestCase
{
    /**
     * Un courrier qui arrive annonce le nouveau total.
     */
    public function testAnArrivingMessageAnnouncesTheCount(): void
    {
        Event::fake([UnreadMessageCountChanged::class]);

        $this->creerUnCourrier();

        // `assertDispatched` ne prend pas de message : un texte en troisieme argument serait
        // avale en silence. Ce qui doit etre dit l est par une assertion qui, elle, en porte un.
        $recus = 0;
        Event::assertDispatched(UnreadMessageCountChanged::class, function (UnreadMessageCountChanged $e) use (&$recus): bool {
            $recus += $e->recipientId === $this->currentUserId ? 1 : 0;

            return true;
        });

        $this->assertSame(1, $recus, 'The announcement did not name this player: the badge would stay stale until a reload.');
    }

    /**
     * L'annonce porte le total du serveur, pas un increment.
     *
     * **C'est la difference qui compte.** Un increment se perd — deux courriers pendant une
     * reconnexion, un onglet endormi — et rien ne le rattrape. Un total perdu est corrige par le
     * suivant. Un essai qui verifierait seulement « un evenement est parti » laisserait passer les
     * deux implementations.
     */
    public function testTheAnnouncementCarriesTheServerSideTotal(): void
    {
        // L'inscription pose deja un message de bienvenue : on part du reel, pas de zero suppose.
        $depart = $this->nombreDeNonLus();

        $this->creerUnCourrier();
        $this->creerUnCourrier();

        $charge = (new UnreadMessageCountChanged($this->currentUserId))->broadcastWith();

        $this->assertSame($depart + 2, $charge['unread'], 'The announcement does not carry the real unread total.');
        $this->assertSame($this->nombreDeNonLus(), $charge['unread'], 'The announced total disagrees with the database.');
    }

    /**
     * Lire ses messages annonce la baisse.
     *
     * Sans ce sens-la, la pastille monterait en direct et ne redescendrait qu'au rechargement —
     * un compteur qui ne ment que dans un sens reste un compteur qui ment.
     */
    public function testReadingMessagesAnnouncesTheDrop(): void
    {
        $this->creerUnCourrier();
        $this->assertGreaterThan(0, $this->nombreDeNonLus(), 'Nothing is unread: the drop could not be observed.');

        Event::fake([UnreadMessageCountChanged::class]);

        resolve(MessageService::class)->getMessagesForTab('universe', '', 1);

        $this->assertSame(0, $this->nombreDeNonLus('universe'), 'The tab was loaded but its messages were not marked as read.');

        $annonces = 0;
        Event::assertDispatched(UnreadMessageCountChanged::class, function () use (&$annonces): bool {
            $annonces++;

            return true;
        });

        $this->assertSame(1, $annonces, 'Reading the messages announced nothing, or announced twice: the badge would stay lit or flicker.');
    }

    /**
     * Relire un onglet deja lu n'annonce rien.
     *
     * Une annonce par ouverture de page redirait le meme nombre a chaque fois, et ferait payer une
     * diffusion pour rien. L'annonce suit un changement, pas une consultation.
     */
    public function testReopeningAnAlreadyReadTabAnnouncesNothing(): void
    {
        $service = resolve(MessageService::class);
        $this->creerUnCourrier();
        $service->getMessagesForTab('universe', '', 1);

        Event::fake([UnreadMessageCountChanged::class]);

        $service->getMessagesForTab('universe', '', 1);

        $annonces = 0;
        Event::assertDispatchedTimes(UnreadMessageCountChanged::class, 0);
        Event::assertNotDispatched(UnreadMessageCountChanged::class, function () use (&$annonces): bool {
            $annonces++;

            return true;
        });

        $this->assertSame(0, $annonces, 'An unchanged tab announced anyway: every page view would broadcast for nothing.');
    }

    /**
     * Le canal n'est ouvert qu'a son proprietaire.
     *
     * **Le pilote d'essai ne prouve rien seul** : il ne consulte aucun rappel d'autorisation et
     * repond 200 a tout. La regle est donc lue sur le diffuseur reel et appliquee a la main.
     */
    public function testOnlyTheOwnerMayListen(): void
    {
        $regle = $this->channelRule('messages.player.{playerId}');

        $moi = User::query()->findOrFail($this->currentUserId);
        $autre = User::factory()->create();

        $this->assertTrue((bool)$regle($moi, (string)$this->currentUserId), 'The owner was refused their own channel.');
        $this->assertFalse((bool)$regle($autre, (string)$this->currentUserId), 'Another player could listen to this one unread count.');
    }

    /**
     * L'evenement part sur le canal prive du destinataire, et sur lui seul.
     */
    public function testItGoesToTheRecipientChannelOnly(): void
    {
        $canaux = (new UnreadMessageCountChanged($this->currentUserId))->broadcastOn();

        $this->assertCount(1, $canaux, 'The announcement reaches more than one channel.');
        $this->assertSame('private-messages.player.' . $this->currentUserId, (string)$canaux[0]);
    }

    private function creerUnCourrier(): void
    {
        $joueur = resolve(\OGame\Factories\PlayerServiceFactory::class)->make($this->currentUserId, true);

        resolve(MessageService::class)->sendSystemMessageToPlayer(
            $joueur,
            \OGame\GameMessages\WelcomeMessage::class,
            []
        );
    }

    private function nombreDeNonLus(string|null $tab = null): int
    {
        $requete = Message::query()->where('user_id', $this->currentUserId)->where('viewed', 0);

        if ($tab !== null) {
            $requete->whereIn('key', \OGame\Factories\GameMessageFactory::GetGameMessageKeysByTab($tab));
        }

        return $requete->count();
    }

    /**
     * @return callable
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
