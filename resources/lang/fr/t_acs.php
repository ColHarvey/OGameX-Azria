<?php

/*
 * Attaque groupée — les messages qu'un joueur lit quand une flotte ne peut pas rejoindre une union.
 *
 * Ces clés étaient appelées par `FleetUnionService` et `FleetController` bien avant que ce fichier
 * n'existe : chacune atteignait donc le joueur telle quelle, sous la forme d'une fenêtre affichant
 * « t_acs.error_max_fleets_reached » au lieu d'une phrase.
 *
 * Chaque message dit ce qui s'est passé et, quand le joueur peut agir, ce qu'il peut faire.
 */

return [
    'error_already_in_union' => 'Cette flotte a déjà rejoint une union.',
    'error_exceeds_delay_limit' => 'Cette flotte arriverait trop tard. Une union attend au plus 30 % de son temps de vol restant.',
    'error_invalid_mission_type' => 'Seule une attaque peut rejoindre une union.',
    'error_max_fleets_reached' => 'Cette union est complète : elle compte déjà 16 flottes.',
    'error_max_players_reached' => 'Cette union est complète : elle compte déjà 5 joueurs. Un joueur déjà présent peut encore envoyer une autre flotte.',
    'error_mission_not_active' => 'Cette flotte est déjà arrivée ou a été rappelée.',
    'error_mission_not_found' => 'Flotte introuvable.',
    'error_returning_fleet' => 'Une flotte qui rentre ne peut pas rejoindre une union.',
    'error_not_buddy_or_ally' => 'Vous ne pouvez rejoindre que l\'union d\'un ami ou d\'un membre de votre alliance.',
    'error_technical' => 'L\'union n\'a pas pu être rejointe pour le moment. Réessayez dans un instant.',
    'error_not_found' => 'Cette union n\'existe plus.',
];
