<?php

namespace OGame\Combat\Policies;

use OGame\Combat\Support\LootPolicy;

/**
 * Le taux de pillage d'un camp entierement pilote par le serveur.
 *
 *     cinquante pour cent, toujours
 *
 * ## Pourquoi une version distincte, alors que le chiffre est le meme
 *
 * Sous `cargo_weighted_v1`, un camp pirate obtient deja cinquante pour cent : son fret est exclu de
 * la part Decouvreur, donc le bonus vaut zero. **Le taux ne change pas.** Ce qui change, c'est ce
 * que l'audit peut en dire.
 *
 * Un combat marque `cargo_weighted_v1` affirme qu'une ponderation par le fret a eu lieu et qu'elle
 * a donne zero. Un combat marque `npc_base_v1` affirme qu'aucune ponderation n'avait lieu d'etre.
 * Le jour ou la premiere formule evoluera, seule la premiere devra etre reconsideree.
 *
 * ## Ce que cette regle ignore volontairement
 *
 * Aucune classe : un compte systeme n'en a pas, et la colonne qu'il porte ne doit pas etre lue.
 * Aucune ponderation : il n'y a pas de Decouvreur a recompenser. L'inactivite de la cible n'a aucun
 * effet : le bonus qu'elle ouvre n'existe pas ici. L'honneur non plus, tant qu'il n'est pas
 * implemente.
 */
final class NpcBaseV1 implements LootRateRule
{
    /**
     * L'identifiant persiste avec chaque combat.
     */
    public const string VERSION = 'npc_base_v1';

    /**
     * Le taux fixe, en points de base.
     */
    public const int RATE = 5_000;

    /**
     * @inheritDoc
     */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @inheritDoc
     */
    public function rateInBasisPoints(LootPolicy $facts): int
    {
        return self::RATE;
    }
}
