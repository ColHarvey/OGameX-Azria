<?php

return [
    'admin_announcement' => [
        'from' => 'Administration',
        'subject' => ':subject',
        'body' => ':body',
    ],
    'event_started' => [
        'from' => 'Administration',
        'subject' => 'Un événement de missions commence',
        'body' => "Un evenement de missions quotidiennes est ouvert du :start au :end inclus.\n\nChaque jour, un nouveau tirage de missions vous attend. Les accomplir rapporte du tritium, qui debloque cinq rangs de recompenses : ressources, matiere noire ou objets.\n\nRendez-vous sur la page Evenement pour consulter vos missions du jour.",
    ],
    'welcome_message' => [
        'from' => 'OGameX Francophone',
        'subject' => 'Bienvenue sur OGameX Francophone !',
        'body' => 'Salutations Empereur :player !

Bienvenue sur OGameX Francophone. Felicitations pour le debut de votre illustre carriere, je serai la pour vous guider dans vos premiers pas.

Sur la gauche, le menu vous permet de superviser et de gouverner votre empire galactique. Les ressources et les installations vous permettent de construire des batiments pour etendre votre territoire.

Commencez par batir une centrale solaire afin d\'alimenter vos mines en energie. Developpez ensuite votre mine de metal et votre mine de cristal pour produire les ressources vitales a votre expansion. Puis explorez par vous-meme : vous vous sentirez bientot chez vous, j\'en suis sur.

Pour changer votre pseudo, cliquez sur votre nom en haut a gauche, saisissez le pseudo souhaite et confirmez avec votre mot de passe.

Une question, un souci, une suggestion ? Ecrivez a admin@azriagaming.ca

L\'univers est vaste et les ressources ne s\'extraient pas toutes seules. Bonne chance, Empereur.

Ce message sera supprime dans 7 jours.',
    ],
    'return_of_fleet_with_resources' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Retour d\'une flotte',
        'body' => 'Votre flotte revient de :from vers :to et a livré sa marchandise :

Métal : :metal
Cristal : :crystal
Deutérium : :deuterium',
    ],
    'return_of_fleet' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Retour d\'une flotte',
        'body' => 'Votre flotte revient de :from vers :to.

La flotte ne livre pas de marchandises.',
    ],
    'fleet_deployment_with_resources' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Retour d\'une flotte',
        'body' => 'Une de vos flottes de :from a atteint :to et a livré ses marchandises :

Métal : :metal
Cristal : :crystal
Deutérium : :deuterium',
    ],
    'fleet_deployment' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Retour d\'une flotte',
        'body' => 'Une de vos flottes de :from a atteint :to. La flotte ne livre pas de marchandises.',
    ],
    'transport_arrived' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Atteindre une planète',
        'body' => 'Votre flotte de :from atteint :to et livre ses marchandises :
Métal : :metal Cristal : :crystal Deutérium : :deuterium',
    ],
    'transport_received' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Flotte entrante',
        'body' => 'Une flotte arrivant de :from a atteint votre planète :to et a livré ses marchandises :
Métal : :metal Cristal : :crystal Deutérium : :deuterium',
    ],
    'acs_defend_arrival_host' => [
        'from' => 'Surveillance de l\'espace',
        'subject' => 'La flotte s\'arrête',
        'body' => 'Une flotte est arrivée à :to.',
    ],
    'acs_defend_arrival_sender' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'La flotte s\'arrête',
        'body' => 'Une flotte est arrivée à :to.',
    ],
    'colony_established' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Rapport de colonisation',
        'body' => 'La flotte est arrivée aux coordonnées assignées :coordinates, y a trouvé une nouvelle planète et commence immédiatement à s\'y développer.',
    ],
    'colony_establish_fail_astrophysics' => [
        'from' => 'Les colons',
        'subject' => 'Rapport de colonisation',
        'body' => 'La flotte est arrivée aux coordonnées assignées :coordinates et vérifie que la planète est viable pour la colonisation. Peu de temps après avoir commencé à développer la planète, les colons se rendent compte que leurs connaissances en astrophysique ne sont pas suffisantes pour achever la colonisation d\'une nouvelle planète.',
    ],
    'espionage_report' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Rapport d\'espionnage de :planet',
    ],
    'espionage_detected' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Rapport d\'espionnage de la planète :planet',
        'body' => 'Une flotte étrangère de la planète :planet (:attacker_name) a été aperçue près de votre planète
