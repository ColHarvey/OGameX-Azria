<?php

namespace OGame\GameMissions\Models;

use OGame\Combat\Support\ReturnPlan;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use RuntimeException;

/**
 * Le corps ou une mission retour se posera, decide par l'appelant et ecrit tel quel.
 *
 * ## Pourquoi la creation du retour ne relit rien
 *
 * Une destination de repli est une **decision**, prise sous verrou par celui qui sait pourquoi la
 * flotte rentre : identite du corps, son type, ses coordonnees et son proprietaire. La relire au
 * moment d'ecrire la mission exposerait la flotte a atterrir ailleurs — un corps transfere, une lune
 * rasee entre la decision et l'insertion — sans que rien ne le signale.
 *
 * ## Pourquoi on ne peut pas la fabriquer a la main
 *
 * Le constructeur est prive, et la seule entree est un `ReturnPlan` deja verifie. Une premiere
 * version acceptait n'importe quelles valeurs et comblait les trous d'un plan incomplet par des
 * replis — planete par defaut, coordonnees `0:0:0`, proprietaire converti depuis `null`. Ces valeurs
 * n'existent nulle part dans le jeu : elles auraient fabrique une destination **plausible** a partir
 * d'un plan corrompu, au lieu d'arreter la transaction.
 *
 * Chaque invariant est donc exige, et son absence leve avant que le combat devienne final : un
 * plan impossible, un identifiant nul, un type ou des coordonnees manquants, un proprietaire qui
 * n'est pas celui de la flotte. Le dernier compte autant que les autres — une flotte de repli ne se
 * pose jamais chez quelqu'un d'autre.
 */
final readonly class ResolvedReturnDestination
{
    private function __construct(
        public int $bodyId,
        public PlanetType $type,
        public Coordinate $coordinate,
        public int $ownerId,
    ) {
    }

    /**
     * La destination d'un plan verifie, pour cette mission.
     *
     * @throws RuntimeException Si le plan ne decrit pas une destination utilisable telle quelle.
     */
    public static function from(ReturnPlan $plan, FleetMission $mission): self
    {
        if (!$plan->isPossible()) {
            throw new RuntimeException(
                'Le plan de retour de la mission ' . $mission->id . ' ne designe aucune destination'
                . ($plan->reason === null ? '' : ' (' . $plan->reason->value . ')')
                . ' : il n y a rien a ecrire.'
            );
        }

        if ($plan->planetId === null || $plan->planetId < 1) {
            throw new RuntimeException('Le plan de retour de la mission ' . $mission->id . ' porte un corps sans identifiant.');
        }

        if ($plan->bodyType === null) {
            throw new RuntimeException('Le plan de retour de la mission ' . $mission->id . ' ne dit pas quel genre de corps il vise.');
        }

        if ($plan->coordinate === null) {
            throw new RuntimeException('Le plan de retour de la mission ' . $mission->id . ' ne porte pas de coordonnees.');
        }

        if ($plan->ownerId === null || $plan->ownerId < 1) {
            throw new RuntimeException('Le plan de retour de la mission ' . $mission->id . ' ne nomme pas le proprietaire de la destination.');
        }

        if ($plan->ownerId !== (int)$mission->user_id) {
            throw new RuntimeException(
                'Le plan de retour de la mission ' . $mission->id . ' se pose chez le joueur ' . $plan->ownerId
                . ' alors que la flotte appartient au joueur ' . $mission->user_id . '.'
            );
        }

        return new self($plan->planetId, $plan->bodyType, $plan->coordinate, $plan->ownerId);
    }
}
