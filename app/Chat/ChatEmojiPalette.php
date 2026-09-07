<?php

namespace OGame\Chat;

/**
 * La palette d'emoji du chat general.
 *
 * ## Pourquoi une liste choisie, et pas un clavier complet
 *
 * Un selecteur exhaustif demande une bibliotheque, des categories, une recherche et un index de
 * plusieurs milliers d'entrees — pour un chat de jeu, c'est du poids sans usage. Quarante-huit
 * signes couvrent ce que des joueurs s'ecrivent reellement : des reactions, des gestes, et le
 * vocabulaire du jeu lui-meme.
 *
 * ## Ce qu'ils exigent de la base
 *
 * Un emoji occupe **quatre octets en UTF-8**. Une colonne en `utf8` (trois octets) les refuse ou
 * les tronque, et cela ne se verrait qu'en production. La connexion du projet est declaree
 * `utf8mb4` / `utf8mb4_unicode_ci` par defaut ; l'epreuve MariaDB verifie qu'un aller-retour les
 * rend intacts, ce que SQLite ne prouve pas.
 */
final class ChatEmojiPalette
{
    /**
     * Les signes offerts, dans l'ordre ou ils s'affichent.
     *
     * Six rangees de huit. L'ordre est celui de la lecture : d'abord ce qu'on ressent, puis ce
     * qu'on fait, puis ce dont on parle dans ce jeu-ci.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            // Reactions
            '😀', '😄', '😂', '🙂', '😉', '😎', '🤔', '😐',
            '😅', '😬', '😢', '😡', '😱', '😴', '🥳', '🤣',
            // Gestes
            '👍', '👎', '👋', '🙏', '💪', '🤝', '🫡', '👀',
            // Le jeu
            '🚀', '🛸', '🪐', '🌍', '🌑', '⭐', '☄️', '💥',
            '🔥', '⚔️', '🛡️', '💀', '🏆', '🎯', '⚡', '🔧',
            // Ressources et suites
            '💰', '💎', '🔩', '🧪', '📈', '📉', '⏳', '❓',
        ];
    }
}
