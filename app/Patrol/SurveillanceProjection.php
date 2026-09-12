<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\SurveillanceFact;
use OGame\Patrol\Enums\SurveillanceTier;

/**
 * Ce qu un joueur a le droit de savoir des patrouilles etrangeres de son systeme, et rien de plus.
 *
 * ## Absent, jamais masque
 *
 * Un fait que le palier n autorise pas **n a pas de clef** dans la charge utile. Ni `null`, ni chaine
 * vide, ni drapeau : absent. Une donnee envoyee puis cachee a l ecran n est pas protegee — elle est
 * dans la reponse, et la reponse appartient au lecteur. C est la seule discipline qui tienne, et le
 * temoin la verifie clef par clef plutot que valeur par valeur.
 *
 * ## Le palier est relu a chaque appel
 *
 * Aucun cache. Le droit du joueur est recalcule sur les contacts acquis **a cet instant**, si bien
 * qu une couverture perdue retire ses renseignements des la demande suivante, sans attendre un
 * rechargement ni un evenement. La reponse porte l instant de son calcul : une reponse retardee est
 * ainsi reconnaissable comme ancienne, et ne peut pas rehabiller ce qui vient d etre revoque.
 *
 * ## Ce que chaque palier ajoute (R5, amendee le 12 septembre 2026)
 *
 * N1 le contact, sa **position tenue a jour**, **son mouvement dans le systeme en temps reel** et
 * **sa relation** avec l observateur — allie ou etranger, ce qui decide de sa couleur sur la carte.
 * Le mouvement en temps reel exige la route : les deux bouts du segment en cours et ses deux
 * instants, sans quoi le navigateur ne pourrait qu attendre la reponse suivante pour deplacer le
 * point. Un bout **hors du systeme observe** n est pas livre — il est reduit a « ailleurs »
 * (`outside`), sans galaxie ni systeme : la flotte se dirige vers le bord, et c est tout ce qu on
 * sait. Un bout qui **nomme un corps** n est livre qu a partir de N2 ; en dessous il est reduit a
 * son point (la vue Galaxie donnerait sinon le proprietaire par la position). La cible d un raid,
 * elle, est un point : elle voyage — c est la ou la flotte va, et le mouvement en temps reel le
 * montrerait de toute facon. N2 l identite du proprietaire. N3 la direction dite en clair, et la
 * destination **seulement si elle reste dans le systeme observe**. N4 un ordre de grandeur. N5
 * l effectif exact.
 *
 * **Decision de Keven** (adaptation d Azria, pas une regle d origine) : « tout ce qui se passe sur
 * la carte en temps reel ». Avant cette date, N1 ne livrait qu une position — nulle pendant un vol —
 * et la destination dans le systeme etait un fait du N3. Un point qui bouge sous les yeux revele sa
 * direction de toute facon ; le N3 garde le fait dit en clair. Reversible en retirant `segment` du
 * premier palier.
 *
 * Jamais, a aucun palier : la composition d une flotte, sa reserve, sa cargaison, ni l identifiant
 * de sa mission.
 */
final class SurveillanceProjection
{
    /**
     * Les bornes des ordres de grandeur du palier N4.
     *
     * **Choix propre a ce fichier, et il est signale comme tel.** R5 dit « estimation de taille »
     * sans fixer de bornes ; celles-ci sont une proposition, epinglee par un temoin pour qu un
     * changement soit delibere. Elles ne se presentent pas comme une decision transmise.
     *
     * @var array<int, array{0: int, 1: int|null}>
     */
    private const array TRANCHES = [
        [1, 9],
        [10, 49],
        [50, 199],
        [200, null],
    ];

    public function __construct(
        private readonly SurveillanceWatch $watch,
        private readonly FleetRelation $relation,
        private readonly PatrolPricing $pricing,
    ) {
    }

    /**
     * Les patrouilles etrangeres que ce joueur observe dans ce systeme, chacune reduite a son droit.
     *
     * Une patrouille dont aucun contact n est acquis n apparait pas — pas meme sous une forme
     * degradee. **Aucun detecteur, aucun renseignement** ; et cela ne touche pas les alertes qui
     * concernent les propres flottes du joueur, qui ne dependent d aucun batiment et ne passent pas
     * par ici.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inSystem(int $userId, int $galaxy, int $system, int $now): array
    {
        /*
         * **Une patrouille terminee n'est plus dans l'espace, et rien ne la montre.**
         *
         * `PatrolProjection` — celle qui sert les patrouilles du joueur — ecarte deja l'etat
         * `Finished`. Celle-ci ne le faisait pas : une patrouille rentree restait projetee, sans
         * position ni segment, mais portant encore sa relation et, au palier de l'identite, le nom
         * de son proprietaire. La revocation des contacts ferme la porte a l'ecriture ; ce filtre
         * la ferme a la lecture. Les deux, parce qu'une seule des deux protections a deja manque.
         */
        $patrouilles = Patrol::query()
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('user_id', '!=', $userId)
            ->where('state', '!=', PatrolState::Finished->value)
            ->orderBy('id')
            ->get();

