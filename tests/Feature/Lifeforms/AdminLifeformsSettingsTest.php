<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\DB;
use OGame\Models\Lifeforms\LifeformRuleRevision;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * La section « Formes de vie » des reglages du serveur : l interrupteur, les coefficients valides au
 * serveur, et la revision datee qu un changement de vitesse laisse derriere lui.
 */
final class AdminLifeformsSettingsTest extends AccountTestCase
{
    /**
     * @var array<string, string>
     */
    private array $reglagesAvant = [];

    private int $revisionsAvant = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reglagesAvant = DB::table('settings')->pluck('value', 'key')->map(static fn ($v): string => (string)$v)->all();
        $this->revisionsAvant = (int)(LifeformRuleRevision::query()->max('id') ?? 0);

        $user = auth()->user();
        if ($user === null) {
            $this->fail('No authenticated user found.');
        }
        $this->artisan('ogamex:admin:assign-role', ['username' => $user->username]);
    }

    protected function tearDown(): void
    {
        foreach ($this->reglagesAvant as $clef => $valeur) {
            DB::table('settings')->where('key', $clef)->update(['value' => $valeur]);
        }
        DB::table('settings')->whereNotIn('key', array_keys($this->reglagesAvant))->delete();
        LifeformRuleRevision::query()->where('id', '>', $this->revisionsAvant)->delete();
        parent::tearDown();
    }

    public function testThePageCarriesTheSwitchTheThreeMultipliersAndTheEffectiveSpeeds(): void
    {
        $reponse = $this->get(route('admin.serversettings.index'));

        $reponse->assertStatus(200);
        $reponse->assertSee('name="lifeforms_enabled"', false);
        $reponse->assertSee('name="lifeforms_build_speed_multiplier"', false);
        $reponse->assertSee('name="lifeforms_research_speed_multiplier"', false);
        $reponse->assertSee('name="lifeforms_discovery_speed_multiplier"', false);
        $reponse->assertSee(__('t_ingame.admin.section_lifeforms'));
    }

    public function testSavingOpensTheSwitchSetsTheMultipliersAndRecordsOneRevisionUntilSomethingChanges(): void
    {
        $reponse = $this->post(route('admin.serversettings.update'), $this->formulaire([
            'lifeforms_enabled' => '1',
            'lifeforms_build_speed_multiplier' => '2',
            'lifeforms_research_speed_multiplier' => '1.5',
            'lifeforms_discovery_speed_multiplier' => '1',
        ]));
        $reponse->assertRedirect(route('admin.serversettings.index'));

        $reglages = resolve(SettingsService::class);
        $this->assertTrue($reglages->lifeformsEnabled());
        $this->assertSame(2.0, $reglages->lifeformsBuildSpeedMultiplier());
        $this->assertSame(1.5, $reglages->lifeformsResearchSpeedMultiplier());
        $this->assertSame(1.0, $reglages->lifeformsDiscoverySpeedMultiplier());

        $revisions = LifeformRuleRevision::query()->where('id', '>', $this->revisionsAvant)->get();
        $this->assertCount(1, $revisions);
        $revision = $revisions->first();
        $this->assertNotNull($revision);
        $this->assertSame(2.0, $revision->build_multiplier);
        $this->assertSame(1.5, $revision->research_multiplier);
        $this->assertSame((float)$reglages->economySpeed(), $revision->economy_speed);
        $this->assertSame('administration', $revision->note);

        // Le meme formulaire renvoye ne date rien de plus ; un coefficient change, si.
        $this->post(route('admin.serversettings.update'), $this->formulaire(['lifeforms_enabled' => '1', 'lifeforms_build_speed_multiplier' => '2', 'lifeforms_research_speed_multiplier' => '1.5']));
        $this->assertSame(1, LifeformRuleRevision::query()->where('id', '>', $this->revisionsAvant)->count());
        $this->post(route('admin.serversettings.update'), $this->formulaire(['lifeforms_enabled' => '1', 'lifeforms_build_speed_multiplier' => '3', 'lifeforms_research_speed_multiplier' => '1.5']));
        $this->assertSame(2, LifeformRuleRevision::query()->where('id', '>', $this->revisionsAvant)->count());

        // Une case absente eteint ; les coefficients absents reviennent a 1.
        $this->post(route('admin.serversettings.update'), $this->formulaire([]));
        $this->assertFalse($reglages->lifeformsEnabled());
        $this->assertSame(1.0, $reglages->lifeformsBuildSpeedMultiplier());
    }

    public function testAnInvalidMultiplierIsRefusedAndNothingIsWritten(): void
    {
        $avant = DB::table('settings')->pluck('value', 'key')->all();
        foreach (['0', '-2', 'abc', '101'] as $mauvais) {
            $reponse = $this->from(route('admin.serversettings.index'))->post(route('admin.serversettings.update'), $this->formulaire([
                'lifeforms_enabled' => '1',
                'lifeforms_build_speed_multiplier' => $mauvais,
            ]));
            $reponse->assertRedirect(route('admin.serversettings.index'));
            $reponse->assertSessionHasErrors('lifeforms_build_speed_multiplier');
        }
        $this->assertSame($avant, DB::table('settings')->pluck('value', 'key')->all(), 'Un refus ne change aucun reglage, pas meme l interrupteur.');
        $this->assertSame(0, LifeformRuleRevision::query()->where('id', '>', $this->revisionsAvant)->count());
    }

    /**
     * Le formulaire complet, comme la page l envoie (voir `AdminChantierSwitchesTest::formulaire()`).
     *
     * @param array<string, mixed> $champs
     * @return array<string, mixed>
     */
    private function formulaire(array $champs): array
    {
        $courant = DB::table('settings')->pluck('value', 'key')->all();
        $settings = resolve(SettingsService::class);
        foreach ([
            'basic_income_crystal', 'basic_income_deuterium', 'basic_income_energy', 'basic_income_metal', 'battle_engine',
            'dark_matter_bonus', 'debris_field_from_defense', 'debris_field_from_ships', 'economy_speed', 'fleet_speed_holding',
            'fleet_speed_peaceful', 'fleet_speed_war', 'maximum_moon_chance', 'number_of_galaxies', 'planet_fields_bonus',
            'registration_planet_amount', 'research_speed',
        ] as $obligatoire) {
            $courant[$obligatoire] ??= $settings->get($obligatoire, 1);
        }
        unset(
            $courant['patrols_enabled'],
            $courant['hull_damage_enabled'],
            $courant['newbie_protection_enabled'],
            $courant['lifeforms_enabled'],
            $courant['lifeforms_build_speed_multiplier'],
            $courant['lifeforms_research_speed_multiplier'],
            $courant['lifeforms_discovery_speed_multiplier'],
        );

        return array_merge($courant, $champs);
    }
}
