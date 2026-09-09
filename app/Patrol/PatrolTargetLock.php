<?php

namespace OGame\Patrol;

use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolTargetVerdict;

/**
 * Ce qu une attaque trouve a l arrivee, compare a ce qu elle avait gele au depart.
 *
 * ## La regle
 *
 * Revues 120 et 121 (D18-D19) : une attaque contre une patrouille fige **l identite et
 * l emplacement** au lancement. A l arrivee :
 *
 *  - la meme patrouille, encore posee au meme point → le combat s ouvre ;
 *  - elle n existe plus, ou n est plus posee → position vide, retour ;
 *  - elle est posee ailleurs → position vide, retour. **L attaque ne la poursuit pas** (revue 117).
 *
 * Et jamais, dans aucun de ces cas, une autre patrouille.
 *
 * ## Pourquoi la lecture se fait par identifiant, et non par point
 *
 * C est la seule forme qui rende « jamais une autre patrouille » **structurelle** plutot que
 * promise par un commentaire. Chercher « la patrouille a ce point » rendrait la substitution
 * possible d un seul changement de requete, et aucun essai de verdict ne le verrait : les deux
 * lectures repondent la meme chose tant qu il n y a qu une patrouille au point.
 *
 * ## Pourquoi le verdict est separe de la lecture
 *
 * `decide()` est pure : elle prend le fait gele et la ligne vivante, et rend une issue. Elle
 * s eprouve sans base, sur les combinaisons qui comptent — dont celles qu une base ne produit
 * qu au prix d un montage entier. `underLock()` fournit la ligne, sous le verrou du combat.
 */
final class PatrolTargetLock
{
    /**
     * Le verdict, a partir du fait gele et de la ligne vivante — ou de son absence.
     *
     * @param FrozenPatrolTarget $gelee ce que le lancement a fige
     * @param Patrol|null $vivante la patrouille **de cet identifiant**, relue maintenant, ou null
     */
    public static function decide(FrozenPatrolTarget $gelee, Patrol|null $vivante): PatrolTargetVerdict
    {
        if ($vivante === null) {
            return PatrolTargetVerdict::Gone;
        }

        // Une ligne d un autre identifiant n est pas une cible degradee : c est un appel fautif.
        // Le verdict le refuse plutot que de comparer des positions qui ne se comparent pas.
        if ((int)$vivante->id !== $gelee->patrolId) {
            return PatrolTargetVerdict::Gone;
        }

        // Le proprietaire fait partie de l identite gelee (voir `FrozenPatrolTarget`).
        if ((int)$vivante->user_id !== $gelee->ownerId) {
            return PatrolTargetVerdict::Gone;
        }

        $point = $vivante->point();

        // Posee, ou rien a combattre : en vol, en retour, ou unites parties attaquer ailleurs.
        if (!$vivante->state->isParked() || $point === null) {
            return PatrolTargetVerdict::Gone;
        }

        if ((int)$vivante->galaxy !== $gelee->galaxy
            || (int)$vivante->system !== $gelee->system
            || $point->x !== $gelee->x
            || $point->y !== $gelee->y
        ) {
            return PatrolTargetVerdict::Moved;
        }

        return PatrolTargetVerdict::Present;
    }

    /**
     * Le verdict, la ligne etant relue sous verrou.
     *
     * A appeler dans la transaction qui ouvrira le combat : entre une lecture libre et l ouverture,
     * la patrouille pourrait partir. Sous SQLite `lockForUpdate()` ne compile a rien — la
     * serialisation reelle est celle de MariaDB, et c est la que les courses se prouvent.
     */
    public function underLock(FrozenPatrolTarget $gelee): PatrolTargetVerdict
    {
        $vivante = Patrol::query()->whereKey($gelee->patrolId)->lockForUpdate()->first();

        return self::decide($gelee, $vivante);
    }
}
