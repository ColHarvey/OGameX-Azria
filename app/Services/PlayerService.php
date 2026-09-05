<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Decisions\CombatSituation;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Exceptions\MovementLocksOutdated;
use OGame\Combat\Services\AccountCombatWithdrawal;
use OGame\Combat\Services\CombatEffectLedger;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Enums\AccountDeletionState;
use OGame\GameObjects\Models\Calculations\CalculationType;
use OGame\Models\BuildingQueue;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Models\User;
use OGame\Models\UserTech;
use RuntimeException;
use Throwable;

/**
 * Class PlayerService.
 *
 * Player object.
 *
 * @package OGame\Services
 */
class PlayerService
{
    /**
     * The planet list object for this player.
     *
     * @var PlanetListService
     */
    public PlanetListService $planets;

    /**
     * The user object from the model of this player.
     *
     * @var User
     */
    private User $user;

    /**
     * The user tech object from the model of this player.
     *
     * @var UserTech
     */
    private UserTech $user_tech;

    /**
     * Private local cached general score for this player.
     *
     * @var int|null
     */
    private int|null $cachedGeneralScore = null;

    /**
     * Player constructor.
     *
     * @param int $player_id
     */
    public function __construct(int $player_id = 0)
    {
        // Load the player object if a positive player ID is given.
        if ($player_id !== 0) {
            $this->load($player_id);
        } else {
            // If no player ID is given then an actual player context will not be available.
            // This is expected for unittests, that's why we create a dummy user object here.
            $this->user = new User();
            $this->user->id = 0;
            $this->planets = resolve(PlanetListService::class, ['player' => $this]);
        }
    }

    /**
     * Checks if this object is equal to another object.
     *
     * @param PlayerService|null $other
     * @return bool
     */
    public function equals(PlayerService|null $other): bool
    {
        return $other !== null && $this->getId() === $other->getId();
    }

    /**
     * Load player object by user ID.
     *
     * @param int $id
     */
    public function load(int $id): void
    {
        // Fetch user from model
        $user = User::with('highscore')->where('id', $id)->first();
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $this->user = $user;

        // Fetch user tech from model
        /** @var UserTech $tech */
        $tech = $this->user->tech()->first();
        if (!$tech) {
            $tech = new UserTech();
            $tech->user_id = $user->id;
            $tech->save();
        }
        $this->setUserTech($tech);

        // Fetch all planets of user
        $planet_list_service = resolve(PlanetListService::class, ['player' => $this]);
        $this->planets = $planet_list_service;
    }

    /**
     * Checks is the supplied password is valid for this user. This method is used as
     * a security measure for critical operations like abandoning a planet.
     *
     * @param string $password
     * @return bool
     */
    public function isPasswordValid(string $password): bool
    {
        return Auth::attempt(['email' => $this->getEmail(), 'password' => $password]);
    }

    /**
     * Set user tech object.
     *
     * @param UserTech $userTech
     * @return void
     */
    public function setUserTech(UserTech $userTech): void
    {
        $this->user_tech = $userTech;
    }

    /**
     * Get current player ID.
     */
    public function getId(): int
    {
        return $this->user->id;
    }

    /**
     * Get the user model instance.
     *
     * @return User
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Reload the user model from the database.
     *
     * @return void
     */
    public function refreshUser(): void
    {
        $user = User::where('id', $this->user->id)->first();
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $this->user = $user;
    }

    /**
     * Reload every piece of state this service keeps in memory.
     *
     * refreshUser() ne recharge que le modele User. Or ce service garde aussi les niveaux de
     * recherche, qui vivent dans une table distincte, et un score general mis en cache. Le
     * moteur de combat lit les technologies d'arme, de bouclier et de blindage a chaque
     * bataille : sans ce rechargement, un combat traite plus tard dans la meme instance
     * applicative utilise des niveaux perimes, ce qui change l'issue du combat.
     *
     * La liste des planetes n'est volontairement pas rechargee : PlanetServiceFactory
     * recharge deja la planete concernee, et repasser par PlanetListService depuis la
     * fabrique creerait une recursion.
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->refreshUser();

        $tech = $this->user->tech()->first();
        if ($tech instanceof UserTech) {
            $this->setUserTech($tech);
        }

        // Le score se recalcule paresseusement au prochain acces : inutile d'interroger la
        // base maintenant si personne ne le demande.
        $this->cachedGeneralScore = null;
    }

    /**
     * Saves current player object to DB.
     */
    public function save(): void
    {
        $this->user->save();
    }

    /**
     * Checks if the player is an admin.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->user->hasRole('admin');
    }

    /**
     * Checks if the player is currently banned.
     *
     * @return bool
     */
    public function isBanned(): bool
    {
        return $this->user->isBanned();
    }

