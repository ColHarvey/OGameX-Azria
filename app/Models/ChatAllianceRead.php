<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Le curseur de lecture d un membre dans le canal de son alliance.
 *
 * `last_read_message_id` ne recule jamais : il n est ecrit que par `ChatService::markAllianceSeenUpTo()`,
 * sous la clause `last_read_message_id < :vu`. Voir la migration `create_chat_alliance_reads_table`.
 *
 * @property int $id
 * @property int $user_id
 * @property int $alliance_id
 * @property int $last_read_message_id
 */
#[Table('chat_alliance_reads')]
#[Fillable(['user_id', 'alliance_id', 'last_read_message_id'])]
class ChatAllianceRead extends Model
{
}
