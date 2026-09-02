<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\ActorKind;
use OGame\Models\User;

/**
 * Qui tient une flotte : un joueur, une faction pilotee par le serveur, ou le serveur lui-meme.
 *
 * ## L'ordre des controles n'est pas indifferent
 *
 * Le compte systeme est reconnu **avant** le drapeau PNJ. Il porte aujourd'hui `is_npc = 0`, mais
 * rien ne garantit qu'il en sera toujours ainsi : le jour ou quelqu'un lui mettra ce drapeau — par
 * commodite, pour l'exclure d'un classement — l'ordre inverse le ferait passer pour une faction
 * pirate ordinaire, avec les regles de pillage qui vont avec.
 *
 * ## Ce que cette classe ne resout pas
 *
 * L'identite du compte systeme repose sur un **nom d'utilisateur**. Un joueur qui choisirait ce
 * pseudonyme heriterait de tous ses traitements : exclusion des classements, immunite aux attaques,
 * et ici un genre d'acteur. Une colonne explicite vaudrait mieux ; c'est un chantier additif
 * separe, et centraliser la chaine en etait le prealable.
 */
final class ActorKindResolver
{
    /**
     * Le genre d'acteur derriere ce compte.
     *
     * @param User $user
     * @return ActorKind
     */
    public static function of(User $user): ActorKind
    {
        if ($user->username === User::SYSTEM_ACCOUNT_USERNAME) {
            return ActorKind::System;
        }

        if ($user->is_npc) {
            return ActorKind::Npc;
        }

        return ActorKind::Player;
    }
}