    /**
     * Checks if the player is inactive.
     *
     * @return bool
     */
    public function isInactive(): bool
    {
        $lastActivity = Date::createFromTimestamp((int)$this->user->time);

        // If the player has not logged in in the last 7 days, then they are considered inactive.
        if ($lastActivity->diffInDays(now()) >= 7) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the player is long inactive.
     *
     * @return bool
     */
    public function isLongInactive(): bool
    {
        $lastActivity = Date::createFromTimestamp((int)$this->user->time);

        // If the player has not logged in in the last 28 days, then they are considered long inactive.
        if ($lastActivity->diffInDays(now()) >= 28) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the player is a newbie.
     *
     * @param PlayerService $comparedTo
     * @return bool
     */
    public function isNewbie(PlayerService $comparedTo): bool
    {
        // Sanity check: if player is inactive, then they cannot have the newbie status.
        if ($this->isInactive()) {
            return false;
        }

        $currentPlayerPoints = $this->getCachedGeneralScore();
        $comparedToPoints = $comparedTo->getCachedGeneralScore();

        // If the current player has less than 20% of points compared to the provided player, then they are considered weak / newbie.
        if ($currentPlayerPoints < ($comparedToPoints * 0.2)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the player is strong.
     *
     * @param PlayerService $comparedTo
     * @return bool
     */
    public function isStrong(PlayerService $comparedTo): bool
    {
        // Sanity check: if player is inactive, then they cannot have the newbie status.
        if ($this->isInactive()) {
            return false;
        }

        $currentPlayerPoints = $this->getCachedGeneralScore();
        $comparedToPoints = $comparedTo->getCachedGeneralScore();

        // If the current player has more than 500% of points compared to the provided player, then they are considered strong.
        if ($currentPlayerPoints > ($comparedToPoints * 5)) {
            return true;
        }

        return false;
    }

    /**
     * Set username property.
     *
     * @param string $username
     */
    public function setUsername(string $username): void
    {
        $this->user->username = $username;
        $this->user->username_updated_at = now();
    }

    /**
     * Validates a username.
     *
     * @param string $username
     * @return false|int
     */
    public function validateUsername(string $username): false|int
    {
        if (strlen($username) < 3) {
            return false;
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9\s]*(?:_[A-Za-z0-9\s]+)*$/', $username);
    }

    /**
     * Validates if a username is already taken.
     *
     * @param string $username
     * @return bool
     */
    public function isUsernameAlreadyTaken(string $username): bool
    {
        return User::where('username', $username)->exists();
    }

    /**
     * Validates a username.
     *
     * @param string $username
     * @return array<string, mixed>
     */
    public function isUsernameValid(string $username): array
    {
        if (!$this->validateUsername($username)) {
            return [
                'valid' => false,
                'error' => __('Nickname :username contains invalid characters or your nickname has an invalid length!', ['username' => $username])
            ];
        }

        if ($this->isUsernameAlreadyTaken($username)) {
            return [
                'valid' => false,
                'error' => __('Player name already in use or invalid.')
            ];
        }

        return [
            'valid' => true,
            'error' => null
        ];
    }

    /**
     * Get the user's username.
     *
     * @param bool $formatted
     * @return string
     */
    public function getUsername(bool $formatted = true): string
    {
        if ($formatted && $this->isAdmin()) {
            return '<span class="status_abbr_admin">' . $this->user->username . '</span>';
        }
        return $this->user->username;
    }

    /**
     * Get the timestamp of the latest username change.
     *
     * @return Carbon|null
     */
    public function getLastUsernameChange(): Carbon|null
    {
        return $this->user->username_updated_at ? Date::parse($this->user->username_updated_at) : null;
    }

    /**
     * Set email address.
     *
     * @param string $email
     */
    public function setEmail(string $email): void
    {
        $this->user->email = $email;
    }

    /**
     * Validates whether input matches current users password.
     *
     * @param string $password
     * @return bool
     */
    public function validatePassword(string $password): bool
    {
        if (Auth::attempt(['email' => $this->getEmail(), 'password' => $password])) {
            return true;
        }

        return false;
    }

    /**
     * Get email address.
     *
     * @return string
     */
    public function getEmail(): string
    {
        return $this->user->email;
    }

    /**
     * Get espionage probes amount preference.
     *
     * @return int|null
     */
    public function getEspionageProbesAmount(): int|null
    {
        return $this->user->espionage_probes_amount;
    }

    /**
     * Set espionage probes amount preference.
     *
     * @param int|null $amount
     */
    public function setEspionageProbesAmount(int|null $amount): void
    {
        $this->user->espionage_probes_amount = $amount;
    }

    /**
     * Gets the level of a research technology for this player.
     *
     * @param string $machine_name
     * @return int
     */
    public function getResearchLevel(string $machine_name): int
    {
        $research = ObjectService::getResearchObjectByMachineName($machine_name);

        $research_level = $this->user_tech->{$research->machine_name} ?? 0;
        if ($research_level) {
            return $research_level;
        } else {
            return 0;
        }
    }

    /**
     * Set the level of a research technology for this player.
     *
     * @param string $machine_name
     * @param int $level
     * @param bool $save_to_db
     * @return void
     */
    public function setResearchLevel(string $machine_name, int $level, bool $save_to_db = true): void
    {
        $research = ObjectService::getResearchObjectByMachineName($machine_name);
        $this->user_tech->{$research->machine_name} = $level;

        if ($save_to_db) {
            $this->user_tech->save();
        }
    }

    /**
     * Calculate the maximum range for interplanetary missiles based on Impulse Drive research level.
     *
     * Formula: (impulse_drive_level × 5) - 1
     *
     * Examples:
     *   - Level 0: 0 systems (no Impulse Drive = no missiles)
     *   - Level 1: 4 systems
     *   - Level 2: 9 systems
     *   - Level 5: 24 systems
     *   - Level 10: 49 systems
     *
     * @return int Maximum range in systems within same galaxy
     */
    public function getMissileRange(): int
    {
        $impulseDriveLevel = $this->getResearchLevel('impulse_drive');

        // If no Impulse Drive research, missiles cannot be launched
        if ($impulseDriveLevel === 0) {
            return 0;
        }

        // Calculate range: (level × 5) - 1
        return ($impulseDriveLevel * 5) - 1;
    }

    /**
     * Get planet ID that player has currently selected / is looking at.
     *
     * @return int
     */
    public function getCurrentPlanetId(): int
    {
        if (!$this->user->planet_current) {
            // If no current planet is set, return the first planet of the player.
            $firstPlanet = $this->planets->first();
            if ($firstPlanet === null) {
                throw new RuntimeException('Player has no planets.');
            }

            return $firstPlanet->getPlanetId();
        }

        return $this->user->planet_current;
    }

    /**
     * Set current planet ID (update).
     *
     * @param int $planet_id
     */
    public function setCurrentPlanetId(int $planet_id): void
    {
        // Check if user owns this planet ID.
        // Planet ID 0 is always valid as that will be updated to the first planet of the player.
        if ($planet_id == 0) {
            $this->user->planet_current = null;
            $this->user->save();
            return;
        } elseif ($this->planets->planetExistsAndOwnedByPlayer($planet_id)) {
            $this->user->planet_current = $planet_id;
            $this->user->save();
        }
    }

    /**
     * Get the amount of fleet slots that the player is currently using.
     *
     * This corresponds to the amount of fleet missions that are currently active for this player.
     *
     * @return int
     */
    public function getFleetSlotsInUse(): int
    {
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this]);
        $activeMissions = $fleetMissionService->getActiveFleetMissionsSentByCurrentPlayer();

        // Exclude missile attacks (type 10) as they don't use fleet slots
        // All other missions use fleet slots for their entire duration (travel + hold + return)
        $fleetMissions = $activeMissions->filter(function ($mission) {
            // Exclude missile attacks
            if ($mission->mission_type === 10) {
                return false;
            }

            return true;
        });

        return $fleetMissions->count();
    }

    /**
     * Get the (maximum) amount of fleet slots that the player has available.
     *
     * This is calculated based on the player's research level and optional bonuses that may apply.
     *
     * @return int
     */
    public function getFleetSlotsMax(): int
    {
        // Calculate max fleet slots based on the user's computer research level.
        $object = ObjectService::getResearchObjectByMachineName('computer_technology');
        $fleet_slots_from_research = $object->performCalculation(CalculationType::MAX_FLEET_SLOTS, $this->getResearchLevel('computer_technology'));

        // Add General class bonus (+2 fleet slots)
        $characterClassService = app(CharacterClassService::class);
        $user = $this->getUser();
        $fleet_slots_bonus = $characterClassService->getAdditionalFleetSlots($user);

        // Amiral : +2 emplacements de flotte.
        $officer_bonus = $this->hasAdmiral() ? 2 : 0;

        return $fleet_slots_from_research + $fleet_slots_bonus + $officer_bonus;
    }

    /**
     * Get the amount of expedition slots that the player is currently using.
     *
     * This corresponds to the amount of expedition missions that are currently active for this player.
     *
     * @return int
     */
    public function getExpeditionSlotsInUse(): int
    {
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this]);
        $activeMissions = $fleetMissionService->getActiveFleetMissionsSentByCurrentPlayer();

        // Count only missions that are of type 15 (expedition)
        $expeditionMissions = $activeMissions->filter(function ($mission) {
            return $mission->mission_type === 15;
        });

        return $expeditionMissions->count();
    }

