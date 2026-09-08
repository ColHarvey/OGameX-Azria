<?php

namespace OGame\Patrol;

use InvalidArgumentException;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\Geometry\SystemGeometry;

/**
 * Ou une patrouille se rend : un point libre de l espace, ou le voisinage d un corps.
 *
 * ## Deux facons de designer, une seule facon de mesurer
 *
 * Un corps se designe par son **identite** — la revue 120 l exige : son dessin tourne avec l orbite,
 * son adresse ne bouge pas. Un point libre se designe par ses **coordonnees de reference**, arrondies
 * a la grille. Les deux se ramenent a un point de la geometrie pour le calcul de distance, et c est
 * la seule chose que la tarification a besoin de savoir.
 *
 * **Une destination ne se fabrique pas a la main.** Les deux fabriques valident ce qu elles
 * construisent : un point hors grille, dans l etoile ou hors du systeme est refuse ici, avant qu un
 * devis existe. Un point valide a la construction reste valide, et aucun appelant n a a s en
 * inquieter ensuite.
 */
final readonly class PatrolDestination
{
    private function __construct(
        public int $galaxy,
        public int $system,
        public SpatialPoint $point,
        public PlanetType $type,
        public int|null $bodyId,
        public int $orbit,
        public bool $landsOnTheBody,
    ) {
    }

    /**
     * Un point libre de l espace, deja arrondi a la grille.
     *
     * @throws InvalidArgumentException Si le point n est pas un stationnement valide.
     */
    public static function spatialPoint(SystemGeometry $geometry, int $galaxy, int $system, SpatialPoint $point): self
    {
        $refus = $geometry->refusalOf($point);

        if ($refus !== null) {
            throw new InvalidArgumentException('Ce point n est pas un stationnement valide : ' . $refus . '.');
        }

        return new self($galaxy, $system, $point, PlanetType::SpatialPoint, null, $geometry->orbitIndexOf($point), false);
    }

    /**
     * Le voisinage d un corps, designe par son identite.
     *
     * La patrouille se pose **pres** du corps, jamais dessus : le point est celui que
     * `stationingPointNear()` rend, sur la grille et donc toujours valide. Le corps garde son
     * identite pour tout le reste — c est elle qui survit a la rotation de son orbite.
     */
    public static function nearBody(SystemGeometry $geometry, int $galaxy, int $system, int $orbit, PlanetType $type, int|null $bodyId): self
    {
        if (!in_array($type, [PlanetType::Planet, PlanetType::Moon], true)) {
            throw new InvalidArgumentException('Seuls une planete et une lune sont des corps.');
        }

        return new self($galaxy, $system, $geometry->stationingPointNear($orbit), $type, $bodyId, $orbit, false);
    }

    /**
     * Le corps sur lequel la patrouille va **se poser** : son atterrissage, jamais un stationnement.
     *
     * ## Pourquoi ce fait vit ici, et ce qu il ferme
     *
     * Un segment de flotte inscrit son corps d arrivee dans `planet_id_to`, et le jeu rend a chaque
     * joueur **toute mission qui arrive sur une de ses planetes** : c est ainsi qu une attaque
     * s annonce. Une patrouille qui stationne au voisinage d un corps n y arrive pas — elle se pose
     * a cote — mais elle inscrivait quand meme son identite, et le proprietaire du corps la voyait
     * dans sa boite d evenements et sur sa carte **sans aucun detecteur**, avec son genre, ses deux
     * instants et ses deux bouts. La detection doit etre le seul chemin par lequel une patrouille
     * etrangere se montre ; celui-la le contournait entierement.
     *
     * Le corps d arrivee n est donc inscrit que lorsque la patrouille s y pose vraiment, c est-a-dire
     * a son retour. `PatrolMission::processArrival()` est le seul lecteur de ce champ, et il ne le
     * lit que pour un retour.
     */
    public static function landingOn(SystemGeometry $geometry, int $galaxy, int $system, int $orbit, PlanetType $type, int $bodyId): self
    {
        if (!in_array($type, [PlanetType::Planet, PlanetType::Moon], true)) {
            throw new InvalidArgumentException('Seuls une planete et une lune sont des corps.');
        }

        return new self($galaxy, $system, $geometry->stationingPointNear($orbit), $type, $bodyId, $orbit, true);
    }

    /**
     * La destination vise un corps, et non un point libre.
     */
    public function isBody(): bool
    {
        return $this->type !== PlanetType::SpatialPoint;
    }

    /**
     * Les coordonnees telles que les lecteurs qui ne connaissent que des positions les attendent :
     * la boite d evenements, les messages, les anciens rendus. La position est l orbite la plus
     * proche du point.
     */
    public function coordinate(): Coordinate
    {
        return new Coordinate($this->galaxy, $this->system, $this->orbit);
    }

    /**
     * Deux destinations designent le meme endroit.
     *
     * Le point ne suffit pas : deux corps partagent leurs coordonnees — une planete et sa lune — et
     * un point libre pose au voisinage d une planete n est pas cette planete.
     */
    public function equals(self $other): bool
    {
        return $this->galaxy === $other->galaxy
            && $this->system === $other->system
            && $this->type === $other->type
            && $this->bodyId === $other->bodyId
            && $this->point->equals($other->point);
    }

    public function describe(): string
    {
        return $this->galaxy . ':' . $this->system . ':' . $this->orbit
            . ' [' . $this->type->value . ($this->isBody() ? '#' . ($this->bodyId ?? 0) : ' ' . $this->point->asString()) . ']';
    }
}
