<?php

/*
 * Alliance Combat System — the messages a player reads when a fleet cannot join a union.
 *
 * These keys were referenced by `FleetUnionService` and `FleetController` long before this file
 * existed, so every one of them reached the player as its own key: a dialog reading
 * "t_acs.error_max_fleets_reached" instead of a sentence.
 *
 * Each message says what happened and, where the player can act, what to do about it.
 */

return [
    'error_already_in_union' => 'This fleet has already joined a union.',
    'error_exceeds_delay_limit' => 'This fleet would arrive too late. A union waits at most 30% of its remaining flight time.',
    'error_invalid_mission_type' => 'Only an attack can join a union.',
    'error_max_fleets_reached' => 'This union is full: it already holds 16 fleets.',
    'error_max_players_reached' => 'This union is full: it already holds 5 players. Someone already in it can still send another fleet.',
    'error_mission_not_active' => 'This fleet has already arrived or been recalled.',
    'error_mission_not_found' => 'Fleet not found.',
    'error_not_buddy_or_ally' => 'You can only join a union created by a buddy or by a member of your alliance.',
    'error_not_found' => 'This union no longer exists.',
];
