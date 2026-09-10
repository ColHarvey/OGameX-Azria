<?php

namespace Tests\Feature;

use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Les deux interrupteurs des chantiers s arment et se desarment depuis l administration.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CET ESSAI EXISTE
 *
 * `patrols_enabled` avait son interrupteur depuis le debut ; `hull_damage_enabled` n en avait
 * **aucun**. L armer — et surtout le desarmer — demandait donc `tinker` sur le serveur de
 * production. Un interrupteur qu on ne peut pas eteindre depuis le jeu n est pas un interrupteur :
 * c est une decision qu on ne peut plus reprendre.
 *
 * Et la page des reglages n avait **aucun essai**. Une faute de nom entre le controleur et le
 * gabarit — `$hull_damage_enabled` absent de la vue — ne se serait vue que dans le navigateur, au
 * moment ou Keven aurait voulu armer.
 *
 * ------------------------------------------------------------------------------------
 * CE QU IL EXIGE
 *
 * Le va-et-vient complet, pas seulement l allumage : une case absente du formulaire **eteint**, et
 * c est ce que le chemin de retour arriere emploie. Un essai qui n armerait que dans un sens
 * laisserait le desarmement non eprouve, alors que c est lui qui compte le jour ou quelque chose va
 * mal en jeu.
 */
class AdminChantierSwitchesTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();

        if ($user === null) {
            $this->fail('No authenticated user found.');
        }

        $this->artisan('ogamex:admin:assign-role', ['username' => $user->username]);
    }

    protected function tearDown(): void
    {
        // **Une epreuve remet ce qu elle a leve.** La base d un processus est partagee entre classes,
        // et laisser un chantier arme ferait mentir les essais suivants.
        $settings = resolve(SettingsService::class);
        $settings->set('patrols_enabled', '0');
        $settings->set('hull_damage_enabled', '0');

        parent::tearDown();
    }

    /**
     * Les valeurs du formulaire, hors interrupteurs : elles doivent accompagner chaque envoi, sinon
     * le controleur les remet a leur valeur par defaut et l essai changerait des reglages voisins.
     *
     * @return array<string, mixed>
     */
    private function formulaire(array $interrupteurs): array
    {
        $settings = resolve(SettingsService::class);

        return array_merge([
            'basic_income_crystal' => $settings->get('basic_income_crystal', 1),
            'basic_income_deuterium' => $settings->get('basic_income_deuterium', 1),
            'basic_income_energy' => $settings->get('basic_income_energy', 1),
            'basic_income_metal' => $settings->get('basic_income_metal', 1),
            'battle_engine' => $settings->get('battle_engine', 1),
            'dark_matter_bonus' => $settings->get('dark_matter_bonus', 1),
            'debris_field_from_defense' => $settings->get('debris_field_from_defense', 1),
            'debris_field_from_ships' => $settings->get('debris_field_from_ships', 1),
            'economy_speed' => $settings->get('economy_speed', 1),
            'fleet_speed_holding' => $settings->get('fleet_speed_holding', 1),
            'fleet_speed_peaceful' => $settings->get('fleet_speed_peaceful', 1),
            'fleet_speed_war' => $settings->get('fleet_speed_war', 1),
            'maximum_moon_chance' => $settings->get('maximum_moon_chance', 1),
            'number_of_galaxies' => $settings->get('number_of_galaxies', 1),
            'planet_fields_bonus' => $settings->get('planet_fields_bonus', 1),
            'registration_planet_amount' => $settings->get('registration_planet_amount', 1),
            'research_speed' => $settings->get('research_speed', 1),
            'patrol_manoeuvre_delay_seconds' => $settings->patrolManoeuvreDelaySeconds(),
            'patrol_upkeep_divisor' => $settings->patrolUpkeepDivisor(),
            'patrol_safety_return_speed' => $settings->patrolSafetyReturnSpeed(),
        ], $interrupteurs);
    }

    public function testLaPageDeReglagesPorteLesDeuxInterrupteurs(): void
    {
        $reponse = $this->get(route('admin.serversettings.index'));

        $reponse->assertStatus(200);

        // **La forme du contrôle, pas le mot** : c est le nom du champ que le controleur lit.
        $reponse->assertSee('name="patrols_enabled"', false);
        $reponse->assertSee('name="hull_damage_enabled"', false);
    }

    public function testLesDeuxChantiersSArmentEtSeDesarment(): void
    {
        $settings = resolve(SettingsService::class);

        $settings->set('patrols_enabled', '0');
        $settings->set('hull_damage_enabled', '0');

        $this->post(route('admin.serversettings.update'), $this->formulaire([
            'patrols_enabled' => 1,
            'hull_damage_enabled' => 1,
        ]));

        $this->assertTrue(resolve(SettingsService::class)->patrolsEnabled(), 'Les patrouilles ne se sont pas armees.');
        $this->assertTrue(resolve(SettingsService::class)->hullDamageEnabled(), 'Les degats de coque ne se sont pas armes.');

        // **Le retour arriere, qui est la vraie raison d etre d un interrupteur** : une case absente
        // du formulaire eteint.
        $this->post(route('admin.serversettings.update'), $this->formulaire([]));

        $this->assertFalse(resolve(SettingsService::class)->patrolsEnabled(), 'Les patrouilles ne se sont pas desarmees.');
        $this->assertFalse(resolve(SettingsService::class)->hullDamageEnabled(), 'Les degats de coque ne se sont pas desarmes.');
    }

    /**
     * **Un chantier s arme sans l autre.** Ils sont independants, et le dire ici evite qu une
     * activation en entraine une seconde sans que personne l ait demandee.
     */
    public function testUnChantierSArmeSansEntrainerLAutre(): void
    {
        $settings = resolve(SettingsService::class);

        $settings->set('patrols_enabled', '0');
        $settings->set('hull_damage_enabled', '0');

        $this->post(route('admin.serversettings.update'), $this->formulaire([
            'hull_damage_enabled' => 1,
        ]));

        $this->assertTrue(resolve(SettingsService::class)->hullDamageEnabled(), 'Les degats de coque ne se sont pas armes.');
        $this->assertFalse(resolve(SettingsService::class)->patrolsEnabled(), 'Armer les coques a arme les patrouilles.');
    }
}
