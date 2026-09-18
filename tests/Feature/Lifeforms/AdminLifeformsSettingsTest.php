<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\DB;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOdds;
use OGame\Models\Lifeforms\LifeformDiscoveryOddsRevision;
use OGame\Models\Lifeforms\LifeformRuleRevision;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use UnexpectedValueException;

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

    private int $oddsRevisionsAvant = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reglagesAvant = DB::table('settings')->pluck('value', 'key')->map(static fn ($v): string => (string)$v)->all();
        $this->revisionsAvant = (int)(LifeformRuleRevision::query()->max('id') ?? 0);
        $this->oddsRevisionsAvant = (int)(LifeformDiscoveryOddsRevision::query()->max('id') ?? 0);

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
        LifeformDiscoveryOddsRevision::query()->where('id', '>', $this->oddsRevisionsAvant)->delete();
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
        $reponse->assertSee('name="lifeform_population_loss_rate"', false);
        $reponse->assertSee('value="25" size="5" maxlength="3" name="lifeform_population_loss_rate"', false);
        $reponse->assertSee(__('t_ingame.admin.section_lifeforms'));
    }

    /**
     * **L ampleur des morts de population est un reglage d administration** (decision de Keven, 16 septembre 2026,
     * journal §155.20) : la part de la population non protegee qui meurt, 25 % au depart, 0 % pour desactiver, rien
     * hors de 0 a 100. Un refus n ecrit rien.
     */
    public function testThePopulationLossRateIsWrittenBetweenZeroAndOneHundredAndRefusedOutside(): void
    {
        $reglages = resolve(SettingsService::class);
        $this->assertSame(25, $reglages->lifeformPopulationLossPercent(), 'Sans reglage ecrit : 25 %, le choix d equilibrage Azria.');

        foreach (['40' => 40, '0' => 0, '100' => 100] as $envoye => $attendu) {
            $this->post(route('admin.serversettings.update'), $this->formulaire(['lifeform_population_loss_rate' => $envoye]))->assertRedirect(route('admin.serversettings.index'));
            $this->assertSame($attendu, $reglages->lifeformPopulationLossPercent());
        }

        $avant = DB::table('settings')->pluck('value', 'key')->all();
        foreach (['-1', '101', 'abc', '12.5'] as $mauvais) {
            $reponse = $this->from(route('admin.serversettings.index'))->post(route('admin.serversettings.update'), $this->formulaire([
                'lifeforms_enabled' => '1',
                'lifeform_population_loss_rate' => $mauvais,
            ]));
            $reponse->assertRedirect(route('admin.serversettings.index'));
            $reponse->assertSessionHasErrors('lifeform_population_loss_rate');
        }
        $this->assertSame($avant, DB::table('settings')->pluck('value', 'key')->all(), 'Un refus ne change aucun reglage, pas meme l interrupteur.');
        $this->assertSame(100, $reglages->lifeformPopulationLossPercent(), 'Le dernier taux accepte est reste.');

        // Une valeur qui n est pas passee par la porte — une base corrigee a la main — est refusee a la lecture, pas
        // transtypee : « abc » ne vaut pas zero, « 150 » ne vaut pas cent.
        foreach (['abc', '150', '-5', '12.5', ''] as $corrompu) {
            $reglages->set('lifeform_population_loss_rate', $corrompu);
            try {
                $reglages->lifeformPopulationLossPercent();
                $this->fail('Un reglage corrompu « ' . $corrompu . ' » a ete lu.');
            } catch (UnexpectedValueException $refus) {
                $this->assertStringContainsString('lifeform_population_loss_rate', $refus->getMessage());
            }
        }
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

    /**
     * **Les cotes d artefacts des vols de decouverte sont un reglage d administration** (journal §159) : six entiers sur
     * la page, aux valeurs de depart qui sont le comportement d avant (4,59 par vol) ; un enregistrement les ecrit et
     * laisse une revision datee et signee, une seule tant que rien ne change ; un champ absent revient a sa valeur de
     * depart.
     */
    public function testTheDiscoveryOddsAreOnThePageWrittenBySavingAndRecordedOnce(): void
    {
        $page = $this->get(route('admin.serversettings.index'));
        $page->assertStatus(200);
        foreach (SettingsService::DISCOVERY_ODDS_KEYS as $clef => $depart) {
            $largeur = str_ends_with($clef, '_chance') ? 3 : 4;
            $page->assertSee('value="' . $depart . '" size="5" maxlength="' . $largeur . '" name="' . $clef . '"', false);
        }
        $page->assertSee('4,59', false);
        $page->assertSee(__('t_ingame.admin.lifeform_discovery_artifact_chance'));
        $this->assertTrue(resolve(SettingsService::class)->lifeformDiscoveryOdds()->equals(LifeformDiscoveryOdds::defaults()), 'Sans reglage, les cotes de depart.');

        $reponse = $this->post(route('admin.serversettings.update'), $this->formulaire([
            'lifeform_discovery_artifact_chance' => '60',
            'lifeform_discovery_artifacts_small' => '10',
            'lifeform_discovery_artifacts_medium' => '40',
            'lifeform_discovery_artifacts_large' => '100',
            'lifeform_discovery_artifacts_medium_chance' => '20',
            'lifeform_discovery_artifacts_large_chance' => '5',
        ]));
        $reponse->assertRedirect(route('admin.serversettings.index'));
        $reponse->assertSessionHasNoErrors();
        $cotes = resolve(SettingsService::class)->lifeformDiscoveryOdds();
        $this->assertSame(['artifact_chance' => 60, 'small' => 10, 'medium' => 40, 'large' => 100, 'medium_chance' => 20, 'large_chance' => 5], $cotes->toStorage());
        $revisions = LifeformDiscoveryOddsRevision::query()->where('id', '>', $this->oddsRevisionsAvant)->get();
        $this->assertCount(1, $revisions);
        $revision = $revisions->first();
        $this->assertNotNull($revision);
        $this->assertSame([60, 10, 40, 100, 20, 5], [$revision->artifact_chance, $revision->small, $revision->medium, $revision->large, $revision->medium_chance, $revision->large_chance]);
        $this->assertSame(auth()->id(), $revision->changed_by);
        $this->assertSame('administration', $revision->note);
        $this->get(route('admin.serversettings.index'))->assertSee('12,30', false);

        // Le meme formulaire ne date rien de plus ; une cote qui change, si ; les champs absents reviennent au depart.
        $this->post(route('admin.serversettings.update'), $this->formulaire([
            'lifeform_discovery_artifact_chance' => '60', 'lifeform_discovery_artifacts_small' => '10', 'lifeform_discovery_artifacts_medium' => '40',
            'lifeform_discovery_artifacts_large' => '100', 'lifeform_discovery_artifacts_medium_chance' => '20', 'lifeform_discovery_artifacts_large_chance' => '5',
        ]));
        $this->assertSame(1, LifeformDiscoveryOddsRevision::query()->where('id', '>', $this->oddsRevisionsAvant)->count());
        $this->post(route('admin.serversettings.update'), $this->formulaire([]));
        $this->assertTrue(resolve(SettingsService::class)->lifeformDiscoveryOdds()->equals(LifeformDiscoveryOdds::defaults()));
        $this->assertSame(2, LifeformDiscoveryOddsRevision::query()->where('id', '>', $this->oddsRevisionsAvant)->count(), 'Le retour aux valeurs de depart est une revision aussi.');
    }

    /**
     * Une cote qui n est pas un entier dans ses bornes, ou un ensemble incoherent (parts au-dela de cent, trouvailles
     * desordonnees, chance au-dela de ce que l experience et l espece laissent), est refuse : rien n est ecrit, pas
     * meme un reglage voisin du meme formulaire, et aucune revision n est datee.
     */
    public function testAnInvalidOrIncoherentOddIsRefusedAndNothingIsWritten(): void
    {
        $avant = DB::table('settings')->pluck('value', 'key')->all();
        // Chaque faute et le champ qui porte son erreur ; une incoherence d ensemble est portee par la chance.
        $fautes = [
            [['lifeform_discovery_artifact_chance' => '-1'], 'lifeform_discovery_artifact_chance'],
            [['lifeform_discovery_artifact_chance' => '76'], 'lifeform_discovery_artifact_chance'],
            [['lifeform_discovery_artifact_chance' => 'abc'], 'lifeform_discovery_artifact_chance'],
            [['lifeform_discovery_artifact_chance' => '4.5'], 'lifeform_discovery_artifact_chance'],
            [['lifeform_discovery_artifacts_small' => '0'], 'lifeform_discovery_artifacts_small'],
            [['lifeform_discovery_artifacts_large' => '3601'], 'lifeform_discovery_artifacts_large'],
            [['lifeform_discovery_artifacts_small' => '30'], 'lifeform_discovery_artifact_chance'],
            [['lifeform_discovery_artifacts_medium_chance' => '60', 'lifeform_discovery_artifacts_large_chance' => '41'], 'lifeform_discovery_artifact_chance'],
            [['lifeform_discovery_artifacts_large_chance' => '101'], 'lifeform_discovery_artifacts_large_chance'],
        ];
        $vitesseAvant = (string)($avant['fleet_speed_war'] ?? '1');
        foreach ($fautes as [$faute, $champ]) {
            // Le formulaire refuse porte aussi un reglage ordinaire change : lui non plus ne doit pas s ecrire.
            $reponse = $this->from(route('admin.serversettings.index'))->post(route('admin.serversettings.update'), $this->formulaire($faute + [
                'lifeforms_enabled' => '1',
                'fleet_speed_war' => (string)((int)$vitesseAvant + 3),
                'lifeform_discovery_artifact_chance' => '45', 'lifeform_discovery_artifacts_small' => '8', 'lifeform_discovery_artifacts_medium' => '25',
                'lifeform_discovery_artifacts_large' => '50', 'lifeform_discovery_artifacts_medium_chance' => '8', 'lifeform_discovery_artifacts_large_chance' => '2',
            ]));
            $reponse->assertRedirect(route('admin.serversettings.index'));
            $reponse->assertSessionHasErrors($champ);
            if ($faute === ['lifeform_discovery_artifact_chance' => '76']) {
                $reponse->assertSessionHasErrors(['lifeform_discovery_artifact_chance' => __('t_ingame.admin.lifeforms_invalid_odds')], null, 'default');
            }
        }
        $this->assertSame($vitesseAvant, (string)resolve(SettingsService::class)->get('fleet_speed_war', '1'), 'Le reglage ordinaire du formulaire refuse n est pas ecrit.');
        $this->assertSame($avant, DB::table('settings')->pluck('value', 'key')->all(), 'Un refus ne change aucun reglage, pas meme l interrupteur.');
        $this->assertSame(0, LifeformDiscoveryOddsRevision::query()->where('id', '>', $this->oddsRevisionsAvant)->count());
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
            $courant['lifeform_population_loss_rate'],
            $courant['lifeform_discovery_artifact_chance'],
            $courant['lifeform_discovery_artifacts_small'],
            $courant['lifeform_discovery_artifacts_medium'],
            $courant['lifeform_discovery_artifacts_large'],
            $courant['lifeform_discovery_artifacts_medium_chance'],
            $courant['lifeform_discovery_artifacts_large_chance'],
        );

        return array_merge($courant, $champs);
    }
}
