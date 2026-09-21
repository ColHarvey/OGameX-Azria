<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use OGame\Models\Ban;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\ChatService;
use Tests\AccountTestCase;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * Les deux routes des non-lus : gardees, hors du moteur de jeu, et sans rien deviner.
 *
 * Une veille toutes les soixante secondes qui traverserait `globalgame` ferait avancer `users.time`
 * (« en ligne ») et `planets.time_last_update` (l etoile d activite de la Galaxie) : tout joueur ayant un
 * onglet ouvert paraitrait actif en permanence. `auth` et `banned` restent — un invite recoit 401, un banni
 * est deconnecte et renvoye a la connexion. Et la photographie ne dit que ce qui concerne le demandeur.
 */
final class ChatUnreadRoutesTest extends AccountTestCase
{
    use DetachesFromAnyAlliance;

    /** L alliance du montage ; zero tant qu il n y en a pas (le demontage ignore zero). */
    private int $alliance = 0;

    protected function tearDown(): void
    {
        $this->dissolveTheBenchAlliances($this->alliance);
        $this->alliance = 0;

        parent::tearDown();
    }

    public function testAGuestGetsNothing(): void
    {
        $this->post('/logout');
        $this->assertGuest();

        $this->getJson('/ajax/chat/unread')->assertStatus(401);
        $this->postJson('/ajax/chat/seen', ['playerId' => 1, 'seenIds' => [1]])->assertStatus(401);
    }

