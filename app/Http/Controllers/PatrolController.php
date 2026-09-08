<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Patrol\PatrolQuote;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use Throwable;

/**
 * Les ordres qu un joueur donne a ses patrouilles depuis la carte : devis, deplacement, rappel,
 * lancement.
 *
 * ## Le navigateur ne decide de rien
 *
 * Chaque point d entree recoit une intention — « vers ce point », « rentre », « pars d ici avec
 * cette flotte » — et rend ce que le serveur en a fait. Le point de depart, le cout, la duree, la
 * reserve restante et le droit de le faire viennent tous d ici. Le devis porte la version d ordre
 * qu il decrit et la confirmation la rapporte : un devis perime est refuse au lieu d etre debite
 * autrement (revue 121).
 *
 * ## Ce qu un refus dit
 *
 * Un refus du service (`PatrolOrderRefused`) rend 409 avec sa clef et sa traduction ; un point qui
 * n est pas un stationnement — hors grille, dans l etoile, hors du systeme — ou une destination mal
 * formee rend 422 avec les siennes ; une patrouille qui n est pas au joueur n existe pas pour lui
 * (404). Le message voyage aussi sous `errors[0].message`, la forme que la carte lit deja pour les
 * envois de flotte. **Un point hors grille est refuse, jamais arrondi** : un arrondi silencieux
 * masquerait un defaut du navigateur en deplacant la flotte ailleurs que la ou le joueur a clique.
 *
 * ## La composition vient de la requete, la planete de la session
 *
 * Un lancement part de la planete courante du joueur, comme tout envoi de flotte, avec une
 * composition `am<id>` — la forme de la page Flotte, pour qu une flotte standard s envoie telle
 * quelle. Le corps vise par une destination « pres d un corps » est **resolu ici** par ses
 * coordonnees et son genre ; l identifiant que le navigateur croirait connaitre n est pas lu.
 */
class PatrolController extends OGameController
{
    public function __construct(
        private readonly PatrolOrders $orders,
        private readonly PatrolPricing $pricing,
    ) {
    }

    /**
     * Le devis d un ordre : pour une patrouille (`patrol_id`), ou pour un lancement depuis la
     * planete courante (composition `am<id>`, `reserve`).
     */
    public function quote(Request $request, PlayerService $player): JsonResponse
    {
        $now = (int)Date::now()->timestamp;
        $to = $this->destinationFrom($request);

        if (is_string($to)) {
            return $this->refused($to, 422);
        }

        $vitesse = $this->speedFrom($request);
        $identifiant = $request->input('patrol_id');

        try {
            if ($identifiant !== null && $identifiant !== '') {
                $patrouille = $this->ownPatrol($player, (int)$identifiant);

                if ($patrouille === null) {
                    return $this->notFound();
                }

                $refus = $this->orders->whyMoveIsRefused($patrouille, $now);

                if ($refus !== null) {
                    throw new PatrolOrderRefused($refus);
                }

                $segment = $patrouille->currentMission;

                if (!$segment instanceof FleetMission) {
                    throw new PatrolOrderRefused('no_current_segment');
                }

                $devis = $this->orders->quoteFor($patrouille, $to, $vitesse, $now);
                $depart = $this->orders->departurePointFor($patrouille, $segment, $now);
            } else {
                $planete = $player->planets->current();
                $units = $this->unitsFrom($request);
                $reserve = (int)$request->input('reserve', 0);
                $refus = $this->orders->whyLaunchIsRefused($planete, $units, new Resources(0, 0, 0, 0), $reserve);

                if ($refus !== null) {
                    throw new PatrolOrderRefused($refus);
                }

                $devis = $this->orders->quoteForLaunch($planete, $units, $reserve, $to, $vitesse);
                $depart = $this->pricing->geometry()->bodyPoint($planete->getPlanetCoordinates()->position);
            }
        } catch (PatrolOrderRefused $refus) {
            return $this->refused($refus->reason, 409);
        }

        return response()->json([
            'success' => true,
            'server_now' => $now,
            'departure' => ['x' => $depart->x, 'y' => $depart->y],
            'quote' => $this->quotePayload($devis),
        ]);
    }

    /**
     * Un nouvel ordre de mouvement, confirme sur un devis dont la version est rapportee.
     */
    public function move(Request $request, PlayerService $player, int $patrol): JsonResponse
    {
        $now = (int)Date::now()->timestamp;
        $patrouille = $this->ownPatrol($player, $patrol);

        if ($patrouille === null) {
            return $this->notFound();
        }

        $to = $this->destinationFrom($request);

        if (is_string($to)) {
            return $this->refused($to, 422);
        }

        $version = $request->input('order_version');

        if (!is_numeric($version)) {
            return $this->refused('stale_quote', 409);
        }

        try {
            $this->orders->orderMove($patrouille, $to, $this->speedFrom($request), (int)$version, $now);
        } catch (PatrolOrderRefused $refus) {
            return $this->refused($refus->reason, 409);
        }

        return $this->done($patrouille, $now, 'order_moved');
    }

