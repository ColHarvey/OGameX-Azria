<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Les deux liens qui decrivent l'effectif d'un combat ne disent pas la meme chose.
 *
 * ## Deux liens, et pourquoi ils doivent concorder
 *
 * Une flotte engagee dans un combat durable est nommee deux fois : par sa ligne de
 * `combat_participants`, qui fixe son camp dans la photographie, et par la colonne
 * `combat_instance_id` de sa mission, que l'arrivee pose et que la porte des mouvements relit. Le
 * premier dit qui compose la bataille ; le second dit qui est retenu sur le corps.
 *
 * Une sortie d'exploitation lit ces deux liens pour savoir qui rendre. S'ils divergent, elle ne
 * sait plus decrire l'effectif : rendre ce que l'un nomme laisserait ce que l'autre nomme pose sur
 * un corps qu'elle vient de liberer, sans combat pour le reclamer et sans retour pour le ramener.
 *
 * ## Ce qu'une cle etrangere ne garantissait pas
 *
 * L'inscription pointe vers la mission, et la base impose que ce pointeur soit valide. Elle
 * n'impose pas qu'il **existe** : une mission effacee laisse son inscription avec un lien vide, et
 * une lecture qui ecarte les liens vides rendait alors un effectif ampute — plus court d'une
 * flotte, et personne pour dire laquelle. Verifier la seule presence de l'initiatrice ne voyait
 * rien de cela.
 *
 * ## Rien n'est ecrit
 *
 * L'ecart arrete l'annulation avant tout changement d'etat : le combat garde son statut, la
 * barriere tient toujours le corps, aucune flotte ne part. Un corps tenu se repare ; un effectif
 * disparu, non.
 */
class IncoherentCombatEnrolment extends RuntimeException
{
    /**
     * @param int $combatInstanceId
     * @param int $orphelines
     * @return self
     */
    public static function becauseAnEnrolmentLostItsFleet(int $combatInstanceId, int $orphelines): self
    {
        return new self(
            'Le combat ' . $combatInstanceId . ' compte ' . $orphelines . ' inscription(s) dont la flotte n existe plus. '
            . 'L effectif ne se decrit plus entierement : rien n est annule, et le corps reste tenu.'
        );
    }

    /**
     * @param int $combatInstanceId
     * @param int $fleetMissionId
     * @return self
     */
    public static function becauseAFleetIsEnrolledTwice(int $combatInstanceId, int $fleetMissionId): self
    {
        return new self(
            'La flotte ' . $fleetMissionId . ' est inscrite plus d une fois au combat ' . $combatInstanceId
            . ', ou dans les deux camps a la fois. Un camp ne se devine pas : rien n est annule.'
        );
    }

    /**
     * @param int $combatInstanceId
     * @param array<int, int> $manquantes
     * @return self
     */
    public static function becauseFleetsAreHeldWithoutBeingEnrolled(int $combatInstanceId, array $manquantes): self
    {
        return new self(
            'Les flottes ' . implode(', ', $manquantes) . ' sont retenues par le combat ' . $combatInstanceId
            . ' sans figurer dans son effectif. Les liberer sans les rendre les laisserait sur un corps sans combat.'
        );
    }

    /**
     * @param int $combatInstanceId
     * @param int $fleetMissionId
     * @param int $autre
     * @return self
     */
    public static function becauseAnEnrolledFleetBelongsToAnotherCombat(int $combatInstanceId, int $fleetMissionId, int $autre): self
    {
        return new self(
            'La flotte ' . $fleetMissionId . ' est inscrite au combat ' . $combatInstanceId . ' alors que sa mission est '
            . 'retenue par le combat ' . $autre . '. Les deux liens se contredisent : rien n est annule.'
        );
    }

    /**
     * @param int $combatInstanceId
     * @param int $inscriptions
     * @return self
     */
    public static function becauseARallyingCombatAlreadyHasARoster(int $combatInstanceId, int $inscriptions): self
    {
        return new self(
            'Le combat ' . $combatInstanceId . ' est encore en ralliement mais porte deja ' . $inscriptions
            . ' inscription(s). La photographie n a pas encore ete prise : cet effectif n a pas d auteur.'
        );
    }

    /**
     * @param int $combatInstanceId
     * @return self
     */
    public static function becauseAClosedCombatHasNoRoster(int $combatInstanceId): self
    {
        return new self(
            'Le combat ' . $combatInstanceId . ' a ferme son ralliement sans laisser d effectif. La photographie qui '
            . 'devait le decrire est introuvable : rien n est annule.'
        );
    }
}
