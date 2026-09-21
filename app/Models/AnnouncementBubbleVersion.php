<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une publication de la bulle d annonce : le contenu **fige** que les joueurs lisent, et son numero de version.
 *
 * Une ligne nait a chaque « Publier une nouvelle annonce », jamais a l enregistrement d un brouillon, jamais a
 * un apercu, et jamais a une desactivation. C est ce qui fait qu une fermeture survit a tout sauf a une vraie
 * nouvelle publication.
 *
 * **L historique se garde**, et pour une raison precise : une fermeture vise une version, et le serveur doit
 * pouvoir lire le drapeau `dismissible` **de cette version-la** pour dire si elle est recevable.
 *
 * @property int $id
 * @property int $version
 * @property string $title
 * @property string|null $body
 * @property string|null $link_url
 * @property string|null $link_label
 * @property bool $dismissible
 * @property Carbon $published_at
 */
#[Fillable([
    'version',
    'title',
    'body',
    'link_url',
    'link_label',
    'dismissible',
    'published_at',
])]
#[Table(name: 'announcement_bubble_versions')]
class AnnouncementBubbleVersion extends Model
{
    protected $casts = [
        'dismissible' => 'boolean',
        'published_at' => 'datetime',
    ];
}
