<?php

namespace OGame\Lifeforms\Presentation;

use Illuminate\Support\Collection;
use OGame\Enums\FleetMissionStatus;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Services\LifeformDiscoveryService;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Lifeforms\LifeformDiscovery;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\SettingsService;
use OGame\ViewModels\FleetEventRowViewModel;

/**
 * Les vols d exploration dans la boite d evenements du bandeau, comme toute mission (demande de Keven, journal §163).
 *
 * Un vol de decouverte part sans vaisseau et ne vit pas dans `fleet_missions` : la boite d evenements ne le voyait pas,
 * et un joueur qui venait de cliquer sur l icone ADN ne voyait rien partir. Cette classe compose, depuis les vols en
 * cours du compte, ce que la boite lit — le compte des vols amis et le prochain evenement pour le bandeau ferme, une
 * ligne par vol pour le deroulant, et les identifiants de lignes que `checkevents` garde affichees.
 *
 * Les lignes du deroulant portent un identifiant que le script confond avec celui d une mission de flotte
 * (`eventRow-<id>`, `counter-eventlist-<id>`, `ids[]` de `checkevents`) : celui d un vol est decale hors de l espace des
 * missions (`ROW_OFFSET`), et le decalage se lit dans les deux sens ici, nulle part ailleurs.
 */
final class DiscoveryFleetEvents
{
    /** Le decalage qui separe l identifiant d une ligne de vol de celui d une mission de flotte. */
    public const int ROW_OFFSET = 900000000;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformDiscoveryService $discoveries,
        private readonly PlanetServiceFactory $planets,
    ) {
    }

    /**
     * Ce que le bandeau ferme compte : les vols en cours (amis, tous), et l echeance la plus proche encore a venir.
     *
     * @return array{count: int, next_ends_at: int|null, label: string}
     */
    public function summary(int $userId, int $now): array
    {
        $vols = $this->running($userId);
        $prochain = $vols->filter(static fn (LifeformDiscovery $vol): bool => (int)$vol->ends_at > $now)->first();

        return [
            'count' => $vols->count(),
            'next_ends_at' => $prochain === null ? null : (int)$prochain->ends_at,
            'label' => self::label(),
        ];
    }

    /**
     * Une ligne du deroulant par vol en cours, dans la forme que `eventrow.blade.php` lit — a une echeance pres, celle
     * du vol, pour que le tri par arrivee les melange aux missions ordinaires.
     *
     * @return array<int, FleetEventRowViewModel>
     */
    public function rows(int $userId): array
    {
        return $this->running($userId)->map(fn (LifeformDiscovery $vol): FleetEventRowViewModel => $this->rowOf($vol))->values()->all();
    }

    /**
     * Les identifiants de lignes que `checkevents` garde affichees : les vols dont l echeance n est pas passee. Un vol
     * echu est regle par le passage du joueur (`settleDue()`) et sa ligne disparait avec le compte a rebours.
     *
     * @return array<int, int>
     */
    public function displayedRowIds(int $userId, int $now): array
    {
        return $this->running($userId)
            ->filter(static fn (LifeformDiscovery $vol): bool => (int)$vol->ends_at > $now)
            ->map(static fn (LifeformDiscovery $vol): int => self::rowId((int)$vol->id))
            ->values()
            ->all();
    }

    /** L identifiant de ligne d un vol, hors de l espace des missions de flotte. */
    public static function rowId(int $flightId): int
    {
        return self::ROW_OFFSET + $flightId;
    }

    /** Le vol derriere un identifiant de ligne, ou null si l identifiant est celui d une mission de flotte. */
    public static function flightIdOf(int $rowId): int|null
    {
        return $rowId > self::ROW_OFFSET ? $rowId - self::ROW_OFFSET : null;
    }

    /** Le libelle de la mission, celui que le bandeau ferme et la ligne du deroulant montrent. */
    public static function label(): string
    {
        return (string)__('t_ingame.fleet.mission_discovery');
    }

    /**
     * Les vols en cours du compte, du plus proche au plus lointain — aucun quand les formes de vie sont fermees : la
     * Galaxie et la page des decouvertes se ferment aussi, les vols echus se reglent sans bruit.
     *
     * @return Collection<int, LifeformDiscovery>
     */
    private function running(int $userId): Collection
    {
        if (!$this->settings->lifeformsEnabled()) {
            return new Collection();
        }

        return $this->discoveries->runningOf($userId);
    }

    private function rowOf(LifeformDiscovery $vol): FleetEventRowViewModel
    {
        $ligne = new FleetEventRowViewModel();
        $ligne->is_discovery = true;
        $ligne->id = self::rowId((int)$vol->id);
        $ligne->real_mission_id = null;
        $ligne->mission_type = GalaxyDiscoveries::MISSION_TYPE;
        $ligne->mission_label = self::label();
        $ligne->mission_time_arrival = (int)$vol->ends_at;
        $ligne->time_departure = (int)$vol->started_at;
        $ligne->is_return_trip = false;
        $ligne->active_recall_time = 0;
        $ligne->is_recallable = false;
        $ligne->friendly_status = FleetMissionStatus::Friendly->value;
        $ligne->user_id = (int)$vol->user_id;
        $ligne->fleet_unit_count = 1;
        $ligne->fleet_units = new UnitCollection();
        $ligne->resources = new Resources(0, 0, 0, 0);

        // La planete de depart : son nom et son genre, ou ses coordonnees seules si elle n existe plus.
        $depart = $this->planets->make((int)$vol->planet_id);
        $ligne->origin_planet_name = $depart?->getPlanetName() ?? '';
        $ligne->origin_planet_coords = $depart?->getPlanetCoordinates() ?? new Coordinate(0, 0, 0);
        $ligne->origin_planet_type = $depart?->getPlanetType() ?? PlanetType::DeepSpace;

        // La position visee : la planete qui s y trouve, s il y en a une — sinon la position seule.
        $cible = new Coordinate((int)$vol->galaxy, (int)$vol->system, (int)$vol->position);
        $planeteVisee = Planet::query()
            ->where('galaxy', $cible->galaxy)
            ->where('system', $cible->system)
            ->where('planet', $cible->position)
            ->where('planet_type', PlanetType::Planet->value)
            ->first(['name']);
        $ligne->destination_planet_name = $planeteVisee === null ? '' : (string)$planeteVisee->name;
        $ligne->destination_planet_coords = $cible;
        $ligne->destination_planet_type = $planeteVisee === null ? PlanetType::DeepSpace : PlanetType::Planet;

        return $ligne;
    }
}
