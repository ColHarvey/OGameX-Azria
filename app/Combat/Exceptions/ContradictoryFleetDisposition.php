<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Deux chemins ont prononce des mouvements differents pour la meme flotte.
 *
 * ## Ce que la contrainte d'unicite prouve, et ce qu'elle ne prouve pas
 *
 * La cle unique sur `fleet_mission_id` garantit qu'il n'existe **qu'une** ligne de disposition par
 * flotte. Elle ne garantit pas que cette ligne dit ce que le second ecrivain croyait ecrire.
 *
 * Un `firstOrCreate()` rend la ligne existante sans regarder son contenu. Si la fermeture prononce
 * « limite de flottes atteinte » et qu'un travailleur non synchronise prononce « ralliement ferme »,
 * le premier arrive devient la verite et le desaccord disparait — silencieusement, alors qu'il
 * signale soit une course que l'ordre des verrous devrait avoir ferme, soit une reparation manuelle
 * incoherente.
 *
 * Un rejeu du **meme** contenu reste sans effet : c'est l'idempotence attendue. Un contenu
 * different s'arrete ici, et la transaction revient en arriere.
 */
class ContradictoryFleetDisposition extends RuntimeException
{
    public function __construct(
        public readonly int $fleetMissionId,
        public readonly string $champ,
        public readonly string $inscrit,
        public readonly string $prononce,
    ) {
        parent::__construct(
            'La flotte ' . $fleetMissionId . ' porte deja une disposition dont ' . $champ
            . ' vaut « ' . $inscrit . ' », et ce passage prononce « ' . $prononce
            . ' » : deux decisions differentes pour un seul mouvement, rien n est ecrit.'
        );
    }
}
