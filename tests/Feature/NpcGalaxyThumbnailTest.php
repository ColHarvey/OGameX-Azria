<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * La vignette des bases hostiles en galaxie.
 *
 * Une base ne doit pas ressembler a une planete : un joueur qui parcourt un systeme doit la
 * reconnaitre a l'image, avant meme d'en lire le nom ou d'en voir la pastille.
 */
class NpcGalaxyThumbnailTest extends AccountTestCase
{
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
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

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
            public_path('build/assets/ingame-Tq4m8Wz1.css'),
        ];

        foreach ($feuilles as $feuille) {
            $this->assertFileExists($feuille);

            $contenu = (string)file_get_contents($feuille);

            $this->assertStringContainsString(
                '.microplanet.npc_pirate',
                $contenu,
                "The pirate thumbnail class is missing from {$feuille}, so the galaxy cell would render empty."
            );
        }
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