:defender
Chance de contre-espionnage : :chance%',
    ],
    'battle_report' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Rapport de combat :planet',
    ],
    'fleet_lost_contact' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Le contact avec la flotte attaquante a été perdu. :coordinates',
        'body' => '(Cela signifie qu\'elle a été détruite au premier tour.)',
    ],
    'debris_field_harvest' => [
        'from' => 'Flotte',
        'subject' => 'Rapport de récolte du champ de débris en :coordinates',
        'body' => 'Vos :ship_name (:ship_amount vaisseaux) ont une capacité de stockage totale de :storage_capacity. Sur la cible :to, :metal Métal, :crystal Cristal et :deuterium Deutérium flottent dans l\'espace. Vous avez récolté :harvested_metal Métal, :harvested_crystal Cristal et :harvested_deuterium Deutérium.',
    ],
    'expedition_resources_captured' => ':resource_type :resource_amount ont été capturés.',
    'expedition_dark_matter_captured' => '(:dark_matter_amount Matière noire)',
    'expedition_units_captured' => 'Les vaisseaux suivants font désormais partie de la flotte :',
    'expedition_unexplored_statement' => 'Inscription du journal de bord des agents de communication : Il semble que cette partie de l\'univers n\'ait pas encore été explorée.',
    'expedition_failed' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'En raison d\'une panne des ordinateurs centraux du vaisseau amiral, la mission d\'expédition a dû être interrompue. Malheureusement, à la suite d\'un dysfonctionnement informatique, la flotte rentre chez elle les mains vides.',
            '2' => 'Votre expédition a failli heurter un champ gravitationnel d’étoiles à neutrons et a eu besoin d’un certain temps pour se libérer. À cause de cela, une grande quantité de deutérium a été consommée et la flotte d\'expédition a dû revenir sans aucun résultat.',
            '3' => 'Pour des raisons inconnues, le saut des expéditions s\'est totalement mal passé. Il a failli atterrir au cœur d\'un soleil. Heureusement, il a atterri dans un système connu, mais le retour en arrière va prendre plus de temps que prévu.',
            '4' => 'Une panne dans le cœur du réacteur phare détruit presque toute la flotte d\'expédition. Heureusement les techniciens étaient plus que compétents et ont pu éviter le pire. Les réparations durent un certain temps et obligent l\'expédition à revenir sans avoir atteint son objectif.',
            '5' => 'Un être vivant fait d\'énergie pure est monté à bord et a induit tous les membres de l\'expédition dans une étrange transe, les obligeant à ne regarder que les motifs hypnotisants sur les écrans d\'ordinateur. Lorsque la plupart d\'entre eux sont finalement sortis de leur état hypnotique, la mission d\'expédition a dû être interrompue car ils avaient beaucoup trop peu de Deutérium.',
            '6' => 'Le nouveau module de navigation est toujours buggé. Le saut de l\'expédition les a non seulement conduits dans la mauvaise direction, mais a également utilisé tout le combustible de deutérium. Heureusement, le saut des flottes les a rapprochés de la lune de départ de la planète. Un peu déçu, l\'expédition revient désormais sans puissance d\'impulsion. Le voyage de retour prendra plus de temps que prévu.',
            '7' => 'Votre expédition a découvert le vaste vide de l\'espace. Il n’y avait même pas un seul petit astéroïde, aucun rayonnement ou particule qui aurait pu rendre cette expédition intéressante.',
            '8' => 'Eh bien, nous savons maintenant que ces anomalies rouges de classe 5 ont non seulement des effets chaotiques sur les systèmes de navigation du vaisseau, mais génèrent également des hallucinations massives chez l\'équipage. L\'expédition n\'a rien rapporté.',
            '9' => 'Votre expédition a pris de superbes photos d\'une super nova. Rien de nouveau n\'a pu être obtenu de l\'expédition, mais au moins il y a de bonnes chances de remporter le concours "Meilleure image de l\'univers" dans le numéro du mois prochain du magazine OGame.',
            '10' => 'Votre flotte d\'expédition a suivi des signaux étranges pendant un certain temps. À la fin, ils ont remarqué que ces signaux étaient envoyés par une vieille sonde envoyée il y a des générations pour saluer les espèces étrangères. La sonde a été sauvée et certains musées de votre planète ont déjà manifesté leur intérêt.',
            '11' => 'Malgré des premiers scans très prometteurs de ce secteur, nous sommes malheureusement revenus bredouille.',
            '12' => 'Hormis quelques petits animaux de compagnie pittoresques venus d’une planète marécageuse inconnue, cette expédition ne rapporte rien d’excitant du voyage.',
            '13' => 'Le vaisseau amiral de l\'expédition est entré en collision avec un vaisseau étranger lorsqu\'il a rejoint la flotte sans aucun avertissement. Le vaisseau étranger a explosé et les dégâts causés au vaisseau amiral ont été importants. L\'expédition ne pouvant continuer dans ces conditions, la flotte commencera donc à repartir une fois les réparations nécessaires effectuées.',
            '14' => 'Notre équipe d’expédition est tombée sur une étrange colonie abandonnée depuis des lustres. Après l\'atterrissage, notre équipage a commencé à souffrir d\'une forte fièvre causée par un virus extraterrestre. On a appris que ce virus avait anéanti toute la civilisation de la planète. Notre équipe d\'expédition rentre chez elle pour soigner les membres de l\'équipage malades. Malheureusement, nous avons dû abandonner la mission et nous rentrons les mains vides.',
            '15' => 'Un étrange virus informatique a attaqué le système de navigation peu de temps après avoir détruit notre système domestique. Cela a fait voler la flotte de l’expédition en rond. Inutile de dire que l’expédition n’a pas vraiment été un succès.',
        ],
    ],
    'expedition_gain_resources' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Sur un planétoïde isolé, nous avons trouvé des champs de ressources facilement accessibles et en avons récolté avec succès.',
            '2' => 'Votre expédition a découvert un petit astéroïde à partir duquel certaines ressources pourraient être récoltées.',
            '3' => 'Votre expédition a découvert un ancien convoi de cargos entièrement chargé mais désert. Certaines ressources pourraient être sauvées.',
            '4' => 'Votre flotte d\'expédition rapporte la découverte d\'une épave de vaisseau extraterrestre géant. Ils n\'ont pas pu apprendre de leurs technologies, mais ils ont pu diviser le vaisseau en ses principaux composants et en tirer des ressources utiles.',
            '5' => 'Sur une petite lune dotée de sa propre atmosphère, votre expédition a découvert un énorme réservoir de ressources brutes. L’équipe au sol tente de soulever et de charger ce trésor naturel.',
            '6' => 'Les ceintures minérales autour d’une planète inconnue contenaient d’innombrables ressources. Les vaisseaux d\'expédition reviennent et leurs réserves sont pleines !',
            '7' => 'Notre expédition est tombée sur des épaves de vaisseaux, vestiges d\'une ancienne bataille. Une partie des composants a pu être récupérée et recyclée.',
            '8' => 'Nous avons croisé un petit convoi de vaisseaux civils qui manquaient cruellement de vivres et de médicaments. En échange, nous avons reçu une belle quantité de ressources utiles.',
            '9' => 'L\'expédition a découvert un planétoïde radioactif à l\'atmosphère extrêmement toxique. Après plusieurs balayages, il s\'avère qu\'il regorge de ressources. À l\'aide de drones automatisés, nous avons tenté d\'en extraire le plus possible.',
        ],
    ],
    'expedition_gain_dark_matter' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'L\'expédition a suivi des signaux étranges vers un astéroïde. Dans le noyau de l\'astéroïde, une petite quantité de matière noire a été trouvée. L\'astéroïde a été pris et les explorateurs tentent d\'en extraire la matière noire.',
            '2' => 'L\'expédition a pu capturer et stocker de la matière noire.',
            '3' => 'Nous avons rencontré un étrange extraterrestre sur le plateau d\'un petit vaisseau qui nous a offert une valise contenant de la matière noire en échange de quelques calculs mathématiques simples.',
            '4' => 'Nous avons trouvé les restes d\'un vaisseau extraterrestre. Nous avons trouvé un petit conteneur contenant de la matière noire sur une étagère dans la soute !',
            '5' => 'Notre expédition a établi un premier contact avec une race spéciale. On dirait qu\'une créature faite d\'énergie pure, qui s\'est nommée Legorian, a survolé les vaisseaux d\'expédition et a ensuite décidé d\'aider notre espèce sous-développée. Une caisse contenant de la Matière Noire matérialisée sur la passerelle du vaisseau !',
            '6' => 'Notre expédition a pris possession d\'un vaisseau fantôme qui transportait une petite quantité de matière noire. Nous n\'avons trouvé aucune indication sur ce qui est arrivé à l\'équipage d\'origine du vaisseau, mais nos techniciens ont réussi à sauver la Matière Noire.',
            '7' => 'Notre expédition a accompli une expérience unique. Ils ont pu récolter la matière noire d\'une étoile mourante.',
            '8' => 'Notre expédition a localisé une station spatiale rouillée, qui semblait flotter de manière incontrôlée dans l\'espace depuis longtemps. La station elle-même était totalement inutile, cependant, on a découvert qu\'un peu de matière noire était stockée dans le réacteur. Nos techniciens essaient d\'économiser autant qu\'ils le peuvent.',
            '9' => 'Notre expédition signale un phénomène spectaculaire : de la matière noire s\'accumule dans les réserves d\'énergie des boucliers. Nos techniciens tentent d\'en stocker le plus possible tant que le phénomène dure.',
            '10' => 'Une déformation spontanée de l\'hyperespace a permis à votre expédition de récolter une grande quantité de matière noire !',
        ],
    ],
    'expedition_gain_ships' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Notre expédition a découvert une planète qui a été presque détruite au cours d\'une certaine chaîne de guerres. Différents vaisseaux flottent en orbite. Les techniciens tentent d\'en réparer certains. Peut-être obtiendrons-nous également des informations sur ce qui s’est passé ici.',
            '2' => 'Nous avons trouvé une station de pirates déserte. Il y a quelques vieux bateaux qui traînent dans le hangar. Nos techniciens sont en train de déterminer si certains d\'entre eux sont encore utiles ou non.',
            '3' => 'Votre expédition s\'est heurtée aux chantiers spatiaux d\'une colonie désertée depuis des lustres. Dans le hangar du chantier spatial, ils découvrent des vaisseaux qui pourraient être récupérés. Les techniciens tentent de faire voler à nouveau certains d\'entre eux.',
            '4' => 'Nous sommes tombés sur les restes d\'une précédente expédition ! Nos techniciens vont essayer de remettre certains vaisseaux en état de marche.',
            '5' => 'Notre expédition s\'est heurtée à un ancien chantier spatial automatique. Certains vaisseaux sont encore en phase de production et nos techniciens tentent actuellement de réactiver les générateurs d\'énergie du chantier.',
            '6' => 'Nous avons trouvé les restes d\'une armada. Les techniciens se sont directement rendus sur les vaisseaux presque intacts pour tenter de les remettre en marche.',
            '7' => 'Nous avons trouvé la planète d\'une civilisation disparue. Nous pouvons voir une station spatiale géante intacte, en orbite. Certains de vos techniciens et pilotes sont allés à la surface à la recherche de vaisseaux encore utilisables.',
                    '8' => 'Nous avons découvert un immense cimetière de vaisseaux. Quelques techniciens de la flotte d\'expédition sont parvenus à en remettre certains en état de marche.',
        ],
    ],
    'expedition_gain_item' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Une flotte en fuite a laissé un objet derrière elle, afin de nous distraire et de faciliter sa fuite.',
        ],
    ],
    'expedition_failed_and_speedup' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Vos expéditions ne rapportent aucune anomalie dans le secteur exploré. Mais la flotte s\'est heurtée au vent solaire en revenant. Cela a permis d’accélérer le voyage de retour. Votre expédition rentre chez elle un peu plus tôt.',
            '2' => 'Le nouveau et audacieux commandant a réussi à traverser un trou de ver instable pour raccourcir le vol de retour ! Cependant, l’expédition elle-même n’a rien apporté de nouveau.',
            '3' => 'Un couplage inattendu dans les bobines d\'énergie des moteurs a accéléré le retour de l\'expédition, elle rentre chez elle plus tôt que prévu. Les premiers rapports indiquent qu’il n’y a rien de passionnant à expliquer.',
        ],
    ],
    'expedition_failed_and_delay' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Votre expédition s\'est rendue dans un secteur rempli de tempêtes de particules. Cela a provoqué une surcharge des réserves d’énergie et la plupart des systèmes principaux des vaisseaux se sont écrasés. Vos mécaniciens ont pu éviter le pire, mais l\'expédition va revenir avec beaucoup de retard.',
            '2' => 'Votre navigateur a commis une grave erreur dans ses calculs qui a entraîné un mauvais calcul du saut des expéditions. Non seulement la flotte a complètement raté son objectif, mais le retour prendra beaucoup plus de temps que prévu initialement.',
            '3' => 'Le vent solaire d\'une géante rouge a ruiné le saut de l\'expédition et il faudra pas mal de temps pour calculer le saut retour. Il n’y avait rien d’autre que le vide de l’espace entre les étoiles dans ce secteur. La flotte reviendra plus tard que prévu.',
        ],
    ],
    'expedition_battle' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Certains barbares primitifs nous attaquent avec des vaisseaux spatiaux qui ne peuvent même pas être nommés ainsi. Si l’incendie s’aggrave, nous serons obligés de riposter.',
            '2' => 'Nous avons dû combattre quelques pirates qui, heureusement, n\'étaient que quelques-uns.',
            '3' => 'Nous avons capté des transmissions radio de pirates ivres. On dirait que nous serons bientôt attaqués.',
            '4' => 'Notre expédition a été attaquée par un petit groupe de vaisseaux inconnus !',
            '5' => 'Des pirates de l\'espace vraiment désespérés ont tenté de capturer notre flotte d\'expédition.',
            '6' => 'Des vaisseaux d\'apparence exotique ont attaqué la flotte d\'expédition sans avertissement !',
            '7' => 'Votre flotte d\'expédition a eu un premier contact hostile avec une espèce inconnue.',
        ],
    ],
    'expedition_battle_pirates' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Certains barbares primitifs nous attaquent avec des vaisseaux spatiaux qui ne peuvent même pas être nommés ainsi. Si l’incendie s’aggrave, nous serons obligés de riposter.',
            '2' => 'Nous avons dû combattre quelques pirates qui, heureusement, n\'étaient que quelques-uns.',
            '3' => 'Nous avons capté des transmissions radio de pirates ivres. On dirait que nous serons bientôt attaqués.',
            '4' => 'Notre expédition a été attaquée par un petit groupe de pirates de l\'espace !',
            '5' => 'Des pirates de l\'espace vraiment désespérés ont tenté de capturer notre flotte d\'expédition.',
            '6' => 'Les pirates ont tendu une embuscade à la flotte d\'expédition sans avertissement !',
            '7' => 'Une flotte hétéroclite de pirates de l\'espace nous a interceptés et nous a demandé hommage.',
        ],
    ],
    'expedition_battle_aliens' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Nous avons capté d\'étranges signaux provenant de vaisseaux inconnus. Ils se sont révélés hostiles !',
            '2' => 'Une patrouille extraterrestre a détecté notre flotte d\'expédition et a immédiatement attaqué !',
            '3' => 'Votre flotte d\'expédition a eu un premier contact hostile avec une espèce inconnue.',
            '4' => 'Des vaisseaux d\'apparence exotique ont attaqué la flotte d\'expédition sans avertissement !',
            '5' => 'Une flotte de vaisseaux de guerre extraterrestres a émergé de l\'hyperespace et nous a attaqué !',
            '6' => 'Nous avons rencontré une espèce extraterrestre technologiquement avancée qui n’était pas pacifique.',
            '7' => 'Nos capteurs ont détecté des signatures énergétiques inconnues avant l\'attaque des vaisseaux extraterrestres !',
        ],
    ],
    'expedition_loss_of_fleet' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'La fusion du noyau du vaisseau de tête entraîne une réaction en chaîne qui détruit toute la flotte d\'expédition dans une explosion spectaculaire.',
        ],
    ],
    'expedition_merchant_found' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Résultat de l\'expédition',
        'body' => [
            '1' => 'Votre flotte d\'expédition a pris contact avec une race extraterrestre amicale. Ils ont annoncé qu\'ils enverraient un représentant avec des marchandises à échanger sur vos mondes.',
            '2' => 'Un mystérieux vaisseau marchand s\'est approché de votre expédition. Le commerçant a proposé de visiter vos planètes et de fournir des services commerciaux spéciaux.',
            '3' => 'L\'expédition rencontra un convoi marchand intergalactique. L\'un des marchands a accepté de visiter votre monde natal pour vous proposer des opportunités commerciales.',
        ],
    ],
    'buddy_request_received' => [
        'from' => 'Amis',
        'subject' => 'Demande d\'ami',
        'body' => 'Vous avez reçu une nouvelle demande d\'ami de :sender_name.<span style="display:none;">:buddy_request_id</span>',
    ],
    'buddy_request_accepted' => [
        'from' => 'Amis',
        'subject' => 'Demande d\'ami acceptée',
        'body' => 'Le joueur :accepter_name vous a ajouté à sa liste d\'amis.',
    ],
    'buddy_removed' => [
        'from' => 'Amis',
        'subject' => 'Vous avez été supprimé d\'une liste d\'amis',
        'body' => 'Le joueur :remover_name vous a supprimé de sa liste d\'amis.',
    ],
    'missile_attack_report' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Attaque de missile sur :target_coords',
        'body' => 'Vos missiles interplanétaires de :origin_planet_name :origin_planet_coords (ID : :origin_planet_id) ont atteint leur cible à :target_planet_name :target_coords (ID : :target_planet_id, Type : :target_type).

