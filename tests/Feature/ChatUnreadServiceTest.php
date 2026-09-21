<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Models\ChatMessage;
use OGame\Services\AllianceService;
use OGame\Services\ChatService;
use Tests\AccountTestCase;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * Ce que le serveur dit des non-lus, et ce qu il refuse de deviner.
 *
 * Le navigateur ne compte plus rien : la photographie est complete, le total est calcule ici sur des
 * conversations, un contact present dans deux listes ne compte qu une fois. Le marquage porte sur ce qui a
 * ete affiche — l ensemble exact pour un message direct, le plus grand identifiant affiche pour le canal
 * d alliance (regle tranchee par Keven le 21 septembre 2026) — et le curseur d alliance ne recule jamais.
 */
final class ChatUnreadServiceTest extends AccountTestCase
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

    // ------------------------------------------------------------------ la photographie

    public function testTheSnapshotCountsMessagesPerSenderAndTheTotalOnTheServer(): void
    {
        $moi = $this->currentUserId;
        $this->createAndLoginUser();
        $kirk = $this->currentUserId;
        $this->createAndLoginUser();
        $spock = $this->currentUserId;

        $chat = resolve(ChatService::class);
        $chat->sendDirectMessage($kirk, $moi, 'un');
        $chat->sendDirectMessage($kirk, $moi, 'deux');
        $chat->sendDirectMessage($spock, $moi, 'trois');
        // Ce que j ecris moi-meme n est jamais non lu pour moi.
        $chat->sendDirectMessage($moi, $kirk, 'reponse');

        $photo = $chat->unreadSnapshot($moi);

        $this->assertSame(3, $photo['total']);
        $this->assertCount(2, $photo['conversations']);
        $parJoueur = [];
        foreach ($photo['conversations'] as $c) {
            $this->assertSame('direct', $c['kind']);
            $joueur = $c['playerId'] ?? null;
            $this->assertIsInt($joueur, 'Une conversation directe nomme son joueur.');
            $parJoueur[$joueur] = $c['unread'];
        }
        $this->assertSame([$kirk => 2, $spock => 1], $parJoueur);
    }

    public function testTheSnapshotIsCompleteAConversationWithoutUnreadIsAbsent(): void
    {
        $moi = $this->currentUserId;
        $this->createAndLoginUser();
        $kirk = $this->currentUserId;

        $chat = resolve(ChatService::class);
        $message = $chat->sendDirectMessage($kirk, $moi, 'un');
        $this->assertSame(1, $chat->unreadSnapshot($moi)['total']);

        $chat->markSeen($moi, $kirk, [(int)$message->id]);

        $photo = $chat->unreadSnapshot($moi);
        $this->assertSame(0, $photo['total']);
        $this->assertSame([], $photo['conversations'], 'Une conversation sans non-lu ne figure pas : c est la completude qui vaut zero.');
    }

    public function testADeletedMessageIsNotUnread(): void
    {
        $moi = $this->currentUserId;
        $this->createAndLoginUser();
        $kirk = $this->currentUserId;

        $chat = resolve(ChatService::class);
        $message = $chat->sendDirectMessage($kirk, $moi, 'efface');
        $message->delete();

        $this->assertSame(0, $chat->unreadSnapshot($moi)['total']);
    }

    // ------------------------------------------------------------------ le marquage direct : l ensemble exact

    public function testMarkingSeenIdsMarksExactlyThoseAndOnlyMine(): void
    {
        $moi = $this->currentUserId;
        $this->createAndLoginUser();
        $kirk = $this->currentUserId;
        $this->createAndLoginUser();
        $autre = $this->currentUserId;

        $chat = resolve(ChatService::class);
        $a = $chat->sendDirectMessage($kirk, $moi, 'a');
        $b = $chat->sendDirectMessage($kirk, $moi, 'b');
        $c = $chat->sendDirectMessage($kirk, $moi, 'c');
        // Un message de Kirk a quelqu un d autre : le meme expediteur, pas mon message.
        $tiers = $chat->sendDirectMessage($kirk, $autre, 't');

        // Je saute au dernier : seuls a et c ont ete affiches, b non.
        $marques = $chat->markSeen($moi, $kirk, [(int)$a->id, (int)$c->id, (int)$tiers->id, 999_999_999]);

        $this->assertSame(2, $marques, 'Exactement les deux messages affiches, rien de plus.');
        $this->assertNotNull(ChatMessage::query()->findOrFail($a->id)->read_at);
        $this->assertNull(ChatMessage::query()->findOrFail($b->id)->read_at, 'Le message saute reste non lu : aucune hypothese de prefixe.');
        $this->assertNotNull(ChatMessage::query()->findOrFail($c->id)->read_at);
        $this->assertNull(ChatMessage::query()->findOrFail($tiers->id)->read_at, 'Le message adresse a un tiers n est pas marque par moi.');
        $this->assertSame(1, $chat->unreadSnapshot($moi)['total']);
    }

    public function testAMessageArrivedMeanwhileStaysUnread(): void
    {
        $moi = $this->currentUserId;
        $this->createAndLoginUser();
        $kirk = $this->currentUserId;

        $chat = resolve(ChatService::class);
        $vu = $chat->sendDirectMessage($kirk, $moi, 'vu');
        $liste = [(int)$vu->id];
        // Arrive pendant que la liste voyageait : il n y figure pas.
        $tardif = $chat->sendDirectMessage($kirk, $moi, 'tardif');

        $chat->markSeen($moi, $kirk, $liste);

        $this->assertNull(ChatMessage::query()->findOrFail($tardif->id)->read_at);
        $this->assertSame(1, $chat->unreadSnapshot($moi)['total']);
    }

    // ------------------------------------------------------------------ le canal d alliance

    public function testTheAllianceChannelCountsOthersMessagesBeyondTheCursor(): void
    {
        [$fondateur, $membre] = $this->anAllianceWithAMember();
        $chat = resolve(ChatService::class);

        $chat->sendAllianceMessage($fondateur, $this->alliance, 'un');
        $chat->sendAllianceMessage($fondateur, $this->alliance, 'deux');
        $chat->sendAllianceMessage($membre, $this->alliance, 'moi');

        $photo = $chat->unreadSnapshot($membre);
        $this->assertSame(2, $photo['total'], 'Deux messages des autres ; le mien ne compte pas.');
        $this->assertSame([['kind' => 'alliance', 'allianceId' => $this->alliance, 'unread' => 2]], $photo['conversations']);
    }

    public function testShowingAnAllianceMessageMarksThePreviousOnesAndTheCursorNeverGoesBack(): void
    {
        [$fondateur, $membre] = $this->anAllianceWithAMember();
        $chat = resolve(ChatService::class);

        $chat->sendAllianceMessage($fondateur, $this->alliance, 'un');
        $deux = $chat->sendAllianceMessage($fondateur, $this->alliance, 'deux');
        $trois = $chat->sendAllianceMessage($fondateur, $this->alliance, 'trois');

        $this->assertTrue($chat->markAllianceSeenUpTo($membre, $this->alliance, (int)$trois->id));
        $this->assertSame(0, $chat->unreadSnapshot($membre)['total'], 'Afficher le dernier marque aussi les precedents : la regle du canal.');

        // Un onglet en retard repond avec un identifiant plus petit : rien ne recule.
        $this->assertFalse($chat->markAllianceSeenUpTo($membre, $this->alliance, (int)$deux->id));
        $this->assertSame((int)$trois->id, (int)DB::table('chat_alliance_reads')->where('user_id', $membre)->where('alliance_id', $this->alliance)->value('last_read_message_id'));
        $this->assertSame(0, $chat->unreadSnapshot($membre)['total']);
    }

    public function testMembershipAndMessageIdentityAreCheckedOnTheServer(): void
    {
        [$fondateur, $membre] = $this->anAllianceWithAMember();
        $this->createAndLoginUser();
        $etranger = $this->currentUserId;
        $chat = resolve(ChatService::class);
        $message = $chat->sendAllianceMessage($fondateur, $this->alliance, 'un');
        $direct = $chat->sendDirectMessage($fondateur, $membre, 'prive');

        $this->assertTrue($chat->isAllianceMember($membre, $this->alliance));
        $this->assertFalse($chat->isAllianceMember($etranger, $this->alliance));
        $this->assertTrue($chat->isAnAllianceMessage($this->alliance, (int)$message->id));
        $this->assertFalse($chat->isAnAllianceMessage($this->alliance, (int)$direct->id), 'Un message direct n est pas un message de ce canal.');
        $this->assertFalse($chat->isAnAllianceMessage($this->alliance, 999_999_999));
    }

    // ------------------------------------------------------------------ le curseur : creation et borne

    public function testTheCursorIsPosedAtJoiningOnTheBoundOfThatInstant(): void
    {
        [$fondateur] = $this->anAllianceWithAMember();
        $chat = resolve(ChatService::class);

        // Le fondateur a ecrit avant que le membre n arrive : ce n est pas un non-lu pour lui.
        $this->createAndLoginUser();
        $nouveau = $this->currentUserId;
        $chat->sendAllianceMessage($fondateur, $this->alliance, 'avant l arrivee');
        $this->admettre($nouveau);

        $this->assertSame(0, $chat->unreadSnapshot($nouveau)['total'], 'L historique d avant l entree n est pas un non-lu.');

        $chat->sendAllianceMessage($fondateur, $this->alliance, 'apres l arrivee');
        $this->assertSame(1, $chat->unreadSnapshot($nouveau)['total']);

        // Et l historique reste entierement lisible : le curseur dit ce qui compte, il n interdit rien.
        $this->assertCount(2, $chat->getAllianceMessages($this->alliance), 'Les deux messages sont consultables, celui d avant l entree compris.');
    }

    public function testAMissingCursorIsCreatedOnTheBoundOfTheJoiningInstantNeverNow(): void
    {
        [$fondateur, $membre] = $this->anAllianceWithAMember();
        $chat = resolve(ChatService::class);

        // Le membre a rejoint, puis le fondateur a ecrit ; la ligne du curseur disparait (cas residuel).
        // **L ordre est etabli, pas suppose** : l horloge du banc est gelee, et sans ce pas `joined_at` et le
        // `created_at` du message tomberaient dans la meme seconde — le message ecrit « apres » l entree
        // passerait pour ecrit « a » l entree.
        $this->travel(1)->seconds();
        $chat->sendAllianceMessage($fondateur, $this->alliance, 'depuis son entree');
        DB::table('chat_alliance_reads')->where('user_id', $membre)->delete();

        $this->assertSame(1, $chat->unreadSnapshot($membre)['total'], 'La ligne recree se pose a l adhesion, pas a maintenant : ce qui est arrive depuis reste non lu.');
        $this->assertSame(1, DB::table('chat_alliance_reads')->where('user_id', $membre)->where('alliance_id', $this->alliance)->count(), 'La ligne est bien recreee.');
    }

    public function testOpeningTheCursorTwiceWritesOneRowAndNeverLowersAnExistingOne(): void
    {
        [$fondateur, $membre] = $this->anAllianceWithAMember();
        $chat = resolve(ChatService::class);
        $trois = $chat->sendAllianceMessage($fondateur, $this->alliance, 'trois');
        $chat->markAllianceSeenUpTo($membre, $this->alliance, (int)$trois->id);

        $chat->openAllianceCursor($membre, $this->alliance, 0);
        $chat->openAllianceCursor($membre, $this->alliance, 0);

        $lignes = DB::table('chat_alliance_reads')->where('user_id', $membre)->where('alliance_id', $this->alliance)->get();
        $this->assertCount(1, $lignes);
        $ligne = $lignes->first();
        $this->assertNotNull($ligne);
        $this->assertSame((int)$trois->id, (int)$ligne->last_read_message_id, 'Un appel concurrent ou repete ne recule pas un curseur existant.');
    }

    public function testLeavingClosesTheCursorAndComingBackPosesANewOne(): void
    {
        [$fondateur, $membre] = $this->anAllianceWithAMember();
        $chat = resolve(ChatService::class);
        $service = resolve(AllianceService::class);

        $chat->sendAllianceMessage($fondateur, $this->alliance, 'pendant le sejour');
        $service->leaveAlliance($membre);
        $this->assertSame(0, DB::table('chat_alliance_reads')->where('user_id', $membre)->count(), 'Partir retire le curseur.');
        $this->assertSame(0, $chat->unreadSnapshot($membre)['total'], 'Sans alliance, aucun non-lu d alliance.');

        $chat->sendAllianceMessage($fondateur, $this->alliance, 'pendant l absence');
        // Partir impose sept jours de carence avant de rejoindre : le banc les laisse passer, il ne les contourne pas.
        $this->travel(8)->days();
        $this->admettre($membre);

        $this->assertSame(0, $chat->unreadSnapshot($membre)['total'], 'Revenir repose le curseur sur une borne neuve : rien de l absence n est un non-lu.');
    }

    // ------------------------------------------------------------------ le montage

    /**
     * @return array{0: int, 1: int} le fondateur et un membre admis
     */
    private function anAllianceWithAMember(): array
    {
        $fondateur = $this->currentUserId;
        $this->createAndLoginUser();
        $membre = $this->currentUserId;

        $service = resolve(AllianceService::class);
        $this->alliance = (int)$service->createAlliance($fondateur, 'U' . str_pad(substr((string)$fondateur, -4), 4, '0', STR_PAD_LEFT), 'Unread ' . $fondateur)->id;
        $this->admettre($membre);

        return [$fondateur, $membre];
    }

    private function admettre(int $joueur): void
    {
        $service = resolve(AllianceService::class);
        $fondateur = (int)DB::table('alliances')->where('id', $this->alliance)->value('founder_user_id');
        $candidature = $service->applyToAlliance($joueur, (int)$this->alliance);
        $service->acceptApplication((int)$candidature->id, $fondateur);
    }
}
