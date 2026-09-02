<?php

namespace OGame\Combat\Enums;

/**
 * Pourquoi un camp attaquant ne releve d'aucune regle de pillage.
 *
 * ## Pourquoi une raison typee plutot que trois exceptions
 *
 * Trois exceptions inviteraient a trois `catch`, et le jour ou une quatrieme composition
 * apparaitrait, elle traverserait la frontiere sans etre interceptee — elle laisserait la mission
 * non traitee, et l ordonnanceur la rejouerait a chaque passage. C est exactement la boucle que le
 * repli existe pour supprimer.
 *
 * Une seule famille d exception, portant la raison, garantit qu une nouvelle composition invalide
 * est **forcement** couverte par le repli. Ce qu il faut alors decider, c est sa raison persistee —
 * et un `match` exhaustif oblige a la decider.
 */
enum UnsupportedSideReason: string
{
    /**
     * Aucun attaquant.
     *
     * Un combat sans camp attaquant n'a pas de fret engage, donc pas de ponderation possible. Le cas
     * signale une collecte de flottes qui a rendu une liste vide la ou elle promettait au moins
     * l'initiateur.
     */
    case EmptySide = 'empty_side';

    /**
     * Des joueurs et des acteurs pilotes par le serveur dans le meme camp.
     *
     * `cargo_weighted_v1` traiterait le fret pirate comme du fret non-Decouvreur, et `npc_base_v1`
     * ignorerait les joueurs presents. Aucune des deux ne decrit ce camp.
     */
    case MixedPlayerNpc = 'mixed_player_npc';

    /**
     * Le compte systeme figure parmi les attaquants.
     *
     * Aucune mecanique de jeu ne s'applique a lui. Le voir attaquer signale une donnee ou un chemin
     * de code inattendu, pas une partie en cours.
     */
    case SystemActorPresent = 'system_actor_present';

    /**
     * La raison de non-pillage persistee quand la mission degrade ce refus.
     *
     * **Un `match` sans branche par defaut, et c est deliberement contraignant.** Une raison ajoutee
     * a cette enumeration sans decision correspondante fera echouer la compilation du `match`, donc
     * un test — au lieu de retomber sur une valeur generique qui rendrait l audit muet sur ce qui
     * s est reellement passe.
     *
     * @return NoLootReason
     */
    public function noLootReason(): NoLootReason
    {
        return match ($this) {
            self::EmptySide => NoLootReason::EmptyAttackingSide,
            self::MixedPlayerNpc => NoLootReason::MixedPlayerNpcSide,
            self::SystemActorPresent => NoLootReason::SystemActorPresent,
        };
    }
}
