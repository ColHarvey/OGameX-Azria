<?php

namespace OGame\Services;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\WreckField;

/**
 * Class WreckFieldService.
 *
 * Wreck field object management service.
 *
 * @package OGame\Services
 */
class WreckFieldService
{
    /**
     * Original OGame Space Dock wreckage multipliers, applied to the non-debris share.
     */
    private const SPACE_DOCK_WRECKAGE_MULTIPLIERS = [
        1 => 0.45,
        2 => 0.48,
        3 => 0.49,
        4 => 0.50,
        5 => 0.51,
        6 => 0.52,
        7 => 0.53,
        8 => 0.53,
        9 => 0.54,
        10 => 0.54,
        11 => 0.55,
        12 => 0.55,
        13 => 0.55,
        14 => 0.56,
        15 => 0.56,
    ];

    /**
     * The wreck field object model.
     *
     * @var WreckField|null
     */
    private ?WreckField $wreckField = null;

    /**
     * WreckFieldService constructor.
     *
     * @param PlayerService $playerService
     * @param SettingsService $settingsService
     */
    public function __construct(
        private PlayerService $playerService,
        private SettingsService $settingsService,
        private int|null $debrisFieldFromShips = null,
        private int|null $lifetimeHours = null,
        private int|null $instant = null,
    ) {
    }

    /**
     * La part des vaisseaux detruits qui devient debris : figee par le combat durable, vivante sinon.
     */
    private function debrisFieldFromShips(): int
    {
        return $this->debrisFieldFromShips ?? $this->settingsService->debrisFieldFromShips();
    }

    /**
     * La duree de vie d'un champ qui nait ou s'etend ici : figee par le combat durable, vivante sinon.
     */
    private function lifetimeHours(): int
    {
        return $this->lifetimeHours ?? $this->settingsService->wreckFieldLifetimeHours();
    }

    /**
     * L'instant auquel un champ nait ou s'etend ici. Le combat durable le fixe a son echeance ; les
     * reparations, elles, sont des actions du joueur et gardent l'horloge courante.
     */
    private function instant(): Carbon
    {
        return $this->instant === null ? now() : Date::createFromTimestamp($this->instant);
    }

    /**
     * Load an existing wreck field or create a new empty one in memory for the given coordinates.
     *
     * @param Coordinate $coordinates
     */
    public function loadOrCreateForCoordinates(Coordinate $coordinates): void
    {
        $wreckField = WreckField::where('galaxy', $coordinates->galaxy)
            ->where('system', $coordinates->system)
            ->where('planet', $coordinates->position)
            ->first();

        if (!$wreckField) {
            $wreckField = new WreckField();
            $wreckField->galaxy = $coordinates->galaxy;
            $wreckField->system = $coordinates->system;
            $wreckField->planet = $coordinates->position;
            $wreckField->owner_player_id = $this->playerService->getId();
            $wreckField->created_at = $this->instant();
            $wreckField->expires_at = $this->instant()->copy()->addHours($this->lifetimeHours());
            $wreckField->status = 'active';
            $wreckField->ship_data = [];
        }

        $this->wreckField = $wreckField;
    }

    /**
     * Load wreck field by coordinate only if it exists.
     *
     * @param Coordinate $coordinate
     * The coordinate of the wreck field.
     *
     * @return bool True if the wreck field exists and was loaded successfully, false otherwise.
     */
    public function loadForCoordinates(Coordinate $coordinate): bool
    {
        // Fetch wreck field model
        $wreckField = WreckField::where('galaxy', $coordinate->galaxy)
            ->where('system', $coordinate->system)
            ->where('planet', $coordinate->position)
            ->first();

        if ($wreckField !== null) {
            $this->wreckField = $wreckField;
            return true;
        }

        return false;
    }

    /**
     * Load a wreck field by its identifier.
     *
     * L'identite exacte d'un champ, la ou les coordonnees ne suffisent plus : plusieurs champs vivent aux memes
     * coordonnees depuis que leur unicite a ete retiree, et une commande doit agir sur celui qu'elle a montre au
     * joueur — jamais sur « le premier a cet endroit ».
     *
     * @return bool True if the wreck field exists and was loaded, false otherwise.
     */
    public function loadById(int $wreckFieldId): bool
    {
        $wreckField = WreckField::query()->whereKey($wreckFieldId)->first();

        if ($wreckField !== null) {
            $this->wreckField = $wreckField;
            return true;
        }

        return false;
    }

    /**
     * Load an active or blocked wreck field for the given coordinates.
     * Prefers active over blocked, and skips repairing wreck fields.
     *
     * @param Coordinate $coordinate
     * @return bool True if a non-repairing wreck field was loaded successfully, false otherwise.
     */
    public function loadActiveOrBlockedForCoordinates(Coordinate $coordinate): bool
    {
        // Fetch active or blocked wreck field model
        // Prefer active over blocked
        $wreckField = WreckField::where('galaxy', $coordinate->galaxy)
            ->where('system', $coordinate->system)
            ->where('planet', $coordinate->position)
            ->whereIn('status', ['active', 'blocked'])
            ->orderByRaw("FIELD(status, 'active', 'blocked')")
            ->first();

        if ($wreckField !== null) {
            $this->wreckField = $wreckField;
            return true;
        }

        return false;
    }

    /**
     * Get the coordinates of the wreck field.
     */
    public function getCoordinates(): Coordinate
    {
        if ($this->wreckField === null) {
            throw new Exception('No wreck field loaded.');
        }

        return new Coordinate($this->wreckField->galaxy, $this->wreckField->system, $this->wreckField->planet);
    }

