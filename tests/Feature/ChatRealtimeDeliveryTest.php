<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Broadcast;
use OGame\Events\ChatMessageSent;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use OGame\Services\AllianceService;
use ReflectionClass;
use ReflectionProperty;
use Tests\AccountTestCase;

/**
 * Le chat en direct : le nom sur le fil, le canal, et ce que le navigateur en fait.
 *
 * ## Pourquoi ces temoins existent
 *
 * `echo.js` n'ayant jamais construit de client dans un navigateur, **tout ce chemin etait
 * inexecute**. Quand il a enfin fonctionne, quatre defauts sont apparus d'un coup, dont un
 * bloquant : le nom de l'evenement ecoute et le nom diffuse ne se rencontraient jamais.
 *
 * `ChatMessageSent` etait le seul des quatre evenements diffuses sans `broadcastAs()` — son nom sur
 * le fil etait donc la classe complete, `OGame\Events\ChatMessageSent`. Et `chat.js` l'ecoutait
 * **sans point initial**, ce qui fait que la bibliotheque y colle son espace de noms par defaut
 * (`App.Events`, lu dans le bundle construit) et attend `App\Events\ChatMessageSent`.
 *
 * La regle exacte, telle qu'elle vit dans la bibliotheque :
 *
 * ```js
 * format(e) {
 *   return [".", "\\"].includes(e.charAt(0))
 *     ? e.substring(1)
 *     : (this.namespace && (e = this.namespace + "." + e), e.replace(/\./g, "\\"));
 * }
 * ```
 *
 * D'ou les deux moities de l'invariant, tenues ensemble par le premier temoin : **tout evenement
 * diffuse nomme son nom court, et tout `listen()` du jeu commence par un point.**
 */
class ChatRealtimeDeliveryTest extends AccountTestCase
{
    /**
     * Les fichiers de temps reel du jeu, ceux que nous ecrivons.
     */
    private const array MODULES = [
        'resources/js/ingame/chat.js',
        'resources/js/ingame/combat.js',
        'resources/js/ingame/messages-badge.js',
    ];

    /**
     * Le nom ecoute et le nom diffuse se rencontrent — pour tous les evenements, pas seulement le chat.
     *
     * **C'est le temoin qui compte.** Il ne verifie pas une egalite de chaines au cas par cas : il
     * epingle les deux regles qui, ensemble, garantissent la rencontre. Un evenement ajoute demain
     * sans `broadcastAs()`, ou un `listen()` ecrit sans point, tombe ici.
     */
    public function testEveryBroadcastNameMeetsTheNameThatIsListenedFor(): void
    {
        $sansNomCourt = [];

        foreach (glob(base_path('app/Events/*.php')) ?: [] as $chemin) {
            $classe = 'OGame\\Events\\' . basename($chemin, '.php');

            if (!class_exists($classe)) {
                continue;
            }

            $reflexion = new ReflectionClass($classe);

            if (!$reflexion->implementsInterface(ShouldBroadcast::class)) {
                continue;
            }

            if (!$reflexion->hasMethod('broadcastAs')) {
                $sansNomCourt[] = $reflexion->getShortName();
            }
        }

        $this->assertSame(
            [],
            $sansNomCourt,
            'These broadcast events keep the fully qualified class name on the wire, which no browser listener can match: '
            . implode(', ', $sansNomCourt)
        );

        $sansPoint = [];

        foreach (self::MODULES as $module) {
            $source = (string)file_get_contents(base_path($module));

            if (preg_match_all("/\\.listen\\(\s*'([^']+)'/", $source, $trouves) === false) {
                continue;
            }

            foreach ($trouves[1] as $nom) {
                if (!str_starts_with($nom, '.')) {
                    $sansPoint[] = basename($module) . ' -> ' . $nom;
                }
            }
        }

        $this->assertSame(
            [],
            $sansPoint,
            'These listeners have no leading dot, so the library prefixes its default namespace (App.Events) and waits for an event this game never emits: '
            . implode(', ', $sansPoint)
        );
    }

    /**
     * Le nom court de l'evenement du chat est exactement celui que le navigateur ecoute.
     *
     * Le temoin precedent tient les deux regles ; celui-ci ferme le cas concret, au cas ou les deux
     * cotes derivent ensemble vers deux noms differents mais tous deux bien formes.
     */
    public function testTheChatEventIsAnnouncedUnderTheNameTheBrowserAwaits(): void
    {
        $message = $this->unMessageDAlliance();
        $evenement = new ChatMessageSent($message);

        $source = (string)file_get_contents(base_path('resources/js/ingame/chat.js'));

        $this->assertStringContainsString(
            ".listen('." . $evenement->broadcastAs() . "'",
            $source,
            'The chat listens for a name the server does not broadcast: nothing would ever arrive.'
        );
    }

    /**
     * Un message d'alliance part sur le canal de l'alliance, et lui seul.
     */
    public function testAnAllianceMessageTravelsOnTheAllianceChannel(): void
    {
        $message = $this->unMessageDAlliance();
        $canaux = (new ChatMessageSent($message))->broadcastOn();

        $this->assertCount(1, $canaux, 'An alliance message reaches more channels than the alliance one.');
        $this->assertInstanceOf(PrivateChannel::class, $canaux[0]);
        $this->assertSame('private-chat.alliance.' . $message->alliance_id, (string)$canaux[0]);
    }

