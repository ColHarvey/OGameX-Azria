<?php

namespace OGame\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use OGame\Chat\PresentedAuthor;
use OGame\Events\ChatMessageSent;
use OGame\Models\ChatMessage;
use OGame\Models\IgnoredPlayer;
use OGame\Models\User;

/**
 * Class ChatService.
 *
 * Chat Service - handles all chat messaging logic.
 *
 * @package OGame\Services
 */
class ChatService
{
    /**
     * Send a direct message to another player.
     */
    public function sendDirectMessage(int $senderId, int $recipientId, string $message, int|null $replyToId = null): ChatMessage
    {
        $chatMessage = ChatMessage::create([
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'message' => $message,
            'reply_to_id' => $replyToId,
        ]);

        $chatMessage->load(['sender', 'replyTo.sender']);

        broadcast(new ChatMessageSent($chatMessage))->toOthers();

        return $chatMessage;
    }

    /**
     * Send a message to an alliance chat.
     */
    public function sendAllianceMessage(int $senderId, int $allianceId, string $message, int|null $replyToId = null): ChatMessage
    {
        $chatMessage = ChatMessage::create([
            'sender_id' => $senderId,
            'alliance_id' => $allianceId,
            'message' => $message,
            'reply_to_id' => $replyToId,
        ]);

        $chatMessage->load(['sender', 'replyTo.sender']);

        broadcast(new ChatMessageSent($chatMessage))->toOthers();

        return $chatMessage;
    }

    /**
     * Get conversation history between two players.
     *
     * @return Collection<int, ChatMessage>
     */
    public function getConversation(int $userId, int $partnerId, int $limit = 50, int|null $beforeId = null): Collection
    {
        $query = ChatMessage::where(function ($q) use ($userId, $partnerId) {
            $q->where(function ($q2) use ($userId, $partnerId) {
                $q2->where('sender_id', $userId)->where('recipient_id', $partnerId);
            })->orWhere(function ($q2) use ($userId, $partnerId) {
                $q2->where('sender_id', $partnerId)->where('recipient_id', $userId);
            });
        })->with(['sender', 'replyTo.sender']);

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        return $query->orderBy('id', 'desc')->limit($limit)->get()->reverse()->values();
    }

    /**
     * Send a message to the whole server.
     *
     * **Ni destinataire, ni alliance** : c'est ce qui distingue un message general des deux autres
     * genres, et cela suffit — les deux colonnes sont nullables depuis l'origine.
     */
    public function sendGeneralMessage(int $senderId, string $message, int|null $replyToId = null): ChatMessage
    {
        $chatMessage = ChatMessage::create([
            'sender_id' => $senderId,
            'message' => $message,
            'reply_to_id' => $replyToId,
        ]);

        $chatMessage->load(['sender', 'replyTo.sender']);

        broadcast(new ChatMessageSent($chatMessage))->toOthers();

        return $chatMessage;
    }

    /**
     * Get general chat history, as this viewer may see it.
     *
     * **Les auteurs que le lecteur ignore sont retires ici.** Le canal, lui, est unique et porte le
     * message a tout le monde : la diffusion ne peut pas filtrer par lecteur. C'est donc le
     * navigateur qui ecarte les memes auteurs en direct, depuis la meme liste — et ce que le joueur
     * voit apres un rechargement concorde avec ce qu'il a vu arriver.
     *
     * @return Collection<int, ChatMessage>
     */
    public function getGeneralMessages(int $viewerId, int $limit = 50, int|null $beforeId = null): Collection
    {
        $query = ChatMessage::whereNull('recipient_id')
            ->whereNull('alliance_id')
            ->whereNotIn('sender_id', $this->playersIgnoredBy($viewerId))
            ->with(['sender', 'replyTo.sender']);

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        return $query->orderBy('id', 'desc')->limit($limit)->get()->reverse()->values();
    }

