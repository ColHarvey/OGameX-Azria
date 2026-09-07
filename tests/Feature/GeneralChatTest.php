<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use OGame\Events\ChatMessageSent;
use OGame\Models\ChatMessage;
use OGame\Models\IgnoredPlayer;
use OGame\Models\User;
use OGame\Services\ChatService;
use ReflectionProperty;
use Tests\AccountTestCase;

/**
 * Le chat general : tout le serveur dans une seule conversation.
 *
 * ## Ce qui le distingue, et pourquoi aucune colonne n'a ete ajoutee
 *
 * Un message general **ne vise personne** : `recipient_id` et `alliance_id` tous deux nuls. Les deux
 * colonnes etaient nullables depuis la creation de la table ; c'est l'absence de destinataire qui
 * porte le sens. Seul un index a ete ajoute, parce que le general sera la conversation la plus
 * fournie du serveur et qu'aucun des trois index existants ne la sert.
 *
 * ## Deux decisions d'Azria, prises par defaut et dites
 *
 * **La liste d'ignores s'applique.** Elle ne valait jusqu'ici que pour les messages prives. Le canal
 * etant unique, la diffusion ne peut pas filtrer par lecteur : l'historique ecarte les auteurs
 * ignores cote serveur, et le navigateur devra ecarter les memes en direct.
 *
 * **Le debit est limite a vingt messages par minute.** Le general est le seul des trois genres
 * qu'un joueur seul peut rendre illisible pour tout le monde.
 */
class GeneralChatTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le limiteur vit dans le cache, qui survit d'un essai a l'autre : un compteur laisse par
        // un voisin ferait echouer le premier envoi de celui-ci.
        RateLimiter::clear('chat-general:' . $this->currentUserId);

        // **Et les messages aussi survivent.** Deux essais de cette classe ont d'abord echoue pour
        // cette seule raison : ils comparaient des textes, et le meme texte ecrit au passage
        // precedent — par un autre joueur, sous un autre identifiant — repondait a leur place.
        // Vider la table rend chaque essai comptable de ce qu'il ecrit, lui seul.
        ChatMessage::query()->forceDelete();
    }

    /**
     * Un message general ne vise personne, et part sur le canal du serveur.
     */
    public function testAGeneralMessageTargetsNobodyAndTravelsOnTheServerChannel(): void
    {
        $message = app(ChatService::class)->sendGeneralMessage($this->currentUserId, 'Bonjour tout le monde');

        $this->assertNull($message->recipient_id, 'A general message names a recipient: it would be read as a private one.');
        $this->assertNull($message->alliance_id, 'A general message names an alliance: it would be read as an alliance one.');

        $canaux = (new ChatMessageSent($message))->broadcastOn();

        $this->assertCount(1, $canaux);
        $this->assertInstanceOf(PrivateChannel::class, $canaux[0]);
        $this->assertSame('private-chat.general', (string)$canaux[0]);
    }

    /**
     * La charge utile se declare generale.
     *
     * **Sans ce marqueur le navigateur ouvrirait une conversation privee avec l'auteur** : il
     * distingue les genres par la presence d'`associationId`, qu'un general n'a pas.
     */
    public function testThePayloadSaysItIsGeneral(): void
    {
        $message = app(ChatService::class)->sendGeneralMessage($this->currentUserId, 'Coucou');
        $charge = (new ChatMessageSent($message))->broadcastWith();

        $this->assertTrue($charge['general'] ?? false, 'Nothing marks this message as general: the browser would treat it as a private one.');
        $this->assertArrayNotHasKey('associationId', $charge);
    }

    /**
     * Un message prive et un message d'alliance ne se declarent pas generaux.
     *
     * Le temoin precedent passerait si `general` etait pose sur tout. Celui-ci l'en empeche.
     */
    public function testOnlyAGeneralMessageSaysItIsGeneral(): void
    {
        $autre = User::factory()->create();

        $prive = app(ChatService::class)->sendDirectMessage($this->currentUserId, (int)$autre->id, 'Pour toi seul');

        $this->assertArrayNotHasKey(
            'general',
            (new ChatMessageSent($prive))->broadcastWith(),
            'A private message claims to be general: every reader would see it.'
        );
    }

    /**
     * Le canal general n'est ouvert qu'a un joueur connecte.
     */
    public function testTheGeneralChannelRequiresASession(): void
    {
        $regle = $this->channelRule('chat.general');

        $this->assertTrue((bool)$regle(User::query()->findOrFail($this->currentUserId)));
        $this->assertFalse((bool)$regle(null), 'The general channel opens to a visitor with no account.');
    }

    /**
     * L'historique general rend les messages generaux, et eux seuls.
     */
    public function testTheHistoryReturnsGeneralMessagesAndNothingElse(): void
    {
        $service = app(ChatService::class);
        $autre = User::factory()->create();

        $service->sendGeneralMessage($this->currentUserId, 'Message general du banc');
        $service->sendDirectMessage($this->currentUserId, (int)$autre->id, 'Message prive du banc');

        $historique = $service->getGeneralMessages($this->currentUserId);
        $textes = $historique->pluck('message')->all();

        $this->assertContains('Message general du banc', $textes);
        $this->assertNotContains('Message prive du banc', $textes, 'A private message leaks into the general history.');

        foreach ($historique as $message) {
            $this->assertNull($message->recipient_id);
            $this->assertNull($message->alliance_id);
        }
    }

    /**
     * Un auteur ignore disparait de l'historique general — pour celui qui l'ignore, et lui seul.
     *
     * **Les deux moities comptent.** Un filtre qui retirerait le message pour tout le monde serait
     * une censure, pas un filtre de lecture.
     */
    public function testAnIgnoredAuthorDisappearsForTheReaderWhoIgnoresThem(): void
    {
        $service = app(ChatService::class);
        $importun = User::factory()->create();

        $indesirable = $service->sendGeneralMessage((int)$importun->id, 'Message de l importun');

        $avant = $service->getGeneralMessages($this->currentUserId)->pluck('id')->all();
        $this->assertContains($indesirable->id, $avant, 'The scenario proves nothing: the message was never visible.');

        IgnoredPlayer::create([
            'user_id' => $this->currentUserId,
            'ignored_user_id' => $importun->id,
        ]);

        $apres = $service->getGeneralMessages($this->currentUserId)->pluck('id')->all();
        $this->assertNotContains($indesirable->id, $apres, 'Ignoring an author does not silence them in the general chat.');

        $pourUnAutre = $service->getGeneralMessages((int)$importun->id)->pluck('id')->all();
        $this->assertContains($indesirable->id, $pourUnAutre, 'One reader ignoring an author removed the message for everyone.');
    }

    /**
     * L'envoi general passe par le mode 5, et l'historique par le mode 6.
     */
    public function testTheRouteAcceptsTheGeneralModes(): void
    {
        $envoi = $this->post('/chat/send', ['mode' => 5, 'text' => 'Salut le serveur']);

        $envoi->assertStatus(200);
        $envoi->assertJsonPath('status', 'OK');
        $envoi->assertJsonPath('targetGeneral', true);

        $historique = $this->post('/chat/history', ['mode' => 6]);

        $historique->assertStatus(200);
        $historique->assertJsonPath('general', true);
        $this->assertStringContainsString('Salut le serveur', (string)$historique->getContent());
    }

    /**
     * Au-dela de la limite, l'envoi est refuse et dit quand reessayer.
     *
     * La limite ne s'applique qu'au general : c'est le seul genre qui atteint tout le serveur.
     */
    public function testBeyondTheLimitTheServerRefusesAndSaysWhen(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/chat/send', ['mode' => 5, 'text' => 'Message ' . $i])->assertJsonPath('status', 'OK');
        }

        $refuse = $this->post('/chat/send', ['mode' => 5, 'text' => 'Un de trop']);

        $refuse->assertStatus(200);
        $refuse->assertJsonPath('status', 'TOO_MANY_MESSAGES');
        $this->assertGreaterThan(0, (int)$refuse->json('retryAfter'), 'The refusal does not say when the player may try again.');

        // Le prive n'est pas concerne par cette limite.
        $autre = User::factory()->create();
        $this->post('/chat/send', ['mode' => 1, 'playerId' => $autre->id, 'text' => 'Toujours possible'])
            ->assertJsonPath('status', 'OK');
    }

    /**
     * La pagination du general remonte les messages plus anciens.
     */
    public function testOlderGeneralMessagesCanBeLoaded(): void
    {
        $service = app(ChatService::class);

        $premier = $service->sendGeneralMessage($this->currentUserId, 'Le plus ancien');
        $service->sendGeneralMessage($this->currentUserId, 'Le plus recent');

        $recent = $service->sendGeneralMessage($this->currentUserId, 'Le plus recent encore');

        $reponse = $this->post('/chat/more', ['general' => 1, 'beforeId' => $recent->id]);

        $reponse->assertStatus(200);
        $rendus = array_map('intval', array_keys((array)$reponse->json('chatItems')));

        $this->assertContains((int)$premier->id, $rendus, 'Pagination lost the older message it was asked for.');
        $this->assertNotContains((int)$recent->id, $rendus, 'Pagination ignored beforeId and returned everything.');
    }

    /**
     * L'index qui sert l'historique general existe.
     *
     * Sans lui, la conversation la plus fournie du serveur se lit en parcourant la table entiere.
     */
    public function testTheGeneralHistoryHasAnIndexToStandOn(): void
    {
        $index = Schema::getIndexListing('chat_messages');

        $this->assertContains(
            'chat_messages_general_index',
            $index,
            'No index serves "recipient_id IS NULL AND alliance_id IS NULL": the general history scans the whole table.'
        );
    }

    /**
     * Le callback d'autorisation enregistre pour ce canal.
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
