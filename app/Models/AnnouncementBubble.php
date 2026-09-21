<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Le brouillon de la bulle d annonce, et son interrupteur d affichage.
 *
 * **Une seule ligne, et c est la base qui le garantit** : la colonne `singleton` porte un index unique et vaut
 * toujours 1. Deux requetes concurrentes qui tenteraient de creer la configuration echouent a la seconde, au
 * lieu de laisser deux lignes derriere elles.
 *
 * Rien de ce qui vit ici n est visible des joueurs : la bulle qu ils lisent vient de
 * {@see AnnouncementBubbleVersion}, ecrite uniquement par une publication explicite.
 *
 * @property int $id
 * @property int $singleton
 * @property string $draft_title
 * @property string|null $draft_body
 * @property string|null $draft_link_url
 * @property string|null $draft_link_label
 * @property bool $draft_dismissible
 * @property bool $enabled
 */
#[Fillable([
    'singleton',
    'draft_title',
    'draft_body',
    'draft_link_url',
    'draft_link_label',
    'draft_dismissible',
    'enabled',
])]
#[Table(name: 'announcement_bubble')]
class AnnouncementBubble extends Model
{
    protected $casts = [
        'draft_dismissible' => 'boolean',
        'enabled' => 'boolean',
    ];
}