    /**
     * Le rappel : la patrouille rentre sur sa base, ou sur le repli si la base a disparu.
     */
    public function recall(Request $request, PlayerService $player, int $patrol): JsonResponse
    {
        $now = (int)Date::now()->timestamp;
        $patrouille = $this->ownPatrol($player, $patrol);

        if ($patrouille === null) {
            return $this->notFound();
        }

        try {
            $this->orders->recall($patrouille, $now);
        } catch (PatrolOrderRefused $refus) {
            return $this->refused($refus->reason, 409);
        }

        return $this->done($patrouille, $now, 'order_recalled');
    }

    /**
     * Le lancement depuis la planete courante, sans cargaison : la reserve seule embarque.
     */
    public function launch(Request $request, PlayerService $player): JsonResponse
    {
        $now = (int)Date::now()->timestamp;
        $to = $this->destinationFrom($request);

        if (is_string($to)) {
            return $this->refused($to, 422);
        }

        try {
            $patrouille = $this->orders->launch(
                $player->planets->current(),
                $this->unitsFrom($request),
                new Resources(0, 0, 0, 0),
                (int)$request->input('reserve', 0),
                $to,
                $this->speedFrom($request),
                $now
            );
        } catch (PatrolOrderRefused $refus) {
            return $this->refused($refus->reason, 409);
        }

        return $this->done($patrouille, $now, 'order_launched');
    }

    /**
     * La destination demandee, ou la clef du refus si elle n en est pas une.
     *
     * Un point libre est refuse par la geometrie du serveur, jamais arrondi. Un corps est resolu par
     * ses coordonnees et son genre ; s il n existe pas, la destination n existe pas.
     */
    private function destinationFrom(Request $request): PatrolDestination|string
    {
        $galaxy = (int)$request->input('galaxy', 0);
        $system = (int)$request->input('system', 0);

        if ($galaxy < 1 || $system < 1) {
            return 'bad_destination';
        }

        $geometrie = $this->pricing->geometry();
        $x = $request->input('x');
        $y = $request->input('y');

        if (is_numeric($x) && is_numeric($y)) {
            $point = new SpatialPoint((int)$x, (int)$y);

            return $geometrie->refusalOf($point) ?? PatrolDestination::spatialPoint($geometrie, $galaxy, $system, $point);
        }

        $position = (int)$request->input('position', 0);
        $genre = PlanetType::tryFrom((int)$request->input('type', 0));

        if ($position < 1 || ($genre !== PlanetType::Planet && $genre !== PlanetType::Moon)) {
            return 'bad_destination';
        }

        $corps = Planet::query()
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('planet', $position)
            ->where('planet_type', $genre->value)
            ->where('destroyed', 0)
            ->value('id');

        if ($corps === null) {
            return 'bad_destination';
        }

        return PatrolDestination::nearBody($geometrie, $galaxy, $system, $position, $genre, (int)$corps);
    }

    /**
     * La vitesse en dixiemes, comme la page Flotte : 10 vaut 100 %.
     */
    private function speedFrom(Request $request): float
    {
        return (float)max(1, min(10, (int)$request->input('speed', 10)));
    }

    /**
     * La composition, dans la forme de la page Flotte (`am<id>` => nombre). Un identifiant inconnu
     * est ignore : il ne peut rien designer.
     */
    private function unitsFrom(Request $request): UnitCollection
    {
        $units = new UnitCollection();

        foreach ($request->all() as $clef => $valeur) {
            if (!is_string($clef) || !str_starts_with($clef, 'am') || !is_numeric($valeur) || (int)$valeur <= 0) {
                continue;
            }

            try {
                $units->addUnit(ObjectService::getUnitObjectById((int)substr($clef, 2)), (int)$valeur);
            } catch (Throwable) {
                continue;
            }
        }

        return $units;
    }

    /**
     * La patrouille du joueur, ou rien : celle d un autre n existe pas pour lui.
     */
    private function ownPatrol(PlayerService $player, int $id): Patrol|null
    {
        return Patrol::query()
            ->whereKey($id)
            ->where('user_id', $player->getId())
            ->with('currentMission')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function quotePayload(PatrolQuote $devis): array
    {
        return $devis->toArray() + [
            'possible' => $devis->isPossible(),
            'refusal_reason' => $devis->refusal === null ? null : (string)__('t_ingame.patrol.refusal_' . $devis->refusal),
        ];
    }

    private function done(Patrol $patrouille, int $now, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'server_now' => $now,
            'patrol_id' => (int)$patrouille->id,
            'message' => (string)__('t_ingame.patrol.' . $message),
        ]);
    }

    private function refused(string $cle, int $statut): JsonResponse
    {
        $message = (string)__('t_ingame.patrol.refusal_' . $cle);

        return response()->json([
            'success' => false,
            'reason_key' => $cle,
            'reason' => $message,
            'errors' => [['message' => $message, 'error' => 140020]],
        ], $statut);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'reason_key' => 'unknown_patrol', 'reason' => (string)__('t_ingame.patrol.refusal_unknown_patrol')], 404);
    }
}