    public function testABannedPlayerIsLoggedOutAndSentToTheLoginPage(): void
    {
        Ban::create(['user_id' => $this->currentUserId, 'reason' => 'essai', 'banned_until' => null, 'canceled' => false]);

        $this->get('/ajax/chat/unread')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * Le temoin nomme la cause : le jour ou la route serait deplacee dans un autre groupe, il dit pourquoi il tombe.
     */
    public function testBothRoutesStayOutOfTheGameEngineAndKeepTheirGuards(): void
    {
        foreach (['chat.unread', 'chat.seen'] as $nom) {
            $route = Route::getRoutes()->getByName($nom);
            $this->assertNotNull($route, "La route $nom n existe plus.");
            $intergiciels = $route->gatherMiddleware();
            $this->assertNotContains('globalgame', $intergiciels, "$nom repasse par le moteur de jeu : chaque veille marquerait le joueur actif dans la Galaxie et en ligne.");
            $this->assertContains('banned', $intergiciels, "$nom ne controle plus les comptes bannis.");
            $this->assertContains('auth', $intergiciels, "$nom ne demande plus l authentification.");
        }
    }

    /**
     * Ce que la pile entiere ecrit pour une veille : la session, et rien d autre — ni le compte, ni ses corps.
     */
    public function testTheWholeStackWritesOnlyTheSessionRow(): void
    {
        $this->get('/overview')->assertStatus(200);

        config(['session.driver' => 'database']);
        $this->actingAs(User::query()->findOrFail($this->currentUserId));

        $corps = fn (): array => DB::table('planets')->where('user_id', $this->currentUserId)->orderBy('id')->get()->map(fn ($ligne) => (array)$ligne)->all();
        $compte = fn (): array => (array)DB::table('users')->where('id', $this->currentUserId)->first();

        $corpsAvant = $corps();
        $compteAvant = $compte();
        $this->assertNotSame([], $corpsAvant, 'Le joueur n a aucune planete : l essai ne comparerait rien.');

        // Dix minutes passent : une page ecrirait tout ; la veille et le marquage ne doivent rien ecrire.
        $this->travel(10)->minutes();
        $this->getJson('/ajax/chat/unread')->assertStatus(200);
        $this->postJson('/ajax/chat/seen', ['playerId' => 1, 'seenIds' => [999_999_999]])->assertStatus(200);

        $this->assertSame($corpsAvant, $corps(), 'La veille a ecrit une planete : l etoile d activite de la Galaxie s allumerait.');
        $this->assertSame($compteAvant, $compte(), 'La veille a ecrit le compte : « en ligne » mentirait.');
    }

    public function testTheSnapshotOnlyTellsTheRequesterAboutHimself(): void
    {
        $moi = $this->currentUserId;
        $this->createAndLoginUser();
        $kirk = $this->currentUserId;
        $this->createAndLoginUser();
        $spock = $this->currentUserId;

        $chat = resolve(ChatService::class);
        $chat->sendDirectMessage($kirk, $moi, 'pour moi');
        $chat->sendDirectMessage($kirk, $spock, 'pour spock');

        $this->actingAs(User::query()->findOrFail($moi));
        $charge = $this->getJson('/ajax/chat/unread')->assertStatus(200)->json();

        $this->assertSame(1, $charge['total']);
        $this->assertSame([['kind' => 'direct', 'playerId' => $kirk, 'unread' => 1]], $charge['conversations']);
        $this->assertStringNotContainsString('pour moi', json_encode($charge, JSON_THROW_ON_ERROR), 'Aucun contenu de message ne voyage avec la photographie.');
    }

    public function testSeenMarksTheListedMessagesAndNothingElse(): void
    {
        $moi = $this->currentUserId;
        $this->createAndLoginUser();
        $kirk = $this->currentUserId;
        $chat = resolve(ChatService::class);
        $a = $chat->sendDirectMessage($kirk, $moi, 'a');
        $b = $chat->sendDirectMessage($kirk, $moi, 'b');

        $this->actingAs(User::query()->findOrFail($moi));
        $this->postJson('/ajax/chat/seen', ['playerId' => $kirk, 'seenIds' => [(int)$a->id]])->assertStatus(200)->assertJson(['ok' => true, 'marked' => 1]);

        $this->assertSame(1, $this->getJson('/ajax/chat/unread')->json('total'));
        $this->assertNull(DB::table('chat_messages')->where('id', $b->id)->value('read_at'));
    }

    public function testAMalformedSeenRequestIsRefusedNotCompleted(): void
    {
        foreach ([
            ['playerId' => 1],
            ['playerId' => 1, 'seenIds' => []],
            ['playerId' => 1, 'seenIds' => ['abc']],
            ['playerId' => 1, 'seenIds' => [1.5]],
            ['playerId' => 'x', 'seenIds' => [1]],
            ['playerId' => 1, 'seenIds' => range(1, 201)],
            ['associationId' => 1],
            ['associationId' => 1, 'seenUpToId' => 0],
            ['associationId' => 'x', 'seenUpToId' => 1],
        ] as $charge) {
            $this->postJson('/ajax/chat/seen', $charge)->assertStatus(422);
        }
    }

    public function testMarkingAnAllianceOneIsNotAMemberOfIsForbidden(): void
    {
        $fondateur = $this->currentUserId;
        $this->createAndLoginUser();
        $etranger = $this->currentUserId;
        $service = resolve(AllianceService::class);
        $this->alliance = (int)$service->createAlliance($fondateur, 'R' . str_pad(substr((string)$fondateur, -4), 4, '0', STR_PAD_LEFT), 'Routes ' . $fondateur)->id;
        $message = resolve(ChatService::class)->sendAllianceMessage($fondateur, $this->alliance, 'entre nous');

        $this->actingAs(User::query()->findOrFail($etranger));
        $this->postJson('/ajax/chat/seen', ['associationId' => $this->alliance, 'seenUpToId' => (int)$message->id])->assertStatus(403);

        // Le fondateur, lui, marque ; un identifiant qui n est pas de ce canal est refuse, pas complete.
        $this->actingAs(User::query()->findOrFail($fondateur));
        $this->postJson('/ajax/chat/seen', ['associationId' => $this->alliance, 'seenUpToId' => 999_999_999])->assertStatus(422);
        $this->postJson('/ajax/chat/seen', ['associationId' => $this->alliance, 'seenUpToId' => (int)$message->id])->assertStatus(200)->assertJson(['ok' => true]);
    }
}
