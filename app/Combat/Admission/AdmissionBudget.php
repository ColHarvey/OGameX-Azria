<?php

namespace OGame\Combat\Admission;

use InvalidArgumentException;

/**
 * Les plafonds d'un camp, figes avec le combat.
 *
 * ## Qui les fournit
 *
 * L'union de l'ouvreur, si elle existe. Sinon un groupe implicite prend les valeurs canoniques du
 * jeu — **16 flottes, 5 joueurs**, ce que `create_fleet_unions_table` inscrit par defaut.
 *
 * **Une autre union ne prend jamais le controle.** Une candidate qui vole sous sa propre union
 * apporte ses membres, pas ses plafonds : sans cette regle, les limites du combat dependraient de
 * l'ordre dans lequel la base a rendu les candidates.
 *
 * ## Ils sont persistes, puis jamais relus
 *
 * Un administrateur qui changerait une limite pendant un combat en cours ne doit pas en modifier
 * l'issue. Le budget appartient a l'instance, comme les reglages de champ d'epave.
 */
final readonly class AdmissionBudget
{
    /**
     * Le plafond de flottes du jeu, quand aucune union ne gouverne.
     */
    public const int CANONICAL_FLEETS = 16;

    /**
     * Le plafond de joueurs distincts du jeu, quand aucune union ne gouverne.
     */
    public const int CANONICAL_PLAYERS = 5;

    /**
     * @param int $maxFleets Le nombre de flottes, **l'ouvreur compris**.
     * @param int $maxPlayers Le nombre de joueurs distincts, **l'ouvreur compris**.
     */
    public function __construct(
        public int $maxFleets,
        public int $maxPlayers,
    ) {
        if ($maxFleets < 1 || $maxPlayers < 1) {
            throw new InvalidArgumentException(
                'Un budget doit laisser place a l ouvreur au moins : il compte dans les deux plafonds.'
            );
        }
    }

    /**
     * Les valeurs canoniques du jeu, pour un groupe implicite.
     */
    public static function canonical(): self
    {
        return new self(self::CANONICAL_FLEETS, self::CANONICAL_PLAYERS);
    }
}