    /**
     * Reloads the wreck field object from the database.
     *
     * @return void
     */
    public function reload(): void
    {
        if ($this->wreckField) {
            // **Par identifiant, jamais par coordonnees** : plusieurs epaves vivent a une meme position, et un
            // rechargement par coordonnees rendait la plus ancienne — une brulee, ou celle d'un ancien
            // proprietaire. C'est la classe de defaut que cette tranche ferme (journal §183).
            $this->loadById((int)$this->wreckField->id);
        }
    }

    /**
     * Check if wreck field conditions are met for creation.
     *
     * @param Resources $destroyedResources
     * @param UnitCollection $totalFleet
     * @param UnitCollection $destroyedShips
     * @return bool
     */
    public function canCreateWreckField(Resources $destroyedResources, UnitCollection $totalFleet, UnitCollection $destroyedShips): bool
    {
        // Check minimum resource loss (default 150,000)
        $totalDestroyedValue = $destroyedResources->metal->get() + $destroyedResources->crystal->get() + $destroyedResources->deuterium->get();
        if ($totalDestroyedValue < $this->settingsService->wreckFieldMinResourcesLoss()) {
            return false;
        }

        // Check minimum fleet percentage destroyed (default 5%)
        $totalFleetResources = $totalFleet->toResources();
        $destroyedFleetResources = $destroyedShips->toResources();
        $totalFleetValue = $totalFleetResources->metal->get() +
                          $totalFleetResources->crystal->get() +
                          $totalFleetResources->deuterium->get();
        $destroyedFleetValue = $destroyedFleetResources->metal->get() +
                             $destroyedFleetResources->crystal->get() +
                             $destroyedFleetResources->deuterium->get();

        if ($totalFleetValue == 0) {
            return false;
        }

        $destroyedPercentage = ($destroyedFleetValue / $totalFleetValue) * 100;
        if ($destroyedPercentage < (float) $this->settingsService->wreckFieldMinFleetPercentage()) {
            return false;
        }

        return true;
    }

    /**
     * Calculate ships that go into a wreck field based on universe debris settings and Space Dock level.
     *
     * @param UnitCollection $destroyedShips
     * @param int $spaceDockLevel
     * @return array
     */
    public function calculateShipsForWreckField(UnitCollection $destroyedShips, int $spaceDockLevel = 1, int|null $planetId = null, float|null $frozenLifeformBonus = null): array
    {
        $wreckFieldPercentage = $this->getRecoverableWreckFieldPercentage($spaceDockLevel, $planetId, $frozenLifeformBonus) / 100;
        $shipData = [];

        foreach ($destroyedShips->units as $unit) {
            if ($unit->amount > 0 && $unit->unitObject->type === GameObjectType::Ship) {
                if (in_array($unit->unitObject->machine_name, ['espionage_probe', 'solar_satellite'], true)) {
                    continue;
                }

                $wreckFieldCount = (int) floor($unit->amount * $wreckFieldPercentage);
                if ($wreckFieldCount > 0) {
                    $shipData[] = [
                        'machine_name' => $unit->unitObject->machine_name,
                        'quantity' => $wreckFieldCount,
                        'repair_progress' => 0,
                    ];
                }
            }
        }

        return $shipData;
    }

