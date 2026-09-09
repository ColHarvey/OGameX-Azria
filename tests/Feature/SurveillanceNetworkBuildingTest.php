<?php

namespace Tests\Feature;

use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le Reseau de surveillance : l objet existe toujours, la page ne l offre que si les patrouilles sont armees.
 *
 * ## Ce que ce temoin separe
 *
 * Deux choses se confondent facilement et n ont pas le meme sens :
 *
 *  - **le catalogue** connait l objet en permanence — le retirer ferait disparaitre le niveau deja
 *    construit d un corps, sa file et son decompte de points ;
 *  - **la page des installations** ne propose sa construction que derriere `patrols_enabled`.
 *
 * Un essai qui ne verifierait que la page laisserait passer un objet absent du catalogue ; un essai
 * qui ne verifierait que le catalogue laisserait passer un batiment offert avant l heure.
 */
class SurveillanceNetworkBuildingTest extends AccountTestCase
{
    /**
     * La marque du carreau dans la grille, la seule forme qui ne peut pas venir d une phrase.
     *
     * Le nom d objet apparait aussi dans le titre et les descriptions ; chercher « surveillance »
     * rougirait sur la prose. `technology surveillanceNetwork` est la classe que le gabarit pose sur
     * le carreau, et rien d autre ne l ecrit.
     */
    private const string CARREAU = 'technology surveillanceNetwork';

    protected function tearDown(): void
    {
        // La base d un processus est partagee : ce que cet essai leve, il le rabaisse.
        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    /**
     * L objet est au catalogue, sous l identifiant libre qui lui a ete donne.
     */
    public function testTheSurveillanceNetworkIsInTheCatalogueUnderItsOwnIdentifier(): void
    {
        $objet = ObjectService::getObjectByMachineName('surveillance_network');

        // **L identifiant compte autant que le nom.** `getObjectById()` rend le premier objet qui
        // repond, sans signaler un doublon : un identifiant deja pris volerait silencieusement
        // l objet existant, et le vol ne se verrait que par ce controle croise.
        $this->assertSame(45, $objet->id);
        $this->assertSame($objet->id, ObjectService::getObjectById(45)->id);
        $this->assertSame('surveillance_network', ObjectService::getObjectById(45)->machine_name);

        // Le nom machine est impose par la colonne posee sur `planets` : `getObjectLevel()` lit
        // `$planet->{machine_name}`. Un autre nom lirait une propriete inexistante et rendrait zero.
        $this->assertTrue(
            resolve('db')->getSchemaBuilder()->hasColumn('planets', $objet->machine_name),
            'The machine name does not match a column of planets: the level would always read zero.'
        );
    }

    /**
     * Les prerequis et le prix sont ceux qui ont ete decides, champ par champ.
     */
    public function testItsRequirementsAndPriceAreTheOnesDecided(): void
    {
        $objet = ObjectService::getObjectByMachineName('surveillance_network');

        $prerequis = [];

        foreach ($objet->requirements as $exigence) {
            $prerequis[$exigence->object_machine_name] = $exigence->level;
        }

        // Compare l ensemble, pas seulement la presence : « il exige l espionnage » resterait vrai
        // avec un troisieme prerequis ajoute par accident.
        $this->assertSame(['espionage_technology' => 4, 'computer_technology' => 2], $prerequis);

        // Les ressources portent des flottants : le dire ici plutot que de le masquer par un
        // transtypage, qui accepterait aussi bien une chaine ou un booleen.
        $this->assertSame(30000.0, $objet->price->resources->metal->get());
        $this->assertSame(60000.0, $objet->price->resources->crystal->get());
        $this->assertSame(15000.0, $objet->price->resources->deuterium->get());
    }

    /**
     * Interrupteur baisse : la page ne propose pas le batiment.
     */
    public function testThePageDoesNotOfferItWhileThePatrolsAreDisarmed(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);

        $reponse = $this->get(route('facilities.index'));

        $reponse->assertStatus(200);
        $reponse->assertDontSee(self::CARREAU, false);
    }

    /**
     * Interrupteur arme : la page le propose.
     */
    public function testThePageOffersItOnceThePatrolsAreArmed(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);

        $reponse = $this->get(route('facilities.index'));

        $reponse->assertStatus(200);
        $reponse->assertSee(self::CARREAU, false);
    }

    /**
     * Les deux langues portent les trois textes, et le francais n est pas l anglais.
     *
     * `__()` rend la clef elle-meme quand la traduction manque : une phrase anglaise lisible,
     * sans erreur. Comparer les deux langues est ce qui rend ce silence visible.
     */
    public function testBothLanguagesCarryTheThreeTexts(): void
    {
        foreach (['fr', 'en'] as $langue) {
            foreach (['title', 'description', 'description_long'] as $clef) {
                $texte = __('t_resources.surveillance_network.' . $clef, [], $langue);

                $this->assertIsString($texte);
                $this->assertNotSame(
                    't_resources.surveillance_network.' . $clef,
                    $texte,
                    'The ' . $langue . ' ' . $clef . ' falls back to the key itself: the translation is missing.'
                );
            }
        }

        $this->assertNotSame(
            __('t_resources.surveillance_network.title', [], 'en'),
            __('t_resources.surveillance_network.title', [], 'fr'),
            'The French title is the English one: the file was never read.'
        );
    }
}
