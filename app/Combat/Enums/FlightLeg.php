<?php

namespace OGame\Combat\Enums;

/**
 * L'etape d'un vol : vers la cible, ou retour chez soi.
 *
 * **Une dimension a part, et non un genre de mission.** Il serait tentant de traiter « retour »
 * comme un onzieme type, puisque tous les retours se comportent pareil face a un combat. Ce
 * raccourci effacerait ce que la flotte etait partie faire : un rapport ne pourrait plus dire
 * qu'elle revenait d'une attaque plutot que d'un transport, et toute regle future qui aurait
 * besoin de cette distinction serait impossible a ecrire sans revenir sur le modele.
 *
 * Dans le schema, un retour se reconnait a son `parent_id` renseigne.
 */
enum FlightLeg: string
{
    /**
     * La flotte va vers sa cible.
     */
    case Outbound = 'outbound';

    /**
     * La flotte rentre a son point de depart.
     */
    case Return = 'return';
}