    /**
     * Le canal d'une alliance n'est ouvert qu'a ses membres.
     *
     * **La regle compare `$user->alliance_id` a un entier.** Cette colonne n'est declaree dans aucun
     * `$casts` : son type PHP depend du pilote, et une comparaison stricte contre une chaine
     * refuserait tout le monde en silence. Ce temoin la mesure au lieu de la supposer.
     */
    public function testTheAllianceChannelOnlyOpensToItsMembers(): void
    {
        $message = $this->unMessageDAlliance();
        $membre = User::query()->findOrFail($this->currentUserId);
        $etranger = User::factory()->create();

        $regle = $this->channelRule('chat.alliance.{allianceId}');

        $this->assertTrue(
            (bool)$regle($membre, (string)$message->alliance_id),
            'A member is refused its own alliance channel: alliance_id does not compare as an integer under this driver.'
        );

        $this->assertFalse(
            (bool)$regle($etranger, (string)$message->alliance_id),
            'Someone outside the alliance is allowed to listen to it.'
        );
    }

    /**
     * L'identifiant de socket accompagne les requetes, sinon `toOthers()` n'exclut personne.
     *
     * Les deux moities comptent : le serveur emploie `toOthers()`, et le navigateur doit lui donner
     * de quoi reconnaitre l'expediteur. Sans l'en-tete, l'auteur d'un message d'alliance le recevait
     * en retour, en double de la copie que son propre envoi affiche.
     */
    public function testTheSenderIsExcludedBecauseTheSocketTravelsWithTheRequest(): void
    {
        $service = (string)file_get_contents(base_path('app/Services/ChatService.php'));

        // **Un nombre litteral etait le mauvais invariant** : il a rougi le jour ou un troisieme
        // genre de message est apparu, alors que rien n'etait casse. Ce qui doit tenir, c'est que
        // *chaque* diffusion du chat exclut son auteur — quel que soit le nombre de genres.
        $diffusions = substr_count($service, 'broadcast(new ChatMessageSent($chatMessage))');
        $exclusions = substr_count($service, 'broadcast(new ChatMessageSent($chatMessage))->toOthers();');

        $this->assertGreaterThan(0, $diffusions, 'The chat no longer broadcasts anything: this guard measures nothing.');
        $this->assertSame(
            $diffusions,
            $exclusions,
            'A chat send broadcasts without excluding its author: that author receives its own message back.'
        );

        $echo = (string)file_get_contents(base_path('resources/js/ingame/echo.js'));

        $this->assertStringContainsString(
            "setRequestHeader('X-Socket-ID'",
            $echo,
            'Nothing sends the socket id: toOthers() has nobody to exclude and the author receives its own message back.'
        );
    }

    /**
     * Le premier message d'un chat vide s'affiche.
     *
     * `getLastChatItemData()` rend `null` quand aucun element n'existe encore, et la condition
     * d'origine — `s !== null && ...` — faisait alors disparaitre le message jusqu'au rechargement.
     * Rien a comparer ne veut pas dire rien a afficher.
     */
    public function testTheFirstMessageOfAnEmptyChatIsDisplayed(): void
    {
        $source = (string)file_get_contents(base_path('resources/js/ingame/chat.js'));

        $this->assertStringContainsString(
            'if (s === null || y.date != s.date || y.chatContent != s.text) {',
            $source,
            'An empty chat has no last item, and the guard drops the very first message it receives.'
        );
    }

    /**
     * Les cles de la charge utile sont lues telles qu'elles sont ecrites.
     *
     * `h.refAuhtor` — deux lettres inversees — rendait « undefined » comme auteur d'une citation
     * recue en direct, alors que la meme citation relue dans l'historique s'affichait correctement.
     */
    public function testTheQuotedAuthorIsReadUnderTheNameItIsSentUnder(): void
    {
        $message = $this->unMessageDAlliance();
        $charge = (new ChatMessageSent($message))->broadcastWith();

        $this->assertArrayHasKey('senderName', $charge);
        $this->assertArrayHasKey('text', $charge);

        $source = (string)file_get_contents(base_path('resources/js/ingame/chat.js'));

        $this->assertStringNotContainsString(
            'refAuhtor',
            $source,
            'The quoted author is read under a misspelled key: a live reply shows "undefined" as its author.'
        );
    }

    /**
     * Un message d'alliance ecrit par le joueur connecte, dans une alliance qu'il vient de fonder.
     */
    private function unMessageDAlliance(): ChatMessage
    {
        // **La base persiste entre les essais d'une meme classe** : un tag fixe est refuse au
        // deuxieme appel. Le tag fait de trois a huit caracteres, d'ou la troncature.
        $tag = strtoupper(substr('C' . dechex(random_int(0x100000, 0xffffff)), 0, 7));
        $alliance = app(AllianceService::class)->createAlliance($this->currentUserId, $tag, 'Alliance du banc ' . $tag);

        /** @var ChatMessage $message */
        $message = ChatMessage::create([
            'sender_id' => $this->currentUserId,
            'alliance_id' => $alliance->id,
            'message' => 'Bonjour a tous',
        ]);

        $message->load(['sender', 'replyTo.sender']);

        return $message;
    }

    /**
     * Le callback d'autorisation enregistre pour ce motif de canal.
     *
     * Le pilote des essais n'interroge aucun callback ; la regle se lit donc sur le registre du
     * diffuseur, la ou `routes/channels.php` l'a posee.
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
