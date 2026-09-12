<?php

namespace OGame\Patrol;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use OGame\Models\BuddyRequest;

/**
 * Ce qu un joueur est pour un autre, vu de la carte : **allie ou etranger**, rien entre les deux.
 *
 * Decision de Keven, 12 septembre 2026 : une flotte detectee qui n est « ni amie ni dans mon
 * alliance » se dessine en rouge, une flotte amie ou alliee en bleu. Deux couleurs, donc deux
 * valeurs — un neutre, un inconnu, un pirate sont des etrangers au meme titre qu un ennemi declare.
 *
 * Allie : la meme alliance (les deux y sont, et c est la meme), ou une demande d amitie **acceptee**
 * entre les deux, dans un sens ou dans l autre. Une demande en attente ou refusee ne fait pas un ami.
 *
 * Lecture seule, deux requetes, aucun cache : la relation se lit a l instant de la reponse, comme le
 * palier — quitter une alliance retire le bleu a la demande suivante.
 */
final class FleetRelation
{
    public const string ALLY = 'ally';

    public const string STRANGER = 'stranger';

    public function between(int $observerId, int $ownerId): string
    {
        if ($observerId === $ownerId) {
            return self::ALLY;
        }

        $alliances = DB::table('users')
            ->whereIn('id', [$observerId, $ownerId])
            ->pluck('alliance_id', 'id');

        $mienne = $alliances[$observerId] ?? null;
        $sienne = $alliances[$ownerId] ?? null;

        if ($mienne !== null && (int)$mienne === (int)$sienne) {
            return self::ALLY;
        }

        $amis = DB::table('buddy_requests')
            ->where('status', BuddyRequest::STATUS_ACCEPTED)
            ->where(static function (Builder $requete) use ($observerId, $ownerId): void {
                $requete
                    ->where(static function (Builder $sens) use ($observerId, $ownerId): void {
                        $sens->where('sender_user_id', $observerId)->where('receiver_user_id', $ownerId);
                    })
                    ->orWhere(static function (Builder $sens) use ($observerId, $ownerId): void {
                        $sens->where('sender_user_id', $ownerId)->where('receiver_user_id', $observerId);
                    });
            })
            ->exists();

        return $amis ? self::ALLY : self::STRANGER;
    }
}
