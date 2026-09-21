<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OGame\Services\ChatService;

/**
 * Les non-lus du chat : la photographie se lit, le marquage s ecrit.
 *
 * ## Hors de `globalgame`, et pourquoi
 *
 * Les deux routes vivent dans le groupe `['auth', 'banned', 'locale']` — celui du bandeau des ressources, de
 * la recompense quotidienne et de la fermeture d annonce. Une veille qui traverserait `globalgame` ferait
 * avancer `users.time` (« en ligne ») et `planets.time_last_update` (l etoile d activite de la Galaxie) :
 * tout joueur ayant un onglet ouvert paraitrait actif en permanence. Authentification et controle des
 * bannis sont conserves par les deux intergiciels restants ; `ChatUnreadRoutesTest` l epingle.
 *
 * ## Ce que le serveur decide, et ce qu il ne devine pas
 *
 * - **La photographie est complete** : chaque conversation qui porte au moins un non-lu, et le total. Une
 *   conversation absente porte zero. Rien d un tiers, aucun contenu de message.
 * - **Le marquage porte sur ce qui a ete affiche**, jamais sur « maintenant » : les identifiants vus pour
 *   un message direct, le plus grand identifiant affiche pour le canal d alliance (regle tranchee par Keven
 *   le 21 septembre 2026 : afficher un message d alliance marque aussi les precedents).
 * - Une demande mal formee est **refusee** (422), une alliance dont le joueur n est pas membre aussi (403) :
 *   le serveur ne complete pas une demande, il la rejette.
 */
class ChatUnreadController extends Controller
{
    /** Au-dela, le navigateur envoie plusieurs demandes ; il n elargit pas la regle. */
    public const int MAX_SEEN_IDS = 200;

    public function snapshot(ChatService $chat): JsonResponse
    {
        return response()->json($chat->unreadSnapshot((int)auth()->id()));
    }

    public function seen(Request $request, ChatService $chat): JsonResponse
    {
        $userId = (int)auth()->id();

        $associationId = $request->input('associationId');
        if ($associationId !== null) {
            return $this->allianceSeen($userId, $associationId, $request->input('seenUpToId'), $chat);
        }

        $playerId = $request->input('playerId');
        $seenIds = $request->input('seenIds');

        if (!self::isAPositiveInt($playerId) || !is_array($seenIds) || $seenIds === [] || count($seenIds) > self::MAX_SEEN_IDS) {
            return response()->json(['ok' => false, 'reason' => 'invalid'], 422);
        }

        $ids = [];
        foreach ($seenIds as $id) {
            if (!self::isAPositiveInt($id)) {
                return response()->json(['ok' => false, 'reason' => 'invalid'], 422);
            }
            $ids[] = (int)$id;
        }

        return response()->json(['ok' => true, 'marked' => $chat->markSeen($userId, (int)$playerId, $ids)]);
    }

    private function allianceSeen(int $userId, mixed $associationId, mixed $seenUpToId, ChatService $chat): JsonResponse
    {
        if (!self::isAPositiveInt($associationId) || !self::isAPositiveInt($seenUpToId)) {
            return response()->json(['ok' => false, 'reason' => 'invalid'], 422);
        }

        $allianceId = (int)$associationId;
        $messageId = (int)$seenUpToId;

        if (!$chat->isAllianceMember($userId, $allianceId)) {
            return response()->json(['ok' => false, 'reason' => 'not_a_member'], 403);
        }

        if (!$chat->isAnAllianceMessage($allianceId, $messageId)) {
            return response()->json(['ok' => false, 'reason' => 'unknown_message'], 422);
        }

        return response()->json(['ok' => true, 'advanced' => $chat->markAllianceSeenUpTo($userId, $allianceId, $messageId)]);
    }

    /**
     * Une porte de confiance ne transtype pas : `'12'` vaut, `12.5`, `true` et `'abc'` ne valent pas.
     */
    private static function isAPositiveInt(mixed $valeur): bool
    {
        if (is_int($valeur)) {
            return $valeur > 0;
        }

        return is_string($valeur) && preg_match('/^[1-9][0-9]{0,17}$/', $valeur) === 1;
    }
}
