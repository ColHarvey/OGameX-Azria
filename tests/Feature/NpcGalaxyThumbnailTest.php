<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\SpawnsNpcBases;

/**
 * La vignette des bases hostiles en galaxie.
 *
 * Une base ne doit pas ressembler a une planete : un joueur qui parcourt un systeme doit la
 * reconnaitre a l'image, avant meme d'en lire le nom ou d'en voir la pastille.
 */
class NpcGalaxyThumbnailTest extends AccountTestCase
{
    use SpawnsNpcBases;

    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_seed_min_distance', '0');
    }

    protected function tearDown(): void
    {
        $npcIds = DB::table('users')->where('is_npc', true)->pluck('id')->all();

        if ($npcIds !== []) {
            Schema::disableForeignKeyConstraints();

            $planetIds = DB::table('planets')->whereIn('user_id', $npcIds)->pluck('id')->all();
            DB::table('building_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('unit_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('highscores')->whereIn('player_id', $npcIds)->delete();
            DB::table('users_tech')->whereIn('user_id', $npcIds)->delete();
            DB::table('planets')->whereIn('user_id', $npcIds)->delete();
            DB::table('users')->whereIn('id', $npcIds)->delete();

            Schema::enableForeignKeyConstraints();
        }

        DB::table('npc_threats')->delete();
        $this->settings->set('npc_enabled', '0');

        parent::tearDown();
    }

    /**
     * Assert that a pirate base carries its own thumbnail and not a planet biome.
     */
    public function testAPirateBaseGetsItsOwnThumbnail(): void
    {
        $base = $this->aSpawnedBase();

        $coordinate = $base->getPlanetCoordinates();

        // Le contenu de la galaxie arrive en AJAX, pas dans le HTML de la page : c'est cet
        // appel-la qui porte la vignette.
        $response = $this->post('/ajax/galaxy', [
            'galaxy' => $coordinate->galaxy,
            'system' => $coordinate->system,
            '_token' => csrf_token(),
        ]);

        $response->assertStatus(200);
        $response->assertSee('npc_pirate', false);
    }

    /**
     * Assert that human planets keep their biome thumbnails.
     *
     * Non-regression : la vignette des bases ne doit pas s'appliquer a tout le monde.
     */
    public function testHumanPlanetsKeepTheirBiomeThumbnail(): void
    {
        $coordinate = $this->planetService->getPlanetCoordinates();

        $response = $this->post('/ajax/galaxy', [
            'galaxy' => $coordinate->galaxy,
            'system' => $coordinate->system,
            '_token' => csrf_token(),
        ]);

        $response->assertStatus(200);
        $response->assertSee($this->planetService->getPlanetBiomeType() . '_', false);
        $response->assertDontSee('npc_pirate', false);
    }

    /**
     * Assert that the stylesheet actually styles the classes the galaxy emits.
     *
     * Une classe emise par le serveur mais absente de la feuille de style donnerait une case
     * vide en jeu, sans la moindre erreur nulle part. C'est exactement le genre de defaut
     * qu'aucun outil d'analyse ne voit.
     */
    public function testTheStylesheetKnowsTheseClasses(): void
    {
        $feuilles = [
            resource_path('css/ingame/469500b3cd5158332fb20a56b14b2c.css'),
            resource_path('css/ingame/990d5d349ed6e981658ff4e2e3444c.css'),
            $this->builtAsset('.css'),
        ];

        foreach ($feuilles as $feuille) {
            $this->assertFileExists($feuille);

            $contenu = (string)file_get_contents($feuille);

            $this->assertStringContainsString(
                '.microplanet.npc_pirate',
                $contenu,
                "The pirate thumbnail class is missing from {$feuille}, so the galaxy cell would render empty."
            );

            // Presente ne veut pas dire appliquee, et la difference a coute une mise en
            // ligne. Le sprite des planetes est pose par une regle a un identifiant et trois
            // classes ; une regle pirate a deux classes perd la cascade en silence, et la
            // case affiche un fragment de planete a la place de l'ecusson. Ni PHPStan, ni un
            // test de rendu, ni la lecture de la feuille ne voient cela — seule la
            // comparaison des specificites le voit.
            $this->assertStringContainsString(
                '#galaxyContent .ctContentRow .cellPlanet .microplanet.npc_pirate',
                $contenu,
                "The pirate rule in {$feuille} is less specific than the planet sprite rule, so the sprite wins and the badge never shows."
            );
        }
    }

    /**
     * Assert that the built script carries the faction behaviour, not just the source.
     *
     * C'est l'erreur la plus probable de ce depot, et la seule que rien d'autre n'attrape.
     * `npm run build` est impossible ici — le conteneur part de php:8.5-fpm et n'a pas Node —
     * donc l'asset construit est commite tel quel et c'est lui qui est servi. Modifier la
     * source sans reporter dans le construit ne casse aucun test, ne fait broncher ni PHPStan
     * ni Pint, et ne se voit qu'en jeu.
     */
    public function testTheBuiltScriptCarriesTheFactionBehaviour(): void
    {
        $construit = $this->builtAsset('.js');
        $this->assertFileExists($construit);

        $contenu = (string)file_get_contents($construit);

        // Sans cette branche, une base heritait de la chaine des statuts humains et sortait
        // etiquetee (n) — debutante — parce que son score est bas.
        $this->assertStringContainsString(
            'else if (player.isPirate)',
            $contenu,
            'The built script has no pirate branch, so a base would be labelled as a beginner player.'
        );

        // Et sans l'ordre particulier des actions, les emplacements laisses par le message et
        // la demande d'ami restent au milieu de la ligne : ils gardent leurs seize pixels et
        // separent l'oeil du missile, ce qui se lit comme si les icones retirees y etaient
        // toujours. L'espionnage doit donc preceder immediatement le missile.
        $this->assertMatchesRegularExpression(
            '/\$\{espionageLink\}\s*\$\{missileLink\}/',
            $contenu,
            'The built script leaves the blank message and buddy slots between the visible icons of a faction row.'
        );
    }

    /**
     * Get the built asset the manifest actually serves for a given extension.
     *
     * Les assets construits sont renommes a chaque modification, pour contourner le cache des
     * navigateurs. Figer un nom dans un test le ferait tomber a chaque report — pour une
     * raison sans rapport avec ce qu'il verifie. On lit donc le manifeste, qui est de toute
     * facon la source de verite de ce qui est servi au joueur.
     */
    private function builtAsset(string $extension): string
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifeste, 'The build manifest could not be read.');

        foreach ($manifeste as $entree) {
            $fichier = is_array($entree) ? ($entree['file'] ?? '') : '';

            if (is_string($fichier) && str_starts_with($fichier, 'assets/ingame-') && str_ends_with($fichier, $extension)) {
                return public_path('build/' . $fichier);
            }
        }

        $this->fail('The manifest declares no built ingame ' . $extension . ' asset.');
    }

    /**
     * Assert that the images the stylesheet points at are actually present.
     *
     * Le CSS peut etre parfait et la case rester vide si le fichier n'a pas ete depose. Ce
     * test le dit franchement plutot que de laisser la decouverte au premier joueur.
     */
    public function testTheThumbnailImagesArePresent(): void
    {
        $this->assertFileExists(
            public_path('img/planets/npc/pirate_base.png'),
            'The pirate galaxy thumbnail is missing: the cell would render empty in game.'
        );
    }

    /**
     * Assert that every faction the galaxy can emit has a thumbnail to show.
     *
     * Une faction ajoutee au code sans son image donnerait une case vide en jeu, sans le
     * moindre message d'erreur. Ce test lie les deux : creer une seconde faction obligera a
     * fournir sa vignette, ou il tombera.
     */
    public function testEveryLivingFactionHasAThumbnail(): void
    {
        $types = DB::table('users')
            ->where('is_npc', true)
            ->distinct()
            ->pluck('npc_type')
            ->filter()
            ->all();

        foreach ($types as $type) {
            $this->assertFileExists(
                public_path('img/planets/npc/' . $type . '_base.png'),
                "The faction '{$type}' has bases in game but no galaxy thumbnail: those cells render empty."
            );
        }

        $this->addToAssertionCount(1);
    }
}