Missiles lancés : :missiles_sent
Missiles interceptés : :missiles_intercepted
Missiles touchés : :missiles_hit

Défenses détruites : :defenses_destroyed',
    ],
    'missile_defense_report' => [
        'from' => 'Commandement de la défense',
        'subject' => 'Attaque de missile sur :planet_coords',
        'body' => 'Votre planète :planet_name à :planet_coords (ID : :planet_id) a été attaquée par des missiles interplanétaires de :attacker_name !

Missiles entrants : :missiles_incoming
Missiles interceptés : :missiles_intercepted
Missiles touchés : :missiles_hit

Défenses détruites : :defenses_destroyed',
    ],
    'alliance_broadcast' => [
        'from' => ':sender_name',
        'subject' => '[:alliance_tag] Diffusion de l\'Alliance de :sender_name',
        'body' => ':message',
    ],
    'alliance_application_received' => [
        'from' => 'Gestion des alliances',
        'subject' => 'Nouvelle demande d\'alliance',
        'body' => 'Le joueur :applicant_name a postulé pour rejoindre votre alliance.

Message de candidature :
:application_message',
    ],
    'planet_relocation_success' => [
        'from' => 'Gérer les colonies',
        'subject' => 'Le déménagement de :planet_name a été réussi',
        'body' => 'La planète :planet_name a été déplacée avec succès des coordonnées [coordonnées]:old_coordinates[/coordonnées] vers [coordonnées]:new_coordinates[/coordonnées].',
    ],
    'fleet_union_invite' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Invitation au combat d\'alliance',
        'body' => ':sender_name vous a invité à la mission :union_name contre :target_player le [:target_coords], la flotte a été chronométrée pour :arrival_time.

