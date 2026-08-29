<?php

namespace OGame\Actions\Fortify;

use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\MessageService;
use OGame\Services\SettingsService;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Create a new controller instance.
     *
     * @param PlayerServiceFactory $playerServiceFactory
     * @param PlanetServiceFactory $planetServiceFactory
     * @param SettingsService $settings
     */
    public function __construct(private PlayerServiceFactory $playerServiceFactory, private PlanetServiceFactory $planetServiceFactory, private SettingsService $settings)
    {
    }

    /**
     * The first names to use when generating a unique username.
     *
     * @var array<string>
     */
    private array $firstNames = [
        "President", "Constable", "Commander", "Engineer", "Commodore",
        "Captain", "Czar", "Gamma", "Jarhead", "Technocrat",
        "Viceregent", "Admiral", "Emperor", "Tempus", "Geologist",
        "Chief", "Navigator", "Mariner", "Astro", "Pioneer",
        "Sentinel", "Vanguard", "Starlord", "Quasar", "Zenith",
        "Eclipse", "Nova", "Comet", "Pulsar", "Meteor",
        "Titan", "Orion", "Celestial", "Lunar", "Solar",
        "Photon", "Cosmic", "Nebula", "Stellar", "Voidwalker",
        "Galaxy", "Astrophysicist", "Quantum", "Sovereign", "Majesty",
        "Sentry", "Guardian", "Vortex", "Spectre", "Legend",
        "Chrono", "Mystic", "Paladin", "Arcane", "Sage",
        "Virtuoso", "Maverick", "Prophet", "Strategist", "Tactician",
        "Expeditionary", "Pioneer", "Visionary", "Vanguard", "Crusader",
        "Centurion", "Cosmonaut", "Astronaut", "Navigator", "Pathfinder",
        "Voyager", "Explorer", "Adventurer", "Ranger", "Wanderer",
        "Nomad", "Pilgrim", "Seeker", "Discoverer", "Scout",
        "Frontiersman", "Trailblazer", "Pioneer", "Innovator", "Inventor"
    ];

    /**
     * The last names to use when generating a unique username.
     *
     * @var array|string[]
     */
    private array $lastNames = [
        "Orbit", "Nova", "Quark", "Vega", "Rigel",
        "Io", "Europ", "Titan", "Ganym", "Calyp",
        "Atlas", "Deimos", "Phobos", "Janus", "Ariel",
        "Oberon", "Mir", "Leda", "Helio", "Sol",
        "Luna", "Mars", "Venus", "Earth", "Mer",
        "Jup", "Sat", "Uran", "Nept", "Pluto",
        "Eris", "Makem", "Haume", "Sedna", "Varun",
        "Quaoar", "Ixion", "Orcus", "Triton", "Nix",
        "Hydra", "Charon", "Styx", "Kerber", "Logos",
        "Comet", "Dactyl", "Ida", "Gaspra", "Mathi",
        "Bennu", "Borrel", "Ymir", "Paalia", "Namaka",
        "Haiku", "Fornj", "Surtur", "Thrymr", "Skathi",
        "Tayget", "Elara", "Janus", "Mimas", "Encel",
        "Thalas", "Arche", "Iapet", "Dione", "Rhea",
        "Kiviuq", "Ijira", "Tarqeq",
        "Varda", "Quirin", "Sila", "Numis", "Orcus",
        "Rhode", "Helik", "Kalyp", "Kore",
        "Phorc", "Deuc", "Neso", "Bergel", "Sirona",
        "Galax", "Cosmo", "Astro", "Stela", "Nebul",
        "Siriu", "Vega", "Altai", "Denib", "Fomal",
        "Orion", "Lyra", "Cygns", "Aquil", "Draco",
        "Solis", "Lunae", "Terra", "Aeria", "Caeli",
        "Zephy", "Borel", "Eurus", "Notus", "Austro"
    ];

    /**
     * Generate a random name based on the first and last name arrays.
     *
     * @return string
     */
    private function generateName(): string
    {
        $firstName = $this->firstNames[array_rand($this->firstNames)];
        $lastName = $this->lastNames[array_rand($this->lastNames)];
        return $firstName . ' ' . $lastName;
    }

    /**
     * Generate a unique username suffix based on the attempt number.
     *
     * @param int $attempt
     * @return int
     */
    private function getUniqueSuffix(int $attempt): int
    {
        return rand(100 * $attempt, 999 * $attempt);
    }

    /**
     * Generate a unique username that is not already in use.
     *
     * @return string
     */
    public function generateUniqueName(): string
    {
        $attempt = 0;
        do {
            // Generate a more unique name by adding digits or initials.
            $username = $this->generateName();
            if ($attempt >= 5) {
                // After 5 attempts, start adding numeric values to ensure uniqueness.
                $username .= $this->getUniqueSuffix($attempt);
            }
            $attempt++;
        } while (User::where('username', $username)->exists() && $attempt < 10);

        if ($attempt >= 10) {
            // As a last resort, append a large random number or a timestamp.
            $username .= '_' . time();
        }

        return $username;
    }

    /**
     * Validate and create a newly registered user.
     *
     * @param array<string, string> $input
     * @throws ValidationException
     * @throws Exception
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                // Meme expression que PlayerService::validateUsername(), pour que
                // l'inscription et le renommage en jeu acceptent les memes pseudos.
                'regex:/^[A-Za-z][A-Za-z0-9\s]*(?:_[A-Za-z0-9\s]+)*$/',
                Rule::unique(User::class),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            // 'confirmed' est ajoute ici uniquement : le formulaire d'options en jeu
            // utilise newpass1/newpass2 et n'envoie pas password_confirmation.
            'password' => [...$this->passwordRules(), 'confirmed'],
        ], [
            'username.required' => __('t_external.register.username_required'),
            'username.min' => __('t_external.register.username_invalid'),
            'username.max' => __('t_external.register.username_invalid'),
            'username.regex' => __('t_external.register.username_invalid'),
            'username.unique' => __('t_external.register.username_taken'),
            'email.required' => __('t_external.register.email_required'),
            'email.email' => __('t_external.register.email_invalid'),
            'email.unique' => __('t_external.register.email_taken'),
            'password.required' => __('t_external.register.password_required'),
            'password.min' => __('t_external.register.password_too_short'),
            'password.confirmed' => __('t_external.register.password_mismatch'),
        ])->validateWithBag('register');

        // Le pseudo est choisi par le joueur et deja valide comme unique ci-dessus.
        // Le try/catch ne couvre plus que la course entre la validation et l'insertion.
        try {
            $user = User::create([
                'lang' => config('app.locale', 'en'),
                'username' => $input['username'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);
        } catch (Exception $e) {
            if ($e->getCode() === 23000) {
                throw ValidationException::withMessages([
                    'username' => __('t_external.register.username_taken'),
                ])->errorBag('register');
            }
            throw $e;
        }

        // Le premier inscrit devient administrateur, mais garde le pseudo qu'il a choisi.
        if (User::count() === 1) {
            $user->assignRole('admin');
        }

        $this->createInitialGameDataForUser($user);

        return $user;
    }

    /**
     * Create initial data for the player such as planets and tech records.
     *
     * @param User $user
     * @throws Exception
     */
    private function createInitialGameDataForUser($user): void
    {
        // Create initial player tech record.
        $tech = new UserTech();
        $tech->user_id = $user->id;
        $tech->save();

        // Create initial planet(s) for the player.
        $playerService = $this->playerServiceFactory->make($user->id);
        $planetNames = ['Homeworld', 'Colony'];
        // The amount of planets to create is defined in the settings and defaults to 1.
        for ($i = 0; $i < $this->settings->registrationPlanetAmount(); $i++) {
            $this->planetServiceFactory->createInitialPlanetForPlayer($playerService, $planetNames[$i === 0 ? 0 : 1]);
        }

        // Send welcome message to player
        $message = new MessageService($playerService);
        $message->sendWelcomeMessage();

        // Send welcome email to player
        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Bienvenue sur OGameX Francophone !\n\n"
                . "Ton compte a bien ete cree.\n\n"
                . "Pseudo : {$user->username}\n"
                . "Adresse : {$user->email}\n\n"
                . "Pour changer ton pseudo plus tard : clique sur ton nom en haut\n"
                . "a gauche dans le jeu, saisis le pseudo souhaite, puis confirme\n"
                . "avec ton mot de passe.\n\n"
                . "Connecte-toi ici : " . config('app.url') . "\n\n"
                . "Bon jeu,\nL'equipe Azria",
                function ($m) use ($user) {
                    $m->to($user->email)->subject('Bienvenue sur OGameX Francophone');
                }
            );
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Welcome email failed: ' . $e->getMessage());
        }
    }
}