    /**
     * Get the (maximum) amount of expedition slots that the player has available.
     *
     * This is calculated based on the player's research level and optional bonuses that may apply.
     *
     * @return int
     */
    public function getExpeditionSlotsMax(): int
    {
        // Calculate max expedition slots based on the user's astrophysics research level.
        $object = ObjectService::getResearchObjectByMachineName('astrophysics');
        $expedition_slots_from_research = $object->performCalculation(CalculationType::MAX_EXPEDITION_SLOTS, $this->getResearchLevel('astrophysics'));

        // Add bonus expedition slots from settings
        $settingsService = app(SettingsService::class);
        $bonus_slots = $settingsService->bonusExpeditionSlots();

        // Add Discoverer class bonus (+2 expedition slots)
        $characterClassService = app(CharacterClassService::class);
        $user = $this->getUser();
        $expedition_slots_bonus = $characterClassService->getExpeditionSlotsBonus($user);

        // Amiral : +1 expedition simultanee.
        $officer_bonus = $this->hasAdmiral() ? 1 : 0;

        return $expedition_slots_from_research + $bonus_slots + $expedition_slots_bonus + $officer_bonus;
    }

    /**
     * Update the player entity.
     *
     * This method is called every time the player logs in.
     * It updates the player's last IP and time properties.
     * It also updates the research queue.
     *
     * @return void
     * @throws Throwable
     */
    public function update(): void
    {
        DB::transaction(function () {
            // Attempt to acquire a lock on the row for this user. This is to prevent
            // race conditions when multiple requests are updating the same user and
            // potentially doing double insertions or overwriting each other's changes.
            $playerLock = User::where('id', $this->getId())
                ->lockForUpdate()
                ->first();

            if ($playerLock) {
                // ------
                // 1. Update research queue
                // ------
                $this->updateResearchQueue(false);

                // ------
                // 2. Update last_ip and time properties.
                // ------
                $this->user->time = (string)Date::now()->timestamp;
                $this->user->last_ip = request()->ip();

                // Pays du joueur fourni par Cloudflare (en-tete CF-IPCountry).
                $cfCountry = request()->header('CF-IPCountry');
                if ($cfCountry && preg_match('/^[A-Za-z]{2}$/', $cfCountry) && strtoupper($cfCountry) !== 'XX') {
                    $this->user->country = strtolower($cfCountry);
                }

                $this->user->save();
            } else {
                throw new Exception('Could not acquire player update lock.');
            }
        });
    }