ATTENTION : L\'heure d\'arrivée peut changer en raison de l\'adhésion aux flottes. Chaque nouvelle flotte peut prolonger ce délai d\'un maximum de 30 %, sinon elle ne sera pas autorisée à adhérer.

REMARQUE : La force totale de tous les participants par rapport à la force totale des défenseurs détermine si ce sera une bataille honorable ou non.',
    ],
    'Shipyard is being upgraded.' => 'Le chantier spatial est en cours de modernisation.',
    'Nanite Factory is being upgraded.' => 'Nanite Factory est en cours de modernisation.',
    'moon_destruction_success' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'La Lune :moon_name [:moon_coords] a été détruite !',
        'body' => 'Avec une probabilité de destruction de :destruction_chance et une probabilité de perte de Deathstar de :loss_chance, votre flotte a réussi à détruire la lune :moon_name à :moon_coords.',
    ],
    'moon_destruction_failure' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'La destruction de la lune à :moon_coords a échoué',
        'body' => 'Avec une probabilité de destruction de :destruction_chance et une probabilité de perte de Deathstar de :loss_chance, votre flotte n\'a pas réussi à détruire la lune :moon_name à :moon_coords. La flotte revient.',
    ],
    'moon_destruction_catastrophic' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'Perte catastrophique lors de la destruction de la lune à :moon_coords',
        'body' => 'Avec une probabilité de destruction de :destruction_chance et une probabilité de perte de Deathstar de :loss_chance, votre flotte n\'a pas réussi à détruire la lune :moon_name à :moon_coords. De plus, tous les Deathstars ont été perdus dans la tentative. Il n\'y a aucune épave.',
    ],
    'moon_destruction_mission_failed' => [
        'from' => 'Commandement de la flotte',
        'subject' => 'La mission de destruction de la Lune a échoué à :coordinates',
        'body' => 'Votre flotte est arrivée à :coordinates mais aucune lune n\'a été trouvée à l\'emplacement cible. La flotte revient.',
    ],
    'moon_destruction_repelled' => [
        'from' => 'Surveillance de l\'espace',
        'subject' => 'Tentative de destruction sur la lune :moon_name [:moon_coords] repoussée',
        'body' => ':attacker_name a attaqué votre lune :moon_name à :moon_coords avec une probabilité de destruction de :destruction_chance et une probabilité de perte de Deathstar de :loss_chance. Votre lune a survécu à l\'attaque !',
    ],
    'moon_destroyed' => [
        'from' => 'Surveillance de l\'espace',
        'subject' => 'La Lune :moon_name [:moon_coords] a été détruite !',
        'body' => 'Votre lune :moon_name à :moon_coords a été détruite par une flotte Deathstar appartenant à :attacker_name !',
    ],
    'wreck_field_repair_completed' => [
        'from' => 'Message système',
        'subject' => 'Réparation terminée',
        'body' => 'Votre demande de réparation sur la planète :planet a été complétée.
:ship_count vaisseau(x) remis en service.',
    ],

    /*
     * Recits des raids de factions hostiles, affiches au bas du rapport de combat.
     *
     * Sept motifs, cinq variantes chacun : le meme joueur ne lira jamais deux fois le meme
     * texte pour la meme raison. Le motif est choisi par le systeme a partir de ce que le
     * joueur a reellement fait — le jeu sait toujours pourquoi une flotte est venue.
     *
     * REGLE INTANGIBLE : une cle deja en production ne change jamais de sens.
     */
    'npc_raid' => [
        'origin' => 'Origine du signal : :crew.',
        'first_contact' => [
            '1' => 'Votre nom circulait depuis quelques jours sur des fréquences que personne ne surveille. Il ne circule plus : il est désormais inscrit quelque part.',
            '2' => 'Ils ne cherchaient pas votre monde en particulier. Ils cherchaient un monde qui en valait la peine, et le vôtre a fini par en valoir une.',
            '3' => 'Une première visite, faite sans hâte et sans colère. On mesure ce qu\'il y a à prendre avant de décider s\'il faudra revenir.',
            '4' => 'Un monde qui prospère finit toujours par être remarqué. Le vôtre vient de l\'être.',
            '5' => 'Ils sont venus voir. Ce qu\'ils ont vu leur a paru intéressant.',
        ],
        'reconnaissance' => [
            '1' => 'Vous aviez envoyé des sondes. Ils ont considéré que la curiosité se rendait, et qu\'elle se rendait avec des vaisseaux.',
            '2' => 'On ne regarde pas impunément chez ces gens-là. Ils ont regardé à leur tour, en plus appuyé.',
            '3' => 'Vos sondes n\'étaient pas passées inaperçues. La réponse a mis quelques jours, elle n\'a pas mis de gants.',
            '4' => 'Ils savaient que vous les observiez. Ils tenaient à ce que vous sachiez qu\'ils le savaient.',
            '5' => 'Une visite en retour, pour équilibrer les comptes de la curiosité.',
        ],
        'reprisal' => [
            '1' => 'Une bande a perdu des vaisseaux au-dessus de votre monde et n\'a pas l\'intention d\'en rester là.',
            '2' => 'Ce que vous avez brûlé leur a coûté. Ils sont venus le reprendre ailleurs, chez vous.',
            '3' => 'Ils comptent leurs pertes avec soin. Votre nom figurait en face du dernier total.',
            '4' => 'On ne détruit pas une flotte sans que quelqu\'un, quelque part, décide de la remplacer à vos frais.',
            '5' => 'La colère est froide, méthodique, et elle a mis exactement le temps qu\'il fallait pour arriver.',
        ],
        'vendetta' => [
            '1' => 'Vous avez rasé une de leurs bases. Ce n\'est plus une affaire de butin : c\'est une affaire de principe.',
            '2' => 'Une base est tombée par votre main. Ils ont retenu la date, les coordonnées, et votre nom.',
            '3' => 'Il ne restait rien de leur monde quand vous êtes reparti. Ils entendent que la réciproque soit envisageable.',
            '4' => 'Ce qui a commencé comme un pillage est devenu une guerre le jour où vous avez détruit leur base.',
            '5' => 'Ils ne viennent plus pour vos ressources. Ils viennent pour vous.',
        ],
        'neighbourhood' => [
            '1' => 'Vous êtes devenu trop grand, et vous l\'êtes devenu trop près de chez eux.',
            '2' => 'Un voisin puissant est un problème qu\'on règle avant qu\'il ne devienne insoluble.',
            '3' => 'Ils vous voient tous les jours. C\'est exactement ce qui pose problème.',
            '4' => 'Ce n\'est pas ce que vous avez fait. C\'est où vous vivez, et ce que vous y êtes devenu.',
            '5' => 'La distance protège de bien des choses. Vous n\'aviez pas cette chance.',
        ],
        'plunder' => [
            '1' => 'Ni colère ni message : vos entrepôts étaient pleins et mal gardés, cela leur a suffi.',
            '2' => 'Une opération sans passion. On prend ce qui se prend, on repart avant que ça ne coûte.',
            '3' => 'Ils avaient besoin de métal. Vous en aviez. Le reste n\'a demandé aucune réflexion.',
            '4' => 'Le calcul était simple et il vous était défavorable.',
            '5' => 'Rien de personnel dans cette visite. C\'est peut-être le plus vexant.',
        ],
        'scavenger' => [
            '1' => 'Vous avez récolté les débris de leurs morts. Ils ont trouvé le procédé déplaisant.',
            '2' => 'Profiter d\'une bataille qu\'on n\'a pas livrée se paie, tôt ou tard.',
            '3' => 'Ce que vous avez ramassé au-dessus de leur champ de bataille ne vous appartenait pas.',
            '4' => 'Ils tolèrent qu\'on les combatte. Ils tolèrent moins qu\'on fouille leurs épaves.',
            '5' => 'Le mépris était perceptible jusque dans la façon dont la flotte est arrivée.',
        ],
    ],
];
