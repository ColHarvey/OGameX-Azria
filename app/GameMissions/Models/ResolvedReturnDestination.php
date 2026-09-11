<?php

namespace OGame\GameMissions\Models;

use OGame\Combat\Enums\ReturnDestinationKind;
use OGame\Combat\Support\ReturnPlan;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Patrol\Geometry\SpatialPoint;
use RuntimeException;

/**
 * Ou une mission retour se posera — un corps, ou un point de l'espace —, decide par l'appelant et
 * ecrit tel quel.
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
 *
 * ## Deux formes, deux jeux d'invariants, aucun relachement
 *
 * Depuis que les patrouilles attaquent (decision de Keven, 11 septembre 2026), une flotte peut
 * revenir **au point de l'espace ou sa patrouille l'attend**. Cette forme n'assouplit pas les
 * gardes ci-dessus : elle en a **d'autres**, exigees aussi strictement — une patrouille identifiee,
 * un point, des coordonnees, et le meme proprietaire que la flotte.
 *
 * Les deux formes **s'excluent**. Un objet qui porterait un corps *et* un point laisserait chaque
 * lecteur choisir lequel compte, et deux lecteurs choisiraient differemment : le genre decide seul,
 * et l'autre forme est vide. `landsOnAPoint()` est la question a poser — jamais `planetId === null`,
 * qui se confond avec un plan impossible.
 */
final readonly class ResolvedReturnDestination
{
    private function __construct(
        public ReturnDestinationKind $kind,
        public int|null $bodyId,
        public PlanetType|null $type,
        public Coordinate $coordinate,
        public int $ownerId,
        public int|null $patrolId = null,
        public SpatialPoint|null $point = null,
    ) {
    }

    /**
     * Si la flotte se pose sur un point de l'espace plutot que sur un corps.
     */
    public function landsOnAPoint(): bool
    {
        return $this->kind === ReturnDestinationKind::PatrolPoint;
    }

    /**
     * Le point, pour une destination qui en est un.
     *
     * **Exiger plutot que transtyper.** Les ecrivains ont besoin d'un point non nul ; le demander
     * ainsi nomme l'invariant a l'endroit ou il compte, au lieu de le supposer par un cast qui
     * fabriquerait un point a `0:0` si l'objet etait mal forme.
     *
     * @throws RuntimeException Si cette destination n'est pas un point.
     */
    public function pointOrFail(): SpatialPoint
    {
        if ($this->point === null) {
            throw new RuntimeException('Cette destination de retour n est pas un point de l espace.');
        }

        return $this->point;
    }

    /**
     * Le corps, pour une destination qui en est un.
     *
     * @throws RuntimeException Si cette destination est un point.
     */
    public function bodyIdOrFail(): int
    {
        if ($this->bodyId === null) {
            throw new RuntimeException('Cette destination de retour ne designe aucun corps celeste.');
        }

        return $this->bodyId;
    }

    /**
     * Le genre du corps, pour une destination qui en est un.
     *
     * @throws RuntimeException Si cette destination est un point.
     */
    public function bodyTypeOrFail(): PlanetType
    {
        if ($this->type === null) {
            throw new RuntimeException('Cette destination de retour ne designe aucun corps celeste.');
        }

        return $this->type;
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

        // **La forme « point » est jugee a part, et aussi durement.** Ce qui suit ne lui convient
        // pas — elle ne designe aucun corps —, mais elle n echappe a rien : elle doit nommer sa
        // patrouille, son point, ses coordonnees, et le proprietaire est compare comme partout
        // ailleurs, juste en dessous.
        if ($plan->landsOnAPoint()) {
            if ($plan->patrolId === null || $plan->patrolId < 1) {
                throw new RuntimeException('Le plan de retour de la mission ' . $mission->id . ' vise un point sans nommer la patrouille qui l attend.');
            }

            if ($plan->point === null) {
                throw new RuntimeException('Le plan de retour de la mission ' . $mission->id . ' vise un point sans dire lequel.');
            }
        }

        if (!$plan->landsOnAPoint() && ($plan->planetId === null || $plan->planetId < 1)) {
            throw new RuntimeException('Le plan de retour de la mission ' . $mission->id . ' porte un corps sans identifiant.');
        }

        if (!$plan->landsOnAPoint() && $plan->bodyType === null) {
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

        return new self(
            $plan->kind,
            $plan->planetId,
            $plan->bodyType,
            $plan->coordinate,
            $plan->ownerId,
            $plan->patrolId,
            $plan->point
        );
    }
}
