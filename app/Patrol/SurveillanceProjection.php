<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
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
 * ## Ce que chaque palier ajoute (R5)
 *
 * N1 le contact et sa **position tenue a jour** — promettre de suivre un contact puis lui refuser sa
 * position des qu il bouge serait se contredire. N2 l identite du proprietaire. N3 la direction, et
 * la destination **seulement si elle reste dans le systeme observe** : livrer une destination hors
 * couverture en l appelant « destination » reviendrait a voir sans detecteur. N4 un ordre de
 * grandeur. N5 l effectif exact.
 *
 * Jamais, a aucun palier : la composition d une flotte, sa reserve, sa cargaison.
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

    public function __construct(private readonly SurveillanceWatch $watch)
    {
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
        $patrouilles = Patrol::query()
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('user_id', '!=', $userId)
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
