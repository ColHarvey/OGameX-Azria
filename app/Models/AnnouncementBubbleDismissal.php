<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * La fermeture d une bulle par un compte, pour une version donnee.
 *
 * **Par compte, jamais par planete** : elle vaut donc sur tous les corps et apres reconnexion. Et **par
 * version** : une nouvelle publication reapparait d elle-meme, sans qu il faille effacer quoi que ce soit.
 *
 * Le couple `(user_id, version)` est unique en base : deux clics ou deux onglets ne posent qu une fermeture, et
 * c est le moteur qui l impose, pas une lecture prealable.
 *
 * @property int $id
 * @property int $user_id
 * @property int $version
 */
#[Fillable(['user_id', 'version'])]
#[Table(name: 'announcement_bubble_dismissals')]
class AnnouncementBubbleDismissal extends Model
{
}
