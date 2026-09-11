<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
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
    /**
     * Tous les reglages, tels qu ils etaient avant cet essai.
     *
     * @var array<string, string>
     */
    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();

        // **La page des reglages ecrit TOUT le formulaire, pas seulement ce qu on lui envoie.**
        // Un champ absent de la requete est remis a sa valeur par defaut : poster trois champs
        // desarme `alliance_combat_system_on`, et la base d un processus etant partagee, les
        // essais voisins tombent avec « ACS is disabled on this server ». La CI l a montre la ou
        // seize processus locaux ne l avaient pas vu.
        //
        // Cet essai photographie donc la table entiere et la remet telle quelle : une epreuve
        // remet ce qu elle a leve, y compris ce qu elle n avait pas l intention de toucher.
        $this->reglagesAvant = DB::table('settings')->pluck('value', 'key')->map(static fn ($v): string => (string)$v)->all();

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
        foreach ($this->reglagesAvant as $clef => $valeur) {
            DB::table('settings')->where('key', $clef)->update(['value' => $valeur]);
        }

        // Ce que cet essai a fait naitre — une clef qui n existait pas — repart avec lui.
        DB::table('settings')->whereNotIn('key', array_keys($this->reglagesAvant))->delete();

        parent::tearDown();
    }

    /**
     * Le formulaire tel que le navigateur l envoie : **l etat courant de tous les reglages**, plus
     * les interrupteurs qu on veut changer.
     *
     * `ServerSettingsController::update()` reecrit chaque reglage du formulaire depuis la requete,
     * avec une valeur par defaut quand le champ manque. Un envoi partiel n est donc pas « un envoi
     * qui ne touche que ce qu il nomme » : c est un envoi qui **remet tout le reste par defaut**.
     * Trois champs suffisaient a desarmer `alliance_combat_system_on`, et la base d un processus
     * etant partagee, quatre essais voisins tombaient sur « ACS is disabled on this server ».
     *
     * Envoyer l etat complet reproduit ce que fait la page, et n a donc aucun effet de bord.
     *
     * @param array<string, mixed> $interrupteurs
     * @return array<string, mixed>
     */
    private function formulaire(array $interrupteurs): array
    {
        $courant = DB::table('settings')->pluck('value', 'key')->all();

        // **Ce que la table ne porte pas, le service le resout.** Le controleur lit ces clefs-la
        // sans valeur par defaut : absentes de la requete, `set()` recoit `null` sur une signature
        // `string|int` et la page rend 500. Une base neuve n a pas encore leurs lignes.
        $settings = resolve(SettingsService::class);

        foreach ([
            'basic_income_crystal',
            'basic_income_deuterium',
            'basic_income_energy',
            'basic_income_metal',
            'battle_engine',
            'dark_matter_bonus',
            'debris_field_from_defense',
            'debris_field_from_ships',
            'economy_speed',
            'fleet_speed_holding',
            'fleet_speed_peaceful',
            'fleet_speed_war',
            'maximum_moon_chance',
            'number_of_galaxies',
            'planet_fields_bonus',
            'registration_planet_amount',
            'research_speed',
        ] as $obligatoire) {
            $courant[$obligatoire] ??= $settings->get($obligatoire, 1);
        }

        // **Une case a cocher decochee est absente de la requete, jamais a zero.** Les deux
        // interrupteurs ne viennent donc que de $interrupteurs : c est ainsi que le navigateur les
        // envoie, et c est ce qui rend le desarmement eprouvable.
        unset($courant['patrols_enabled'], $courant['hull_damage_enabled'], $courant['newbie_protection_enabled']);

        return array_merge($courant, $interrupteurs);
    }

    public function testLaPageDeReglagesPorteLesDeuxInterrupteurs(): void
    {
        $reponse = $this->get(route('admin.serversettings.index'));

        $reponse->assertStatus(200);

        // **La forme du contrôle, pas le mot** : c est le nom du champ que le controleur lit.
        $reponse->assertSee('name="patrols_enabled"', false);
        $reponse->assertSee('name="hull_damage_enabled"', false);

        // **La protection des debutants a son interrupteur, elle aussi.** Sans lui elle ne se
        // desarmerait que par `tinker` sur la production — le defaut exact que ce banc a
        // deja ferme une fois pour les degats de coque.
        $reponse->assertSee('name="newbie_protection_enabled"', false);
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
     * **Armer un chantier ne touche a rien d autre.**
     *
     * Ce n est pas une precaution : c est le defaut que la CI a trouve. Le formulaire de cette
     * classe n envoyait que trois champs, et `update()` reecrit **tout** le formulaire depuis la
     * requete, avec une valeur par defaut quand le champ manque. `alliance_combat_system_on`
     * passait donc a zero, et quatre essais voisins tombaient sur « ACS is disabled on this
     * server » — dans un autre processus, plusieurs classes plus loin, sans aucun rapport visible
     * avec l administration.
     *
     * Seize processus locaux ne l avaient pas vu ; la repartition de la CI, si.
     */
    public function testUnEnvoiNeTouchePasLesReglagesVoisins(): void
    {
        $settings = resolve(SettingsService::class);
        $settings->set('alliance_combat_system_on', '1');
        $settings->set('debris_field_from_ships', '42');

        $this->post(route('admin.serversettings.update'), $this->formulaire([
            'hull_damage_enabled' => 1,
        ]));

        $apres = resolve(SettingsService::class);

        $this->assertSame('1', $apres->get('alliance_combat_system_on'), 'Armer un chantier a desarme le systeme de combat d alliance.');
        $this->assertSame('42', $apres->get('debris_field_from_ships'), 'Armer un chantier a remis un reglage voisin par defaut.');
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