    /**
     * Update the research queue for this player.
     *
     * @param bool $save_user
     *   Optional flag whether to save the user in this method. This defaults to TRUE
     *   but can be set to FALSE when update happens in bulk and the caller method calls
     *   the save user itself to prevent on unnecessary multiple updates.
     *
     * @return void
     * @throws Exception
     */
    public function updateResearchQueue(bool $save_user = true): void
    {
        // Skip research queue processing if player is in vacation mode
        if ($this->isInVacationMode()) {
            return;
        }

        $queue = resolve(ResearchQueueService::class);
        $research_queue = $queue->retrieveFinishedForUser($this);

        // @TODO: add DB transaction wrapper
        foreach ($research_queue as $item) {
            // Get object information of research object.
            $object = ObjectService::getResearchObjectById($item->object_id);

            // Update planet and update level of the building that has been processed.
            $this->setResearchLevel($object->machine_name, $item->object_level_target);

            // Update build queue record
            $item->processed = 1;
            $item->save();

            // Build the next item in queue (if there is any)
            $queue->start($this, $item->time_end);
        }

        if ($save_user) {
            $this->user->save();
        }
    }

    /**
     * @throws Throwable
     */
    public function updateFleetMissions(): void
    {
        $planetIds = $this->planets->allIds();
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this]);

        // **Les missions que le combat gouverne entrent par la porte, et la porte possede sa
        // transaction.** Le traitement de page verrouillait d'abord les planetes du joueur, puis
        // chaque mission, puis appelait la porte — dont l'ordre global commence par la barriere,
        // l'instance et l'union avant la mission. Deux sens de rotation : une fermeture ou une
        // annulation pouvait tenir la barriere et attendre la mission pendant que la page tenait la
        // mission et attendait la barriere. Ces missions sont donc traitees **hors de toute
        // transaction de page**, chacune dans la transaction racine que la porte ouvre, et le
        // double traitement est ferme par la relecture sous verrou — pas par un verrou pris avant.
        $dues = $fleetMissionService->getArrivedMissionsByPlanetIds($planetIds);
        $gouverneesParLeCombat = [];
        $autres = [];

        foreach ($dues as $mission) {
            if ($this->isGovernedByTheCombatGate($mission)) {
                $gouverneesParLeCombat[] = $mission;
            } else {
                $autres[] = $mission;
            }
        }

        foreach ($gouverneesParLeCombat as $mission) {
            try {
                if ($this->gatesItself($mission)) {
                    $fleetMissionService->updateMission($mission);
                } else {
                    // **Par la porte, et par elle seule.** Une arrivee que la fermeture peut appliquer
                    // elle-meme — un transport, un deploiement — n'est livree qu'une fois : la porte
                    // attend la barriere que la fermeture tient, relit la mission sous verrou, et le
                    // gestionnaire trouve `processed` deja pose.
                    resolve(FleetMovementGate::class)->decideUnderLock($mission, static function (FleetMission $tenue) use ($fleetMissionService): void {
                        // **Sous une barriere ouverte, l'effet est mesure et inscrit au registre.** La
                        // fermeture ne rejouera pas ce que le monde a deja livre — un gestionnaire
                        // idempotent rejoue a vide — et lira ce que l'effet a reellement change.
                        resolve(CombatEffectLedger::class)->applyUnderAnOpenBarrier($tenue, static function () use ($fleetMissionService, $tenue): void {
                            $fleetMissionService->updateMission($tenue);
                        });
                    });
                }
            } catch (MovementLocksOutdated $perime) {
                // **Un lien de cette mission a change plus vite que la porte ne le rattrape.** La
                // porte a relache sa transaction et l'a dit au journal. Faire tomber la page serait
                // la reponse instable ; la mission est laissee au passage suivant — la prochaine
                // page, le prochain tic.
                Log::notice('Mission laissee au passage suivant : un lien a change sous la porte des mouvements.', [
                    'fleet_mission_id' => $mission->id,
                    'lien' => $perime->lien,
                ]);
            } catch (Exception $e) {
                throw new RuntimeException('Fleet mission service process error: Could not update fleet mission with ID ' . $mission->id . ': ' . $e->getMessage());
            }
        }

        DB::transaction(function () use ($planetIds, $fleetMissionService, $autres) {
            // Attempt to acquire a lock on the row for this planet. This is to prevent
            // race conditions when multiple requests are updating the fleet missions for the
            // same planet and potentially doing double insertions or overwriting each other's changes.
            $planetMissionUpdateLock = Planet::whereIn('id', $planetIds)
                ->lockForUpdate()
                ->get();

            if ($planetMissionUpdateLock->count() === count($planetIds)) {
                try {
                    foreach ($autres as $mission) {
                        // Attempt to acquire a lock on the row for this fleet mission. This is to prevent
                        // race conditions when multiple requests are updating the same fleet mission and
                        // potentially doing double insertions or overwriting each other's changes.
                        $fleetMissionLock = FleetMission::where('id', $mission->id)
                            ->lockForUpdate()
                            ->first();

                        if ($fleetMissionLock) {
                            try {
                                $fleetMissionService->updateMission($mission);
                            } catch (Exception $e) {
                                throw new Exception('Could not update fleet mission with ID ' . $mission->id . ': ' . $e->getMessage());
                            }
                        } else {
                            throw new Exception('Could not acquire update fleet mission update lock.');
                        }
                    }
                } catch (Exception $e) {
                    throw new RuntimeException('Fleet mission service process error: ' . $e->getMessage());
                }
            } else {
                throw new Exception('Could not acquire update fleet mission planet lock.');
            }
        });

        if ($dues->count() > 0) {
            // Update the current player object and all child planets to make sure any changes
            // to the fleet missions are reflected in the player/planet objects.
            $this->load($this->getId());
        }
    }

    /**
     * Cette mission prend-elle la porte elle-meme a son traitement ?
     *
     * L'arrivee attaquante (ouvrir, rejoindre, se rattacher) et la Defense ACS a l'aller passent par
     * `decideUnderLock()` dans leur propre traitement ; les faire entrer une seconde fois par la
     * porte ne changerait rien mais doublerait la couche. Tout autre genre gouverne est conduit par
     * le travailleur lui-meme.
     */
    private function gatesItself(FleetMission $mission): bool
    {
        $type = (int)$mission->mission_type;

        return $mission->parent_id === null && ($type === 1 || $type === 5);
    }

    /**
     * Cette mission passe-t-elle par la porte des mouvements a son traitement ?
     *
     * ## La portee ne se recopie pas, elle se demande
     *
     * Ce predicat portait une liste de genres — attaque, attaque groupee, Defense ACS — et laissait
     * dehors **tous les retours**, ainsi que le transport, le deploiement, le missile et la
     * destruction de lune. Or une arrivee de n'importe lequel de ces genres pendant un ralliement
     * touche le corps que la barriere tient, et peut donc composer ou deranger la photographie. La
     * liste etait une seconde matrice, qui aurait diverge de la vraie au premier genre ajoute.
     *
     * La question est maintenant posee a la matrice elle-meme : `CombatSituation::scopeOf()` dit ce
     * qu'une arrivee touche reellement, en tenant compte de l'etape de vol autant que du genre. Un
     * retour se pose toujours sur un corps celeste, quel qu'ait ete l'objet de son aller ; un
     * recyclage vise un champ de debris, une colonisation une position vide, une expedition l'espace
     * profond — aucun de ces trois-la ne touche le corps, et aucun n'a besoin de la porte.
     *
     * ## Ce que l'interrupteur commande encore
     *
     * Une Defense ACS a l'aller passe **toujours** par la porte : sa retenue et son demi-tour s'y
     * decident, que le combat durable soit actif ou non. Tout le reste n'y passe que **quand le
     * combat durable est arme** : sans lui aucune barriere n'existe, l'arrivee d'une attaque est la
     * bataille instantanee, et le traitement de page la protege comme il l'a toujours fait. Elargir
     * la porte a l'interrupteur ferme changerait le chemin de toutes les missions du serveur pour
     * fermer une course qui ne peut pas s'y produire.
     */
    private function isGovernedByTheCombatGate(FleetMission $mission): bool
    {
        $etape = $mission->parent_id === null ? FlightLeg::Outbound : FlightLeg::Return;

        if ($etape === FlightLeg::Outbound && (int)$mission->mission_type === 5) {
            return true;
        }

        if (!resolve(SettingsService::class)->persistentCombatEnabled()) {
            return false;
        }

        return CombatSituation::scopeOf(
            CombatMissionKind::fromMissionType((int)$mission->mission_type),
            $etape
        ) === TargetScope::CelestialBody;
    }

    /**
     * Get the cached general score for this player from the database.
     *
     * @return int
     */
    public function getCachedGeneralScore(): int
    {
        if ($this->cachedGeneralScore === null) {
            $this->cachedGeneralScore = Highscore::where('player_id', $this->getId())->first()->general ?? 0;
        }
        return $this->cachedGeneralScore;
    }

    /**
     * Calculate and return planet score based on levels of buildings and amount of units.
     *
     * @return int
     */
    public function getResearchScore(): int
    {
        // For every research in the game, calculate the score based on how much resources it costs to build it.
        // For research it is the sum of resources needed for all levels up to the current level.
        // The score is the sum of all these values.
        $resources_spent = new Resources(0, 0, 0, 0);

        // Create object array
        $research_objects = ObjectService::getResearchObjects();
        foreach ($research_objects as $object) {
            $level = $this->getResearchLevel($object->machine_name);
            if ($level > 0) {
                $cumulative_cost = ObjectService::getObjectCumulativeCost($object->machine_name, $level);
                $resources_spent->add($cumulative_cost);
            }
        }

        // Divide the score by 1000 to get the amount of points. Floor the result.
        $resources_sum = $resources_spent->metal->get() + $resources_spent->crystal->get() + $resources_spent->deuterium->get();
        $score = floor($resources_sum / 1000);

        // Cap at PHP_INT_MAX to prevent overflow on PHP 8.5+
        if ($score > PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return (int)$score;
    }

    /**
     * Get array with all research objects that this player has.
     *
     * @return array<string, int>
     */
    public function getResearchArray(): array
    {
        $array = [];
        $objects = ObjectService::getResearchObjects();
        foreach ($objects as $object) {
            if ($this->user_tech->{$object->machine_name} > 0) {
                $array[$object->machine_name] = $this->user_tech->{$object->machine_name};
            }
        }

        return $array;
    }

    /**
     * Get is the player researching any tech or not
     *
     * @return bool
     */
    public function isResearching(): bool
    {
        $research_queue = resolve(ResearchQueueService::class);
        return (bool) $research_queue->activeResearchQueueItemCount($this);
    }

    public function isBuildingShipsOrDefense(): bool
    {
        $unit_queue = resolve(UnitQueueService::class);

        return $unit_queue->isBuildingShipsOrDefense($this->getCurrentPlanetId());
    }

    /**
     * Get is the player researching the tech or not
     *
     * @param string $machine_name
     * @param int $level
     * @return bool
     */
    public function isResearchingTech(string $machine_name, int $level): bool
    {
        $research_queue = resolve(ResearchQueueService::class);
        return $research_queue->objectInResearchQueue($this, $machine_name, $level);
    }

    /**
     * Get the maximum amount of planets that this player can have based on research levels.
     *
     * @return int
     */
    public function getMaxPlanetAmount(): int
    {
        $astrophyicsLevel = $this->getResearchLevel('astrophysics');
        $astrophysicsObject = ObjectService::getResearchObjectByMachineName('astrophysics');

        // +1 to max_colonies to get max_planets because the main planet is not included in the calculation above.
        return 1 + $astrophysicsObject->performCalculation(CalculationType::MAX_COLONIES, $astrophyicsLevel);
    }

    /**
     * Check if the player can colonize a specific planet position based on Astrophysics research level.
     *
     * @param int $position The planet position (1-15)
     * @return bool
     */
    public function canColonizePosition(int $position): bool
    {
        $astrophysicsLevel = $this->getResearchLevel('astrophysics');

        // Positions 1 and 15 require Astrophysics level 8
        if (($position === 1 || $position === 15) && $astrophysicsLevel < 8) {
            return false;
        }

        // Positions 2 and 14 require Astrophysics level 6
        if (($position === 2 || $position === 14) && $astrophysicsLevel < 6) {
            return false;
        }

        // Positions 3 and 13 require Astrophysics level 4
        if (($position === 3 || $position === 13) && $astrophysicsLevel < 4) {
            return false;
        }

        // Positions 4-12 have no special requirements
        return true;
    }

    /**
     * Si ce compte est en suppression en attente : il ne lance plus rien.
     *
     * ## Pourquoi un etat, et pas un instant
     *
     * Un compte qui renforce le combat d'un autre joueur ne peut pas disparaitre tout de suite —
     * retirer son renfort changerait une issue deja gelee. Regle arretee par Keven : il passe en
     * attente, cesse d'agir, et sa suppression reprend d'elle-meme quand ces combats sont finaux.
     *
     * Le drapeau ferme aussi une course : pose **avant** l'inventaire des combats, il empeche qu'un
     * combat s'ouvre entre cet inventaire et l'effacement des lignes du compte.
     */
    public function isPendingDeletion(): bool
    {
        return $this->user->deletion_pending_since !== null;
    }

    /**
     * Ce qui retient la suppression de ce compte, ou une chaine vide.
     */
    public function deletionDeferredReason(): string
    {
        return (string)($this->user->deletion_deferred_reason ?? '');
    }

    /**
     * Le compte entre en suppression en attente : des cet instant il ne lance plus rien.
     */
    private function markDeletionPending(): void
    {
        // La barriere refuse d'etre appelee sous une transaction : un point de sauvegarde n'est
        // pas un commit, et le drapeau doit survivre au retour arriere du retrait.
        AccountDeletionBarrier::markPending($this->getId(), (int)Date::now()->timestamp);

        // **Le modele suit la ligne, si elle est encore la.** Une autre passe a pu finir le travail
        // entre-temps : `refresh()` sur une ligne effacee leve, et ce n'est pas une erreur — c'est
        // le cas idempotent, que la transaction du retrait reconnait ensuite comme « absent ».
        $ligne = User::query()->whereKey($this->getId())->first();

        if ($ligne instanceof User) {
            $this->user = $ligne;
        }
    }

    /**
     * La suppression attend, et la raison se lit sur le compte.
     */
    private function deferDeletion(string $pourquoi): void
    {
        $this->user->deletion_deferred_reason = $pourquoi;
        $this->user->save();
    }

    /**
     * Supprime le compte, ou le laisse en attente si un combat le retient.
     *
     * @return void
     */
    public function delete(): void
    {
        // **Le drapeau avant tout, et rien avant lui.** Sans lui, un combat pouvait s'ouvrir entre
        // le moment ou l'on releve les combats du compte et celui ou l'on efface ses lignes : ce
        // combat-la n'aurait ete annule par personne, et sa barriere aurait tenu un corps pour
        // toujours. Le lancement de flotte lit ce drapeau et refuse.
        //
        // **L'inventaire des corps ne le precede plus.** Il vivait ici, avant le drapeau et avant
        // le verrou : une colonisation ou une arrivee deja en vol pouvait creer ou changer un corps
        // entre la photographie et le drapeau. La purge finale efface bien toutes les planetes,
        // mais les files, les missions etrangeres et le plan de retrait etaient calcules depuis une
        // liste perimee.
        $this->markDeletionPending();

        // **Tout le retrait vit dans une transaction qui tient le compte.**
        //
        // « Le plan avant les effets » protegeait les decisions deja lues, pas les lignes qui
        // peuvent apparaitre pendant la lecture. Parmi les chemins qui prennent **a la fois** la
        // ligne d'un compte et une barriere de combat — le lancement d'une flotte et ce retrait —,
        // le compte vient en premier. C'est ce qui les serialise au lieu de les croiser.
        //
        // Le drapeau, lui, est deja valide : une defaillance ici ramene l'inventaire et la purge en
        // arriere, et laisse un compte **en attente** que la commande de reprise peut reprendre —
        // jamais un compte ordinaire au milieu d'un retrait commence.
        DB::transaction(function (): void {
            // **Ce que la ligne dit, une fois tenue.** La suppression qui vient de poser le drapeau
            // et la commande de reprise peuvent toutes deux entrer : si l'autre a fini le travail,
            // la ligne n'est plus la, et poursuivre effacerait des lignes qui ne nous appartiennent
            // plus — avec un modele et une liste de corps perimes.
            $etat = AccountDeletionBarrier::heldState($this->getId());

            if ($etat === AccountDeletionState::Absent) {
                return;
            }

            if ($etat === AccountDeletionState::NotPending) {
                throw new RuntimeException(
                    'Le compte ' . $this->getId() . ' a perdu son drapeau de suppression entre sa pose et le '
                    . 'verrou : personne n a le droit de l effacer en chemin.'
                );
            }

            // **L'inventaire se prend ici, sous le verrou.** Pris avant, il pouvait vieillir entre
            // la photographie et le drapeau, et tout ce qui en decoule aurait ete calcule de travers.
            // Les corps detruits en attente de purge en font partie : leurs lignes liees se nettoient
            // comme les autres.
            $corps = Planet::where('user_id', $this->getId())
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int)$id)
                ->all();

            $this->carryOutTheDeletion($corps);
        });
    }

    /**
     * Le retrait lui-meme, sous le verrou du compte et dans sa transaction.
     *
     * @param array<int, int> $corps Les corps du compte, ceux en attente de purge compris.
     */
    private function carryOutTheDeletion(array $corps): void
    {
        // **Ses combats d'abord, et le plan entier avant le premier effet.** Une mission inscrite
        // dans un combat actif ne s'efface pas : la bataille a ete calculee avec elle. Le retrait
        // annulait combat par combat et ne decouvrait qu'en arrivant a sa ligne qu'un combat
        // retenait la suppression — le compte engage dans deux combats perdait le premier et
        // restait la. Un seul empechement suffit maintenant a n'en annuler aucun.
        $retrait = resolve(AccountCombatWithdrawal::class);
        $plan = $retrait->planFor($this->getId(), $corps);

        if ($plan->deferred()) {
            // **La suppression attend, elle n'echoue pas.** Regle arretee par Keven : le compte
            // reste, ne lance plus rien, et sa suppression reprend d'elle-meme des que les combats
            // qui la retiennent sont finaux. Aucun combat d'un tiers n'est annule.
            $this->deferDeletion($plan->reason());

            return;
        }

        $retrait->apply($this->getId(), $plan, (int)Date::now()->timestamp);

        foreach ($corps as $planetId) {
            // Delete all queue items.
            ResearchQueue::where('planet_id', $planetId)->delete();
            BuildingQueue::where('planet_id', $planetId)->delete();
            UnitQueue::where('planet_id', $planetId)->delete();
            // **Les missions des autres se detachent.** Ce code effacait toute mission qui partait
            // de ce corps ou y allait, quel qu'en fut le proprietaire : la flotte d'un autre joueur
            // en route vers cette planete — un transport, une attaque, un retour cree par
            // l'annulation de son combat — disparaissait avec le compte. Les corps du compte cessent
            // d'exister ; les missions des autres qui les nommaient gardent leurs coordonnees, et
            // perdent seulement le lien vers un corps qui n'est plus la — exactement ce que
            // `startReturn()` sait deja traiter.
            FleetMission::where('user_id', '!=', $this->getId())->where('planet_id_to', $planetId)->update(['planet_id_to' => null]);
            FleetMission::where('user_id', '!=', $this->getId())->where('planet_id_from', $planetId)->update(['planet_id_from' => null]);
        }

        // **Toutes les missions du compte, quels que soient leurs liens de corps.** Elles etaient
        // effacees corps par corps : une mission dont les deux liens etaient deja nuls — detachee
        // par la disparition d'un autre corps, ou par une lune rasee — survivait a son
        // proprietaire. Les enfants d'abord, les parents ensuite : l'ordre est celui de la
        // contrainte, pas une precaution.
        $siennes = FleetMission::where('user_id', $this->getId())->orderBy('id')->pluck('id')->all();

        if ($siennes !== []) {
            FleetMission::whereIn('parent_id', $siennes)->delete();
            FleetMission::whereIn('id', $siennes)->delete();
        }

        // Delete all messages.
        Message::where('user_id', $this->getId())->delete();

        // Delete highscore record.
        Highscore::where('player_id', $this->getId())->delete();

        // Delete tech record.
        UserTech::where('user_id', $this->getId())->delete();

        // Clear planet_current reference before deleting planets (FK constraint).
        $this->user->planet_current = null;
        $this->user->save();

        // Delete all planets.
        Planet::where('user_id', $this->getId())->delete();

        // Delete the actual user.
        $this->user->delete();
    }

    /**
     * Get is the player building the object or not
     *
     * @return bool
     */
    public function isBuildingObject(string $machine_name): bool
    {
        foreach ($this->planets->all() as $planet) {
            if ($planet->isBuildingObject($machine_name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le joueur a-t-il un commandant actif ?
     *
     * @return bool
     */
    public function hasCommander(): bool
    {
        return $this->hasActiveOfficer('commander');
    }

    /**
     * Le joueur a-t-il un amiral actif ?
     *
     * @return bool
     */
    public function hasAdmiral(): bool
    {
        return $this->hasActiveOfficer('admiral');
    }

    /**
     * Le joueur a-t-il un ingenieur actif ?
     *
     * @return bool
     */
    public function hasEngineer(): bool
    {
        return $this->hasActiveOfficer('engineer');
    }

    /**
     * Le joueur a-t-il un geologue actif ?
     *
     * @return bool
     */
    public function hasGeologist(): bool
    {
        return $this->hasActiveOfficer('geologist');
    }

    /**
     * Le joueur a-t-il un technocrate actif ?
     *
     * @return bool
     */
    public function hasTechnocrat(): bool
    {
        return $this->hasActiveOfficer('technocrat');
    }

    /**
     * Un officier est actif tant que sa date d'expiration est dans le futur.
     *
     * La verification se fait directement sur la colonne, sans passer par
     * OfficerService : ces methodes sont appelees a chaque calcul de production,
     * donc a chaque chargement de page.
     *
     * @param string $officer
     * @return bool
     */
    private function hasActiveOfficer(string $officer): bool
    {
        $expiry = $this->user->{$officer . '_until'};

        return $expiry !== null && $expiry->isFuture();
    }

    public function hasCommandingStaff(): bool
    {
        return $this->hasCommander()
            && $this->hasAdmiral()
            && $this->hasEngineer()
            && $this->hasGeologist()
            && $this->hasTechnocrat();
    }

    public function getDarkMatter(): int
    {
        return $this->user->dark_matter ?? 0;
    }

    /**
     * Checks if the player is in vacation mode.
     *
     * @return bool
     */
    public function isInVacationMode(): bool
    {
        return (bool)$this->user->vacation_mode;
    }

    /**
     * Checks if the player can activate vacation mode.
     * Vacation mode can only be activated if no fleets are in transit.
     *
     * @return bool
     */
    public function canActivateVacationMode(): bool
    {
        // Check if player has any active fleet missions sent by themselves
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this]);
        $activeFleetMissions = $fleetMissionService->getActiveFleetMissionsSentByCurrentPlayer();

        return $activeFleetMissions->isEmpty();
    }

    /**
     * Checks if the player can deactivate vacation mode.
     * Vacation mode can only be deactivated after the minimum duration (48 hours).
     *
     * @return bool
     */
    public function canDeactivateVacationMode(): bool
    {
        if (!$this->isInVacationMode()) {
            return false;
        }

        if ($this->user->vacation_mode_until === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo($this->user->vacation_mode_until);
    }

    /**
     * Get the date when vacation mode can be deactivated.
     *
     * @return Carbon|null
     */
    public function getVacationModeUntil(): Carbon|null
    {
        return $this->user->vacation_mode_until;
    }

    /**
     * Activates vacation mode for the player.
     * Sets all mine production percentages to 0 across all planets.
     *
     * @return void
     */
    public function activateVacationMode(): void
    {
        $this->user->vacation_mode = true;
        $this->user->vacation_mode_activated_at = now();
        // Minimum duration: 48 hours
        $this->user->vacation_mode_until = now()->addHours(48);
        $this->save();

        // Set all production percentages to 0 for all player's planets
        $productionBuildings = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'solar_plant', 'fusion_plant', 'solar_satellite'];
        foreach ($this->planets->allPlanets() as $planet) {
            foreach ($productionBuildings as $buildingName) {
                $building = ObjectService::getObjectByMachineName($buildingName);
                $planet->setBuildingPercent($building->id, 0);
            }
        }
    }

    /**
     * Deactivates vacation mode for the player.
     * Production percentages remain at 0 and must be manually reset by the player.
     *
     * @return void
     */
    public function deactivateVacationMode(): void
    {
        $this->user->vacation_mode = false;
        $this->user->vacation_mode_activated_at = null;
        $this->user->vacation_mode_until = null;
        $this->save();

        // Note: Production percentages are intentionally left at 0.
        // Players must manually reset mine production to 100% after vacation mode ends.
    }
}