    /**
     * Create or extend a wreck field with the given ships.
     *
     * Behavior:
     * - If no existing wreck field: create new one
     * - If existing wreck field is active (repairs not started): combine ships and reset expiration timer
     * - If existing wreck field is repairing or blocked: create a separate blocked wreck field
     *
     * @param Coordinate $coordinate
     * @param array $shipData
     * @param int $ownerPlayerId
     * @return WreckField
     */
    public function createWreckField(Coordinate $coordinate, array $shipData, int $ownerPlayerId): WreckField
    {
        return DB::transaction(function () use ($coordinate, $shipData, $ownerPlayerId): WreckField {
            // **Ce chemin ne verrouille aucune planete, et c'est voulu.** Ce qui serialise une extension par une
            // bataille et un demarrage par le joueur, ce sont les lignes d'epaves, que les deux prennent ; le
            // reglement d'un combat les tient deja. Prendre en plus la planete ajoutait, pour une cible **lune**,
            // une ligne que le reglement n'avait pas listee avant ses epaves : epaves puis planete d'un cote,
            // planete puis epaves de l'autre — un interblocage sur un chemin qui n'en avait aucun. Aucun chemin ne
            // prend donc une epave **puis** une planete (journal §183).
            //
            // L'existant du proprietaire sous verrou : etendre, bloquer ou creer se decide sur l'etat que la
            // transition precedente a laisse, jamais sur une lecture d'avant — une extension se glissait sous un
            // minuteur calcule sans elle.

            // Check if wreck field already exists at this location
            //
            // **L'ordre est explicite** : une active et une bloquee coexistent des qu'une bataille survient pendant
            // une reparation, et sans `orderBy` c'est le plan d'execution qui choisissait laquelle repond. Une
            // bloquee rendue la premiere faisait creer une troisieme ligne au lieu d'etendre l'active. `CASE`
            // plutot que `FIELD()`, qui n'existe pas sous SQLite.
            $existingWreckField = WreckField::where('galaxy', $coordinate->galaxy)
                ->where('system', $coordinate->system)
                ->where('planet', $coordinate->position)
                ->where('owner_player_id', $ownerPlayerId)
                ->whereIn('status', ['active', 'repairing', 'blocked'])
                ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'repairing' THEN 1 ELSE 2 END")
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existingWreckField) {
                if ($existingWreckField->status === 'active') {
                    // Repairs haven't started - combine and reset expiration timer
                    $this->extendWreckFieldWithReset($existingWreckField, $shipData);
                    return $existingWreckField;
                }

                // Repairs in progress or already blocked - create a separate blocked wreck field
                return $this->createBlockedWreckField($coordinate, $shipData, $ownerPlayerId);
            }

            // Create new active wreck field
            $wreckField = new WreckField();
            $wreckField->galaxy = $coordinate->galaxy;
            $wreckField->system = $coordinate->system;
            $wreckField->planet = $coordinate->position;
            $wreckField->owner_player_id = $ownerPlayerId;
            $wreckField->created_at = $this->instant();
            $wreckField->expires_at = $this->instant()->copy()->addHours($this->lifetimeHours());
            $wreckField->status = 'active';
            $wreckField->ship_data = $shipData;
            $wreckField->save();

            return $wreckField;
        });
    }

    /**
     * Extend an existing wreck field with new ships.
     *
     * @param WreckField $wreckField
     * @param array $newShipData
     * @return void
     */
    public function extendWreckField(WreckField $wreckField, array $newShipData): void
    {
        $currentShipData = $wreckField->ship_data ?? [];

        // Merge new ship data with existing
        foreach ($newShipData as $newShip) {
            $found = false;
            foreach ($currentShipData as &$currentShip) {
                if ($currentShip['machine_name'] === $newShip['machine_name']) {
                    $currentShip['quantity'] += $newShip['quantity'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $currentShipData[] = $newShip;
            }
        }

        $wreckField->ship_data = $currentShipData;

        // Extend expiration time up to the maximum
        $newExpiresAt = $this->instant()->copy()->addHours($this->lifetimeHours());
        if ($newExpiresAt->greaterThan($wreckField->expires_at)) {
            $wreckField->expires_at = $newExpiresAt;
        }

        $wreckField->save();
    }

    /**
     * Extend an existing wreck field with new ships and reset expiration timer.
     * Used when a new wreck field is created at the same location and repairs haven't started.
     *
     * @param WreckField $wreckField
     * @param array $newShipData
     * @return void
     */
    public function extendWreckFieldWithReset(WreckField $wreckField, array $newShipData): void
    {
        $currentShipData = $wreckField->ship_data ?? [];

        // Merge new ship data with existing
        foreach ($newShipData as $newShip) {
            $found = false;
            foreach ($currentShipData as &$currentShip) {
                if ($currentShip['machine_name'] === $newShip['machine_name']) {
                    $currentShip['quantity'] += $newShip['quantity'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $currentShipData[] = $newShip;
            }
        }

        $wreckField->ship_data = $currentShipData;

        // Reset expiration timer to full duration
        $wreckField->expires_at = $this->instant()->copy()->addHours($this->lifetimeHours());
        $wreckField->created_at = $this->instant();

        $wreckField->save();
    }

    /**
     * Add ships to an ongoing repair job.
     * Used when a new wreck field is created at the same location while repairs are in progress.
     * The new ships are automatically added to the ongoing repairs without changing the repair completion time.
     *
     * IMPORTANT: Ships added during ongoing repairs are marked as 'late_added' and CANNOT be collected
     * via the "put partially finished repair back into service" button. Players must wait until ALL
     * ships (including late-added ones) are automatically put back into service.
     *
     * @param WreckField $wreckField
     * @param array $newShipData
     * @return void
     */
    public function addShipsToOngoingRepairs(WreckField $wreckField, array $newShipData): void
    {
        $currentShipData = $wreckField->ship_data ?? [];

        // Merge new ship data with existing
        foreach ($newShipData as $newShip) {
            $found = false;
            foreach ($currentShipData as &$currentShip) {
                if ($currentShip['machine_name'] === $newShip['machine_name']) {
                    $currentShip['quantity'] += $newShip['quantity'];
                    // Mark ships that are added to ongoing repairs as non-collectable
                    $currentShip['late_added'] = true;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                // Mark new ships as late_added
                $newShip['late_added'] = true;
                $currentShipData[] = $newShip;
            }
        }

        $wreckField->ship_data = $currentShipData;

        // Don't modify repair times - new ships are added to ongoing repairs
        // The new ships will be repaired at the same time as the existing ones

        $wreckField->save();
    }

    /**
     * Create a blocked wreck field when another wreck field is already being repaired.
     * The blocked wreck field can only be dismissed or start repairs when the first one completes.
     *
     * @param Coordinate $coordinate
     * @param array $shipData
     * @param int $ownerPlayerId
     * @return WreckField
     */
    public function createBlockedWreckField(Coordinate $coordinate, array $shipData, int $ownerPlayerId): WreckField
    {
        $wreckField = new WreckField();
        $wreckField->galaxy = $coordinate->galaxy;
        $wreckField->system = $coordinate->system;
        $wreckField->planet = $coordinate->position;
        $wreckField->owner_player_id = $ownerPlayerId;
        $wreckField->created_at = $this->instant();
        $wreckField->expires_at = $this->instant()->copy()->addHours($this->lifetimeHours());
        $wreckField->status = 'blocked';
        $wreckField->ship_data = $shipData;
        $wreckField->save();

        return $wreckField;
    }

    /**
     * Check if there's a wreck field currently being repaired at the given coordinates.
     *
     * @param Coordinate $coordinate
     * @param int $ownerPlayerId
     * @param int|null $excludeWreckFieldId
     * @return bool
     */
    public function hasRepairingWreckFieldAt(Coordinate $coordinate, int $ownerPlayerId, int|null $excludeWreckFieldId = null): bool
    {
        // **Une lecture verrouillante, pas une lecture coherente.** Sous `REPEATABLE READ`, une lecture ordinaire
        // rend la photographie prise a la premiere lecture de la transaction — qui, dans le deploiement
        // automatique, precede le verrou de la planete. La regle « une seule reparation a la fois » se lirait
        // alors sur un etat d'avant. Ici, la base rend ce qu'elle tient maintenant.
        $query = WreckField::where('galaxy', $coordinate->galaxy)
            ->where('system', $coordinate->system)
            ->where('planet', $coordinate->position)
            ->where('owner_player_id', $ownerPlayerId)
            ->where('status', 'repairing');

        if ($excludeWreckFieldId !== null) {
            $query->where('id', '!=', $excludeWreckFieldId);
        }

        return $query->lockForUpdate()->first() !== null;
    }

    /**
     * Unblock the next wreck field at the given coordinates (if any).
     * Called when a wreck field completes repairs or is burned.
     *
     * @param Coordinate $coordinate
     * @param int $ownerPlayerId
     * @return void
     */
    public function unblockNextWreckField(Coordinate $coordinate, int $ownerPlayerId): void
    {
        // Une epave bloquee attend la fin de la reparation en cours : tant qu'une autre epave de ce proprietaire est
        // en reparation ici, rien n'est libere — une seule reparation a la fois, et « bloquee » garde son sens. Les
        // etats du jeu n'atteignent pas ce cas d'eux-memes ; la garde tient pour tout appelant futur.
        if ($this->hasRepairingWreckFieldAt($coordinate, $ownerPlayerId)) {
            return;
        }

        // Find the oldest blocked wreck field and change it to active
        $blockedWreckField = WreckField::where('galaxy', $coordinate->galaxy)
            ->where('system', $coordinate->system)
            ->where('planet', $coordinate->position)
            ->where('owner_player_id', $ownerPlayerId)
            ->where('status', 'blocked')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->first();

        if ($blockedWreckField) {
            $blockedWreckField->status = 'active';
            $blockedWreckField->save();
        }
    }

    /**
     * Start repairs for the wreck field.
     *
     * @param int $spaceDockLevel
     * @return bool
     * @throws Exception
     */
    public function startRepairs(int $spaceDockLevel): bool
    {
        $this->wreckField = $this->decideUnderLock(function (WreckField $wreckField) use ($spaceDockLevel): void {
            if (!$wreckField->canBeRepaired()) {
                throw new Exception('Wreck field cannot be repaired');
            }

            if ($wreckField->getTotalShips() === 0) {
                throw new Exception('No ships to repair');
            }

            // Une seule reparation a la fois pour ce proprietaire a cette position — lue sous le verrou de la
            // planete, donc apres toute transition qui l'a precedee.
            if ($this->hasRepairingWreckFieldAt($this->coordinatesOf($wreckField), (int)$wreckField->owner_player_id, (int)$wreckField->id)) {
                throw new Exception('Another wreck field is already being repaired at this location');
            }

            // **Un seul instant pour les deux colonnes** : deux lectures de l'horloge separees par le calcul de la
            // duree ecrivaient parfois un minuteur d'une seconde de trop, et le temoin qui le compare ne peut pas
            // etre deterministe sur une valeur qui depend du temps d'execution.
            $maintenant = now();
            $wreckField->status = 'repairing';
            $wreckField->repair_started_at = $maintenant;
            $wreckField->space_dock_level = $spaceDockLevel;

            // Cap repair time between the configured minimum and maximum limits.
            $wreckField->repair_completed_at = $maintenant->copy()->addSeconds($this->calculateRepairDuration($wreckField->getTotalShips()));
            $wreckField->save();
        });

        return true;
    }

    /**
     * Calculate repair duration in seconds for the given ship count.
     *
     * Formula is based on ship count only; Space Dock level affects recovery %, not time.
     */
    private function calculateRepairDuration(int $shipCount): int
    {
        $minimumDuration = max(0, $this->settingsService->wreckFieldRepairMinMinutes()) * 60;
        $configuredMaxHours = $this->settingsService->wreckFieldRepairMaxHours();
        if ($configuredMaxHours <= 0) {
            $configuredMaxHours = 12;
        }

        $maximumDuration = max($minimumDuration, $configuredMaxHours * 3600);

        $calculatedDuration = (int) round(sqrt(max(0, $shipCount) * 30) * 10);

        return min($maximumDuration, max($minimumDuration, $calculatedDuration));
    }

    /**
     * Complete repairs and return the ship data.
     *
     * @return array
     * @throws Exception
     */
    public function completeRepairs(): array
    {
        $shipData = [];
        $this->wreckField = $this->decideUnderLock(function (WreckField $wreckField) use (&$shipData): void {
            if ($wreckField->status !== 'repairing') {
                throw new Exception('No repairs in progress');
            }

            $shipData = $wreckField->ship_data ?? [];

            // Mark all ships as repaired
            foreach ($shipData as &$ship) {
                $ship['repair_progress'] = 100;
            }
            unset($ship);

            $wreckField->ship_data = $shipData;
            $wreckField->status = 'completed';
            $wreckField->save();

            // Unblock the next wreck field at this location
            $this->unblockNextWreckField($this->coordinatesOf($wreckField), (int)$wreckField->owner_player_id);
        });

        return $shipData;
    }

    /**
     * Burn/destroy the wreck field.
     *
     * @return bool
     * @throws Exception
     */
    public function burnWreckField(): bool
    {
        $this->wreckField = $this->decideUnderLock(function (WreckField $wreckField): void {
            if (!$wreckField->canBeBurned()) {
                throw new Exception('Wreck field cannot be burned while repairs are in progress');
            }

            $wreckField->status = 'burned';
            $wreckField->save();

            // Unblock the next wreck field at this location
            $this->unblockNextWreckField($this->coordinatesOf($wreckField), (int)$wreckField->owner_player_id);
        });

        return true;
    }

    /**
     * Une transition de l'epave chargee, decidee sur l'etat relu sous verrou, dans une transaction.
     *
     * ## Pourquoi relire
     *
     * « Verifier puis sauver » sur le modele en memoire laissait deux commandes se croiser : une epave brulee revenait
     * en reparation, des vaisseaux en reparation etaient brules, un second demarrage remettait le minuteur a zero
     * (journal §183, six courses MariaDB). La decision se prend ici sur la ligne relue `FOR UPDATE`, apres le verrou
     * de la planete du corps — l'ordre du reglement d'une bataille, qui tient la planete avant d'ecrire l'epave.
     *
     * ## Ce qui est verifie avant toute decision
     *
     * L'epave existe encore et appartient au joueur de ce service : une commande du controleur agit sur l'identite
     * exacte du champ qu'elle a montre au joueur, jamais sur celle d'un autre proprietaire de la position.
     *
     * @param Closure(WreckField): void $decision
     * @throws Exception
     */
    private function decideUnderLock(Closure $decision): WreckField
    {
        if (!$this->wreckField) {
            throw new Exception('No wreck field loaded');
        }

        $wreckFieldId = (int)$this->wreckField->id;
        $coordinates = $this->coordinatesOf($this->wreckField);

        return DB::transaction(function () use ($wreckFieldId, $coordinates, $decision): WreckField {
            $this->lockThePlanetAt($coordinates);

            $wreckField = WreckField::query()->whereKey($wreckFieldId)->lockForUpdate()->first();
            if ($wreckField === null || (int)$wreckField->owner_player_id !== $this->playerService->getId()) {
                throw new Exception('Wreck field not found');
            }

            $decision($wreckField);

            return $wreckField;
        });
    }

    /**
     * Tient la planete de ces coordonnees jusqu'a la fin de la transaction.
     *
     * C'est la ligne que les commandes du joueur prennent en premier — demarrer, bruler, recuperer, et le
     * deploiement automatique — et c'est ce qui corrige le cycle reproduit au §183 : une recuperation qui prenait
     * l'epave puis la planete, contre un reglement qui tient ses corps puis ecrit l'epave. **Cela ne dit rien des
     * autres verrous du jeu** : d'autres chemins prennent d'autres lignes, et seule une course sur le bac etablit
     * qu'un ordre tient.
     *
     * Une position sans planete (abandonnee, pas encore recolonisee) n'a rien a tenir : aucune bataille ne peut plus
     * s'y produire, et aucune commande n'en part.
     */
    private function lockThePlanetAt(Coordinate $coordinates): void
    {
        Planet::query()
            ->where('galaxy', $coordinates->galaxy)
            ->where('system', $coordinates->system)
            ->where('planet', $coordinates->position)
            ->where('planet_type', PlanetType::Planet->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * L'identifiant de la planete de ces coordonnees, ou `null` si la position n'en porte pas.
     */
    private function planetIdAt(Coordinate $coordinates): int|null
    {
        $id = Planet::query()
            ->where('galaxy', $coordinates->galaxy)
            ->where('system', $coordinates->system)
            ->where('planet', $coordinates->position)
            ->where('planet_type', PlanetType::Planet->value)
            ->value('id');

        return $id === null ? null : (int)$id;
    }

    private function coordinatesOf(WreckField $wreckField): Coordinate
    {
        return new Coordinate((int)$wreckField->galaxy, (int)$wreckField->system, (int)$wreckField->planet);
    }

    /**
     * Save the wreck field to the database.
     *
     * @return void
     */
    public function save(): void
    {
        if ($this->wreckField) {
            $this->wreckField->save();
        }
    }

    /**
     * Delete the wreck field from the database.
     *
     * @return void
     */
    public function delete(): void
    {
        if ($this->wreckField && $this->wreckField->exists) {
            $this->wreckField->delete();
            $this->wreckField = null;
        }
    }

    /**
     * Get the wreck field model.
     *
     * @return WreckField|null
     */
    public function getWreckField(): WreckField|null
    {
        return $this->wreckField;
    }

    /**
     * Get ship data for the wreck field.
     *
     * @return array
     */
    public function getShipData(): array
    {
        return $this->wreckField->ship_data ?? [];
    }

    /**
     * Get the estimated repair completion time.
     *
     * @return Carbon|null
     */
    public function getRepairCompletionTime(): Carbon|null
    {
        return $this->wreckField?->repair_completed_at;
    }

    /**
     * Get remaining repair time in seconds.
     *
     * @return int
     */
    public function getRemainingRepairTime(): int
    {
        if (!$this->wreckField || !$this->wreckField->repair_completed_at) {
            return 0;
        }

        $remainingTime = (int) $this->wreckField->repair_completed_at->timestamp - (int) now()->timestamp;
        return max(0, (int) $remainingTime);
    }

    /**
     * Get repair progress percentage.
     *
     * @return int Repair progress percentage (0-100)
     */
    public function getRepairProgress(): int
    {
        if (!$this->wreckField) {
            return 0;
        }

        return $this->getTimeBasedRepairProgress($this->wreckField);
    }

    /**
     * Atomically collect all currently repaired ships for the primary wreck field on a planet.
     *
     * @param Coordinate $coordinate
     * @param int $planetId
     * @return array{success:true,error:false,message:string,collected_ships:array<int,array<string,mixed>>,remaining_ships:array<int,array<string,mixed>>}|array{success:false,error:true,message:string}
     */
    public function collectRepairedShipsAtomic(Coordinate $coordinate, int $planetId): array
    {
        return DB::transaction(function () use ($coordinate, $planetId) {
            // Les corps d'abord, l'epave ensuite : l'ordre du reglement d'une bataille, qui tient les planetes avant
            // d'ecrire l'epave. L'ordre inverse formait un cycle avec lui, et MariaDB tuait l'un des deux
            // (journal §183, course de l'interblocage).
            //
            // **Deux lignes peuvent etre en jeu, et elles se prennent par identifiant croissant** — la regle du
            // reglement. Le corps credite est le corps courant du joueur : depuis une lune, ce n'est pas la planete
            // de la position, qui est pourtant le point de serialisation de ses epaves. Ne tenir que le corps
            // credite laissait deux commandes se croiser sur la meme epave sans jamais se rencontrer.
            $corps = array_values(array_unique(array_filter([$planetId, $this->planetIdAt($coordinate)])));
            sort($corps);

            $lignes = Planet::query()
                ->whereIn('id', $corps)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedPlanet = $lignes->get($planetId);
            if (!$lockedPlanet instanceof Planet) {
                throw (new ModelNotFoundException())->setModel(Planet::class, [$planetId]);
            }

            $wreckField = $this->lockCollectibleWreckField($coordinate);

            if (!$wreckField) {
                return $this->buildMissingCollectibleWreckFieldResult($coordinate);
            }

            $currentShipData = $wreckField->ship_data ?? [];
            if ($this->hasLateAddedShips($currentShipData)) {
                return $this->buildLateAddedShipsResult($currentShipData);
            }

            $collectionResult = $this->collectShipsFromWreckField($wreckField, $lockedPlanet);

            // Une recuperation finale — plus rien ne reste, l'epave est effacee — libere l'epave suivante, dans cette
            // transaction ; une recuperation partielle ne libere rien.
            if ($collectionResult['remaining_ships'] === []) {
                $this->unblockNextWreckField($coordinate, $this->playerService->getId());
            }

            return [
                'success' => true,
                'error' => false,
                'message' => count($collectionResult['collected_ships']) > 0 ? __('wreck_field.all_ships_deployed') : __('wreck_field.no_ships_ready'),
                'collected_ships' => $collectionResult['collected_ships'],
                'remaining_ships' => $collectionResult['remaining_ships'],
            ];
        });
    }

    /**
     * Atomically auto-deploy overdue repaired ships for a wreck field.
     *
     * @param int $wreckFieldId
     * @return array<string,mixed>|false
     */
    public function autoDeployWreckFieldAtomic(int $wreckFieldId): array|false
    {
        return DB::transaction(function () use ($wreckFieldId) {
            // Lue d'abord sans verrou pour connaitre sa position, puis la planete tenue, puis l'epave relue sous
            // verrou : l'ordre du reglement d'une bataille (journal §183).
            $located = WreckField::query()->whereKey($wreckFieldId)->first();

            if ($located === null || !in_array($located->status, ['repairing', 'completed'], true)) {
                return false;
            }

            $lockedPlanet = Planet::where('user_id', $located->owner_player_id)
                ->where('galaxy', $located->galaxy)
                ->where('system', $located->system)
                ->where('planet', $located->planet)
                ->where('planet_type', PlanetType::Planet->value)
                ->lockForUpdate()
                ->first();

            if (!$lockedPlanet) {
                throw new Exception("Could not find planet for wreck field at {$located->galaxy}:{$located->system}:{$located->planet}");
            }

            $wreckField = WreckField::whereKey($wreckFieldId)
                ->lockForUpdate()
                ->first();

            if ($wreckField === null || !in_array($wreckField->status, ['repairing', 'completed'], true)) {
                return false;
            }

            $totalDeployed = $this->deployShipsToPlanetWithProgress($wreckField, $lockedPlanet);
            $planetId = $lockedPlanet->id;
            $ownerPlayerId = (int)$wreckField->owner_player_id;
            $coordinates = $this->coordinatesOf($wreckField);
            $wreckField->delete();

            // Le deploiement automatique termine la reparation : l'epave suivante est liberee ici, dans la meme
            // transaction que l'effacement.
            $this->unblockNextWreckField($coordinates, $ownerPlayerId);

            return [
                'total_deployed' => $totalDeployed,
                'planet_id' => $planetId,
                'owner_player_id' => $ownerPlayerId,
            ];
        });
    }

    /**
     * Get the percentage of destroyed ships that become repairable wreckage.
     */
    public function getMaxRecoverablePercentage(): float
    {
        $spaceDockLevel = $this->wreckField->space_dock_level ?? 1;
        return $this->getRecoverableWreckFieldPercentage($spaceDockLevel, $this->wreckFieldPlanetId());
    }

    /**
     * Lock the primary collectible wreck field for a planet.
     */
    private function lockCollectibleWreckField(Coordinate $coordinate): WreckField|null
    {
        return WreckField::where('galaxy', $coordinate->galaxy)
            ->where('system', $coordinate->system)
            ->where('planet', $coordinate->position)
            ->where('owner_player_id', $this->playerService->getId())
            ->whereIn('status', ['repairing', 'completed'])
            ->orderByRaw("CASE status WHEN 'repairing' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Build the correct response when no collectible wreck field is available.
     *
     * @param Coordinate $coordinate
     * @return array{success:false,error:true,message:string}
     */
    private function buildMissingCollectibleWreckFieldResult(Coordinate $coordinate): array
    {
        $hasAnyWreckField = WreckField::where('galaxy', $coordinate->galaxy)
            ->where('system', $coordinate->system)
            ->where('planet', $coordinate->position)
            ->where('owner_player_id', $this->playerService->getId())
            ->exists();

        if ($hasAnyWreckField) {
            return [
                'success' => false,
                'error' => true,
                'message' => __('wreck_field.repairs_not_started'),
            ];
        }

        return [
            'success' => false,
            'error' => true,
            'message' => __('wreck_field.error_no_wreck_field'),
        ];
    }

    /**
     * Determine if a ship payload contains late-added ships.
     *
     * @param array<int,array<string,mixed>> $shipData
     */
    private function hasLateAddedShips(array $shipData): bool
    {
        foreach ($shipData as $ship) {
            if (($ship['late_added'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the response for manual collection when late-added ships block collection.
     *
     * @param array<int,array<string,mixed>> $shipData
     * @return array{success:true,error:false,message:string,collected_ships:array<int,array<string,mixed>>,remaining_ships:array<int,array<string,mixed>>}
     */
    private function buildLateAddedShipsResult(array $shipData): array
    {
        return [
            'success' => true,
            'error' => false,
            'message' => '',
            'collected_ships' => [],
            'remaining_ships' => $shipData,
        ];
    }

    /**
     * Claim currently repaired ships from a wreck field and apply them to a locked planet row.
     *
     * @return array{collected_ships:array<int,array<string,mixed>>,remaining_ships:array<int,array<string,mixed>>}
     */
    private function collectShipsFromWreckField(WreckField $wreckField, Planet $planet): array
    {
        $overallProgress = $this->getTimeBasedRepairProgress($wreckField) / 100;
        $currentShipData = $wreckField->ship_data ?? [];
        $collectedShips = [];
        $remainingShips = [];
        $planetChanged = false;
        $objectService = app(ObjectService::class);
        $isCompletedWreckField = $wreckField->status === 'completed';

        foreach ($currentShipData as $ship) {
            $repairedCount = (int) floor($ship['quantity'] * $overallProgress);
            $remainingCount = $isCompletedWreckField ? 0 : $ship['quantity'] - $repairedCount;

            if ($repairedCount > 0) {
                $collectedShips[] = [
                    'machine_name' => $ship['machine_name'],
                    'quantity' => $repairedCount,
                    'repair_progress' => 100,
                ];

                $unitObject = $objectService->getUnitObjectByMachineName($ship['machine_name']);
                if ($unitObject) {
                    $this->addUnitToLockedPlanet($planet, $unitObject->machine_name, $repairedCount);
                    $planetChanged = true;
                }
            }

            if ($remainingCount > 0) {
                $remainingShips[] = [
                    'machine_name' => $ship['machine_name'],
                    'quantity' => $remainingCount,
                    'repair_progress' => 0,
                ];
            }
        }

        if ($planetChanged) {
            $planet->save();
        }

        if (empty($remainingShips)) {
            $wreckField->delete();
        } else {
            $wreckField->ship_data = $remainingShips;
            $wreckField->save();
        }

        return [
            'collected_ships' => $collectedShips,
            'remaining_ships' => $remainingShips,
        ];
    }

    /**
     * Deploy repaired ships to a locked planet model based on repair progress.
     */
    private function deployShipsToPlanetWithProgress(WreckField $wreckField, Planet $planet): int
    {
        $overallProgress = $this->getTimeBasedRepairProgress($wreckField) / 100;
        $objectService = app(ObjectService::class);
        $totalDeployed = 0;

        foreach ($wreckField->getShipData() as $ship) {
            $repairedCount = (int) floor($ship['quantity'] * $overallProgress);
            if ($repairedCount <= 0) {
                continue;
            }

            $unitObject = $objectService->getUnitObjectByMachineName($ship['machine_name']);
            if ($unitObject) {
                $this->addUnitToLockedPlanet($planet, $unitObject->machine_name, $repairedCount);
                $totalDeployed += $repairedCount;
            }
        }

        if ($totalDeployed > 0) {
            $planet->save();
        }

        return $totalDeployed;
    }

    /**
     * Get the percentage of destroyed ships that become repairable wreckage for a Space Dock level.
     */
    public function getRecoverableWreckFieldPercentage(int $spaceDockLevel, int|null $planetId = null, float|null $frozenLifeformBonus = null): float
    {
        $nonDebrisShare = max(0.0, 100.0 - $this->debrisFieldFromShips());
        $normalizedLevel = max(1, min(15, $spaceDockLevel));
        $multiplier = self::SPACE_DOCK_WRECKAGE_MULTIPLIERS[$normalizedLevel] ?? self::SPACE_DOCK_WRECKAGE_MULTIPLIERS[1];
        $part = round($nonDebrisShare * $multiplier, 1);

        // Formes de vie : les Nano-robots de reparation de la planete (plafonnes a 50 %) rendent plus d epaves
        // reparables, jamais plus de 100 % (journal §155.5).
        // Un combat durable passe la part photographiee a l ouverture ; sans elle, la planete est lue vivante.
        $bonus = $frozenLifeformBonus;
        if ($bonus === null && $planetId !== null) {
            $bonus = app(LifeformBonusResolver::class)->forPlanet($planetId)->fraction(LifeformEffect::WRECK_RECOVERY);
        }
        if ($bonus !== null && $bonus > 0) {
            $part = round(min(100.0, $part * (1 + $bonus)), 1);
        }

        return $part;
    }

    /**
     * La planete d un champ d epaves : celle qui porte ses coordonnees, ou rien.
     */
    private function wreckFieldPlanetId(): int|null
    {
        if ($this->wreckField === null) {
            return null;
        }
        $id = Planet::query()
            ->where('galaxy', (int)$this->wreckField->galaxy)
            ->where('system', (int)$this->wreckField->system)
            ->where('planet', (int)$this->wreckField->planet)
            ->where('planet_type', PlanetType::Planet->value)
            ->value('id');

        return is_int($id) ? $id : null;
    }

    /**
     * Apply unit gains to a planet row that is already locked inside the current transaction.
     *
     * We intentionally mutate the locked model directly instead of going through PlanetService::addUnit()
     * so that both the read and write happen against the same locked row instance.
     */
    private function addUnitToLockedPlanet(Planet $planet, string $machineName, int $amount): void
    {
        $planet->{$machineName} += $amount;
    }

    /**
     * Calculate time-based repair progress without the Space Dock percentage cap.
     */
    private function getTimeBasedRepairProgress(WreckField $wreckField): int
    {
        if ($wreckField->status === 'completed') {
            return 100;
        }

        if (!$wreckField->repair_started_at || !$wreckField->repair_completed_at) {
            return 0;
        }

        $totalTime = (int) $wreckField->repair_completed_at->timestamp - (int) $wreckField->repair_started_at->timestamp;
        if ($totalTime <= 0) {
            return now()->greaterThanOrEqualTo($wreckField->repair_completed_at) ? 100 : 0;
        }

        $elapsedTime = (int) now()->timestamp - (int) $wreckField->repair_started_at->timestamp;

        return min(100, max(0, (int) (($elapsedTime / $totalTime) * 100)));
    }

    /**
     * Get wreck field data for the current planet.
     * Returns the "primary" wreck field (active or repairing) for backward compatibility.
     *
     * @param PlanetService $planetService
     * @return array|null
     */
    public function getWreckFieldForCurrentPlanet(PlanetService $planetService): array|null
    {
        $wreckFields = $this->getAllWreckFieldsForCurrentPlanet($planetService);

        if (empty($wreckFields)) {
            return null;
        }

        // Return the first (primary) wreck field for backward compatibility
        return $wreckFields[0];
    }

    /**
     * Get all wreck fields for the current planet (including blocked ones).
     * Returns an array of wreck field data arrays, ordered by priority:
     * 1. Active or repairing wreck fields (primary)
     * 2. Blocked wreck fields (queued)
     * 3. Completed wreck fields (for collection)
     *
     * @param PlanetService $planetService
     * @return array
     */
    public function getAllWreckFieldsForCurrentPlanet(PlanetService $planetService): array
    {
        $coordinates = $planetService->getPlanetCoordinates();

        $wreckFields = WreckField::where('galaxy', $coordinates->galaxy)
            ->where('system', $coordinates->system)
            ->where('planet', $coordinates->position)
            ->where('owner_player_id', $this->playerService->getId())
            ->whereIn('status', ['active', 'repairing', 'blocked', 'completed'])
            ->orderByRaw("FIELD(status, 'repairing', 'active', 'blocked', 'completed')")
            ->orderBy('created_at', 'asc')
            ->get();

        $result = [];

        foreach ($wreckFields as $wreckField) {
            if ($wreckField->isExpired()) {
                continue;
            }

            // Get ship information without unit objects for now
            $shipData = [];

            foreach ($wreckField->getShipData() as $ship) {
                $shipData[] = [
                    'machine_name' => $ship['machine_name'],
                    'quantity' => $ship['quantity'],
                    'repair_progress' => $ship['repair_progress'] ?? 0,
                    'unit_object' => null, // TODO: Implement proper unit object creation
                ];
            }

            $timeRemaining = $wreckField->getTimeRemaining();

            // Calculate total repair time (in seconds)
            $totalRepairTime = 0;
            if ($wreckField->repair_started_at && $wreckField->getRepairCompletionTime()) {
                $totalRepairTime = (int) $wreckField->getRepairCompletionTime()->timestamp - (int) $wreckField->repair_started_at->timestamp;
            }

            // Temporarily load this wreck field to get its max recoverable percentage
            $previousWreckField = $this->wreckField;
            $this->wreckField = $wreckField;
            $maxRecoverablePercentage = $this->getMaxRecoverablePercentage();
            $this->wreckField = $previousWreckField;

            $result[] = [
                'id' => (int)$wreckField->id,
                'wreck_field' => $wreckField,
                'ship_data' => $shipData,
                'time_remaining' => $timeRemaining,
                'can_repair' => $wreckField->canBeRepaired(),
                'is_repairing' => $wreckField->isRepairing(),
                'is_blocked' => $wreckField->isBlocked(),
                'is_completed' => $wreckField->isCompleted(),
                'repair_progress' => $wreckField->getRepairProgress(),
                'max_recoverable_percentage' => $maxRecoverablePercentage,
                'space_dock_level' => $wreckField->space_dock_level ?? 1,
                'repair_completion_time' => $wreckField->getRepairCompletionTime(),
                'repair_started_at' => $wreckField->repair_started_at,
                'total_repair_time' => $totalRepairTime,
                'remaining_repair_time' => $wreckField->getRepairCompletionTime() ?
                    max(0, (int) $wreckField->getRepairCompletionTime()->timestamp - (int) now()->timestamp) : 0,
            ];
        }

        return $result;
    }
}