    /**
     * The identifiers this player has chosen not to hear from.
     *
     * @return list<int>
     */
    public function playersIgnoredBy(int $viewerId): array
    {
        $identifiants = IgnoredPlayer::where('user_id', $viewerId)->pluck('ignored_user_id')->all();

        return array_values(array_map(static fn ($identifiant): int => (int)$identifiant, $identifiants));
    }

    /**
     * Get alliance chat message history.
     *
     * @return Collection<int, ChatMessage>
     */
    public function getAllianceMessages(int $allianceId, int $limit = 50, int|null $beforeId = null): Collection
    {
        $query = ChatMessage::where('alliance_id', $allianceId)
            ->with(['sender', 'replyTo.sender']);

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        return $query->orderBy('id', 'desc')->limit($limit)->get()->reverse()->values();
    }

    /**
     * Mark all messages from a partner as read.
     */
    public function markAsRead(int $userId, int $partnerId): void
    {
        ChatMessage::where('sender_id', $partnerId)
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * L etat complet des non-lus du joueur : chaque conversation qui en porte, et le total.
     *
     * **Complete, et c est ce qui compte** : une conversation absente porte zero, et le navigateur remplace tout
     * son etat par cette photographie. Le total est calcule ici, sur des conversations, jamais somme sur des
     * badges — un contact present dans deux listes ne compte qu une fois.
     *
     * @return array{total: int, conversations: list<array{kind: string, playerId?: int, allianceId?: int, unread: int}>}
     */
    public function unreadSnapshot(int $userId): array
    {
        $conversations = [];
        $total = 0;

        foreach ($this->getUnreadCounts($userId) as $senderId => $count) {
            $conversations[] = ['kind' => 'direct', 'playerId' => (int)$senderId, 'unread' => (int)$count];
            $total += (int)$count;
        }

        $appartenance = DB::table('alliance_members')->where('user_id', $userId)->first(['alliance_id', 'joined_at']);
        if ($appartenance !== null) {
            $allianceId = (int)$appartenance->alliance_id;
            $nonLus = $this->allianceUnreadCount($userId, $allianceId, $this->allianceCursor($userId, $allianceId, $appartenance->joined_at));
            if ($nonLus > 0) {
                $conversations[] = ['kind' => 'alliance', 'allianceId' => $allianceId, 'unread' => $nonLus];
                $total += $nonLus;
            }
        }

        return ['total' => $total, 'conversations' => $conversations];
    }

    /**
     * Marquer lus **exactement** les messages directs vus, et eux seuls.
     *
     * Aucune hypothese de prefixe : sauter au dernier message ne marque pas ceux qu on n a pas affiches, et un
     * message arrive pendant la requete n est pas dans la liste, donc reste non lu.
     *
     * @param list<int> $seenIds
     * @return int le nombre de messages passes a « lu » par cet appel
     */
    public function markSeen(int $userId, int $partnerId, array $seenIds): int
    {
        $ids = array_values(array_unique(array_filter($seenIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        return ChatMessage::where('sender_id', $partnerId)
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->whereIn('id', $ids)
            ->update(['read_at' => now()]);
    }

    /**
     * Avancer le curseur du canal d alliance jusqu au plus grand message affiche — et **jamais en arriere**.
     *
     * La clause `<` fait la garantie : deux onglets qui repondent dans le desordre ne le font pas reculer, et
     * un curseur deja au-dela ne bouge pas. Le nombre de lignes rendu n est pas lu comme une possession
     * (MariaDB compte les changees, SQLite les trouvees) : on relit.
     *
     * @return bool le curseur a-t-il avance
     */
    public function markAllianceSeenUpTo(int $userId, int $allianceId, int $seenUpToId): bool
    {
        $avant = (int)(DB::table('chat_alliance_reads')->where('user_id', $userId)->where('alliance_id', $allianceId)->value('last_read_message_id') ?? -1);
        if ($avant === -1) {
            $this->openAllianceCursor($userId, $allianceId, 0);
            $avant = 0;
        }

        DB::table('chat_alliance_reads')
            ->where('user_id', $userId)
            ->where('alliance_id', $allianceId)
            ->where('last_read_message_id', '<', $seenUpToId)
            ->update(['last_read_message_id' => $seenUpToId, 'updated_at' => now()]);

        $apres = (int)DB::table('chat_alliance_reads')->where('user_id', $userId)->where('alliance_id', $allianceId)->value('last_read_message_id');

        return $apres > $avant;
    }

    public function isAllianceMember(int $userId, int $allianceId): bool
    {
        return DB::table('alliance_members')->where('user_id', $userId)->where('alliance_id', $allianceId)->exists();
    }

    public function isAnAllianceMessage(int $allianceId, int $messageId): bool
    {
        return ChatMessage::where('alliance_id', $allianceId)->whereKey($messageId)->exists();
    }

    /**
     * Poser le curseur d un membre a son entree dans l alliance, sur la borne de cet instant.
     *
     * Sans borne donnee, elle vaut le dernier message de l alliance **maintenant** : ce qui precede l entree
     * ne devient pas un tas de non-lus — et ce n est pas une interdiction de consulter l historique, qui reste
     * entierement lisible. `insertOrIgnore` sur la clef unique : deux appels concurrents n ecrivent qu une
     * ligne, et aucun ne recule un curseur existant.
     */
    public function openAllianceCursor(int $userId, int $allianceId, int|null $bound = null): void
    {
        $borne = $bound ?? (int)(ChatMessage::where('alliance_id', $allianceId)->max('id') ?? 0);

        DB::table('chat_alliance_reads')->insertOrIgnore([
            'user_id' => $userId,
            'alliance_id' => $allianceId,
            'last_read_message_id' => $borne,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Quitter — ou etre exclu — retire le curseur : revenir le repose a l entree, sur une borne neuve. */
    public function closeAllianceCursor(int $userId, int $allianceId): void
    {
        DB::table('chat_alliance_reads')->where('user_id', $userId)->where('alliance_id', $allianceId)->delete();
    }

    /** Les messages d alliance ecrits par un autre, au-dela du curseur. */
    public function allianceUnreadCount(int $userId, int $allianceId, int $cursor): int
    {
        return ChatMessage::where('alliance_id', $allianceId)
            ->where('id', '>', $cursor)
            ->where('sender_id', '!=', $userId)
            ->count();
    }

    /**
     * Le curseur, ou sa creation si la ligne manque malgre tout.
     *
     * **Jamais « maintenant »** : la borne est le dernier message de l alliance **au moment de l adhesion**
     * (`alliance_members.joined_at`). Une ligne absente ne peut donc pas avaler ce qui est arrive depuis
     * l entree du membre. Puis relecture de la ligne effectivement en base : `insertOrIgnore` a pu perdre
     * contre un appel concurrent, et c est celui-la qui fait foi.
     */
    private function allianceCursor(int $userId, int $allianceId, mixed $joinedAt): int
    {
        $curseur = DB::table('chat_alliance_reads')->where('user_id', $userId)->where('alliance_id', $allianceId)->value('last_read_message_id');
        if ($curseur !== null) {
            return (int)$curseur;
        }

        $requete = ChatMessage::where('alliance_id', $allianceId);
        if (is_string($joinedAt) && $joinedAt !== '') {
            $requete->where('created_at', '<=', $joinedAt);
        }
        $this->openAllianceCursor($userId, $allianceId, (int)($requete->max('id') ?? 0));

        return (int)(DB::table('chat_alliance_reads')->where('user_id', $userId)->where('alliance_id', $allianceId)->value('last_read_message_id') ?? 0);
    }

    /**
     * Get unread message counts per sender for a user.
     *
     * @return array<int, int> Map of sender_id => unread count
     */
    public function getUnreadCounts(int $userId): array
    {
        return ChatMessage::where('recipient_id', $userId)
            ->whereNull('read_at')
            ->selectRaw('sender_id, COUNT(*) as count')
            ->groupBy('sender_id')
            ->pluck('count', 'sender_id')
            ->toArray();
    }

    /**
     * Get the number of conversations with unread messages for a user.
     */
    public function getUnreadConversationCount(int $userId): int
    {
        return ChatMessage::where('recipient_id', $userId)
            ->whereNull('read_at')
            ->distinct('sender_id')
            ->count('sender_id');
    }

    /**
     * Get the total number of unread chat messages for a user.
     */
    public function getTotalUnreadMessageCount(int $userId): int
    {
        return ChatMessage::where('recipient_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Get recent conversations for a user (unique chat partners with last message info).
     *
     * @return array<int, array{partner_id: int, partner_name: string, last_message: string, last_message_date: Carbon, unread_count: int}>
     */
    public function getRecentConversations(int $userId): array
    {
        // Get all direct messages involving this user, ordered by most recent
        $messages = ChatMessage::where(function ($q) use ($userId) {
            $q->where('sender_id', $userId)->orWhere('recipient_id', $userId);
        })
            ->whereNull('alliance_id')
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->get();

        $unreadCounts = $this->getUnreadCounts($userId);
        $conversations = [];

        foreach ($messages as $message) {
            $partnerId = $message->sender_id === $userId ? $message->recipient_id : $message->sender_id;

            if ($partnerId === null) {
                continue;
            }

            if (isset($conversations[$partnerId])) {
                continue;
            }

            $createdAt = $message->created_at;
            if ($createdAt === null) {
                continue;
            }

            $partner = $message->sender_id === $partnerId ? $message->sender : User::find($partnerId);

            $conversations[$partnerId] = [
                'partner_id' => $partnerId,
                'partner_name' => $partner->username ?? 'Unknown',
                'last_message' => $message->message,
                'last_message_date' => $createdAt,
                'unread_count' => $unreadCounts[$partnerId] ?? 0,
            ];
        }

        return array_values($conversations);
    }

    /**
     * Check if a player can message another player (not ignored).
     */
    public function canMessagePlayer(int $senderId, int $recipientId): bool
    {
        // Check if sender is ignored by recipient
        return !IgnoredPlayer::where('user_id', $recipientId)
            ->where('ignored_user_id', $senderId)
            ->exists();
    }

    /**
     * Format chat messages for the frontend response.
     *
     * @param Collection<int, ChatMessage> $messages
     * @return array{chatItems: array<int|string, array<string, mixed>>, chatItemsByDateAsc: list<numeric-string>}
     */
    public function formatMessagesForFrontend(Collection $messages, int $currentPlayerId): array
    {
        $chatItems = [];
        $chatItemsByDateAsc = [];

        foreach ($messages as $message) {
            $key = (string) $message->id;
            $isOwnMessage = $message->sender_id === $currentPlayerId;

            $createdAt = $message->created_at;

            $item = [
                'date' => $createdAt !== null ? $createdAt->timestamp : 0,
                'newClass' => '',
                'playerName' => $message->sender->username,
                'altClass' => $isOwnMessage ? 'odd' : '',
                'chatID' => $message->id,
                'chatContent' => e($message->message),
            ];

            // Add reply reference data if present
            if ($message->replyTo) {
                $item['refData'] = [
                    'author' => $message->replyTo->sender->username ?? 'Unknown',
                    'text' => e($message->replyTo->message),
                ];
            }

            // Les marques du classement — tag d'alliance, badge, honneur — composees par la meme
            // classe que la diffusion. Les deux chemins disent donc la meme chose.
            $item['author'] = PresentedAuthor::of($message->sender)->forTheBrowser();

            $chatItems[$key] = $item;
            $chatItemsByDateAsc[] = $key;
        }

        return [
            'chatItems' => $chatItems,
            'chatItemsByDateAsc' => $chatItemsByDateAsc,
        ];
    }
}
