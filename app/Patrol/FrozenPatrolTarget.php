<?php

namespace OGame\Patrol;

use OGame\Models\Patrol;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;

/**
 * L identite **et** l emplacement d une patrouille visee, geles a l instant du lancement.
 *
 * ## La regle que cet objet porte
 *
 * Revues 120 et 121, D18-D19 : « attaque = identite et emplacement figes au lancement, cible partie
 * → position vide et retour, jamais une autre patrouille ». Les deux moities comptent. Geler le seul
 * emplacement ferait attaquer **celui qui s y trouve a l arrivee** — une patrouille tierce, peut-etre
 * alliee. Geler la seule identite ferait poursuivre une cible qui a bouge, ce que la revue 117
 * interdit explicitement.
 *
 * Le proprietaire est gele avec le reste. Aucun chemin ne change aujourd hui le proprietaire d une
 * patrouille, mais le chantier a deja paye une fois l hypothese inverse (journal §114.25 : une
 * patrouille offrait sa flotte au nouveau proprietaire de sa base). Un fait gele coute une
 * comparaison ; une hypothese non ecrite coute un defaut.
 */
final class FrozenPatrolTarget
{
    public function __construct(
        public readonly int $patrolId,
        public readonly int $ownerId,
        public readonly int $galaxy,
        public readonly int $system,
        public readonly int $x,
        public readonly int $y,
    ) {
    }

    /**
     * Gele une patrouille qu on s apprete a attaquer.
     *
     * **Elle doit etre posee.** On ne vise pas ce qui n est pas la : une patrouille en vol n a
     * meme pas de point (`Patrol::point()` rend `null` tant qu un segment vole), et une patrouille
     * dont les unites sont parties attaquer n a rien a son point. Le refus est explicite plutot
     * que silencieux — un gel sur des coordonnees nulles produirait une cible a 0:0.
     *
     * @throws PatrolOrderRefused
     */
    public static function of(Patrol $patrouille): self
    {
        $point = $patrouille->point();

        if (!$patrouille->state->isParked() || $point === null) {
            throw new PatrolOrderRefused('t_ingame.patrol.refusal_target_not_parked');
        }

        return new self(
            (int)$patrouille->id,
            (int)$patrouille->user_id,
            (int)$patrouille->galaxy,
            (int)$patrouille->system,
            $point->x,
            $point->y,
        );
    }

    /**
     * L emplacement gele, tel que la mission d attaque l ecrira dans ses coordonnees.
     */
    public function point(): SpatialPoint
    {
        return new SpatialPoint($this->x, $this->y);
    }
}
