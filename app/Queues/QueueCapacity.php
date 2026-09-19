<?php

namespace OGame\Queues;

use Illuminate\Support\Facades\Date;
use OGame\Models\User;

/**
 * **Combien de travaux peuvent attendre dans une file, et ce que le Commandant y change.**
 *
 * Regle d Azria, arretee par Keven le 19 septembre 2026 :
 *
 * - **construction** (batiments ordinaires et batiments de formes de vie) : 1 travail en cours et **4 en attente**,
 *   **8 en attente** tant qu un Commandant est embauche ;
 * - **recherche** (ordinaire et formes de vie) : 1 en cours et **4 en attente**, sans extension ;
 * - le chantier spatial ne change pas : il n a pas de limite ;
 * - **l expiration du Commandant n annule rien** : les travaux deja en file restent, seuls les ajouts nouveaux sont
 *   refuses tant que la file atteint la limite ordinaire.
 *
 * Avant cette classe, la limite etait ecrite en dur a trois endroits (5 elements au total cote jeu, 5 en attente cote
 * formes de vie) et **aucun** ne consultait les officiers — alors que la page des officiers vend le Commandant en
 * promettant « file de construction portee de 4 a 8 ». Un avantage annonce et paye doit fonctionner.
 *
 * Une seule regle, donc, partagee par le serveur (qui refuse) et par l interface (qui dit « file pleine ») : les deux
 * ne peuvent plus diverger.
 */
final class QueueCapacity
{
    /** Le nombre de travaux qui peuvent attendre, derriere celui qui est en cours. */
    public const int WAITING_BASE = 4;

    /** Ce que le Commandant porte la file de construction a, tant qu il est embauche. */
    public const int WAITING_WITH_COMMANDER = 8;

    /**
     * Les travaux en attente permis dans une file de **construction** pour ce joueur.
     */
    public static function waitingAllowedForBuildings(User|null $user): int
    {
        return self::hasCommander($user) ? self::WAITING_WITH_COMMANDER : self::WAITING_BASE;
    }

    /**
     * Les travaux en attente permis dans une file de **recherche** : le Commandant ne les etend pas (decision de
     * Keven, 19 septembre 2026 — le texte de l officier ne parle que de construction).
     */
    public static function waitingAllowedForResearch(): int
    {
        return self::WAITING_BASE;
    }

    /**
     * Un Commandant embauche, et pas expire. La colonne porte l instant de fin ; nulle ou passee, il n y en a pas.
     */
    public static function hasCommander(User|null $user): bool
    {
        if ($user === null || $user->commander_until === null) {
            return false;
        }

        return Date::parse($user->commander_until)->getTimestamp() > Date::now()->getTimestamp();
    }
}
