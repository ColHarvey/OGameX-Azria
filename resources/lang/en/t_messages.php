<?php

return [
    'admin_announcement' => [
        'from' => 'Administration',
        'subject' => ':subject',
        'body' => ':body',
    ],
    'event_started' => [
        'from' => 'Administration',
        'subject' => 'A mission event is starting',
        'body' => "A daily mission event is running from :start to :end inclusive.\n\nEvery day a fresh draw of missions awaits you. Completing them earns tritium, which unlocks five ranks of rewards: resources, dark matter or items.\n\nHead to the Event page to see today's missions.",
    ],
    // ------------------------
    'welcome_message' => [
        'from' => 'OGameX',
        'subject' => 'Welcome to OGameX!',
        'body' => 'Greetings Emperor :player!

Congratulations on starting your illustrious career. I will be here to guide you through your first steps.

On the left you can see the menu which allows you to supervise and govern your galactic empire.

You’ve already seen the Overview. Resources and Facilities allow you to construct buildings to help you expand your empire. Start by building a Solar Plant to harvest energy for your mines.

Then expand your Metal Mine and Crystal Mine to produce vital resources. Otherwise, simply take a look around for yourself. You’ll soon feel well at home, I’m sure.

You can find more help, tips and tactics here:

Discord Chat: Discord Server
Forum: OGameX Forum
Support: Game Support

You’ll only find current announcements and changes to the game in the forums.


Now you’re ready for the future. Good luck!

This message will be deleted in 7 days.',
    ],

    // ------------------------
    'return_of_fleet_with_resources' => [
        'from' => 'Fleet Command',
        'subject' => 'Return of a fleet',
        'body' => 'Your fleet is returning from :from to :to and delivered its goods:

Metal: :metal
Crystal: :crystal
Deuterium: :deuterium',
    ],

    // ------------------------
    'return_of_fleet' => [
        'from' => 'Fleet Command',
        'subject' => 'Return of a fleet',
        'body' => 'Your fleet is returning from :from to :to.

The fleet doesn\'t deliver goods.',
        ],

    // ------------------------
    'fleet_deployment_with_resources' => [
        'from' => 'Fleet Command',
        'subject' => 'Return of a fleet',
        'body' => 'One of your fleets from :from has reached :to and delivered its goods:

Metal: :metal
Crystal: :crystal
Deuterium: :deuterium',
    ],

    // ------------------------
    'fleet_deployment' => [
        'from' => 'Fleet Command',
        'subject' => 'Return of a fleet',
        'body' => 'One of your fleets from :from has reached :to. The fleet doesn`t deliver goods.',
        ],

    // ------------------------
    'transport_arrived' => [
        'from' => 'Fleet Command',
        'subject' => 'Reaching a planet',
        'body' => 'Your fleet from :from reaches :to and delivers its goods:
Metal: :metal Crystal: :crystal Deuterium: :deuterium',
        ],

    // ------------------------
    'transport_received' => [
        'from' => 'Fleet Command',
        'subject' => 'Incoming fleet',
        'body' => 'An incoming fleet from :from has reached your planet :to and delivered its goods:
Metal: :metal Crystal: :crystal Deuterium: :deuterium',
    ],

    // ------------------------
    'combat_rally_refused' => [
        'from' => 'Fleet Command',
        'subject' => 'Fleet refused at the rally',
        'body' => 'Your fleet could not join the combat at :coordinates and is returning to base.
Reason: :reason',
        'reasons' => [
            'origin_combat_locked' => 'its planet of origin is engaged in a combat',
            'target_combat_locked' => 'the target is already engaged in another combat',
            'rally_closed' => 'the rally was already closed when it arrived',
            'alliance_not_eligible' => 'your alliance cannot join this combat',
            'fleet_limit_reached' => 'the maximum number of fleets has been reached',
            'player_limit_reached' => 'the maximum number of players has been reached',
            'npc_side_not_reinforceable' => 'this side cannot be reinforced',
            'already_engaged' => 'it is already engaged elsewhere',
            'resolution_in_progress' => 'the combat was being resolved',
            'own_fleet_coming_home' => 'one of your fleets was already coming home',
            'no_combat_effect' => 'its mission has no effect on this combat',
            'undecided' => 'the rule for this case has not been settled yet',
            'position_no_longer_free' => 'the position is no longer free',
            'wrong_target_body' => 'it was not aimed at this body',
            'not_already_in_flight' => 'it was not in flight yet when the combat opened',
            'rally_window_limit' => 'it would have arrived after the rally window',
            'candidate_recalled' => 'it was recalled before the closure',
            'no_return_destination' => 'no return destination could be established',
        ],
    ],
    'combat_cancelled' => [
        'from' => 'Fleet Command',
        'subject' => 'Combat cancelled',
        'body' => 'The combat at :coordinates has been cancelled: :cause.
Your fleets are returning to base. Fingerprint of the abandoned facts: :fingerprint',
        'no_fingerprint' => 'none (the combat had not been frozen yet)',
        'causes' => [
            'target_disappeared' => 'the targeted body no longer exists',
            'attacker_removed' => 'the attacker left the game',
            'administrative_decision' => 'administrative decision',
            'inconsistent_snapshot' => 'the combat snapshot turned out to be inconsistent',
        ],
    ],
    'acs_defend_arrival_host' => [
        'from' => 'Space Monitoring',
        'subject' => 'Fleet is stopping',
        'body' => 'A fleet has arrived at :to.',
    ],

    // ------------------------
    'acs_defend_arrival_sender' => [
        'from' => 'Fleet Command',
        'subject' => 'Fleet is stopping',
        'body' => 'A fleet has arrived at :to.',
    ],

    // ------------------------
    'colony_established' => [
        'from' => 'Fleet Command',
        'subject' => 'Settlement Report',
        'body' => 'The fleet has arrived at the assigned coordinates :coordinates, found a new planet there and are beginning to develop upon it immediately.',
    ],

    // ------------------------
    'colony_establish_fail_astrophysics' => [
        'from' => 'Settlers',
        'subject' => 'Settlement Report',
        'body' => 'The fleet has arrived at assigned coordinates :coordinates and ascertains that the planet is viable for colonisation. Shortly after starting to develop the planet, the colonists realise that their knowledge of astrophysics is not sufficient to complete the colonisation of a new planet.',
    ],

    // ------------------------
    'espionage_report' => [
        'from' => 'Fleet Command',
        'subject' => 'Espionage report from :planet',
    ],

    // ------------------------
    'espionage_detected' => [
        'from' => 'Fleet Command',
        'subject' => 'Espionage report from Planet :planet',
        'body' => "A foreign fleet from planet :planet (:attacker_name) was sighted near your planet\n:defender\nChance of counter-espionage: :chance%",
    ],

    // ------------------------
    'battle_report' => [
        'from' => 'Fleet Command',
        'subject' => 'Combat report :planet',
    ],

      // ------------------------
    'fleet_lost_contact' => [
        'from' => 'Fleet Command',
        'subject' => 'Contact with the attacking fleet has been lost. :coordinates',
        'body' => '(That means it was destroyed in the first round.)',
    ],

    // ------------------------
    'debris_field_harvest' => [
        'from' => 'Fleet',
        'subject' => 'Harvesting report from DF on :coordinates',
        'body' => 'Your :ship_name (:ship_amount ships) have a total storage capacity of :storage_capacity. At the target :to, :metal Metal, :crystal Crystal and :deuterium Deuterium are floating in space. You have harvested :harvested_metal Metal, :harvested_crystal Crystal and :harvested_deuterium Deuterium.',
    ],

    // ------------------------
    // Expedition generic message parts
    'expedition_resources_captured' => ':resource_type :resource_amount have been captured.',
    'expedition_dark_matter_captured' => '(:dark_matter_amount Dark Matter)',
    'expedition_units_captured' => 'The following ships are now part of the fleet:',

    'expedition_unexplored_statement' => 'Entry from the communication officers logbook: It seems that this part of the universe has not been explored yet.',

    // Expedition Failed
    'expedition_failed' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionFailed class.
        'body' => [
            '1' => 'Due to a failure in the central computers of the flagship, the expedition mission had to be aborted. Unfortunately as a result of the computer malfunction, the fleet returns home empty handed.',
            '2' => 'Your expedition nearly ran into a neutron stars gravitation field and needed some time to free itself. Because of that a lot of Deuterium was consumed and the expedition fleet had to come back without any results.',
            '3' => 'For unknown reasons the expeditions jump went totally wrong. It nearly landed in the heart of a sun. Fortunately it landed in a known system, but the jump back is going to take longer than thought.',
            '4' => 'A failure in the flagships reactor core nearly destroys the entire expedition fleet. Fortunately the technicians were more than competent and could avoid the worst. The repairs took quite some time and forced the expedition to return without having accomplished its goal.',
            '5' => 'A living being made out of pure energy came aboard and induced all the expedition members into some strange trance, causing them to only gazed at the hypnotizing patterns on the computer screens. When most of them finally snapped out of the hypnotic-like state, the expedition mission needed to be aborted as they had way too little Deuterium.',
            '6' => 'The new navigation module is still buggy. The expeditions jump not only lead them in the wrong direction, but it used all the Deuterium fuel. Fortunately the fleets jump got them close to the departure planets moon. A bit disappointed the expedition now returns without impulse power. The return trip will take longer than expected.',
            '7' => 'Your expedition has learnt about the extensive emptiness of space. There was not even one small asteroid or radiation or particle that could have made this expedition interesting.',
            '8' => 'Well, now we know that those red, class 5 anomalies do not only have chaotic effects on the ships navigation systems but also generate massive hallucination on the crew. The expedition didn`t bring anything back.',
            '9' => 'Your expedition took gorgeous pictures of a super nova. Nothing new could be obtained from the expedition, but at least there is good chance to win that "Best Picture Of The Universe" competition in next months issue of OGame magazine.',
            '10' => 'Your expedition fleet followed odd signals for some time. At the end they noticed that those signals where being sent from an old probe which was sent out generations ago to greet foreign species. The probe was saved and some museums of your home planet already voiced their interest.',
            '11' => 'Despite the first, very promising scans of this sector, we unfortunately returned empty handed.',
            '12' => 'Besides some quaint, small pets from a unknown marsh planet, this expedition brings nothing thrilling back from the trip.',
            '13' => 'The expedition`s flagship collided with a foreign ship when it jumped into the fleet without any warning. The foreign ship exploded and the damage to the flagship was substantial. The expedition cannot continue in these conditions, and so the fleet will begin to make its way back once the needed repairs have been carried out.',
            '14' => 'Our expedition team came across a strange colony that had been abandoned eons ago. After landing, our crew started to suffer from a high fever caused by an alien virus. It has been learned that this virus wiped out the entire civilization on the planet. Our expedition team is heading home to treat the sickened crew members. Unfortunately we had to abort the mission and we come home empty handed.',
            '15' => 'A strange computer virus attacked the navigation system shortly after parting our home system. This caused the expedition fleet to fly in circles. Needless to say that the expedition wasn`t really successful.',
        ],
    ],

    // Gain Resources
    'expedition_gain_resources' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionGainResources class.
        // Ids are append-only: message_variation_id is persisted on sent messages, so existing ids must keep
        // their text and new messages must be added at the end. The find-variant tier (normal/rare/exceptional)
        // of each id is defined in the ExpeditionGainResources class.
        'body' => [
            '1' => 'On an isolated planetoid we found some easily accessible resources fields and harvested some successfully.', // normal
            '2' => 'Your expedition discovered a small asteroid from which some resources could be harvested.', // normal
            '3' => 'Your expedition found an ancient, fully loaded but deserted freighter convoy. Some of the resources could be rescued.', // rare
            '4' => 'Your expedition fleet reports the discovery of a giant alien ship wreck. They were not able to learn from their technologies but they were able to divide the ship into its main components and made some useful resources out of it.', // normal
            '5' => 'On a tiny moon with its own atmosphere your expedition found some huge raw resources storage. The crew on the ground is trying to lift and load that natural treasure.', // rare
            '6' => 'Mineral belts around an unknown planet contained countless resources. The expedition ships are coming back and their storages are full!', // exceptional
            '7' => 'Your expedition ran into some spaceship wrecks from an old battle. Some of the components could be saved and recycled.', // normal
            '8' => 'We met a small convoy of civil ships which needed food and medicine desperately. In exchange to that we got loads of useful resources.', // rare
            '9' => 'The expedition found a radioactive planetoid with an extremely toxic atmosphere. After multiple scans, it shows that it has loads of resources. With the help of automated drones, we tried to harvest as many resources as possible.', // rare
        ],
    ],

    // Gain Dark Matter
    'expedition_gain_dark_matter' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionGainDarkMatter class.
        // Ids are append-only: message_variation_id is persisted on sent messages, so existing ids must keep
        // their text and new messages must be added at the end. The find-variant tier (normal/rare/exceptional)
        // of each id is defined in the ExpeditionGainDarkMatter class.
        'body' => [
            '1' => 'The expedition followed some odd signals to an asteroid. In the asteroids core a small amount of Dark Matter was found. The asteroid was taken and the explorers are attempting to extract the Dark Matter.', // normal
            '2' => 'The expedition was able to capture and store some Dark Matter.', // normal
            '3' => 'We met an odd alien on the shelf of a small ship who gave us a case with Dark Matter in exchange for some simple mathematical calculations.', // normal
            '4' => 'We found the remains of an alien ship. We found a little container with some Dark Matter on a shelf in the cargo hold!', // normal
            '5' => 'Our expedition made first contact with a special race. It looks as though a creature made of pure energy, who named himself Legorian, flew through the expedition ships and then decided to help our underdeveloped species. A case containing Dark Matter materialized at the bridge of the ship!', // normal
            '6' => 'Our expedition took over a ghost ship which was transporting a small amount of Dark Matter. We didn`t find any hints of what happened to the original crew of the ship, but our technicians where able to rescue the Dark Matter.', // normal
            '7' => 'Our expedition accomplished a unique experiment. They were able to harvest Dark Matter from a dying star.', // rare
            '8' => 'Our expedition located a rusty space station, which seemed to have been floating uncontrolled through outer space for a long time. The station itself was totally useless, however, it was discovered that some Dark Matter is stored in the reactor. Our technicians are trying to save as much as they can.', // normal
            '9' => 'Our expedition reports a spectacular phenomenon. The accumulation of Dark Matter in the energy storages of the ship shields. Our technicians try to store as much Dark Matter as they can while the phenomenon lasts.', // rare
            '10' => 'A spontaneous hyper space deformation allowed your expedition to harvest large amount of Dark Matter!', // exceptional
        ],
    ],

    // Gain Ships
    'expedition_gain_ships' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionGainShips class.
        // Ids are append-only: message_variation_id is persisted on sent messages, so existing ids must keep
        // their text and new messages must be added at the end. The find-variant tier (normal/rare/exceptional)
        // of each id is defined in the ExpeditionGainShips class.
        'body' => [
            '1' => 'Our expedition found a planet which was almost destroyed during a certain chain of wars. There are different ships floating around in the orbit. The technicians are trying to repair some of them. Maybe we will also get information about what happened here.', // normal
            '2' => 'We found a deserted pirate station. There are some old ships lying in the hangar. Our technicians are figuring out whether some of them are still useful or not.', // normal
            '3' => 'Your expedition ran into the shipyards of a colony that was deserted eons ago. In the shipyards hangar they discover some ships that could be salvaged. The technicians are trying to get some of them to fly again.', // normal
            '4' => 'We came across the remains of a previous expedition! Our technicians will try to get some of the ships to work again.', // normal
            '5' => 'Our expedition ran into an old automatic shipyard. Some of the ships are still in the production phase and our technicians are currently trying to reactivate the yards energy generators.', // normal
            '6' => 'We found the remains of an armada. The technicians directly went to the almost intact ships to try to get them to work again.', // rare
            '7' => 'We found the planet of an extinct civilization. We are able to see a giant intact space station, orbiting. Some of your technicians and pilots went to the surface looking for some ships which could still be used.', // rare
            '8' => 'We found an enormous spaceship graveyard. Some of the technicians from the expedition fleet were able to get some of the ships to work again.', // exceptional
        ],
    ],

    // Gain Item
    'expedition_gain_item' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionGainItem class.
        'body' => [
            '1' => 'A fleeing fleet left an item behind, in order to distract us in aid of their escape.',
        ],
    ],

    // Failed and Speedup
    'expedition_failed_and_speedup' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionSpeedup class.
        'body' => [
            '1' => 'Your expeditions doesn`t report any anomalies in the explored sector. But the fleet ran into some solar wind while returning. This resulted in the return trip being expedited. Your expedition returns home a bit earlier.',
            '2' => 'The new and daring commander successfully traveled through an unstable wormhole to shorten the flight back! However, the expedition itself didn`t bring anything new.',
            '3' => 'An unexpected back coupling in the energy spools of the engines hastened the expeditions return, it returns home earlier than expected. First reports tell they do not have anything thrilling to account for.',
        ],
    ],

    // Failure and Delay
    'expedition_failed_and_delay' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionDelay class.
        'body' => [
            '1' => 'Your expedition went into a sector full of particle storms. This set the energy stores to overload and most of the ships` main systems crashed. Your mechanics were able to avoid the worst, but the expedition is going to return with a big delay.',
            '2' => 'Your navigator made a grave error in his computations that caused the expeditions jump to be miscalculated. Not only did the fleet miss the target completely, but the return trip will take a lot more time than originally planned.',
            '3' => 'The solar wind of a red giant ruined the expeditions jump and it will take quite some time to calculate the return jump. There was nothing besides the emptiness of space between the stars in that sector. The fleet will return later than expected.',
        ],
    ],

    // Battle
    'expedition_battle' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionBattle class.
        'body' => [
            '1' => 'Some primitive barbarians are attacking us with spaceships that can`t even be named as such. If the fire gets serious we will be forced to fire back.',
            '2' => 'We needed to fight some pirates which were, fortunately, only a few.',
            '3' => 'We caught some radio transmissions from some drunk pirates. Seems like we will be under attack soon.',
            '4' => 'Our expedition was attacked by a small group of unknown ships!',
            '5' => 'Some really desperate space pirates tried to capture our expedition fleet.',
            '6' => 'Some exotic looking ships attacked the expedition fleet without warning!',
            '7' => 'Your expedition fleet had an unfriendly first contact with an unknown species.',
        ],
    ],

    // Battle - Pirates
    'expedition_battle_pirates' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        'body' => [
            '1' => 'Some primitive barbarians are attacking us with spaceships that can`t even be named as such. If the fire gets serious we will be forced to fire back.',
            '2' => 'We needed to fight some pirates which were, fortunately, only a few.',
            '3' => 'We caught some radio transmissions from some drunk pirates. Seems like we will be under attack soon.',
            '4' => 'Our expedition was attacked by a small group of space pirates!',
            '5' => 'Some really desperate space pirates tried to capture our expedition fleet.',
            '6' => 'Pirates ambushed the expedition fleet without warning!',
            '7' => 'A ragtag fleet of space pirates intercepted us, demanding tribute.',
        ],
    ],

    // Battle - Aliens
    'expedition_battle_aliens' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        'body' => [
            '1' => 'We picked up strange signals from unknown ships. They turned out to be hostile!',
            '2' => 'An alien patrol detected our expedition fleet and attacked immediately!',
            '3' => 'Your expedition fleet had an unfriendly first contact with an unknown species.',
            '4' => 'Some exotic looking ships attacked the expedition fleet without warning!',
            '5' => 'A fleet of alien warships emerged from hyperspace and engaged us!',
            '6' => 'We encountered a technologically advanced alien species that was not peaceful.',
            '7' => 'Our sensors detected unknown energy signatures before alien ships attacked!',
        ],
    ],

    // Loss of Fleet
    'expedition_loss_of_fleet' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionLossOfFleet class.
        'body' => [
            '1' => 'A core meltdown of the lead ship leads to a chain reaction, which destroys the entire expedition fleet in a spectacular explosion.',
        ],
    ],

    // Merchant Found
    'expedition_merchant_found' => [
        'from' => 'Fleet Command',
        'subject' => 'Expedition Result',
        // An expedition message can have different variations which are parsed by the ExpeditionMerchantFound class.
        'body' => [
            '1' => 'Your expedition fleet made contact with a friendly alien race. They announced that they would send a representative with goods to trade to your worlds.',
            '2' => 'A mysterious merchant vessel approached your expedition. The trader offered to visit your planets and provide special trading services.',
            '3' => 'The expedition encountered an intergalactic merchant convoy. One of the merchants has agreed to visit your homeworld to offer trading opportunities.',
        ],
    ],

    // ------------------------
    // Buddy Request Received
    'buddy_request_received' => [
        'from' => 'Buddies',
        'subject' => 'Buddy request',
        'body' => 'You have received a new buddy request from :sender_name.<span style="display:none;">:buddy_request_id</span>',
    ],

    // ------------------------
    // Buddy Request Accepted
    'buddy_request_accepted' => [
        'from' => 'Buddies',
        'subject' => 'Buddy request accepted',
        'body' => 'Player :accepter_name added you to his buddy list.',
    ],

    // ------------------------
    // Buddy Removed
    'buddy_removed' => [
        'from' => 'Buddies',
        'subject' => 'You were deleted from a buddy list',
        'body' => 'Player :remover_name removed you from their buddy list.',
    ],

    // ------------------------
    // Missile Attack Report (Attacker)
    'missile_attack_report' => [
        'from' => 'Fleet Command',
        'subject' => 'Missile attack on :target_coords',
        'body' => 'Your interplanetary missiles from :origin_planet_name :origin_planet_coords (ID: :origin_planet_id) have reached their target at :target_planet_name :target_coords (ID: :target_planet_id, Type: :target_type).

Missiles launched: :missiles_sent
Missiles intercepted: :missiles_intercepted
Missiles hit: :missiles_hit

Defenses destroyed: :defenses_destroyed',
    ],

    // ------------------------
    // Missile Defense Report (Defender)
    'missile_defense_report' => [
        'from' => 'Defense Command',
        'subject' => 'Missile attack on :planet_coords',
        'body' => 'Your planet :planet_name at :planet_coords (ID: :planet_id) has been attacked by interplanetary missiles from :attacker_name!

Incoming missiles: :missiles_incoming
Missiles intercepted: :missiles_intercepted
Missiles hit: :missiles_hit

Defenses destroyed: :defenses_destroyed',
    ],

    // ------------------------
    // Alliance Broadcast
    'alliance_broadcast' => [
        'from' => ':sender_name',
        'subject' => '[:alliance_tag] Alliance broadcast from :sender_name',
        'body' => ':message',
    ],

    // ------------------------
    // Alliance Application Received
    'alliance_application_received' => [
        'from' => 'Alliance Management',
        'subject' => 'New alliance application',
        'body' => 'Player :applicant_name has applied to join your alliance.

Application message:
:application_message',
    ],

    // Planet relocation messages
    'planet_relocation_success' => [
        'from' => 'Manage colonies',
        'subject' => ':planet_name`s relocation has been successful',
        'body' => 'The planet :planet_name has been successfully relocated from the coordinates [coordinates]:old_coordinates[/coordinates] to [coordinates]:new_coordinates[/coordinates].',
    ],

    // Fleet union invite
    'fleet_union_invite' => [
        'from' => 'Fleet Command',
        'subject' => 'Invitation to alliance combat',
        'body' => ':sender_name invited you to mission :union_name against :target_player on [:target_coords], the fleet has been timed for :arrival_time.

CAUTION: Time of arrival can change due to joining fleets. Each new fleet may extend this time by a maximum of 30 %, otherwise it won`t be allowed to join.

NOTE: The total strength of all participants compared to the total strength of defenders determines whether it will be an honourable battle or not.',
    ],

    // Building upgrade messages
    'Shipyard is being upgraded.' => 'Shipyard is being upgraded.',
    'Nanite Factory is being upgraded.' => 'Nanite Factory is being upgraded.',

    // ------------------------
    // Moon destruction messages (attacker)
    // TODO: these moon destruction messages are not correct and should be updated with
    // real official messages from the original game. These are just placeholders for now.
    'moon_destruction_success' => [
        'from' => 'Fleet Command',
        'subject' => 'Moon :moon_name [:moon_coords] has been destroyed!',
        'body' => 'With a destruction probability of :destruction_chance and a Deathstar loss probability of :loss_chance, your fleet has successfully destroyed the moon :moon_name at :moon_coords.',
    ],

    // ------------------------
    'moon_destruction_failure' => [
        'from' => 'Fleet Command',
        'subject' => 'Moon destruction at :moon_coords failed',
        'body' => 'With a destruction probability of :destruction_chance and a Deathstar loss probability of :loss_chance, your fleet failed to destroy the moon :moon_name at :moon_coords. The fleet is returning.',
    ],

    // ------------------------
    'moon_destruction_catastrophic' => [
        'from' => 'Fleet Command',
        'subject' => 'Catastrophic loss during moon destruction at :moon_coords',
        'body' => 'With a destruction probability of :destruction_chance and a Deathstar loss probability of :loss_chance, your fleet failed to destroy the moon :moon_name at :moon_coords. In addition, all Deathstars were lost in the attempt. There is no wreckage.',
    ],

    // ------------------------
    'moon_destruction_mission_failed' => [
        'from' => 'Fleet Command',
        'subject' => 'Moon destruction mission failed at :coordinates',
        'body' => 'Your fleet arrived at :coordinates but no moon was found at the target location. The fleet is returning.',
    ],

    // ------------------------
    // Moon destruction messages (defender)
    'moon_destruction_repelled' => [
        'from' => 'Space Monitoring',
        'subject' => 'Destruction attempt on moon :moon_name [:moon_coords] repelled',
        'body' => ':attacker_name attacked your moon :moon_name at :moon_coords with a destruction probability of :destruction_chance and a Deathstar loss probability of :loss_chance. Your moon has survived the attack!',
    ],

    // ------------------------
    'moon_destroyed' => [
        'from' => 'Space Monitoring',
        'subject' => 'Moon :moon_name [:moon_coords] has been destroyed!',
        'body' => 'Your moon :moon_name at :moon_coords has been destroyed by a Deathstar fleet belonging to :attacker_name!',
    ],

    // ------------------------
    // Wreck field repair completed
    'wreck_field_repair_completed' => [
        'from' => 'System Message',
        'subject' => 'Repair completed',
        'body' => 'Your repair request on planet :planet has been completed.
:ship_count ships have been put back into service.',
    ],

    /*
     * Hostile faction raid narratives, shown at the bottom of the battle report.
     *
     * Seven motives, five variations each: the same player never reads the same text twice
     * for the same reason. The motive is chosen from what the player actually did.
     */
    "npc_raid" => [
        "origin" => "Signal origin: :crew.",
        "first_contact" => [
            "1" => "Your name had been drifting across frequencies nobody monitors. It is no longer drifting: it has been written down.",
            "2" => "They were not looking for your world in particular. They were looking for a world worth the trouble, and yours became one.",
            "3" => "A first visit, made without haste and without anger. One measures what there is to take before deciding whether to come back.",
            "4" => "A world that prospers is eventually noticed. Yours just was.",
            "5" => "They came to look. What they saw struck them as interesting.",
        ],
        "reconnaissance" => [
            "1" => "You sent probes. They decided curiosity should be returned, and returned with ships.",
            "2" => "One does not look in on these people for free. They looked back, rather more firmly.",
            "3" => "Your probes had not gone unnoticed. The answer took a few days; it did not take any care.",
            "4" => "They knew you were watching them. They wanted you to know that they knew.",
            "5" => "A visit in return, to settle the accounts of curiosity.",
        ],
        "reprisal" => [
            "1" => "A crew lost ships above your world and has no intention of leaving it at that.",
            "2" => "What you burned cost them. They came to take it back elsewhere, from you.",
            "3" => "They count their losses carefully. Your name stood against the latest total.",
            "4" => "One does not destroy a fleet without someone, somewhere, deciding to replace it at your expense.",
            "5" => "The anger is cold and methodical, and it took exactly as long as it needed to arrive.",
        ],
        "vendetta" => [
            "1" => "You levelled one of their bases. This is no longer about plunder: it is a matter of principle.",
            "2" => "A base fell by your hand. They noted the date, the coordinates, and your name.",
            "3" => "Nothing was left of their world when you departed. They intend the reverse to be conceivable.",
            "4" => "What began as raiding became a war the day you destroyed their base.",
            "5" => "They no longer come for your resources. They come for you.",
        ],
        "neighbourhood" => [
            "1" => "You have grown too large, and you have grown too close to them.",
            "2" => "A powerful neighbour is a problem one settles before it becomes unsolvable.",
            "3" => "They see you every day. That is precisely the problem.",
            "4" => "It is not what you did. It is where you live, and what you have become there.",
            "5" => "Distance protects against a great many things. You did not have that luxury.",
        ],
        "plunder" => [
            "1" => "No anger, no message: your storehouses were full and poorly guarded, and that was enough.",
            "2" => "An operation without passion. Take what can be taken, leave before it costs anything.",
            "3" => "They needed metal. You had some. The rest required no thought at all.",
            "4" => "The arithmetic was simple, and it did not favour you.",
            "5" => "Nothing personal in this visit. That may be the most galling part.",
        ],
        "scavenger" => [
            "1" => "You harvested the debris of their dead. They found the practice distasteful.",
            "2" => "Profiting from a battle one did not fight is paid for, sooner or later.",
            "3" => "What you scooped up above their battlefield was not yours.",
            "4" => "They tolerate being fought. They tolerate having their wrecks picked over rather less.",
            "5" => "The contempt was audible in the very way the fleet arrived.",
        ],
    ],
];