        $projections = [];

        foreach ($patrouilles as $patrouille) {
            $palier = $this->watch->acquiredTierFor($userId, (int)$patrouille->id, $now);

            if ($palier === null) {
                continue;
            }

            $projections[] = $this->reduireADroit($patrouille, $palier, $userId, $galaxy, $system, $now);
        }

        return $projections;
    }

    /**
     * Une patrouille reduite aux seuls faits que ce palier autorise.
     *
     * @return array<string, mixed>
     */
    private function reduireADroit(Patrol $patrol, SurveillanceTier $tier, int $userId, int $galaxy, int $system, int $now): array
    {
        // **La clef du contact, non celle de la patrouille.** Un identifiant de patrouille suivrait
        // le joueur d un systeme a l autre et d une couverture a la suivante ; celui du contact naît
        // avec l acquisition et meurt avec elle, ce qui est exactement sa portee.
        $projection = [
            'contact_id' => $this->clefDuContact($userId, (int)$patrol->id, $now),
            'tier' => $tier->value,
            'computed_at' => $now,
        ];

        if ($tier->reveals(SurveillanceFact::Position)) {
            $projection['position'] = [
                'galaxy' => $galaxy,
                'system' => $system,
                'x' => $patrol->x === null ? null : (int)$patrol->x,
                'y' => $patrol->y === null ? null : (int)$patrol->y,
            ];
            $projection['relation'] = $this->relation->between($userId, (int)$patrol->user_id);

            $route = $this->route($patrol, $galaxy, $system, $tier->reveals(SurveillanceFact::Owner));

            if ($route !== null) {
                $projection['segment'] = $route;
            }
        }

        if ($tier->reveals(SurveillanceFact::Owner)) {
            $projection['owner'] = [
                'id' => (int)$patrol->user_id,
                'name' => (string)DB::table('users')->where('id', (int)$patrol->user_id)->value('username'),
            ];
        }

        if ($tier->reveals(SurveillanceFact::Heading)) {
            $projection['heading'] = $this->direction($patrol, $galaxy, $system);
        }

        if ($tier->reveals(SurveillanceFact::SizeEstimate)) {
            $projection['size_estimate'] = $this->tranche($this->effectif($patrol));
        }

        if ($tier->reveals(SurveillanceFact::ExactStrength)) {
            $projection['strength'] = $this->effectif($patrol);
        }

        return $projection;
    }

    /**
     * La route de la patrouille dans le systeme observe : les deux bouts de son segment en cours et
     * ses deux instants. C est ce qui permet au navigateur de la faire avancer a chaque image, et
     * de la poser dans son cap une fois arrivee — la meme forme que pour les patrouilles du lecteur.
     *
     * Un bout hors du systeme observe est reduit a `['outside' => true]` : ni galaxie, ni systeme,
     * ni position. « Absent, jamais masque » vaut ici aussi — la clef qui dirait ou n existe pas.
     *
     * @return array<string, mixed>|null
     */
    private function route(Patrol $patrol, int $galaxy, int $system, bool $bodiesMayBeNamed): array|null
    {
        $segment = $patrol->currentMission;

        if (!$segment instanceof FleetMission) {
            return null;
        }

        /*
         * **Le vol courant d une patrouille qui attaque est l attaque — deja traitee quand la flotte
         * rentre.** Le retour du raid est une autre mission, nee de l attaque, que la patrouille ne
         * nomme qu une fois posee (`parkAgain`). Sans lui, la carte de l observateur laissait le
         * glyphe au point de la patrouille pendant tout le retour. Relecture du lot.
         */
        if ((int)$segment->processed === 1) {
            $segment = FleetMission::query()
                ->where('parent_id', (int)$segment->id)
                ->where('processed', 0)
                ->orderBy('id')
                ->first();

            if (!$segment instanceof FleetMission) {
                return null;
            }
        }

        return [
            'from' => $this->bout($segment->galaxy_from, $segment->system_from, $segment->position_from, $segment->type_from, $segment->x_from, $segment->y_from, $galaxy, $system, $bodiesMayBeNamed),
            'to' => $this->bout($segment->galaxy_to, $segment->system_to, $segment->position_to, $segment->type_to, $segment->x_to, $segment->y_to, $galaxy, $system, $bodiesMayBeNamed),
            'time_departure' => (int)$segment->time_departure,
            'time_arrival' => (int)$segment->time_arrival,
        ];
    }

    /**
     * Un bout de segment, tel que la carte le lit — ou « ailleurs » s il sort du systeme observe.
     *
     * **Un corps n est nomme qu a partir du palier de l identite.** La position d orbite d une
     * planete se lit dans la vue Galaxie avec son proprietaire : un segment de lancement qui nomme
     * la planete de depart aurait livre l identite des le premier palier (relecture du lot). En
     * dessous, le corps est reduit a son **point** — l adresse de son orbite dans la geometrie du
     * systeme —, ce qui suffit a la carte pour faire partir ou arriver le glyphe au bon endroit,
     * sans dire quel corps c est.
     *
     * @return array<string, mixed>
     */
    private function bout(mixed $galaxy, mixed $system, mixed $position, mixed $type, mixed $x, mixed $y, int $observedGalaxy, int $observedSystem, bool $bodiesMayBeNamed): array
    {
        if ((int)$galaxy !== $observedGalaxy || (int)$system !== $observedSystem) {
            return ['outside' => true];
        }

        $estUnCorps = (int)$type !== PlanetType::SpatialPoint->value && ($x === null || $y === null);

        if ($estUnCorps && !$bodiesMayBeNamed) {
            $point = $this->pricing->geometry()->bodyPoint((int)$position);

            return [
                'galaxy' => (int)$galaxy,
                'system' => (int)$system,
                'position' => 0,
                'type' => PlanetType::SpatialPoint->value,
                'x' => $point->x,
                'y' => $point->y,
            ];
        }

        return [
            'galaxy' => (int)$galaxy,
            'system' => (int)$system,
            'position' => (int)$position,
            'type' => (int)$type,
            'x' => $x === null ? null : (int)$x,
            'y' => $y === null ? null : (int)$y,
        ];
    }

    /**
     * La clef stable du contact : le plus ancien acquis, pour que le lecteur suive le meme objet.
     */
    private function clefDuContact(int $userId, int $patrolId, int $now): int
    {
        return (int)DB::table('surveillance_contacts')
            ->where('observer_user_id', $userId)
            ->where('patrol_id', $patrolId)
            ->whereNull('revoked_at')
            ->where('visible_from', '<=', $now)
            ->orderBy('id')
            ->value('id');
    }

    /**
     * La direction de la patrouille, sans jamais livrer une destination hors couverture.
     *
     * Dire qu une patrouille quitte le systeme est une direction ; dire **ou** elle va quand cela
     * sort du systeme observe serait voir sans detecteur. Les deux se ressemblent et R5 les separe.
     *
     * @return array<string, mixed>
     */
    private function direction(Patrol $patrol, int $galaxy, int $system): array
    {
        $segment = $patrol->currentMission;

        if (!$segment instanceof FleetMission || (int)$segment->processed === 1) {
            return ['moving' => false];
        }

        $memeSysteme = (int)$segment->galaxy_to === $galaxy && (int)$segment->system_to === $system;

        if (!$memeSysteme) {
            // Elle s en va : le fait est une direction, la destination ne l est pas.
            return ['moving' => true, 'leaves_system' => true];
        }

        return [
            'moving' => true,
            'leaves_system' => false,
            'towards' => [
                'x' => (int)$segment->x_to,
                'y' => (int)$segment->y_to,
            ],
        ];
    }

    /**
     * L effectif total, jamais sa composition.
     */
    private function effectif(Patrol $patrol): int
    {
        $segment = $patrol->currentMission;

        if (!$segment instanceof FleetMission) {
            return 0;
        }

        return resolve(PatrolOrders::class)->unitsOf($segment)->getAmount();
    }

    /**
     * L ordre de grandeur d un effectif : ses bornes, jamais un mot que le serveur choisirait.
     *
     * @return array{from: int, to: int|null}
     */
    private function tranche(int $effectif): array
    {
        foreach (self::TRANCHES as [$de, $a]) {
            if ($effectif >= $de && ($a === null || $effectif <= $a)) {
                return ['from' => $de, 'to' => $a];
            }
        }

        return ['from' => 0, 'to' => 0];
    }
}
