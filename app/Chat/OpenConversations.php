<?php

namespace OGame\Chat;

use OGame\Models\User;
use OGame\Services\BuddyService;
use OGame\Services\ChatService;

/**
 * Les conversations qu un joueur avait ouvertes, et ce que la page en rend **tout de suite**.
 *
 * Le navigateur memorise les fenetres ouvertes dans le cookie `visibleChats` (`updateVisibleState` de `chat.js`). Sans
 * cette classe, la page arrivait sans aucune conversation et le script redemandait chaque historique : la fenetre
 * disparaissait puis revenait a chaque changement de page — « c est comme si elle se reloadait » (constat de Keven,
 * 19 septembre 2026). La page porte desormais la meme charge utile que la route d historique, construite **ici** et
 * employee par elle : memes droits, memes donnees, aucune divergence possible.
 *
 * Ce que cette classe ne fait pas : marquer les messages comme lus. Rouvrir une fenetre en changeant de page n est pas
 * la lire — seul le chemin volontaire (`updateUnread`) le fait.
 */
final class OpenConversations
{
    /** Au plus cinq conversations : une page ne rend pas dix historiques. */
    public const int LIMITE = 5;

    public function __construct(private ChatService $chat, private BuddyService $buddies)
    {
    }

    /**
     * Les conversations a rendre, d apres la memoire du navigateur. Une memoire illisible, un identifiant qui n est pas
     * un entier positif, un interlocuteur disparu ou une alliance dont le joueur n est pas membre : rien n est rendu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fromCookie(int $userId, string|null $memoire): array
    {
        if ($userId <= 0 || $memoire === null || $memoire === '') {
            return [];
        }

        $lu = json_decode(rawurldecode($memoire), true);
        if (!is_array($lu)) {
            return [];
        }

        $ouvertes = [];
        foreach ($this->identifiants($lu['players'] ?? null) as $partenaire) {
            if (count($ouvertes) >= self::LIMITE) {
                return $ouvertes;
            }
            $charge = $this->withPlayer($userId, $partenaire);
            if ($charge !== null) {
                $ouvertes[] = $charge;
            }
        }
        foreach ($this->identifiants($lu['associations'] ?? null) as $alliance) {
            if (count($ouvertes) >= self::LIMITE) {
                return $ouvertes;
            }
            $charge = $this->withAlliance($userId, $alliance);
            if ($charge !== null) {
                $ouvertes[] = $charge;
            }
        }

        return $ouvertes;
    }

    /**
     * L historique d une conversation privee, tel que le navigateur l attend.
     *
     * @return array<string, mixed>|null
     */
    public function withPlayer(int $userId, int $partnerId): array|null
    {
        $partner = User::find($partnerId);
        if ($partner === null) {
            return null;
        }

        $formatted = $this->chat->formatMessagesForFrontend($this->chat->getConversation($userId, $partnerId), $userId);
        $user = User::find($userId);
        // La presence ne se revele qu a un ami ou a un membre de la meme alliance : la meme regle que la route.
        $visible = $this->buddies->areBuddies($userId, $partnerId)
            || ($user !== null && $user->alliance_id && $user->alliance_id === $partner->alliance_id);

        return [
            'playerId' => $partnerId,
            'playerName' => $partner->username,
            'playerstatus' => $visible && $partner->isOnline() ? 'online' : 'offline',
            'chatItems' => $formatted['chatItems'],
            'chatItemsByDateAsc' => $formatted['chatItemsByDateAsc'],
        ];
    }

    /**
     * L historique du canal d une alliance — seulement pour un membre de cette alliance.
     *
     * @return array<string, mixed>|null
     */
    public function withAlliance(int $userId, int $allianceId): array|null
    {
        $user = User::find($userId);
        if ($user === null || (int)$user->alliance_id !== $allianceId) {
            return null;
        }

        $formatted = $this->chat->formatMessagesForFrontend($this->chat->getAllianceMessages($allianceId), $userId);

        return [
            'associationId' => $allianceId,
            'associationName' => $user->alliance->alliance_name ?? 'Alliance',
            'playerstatus' => 'online',
            'chatItems' => $formatted['chatItems'],
            'chatItemsByDateAsc' => $formatted['chatItemsByDateAsc'],
        ];
    }

    /**
     * Les identifiants entiers positifs d une liste memorisee, sans doublon. Le navigateur ecrit des nombres ; une
     * version plus ancienne ecrivait des objets `{partnerId: …}` : les deux sont acceptes, rien d autre.
     *
     * @return array<int, int>
     */
    private function identifiants(mixed $liste): array
    {
        if (!is_array($liste)) {
            return [];
        }

        $vus = [];
        foreach ($liste as $entree) {
            if (is_array($entree)) {
                $entree = $entree['partnerId'] ?? null;
            }
            if (!is_int($entree) && !(is_string($entree) && ctype_digit($entree))) {
                continue;
            }
            $identifiant = (int)$entree;
            if ($identifiant > 0 && !in_array($identifiant, $vus, true)) {
                $vus[] = $identifiant;
            }
        }

        return $vus;
    }
}
