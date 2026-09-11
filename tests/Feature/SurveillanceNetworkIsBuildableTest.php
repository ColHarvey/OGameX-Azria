<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Patrol\SurveillanceWatch;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Throwable;

/**
 * Le Reseau de surveillance se **construit vraiment**, par le chemin du joueur.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CET ESSAI EXISTE
 *
 * Les temoins voisins etablissent beaucoup : le batiment est dans le catalogue sous son
 * identifiant, ses prerequis et son prix sont ceux decides, la page l offre une fois les patrouilles
 * armees et ne l offre pas sinon, les trois textes existent dans les deux langues, et poser son
 * niveau ouvre les contacts.
 *
 * **Aucun ne demande au jeu de le construire.** Ils appellent `setObjectLevel()` — l ecrivain de bas
 * niveau — et sautent donc tout le trajet du joueur : la demande de construction, la file, le
 * paiement, l achevement. Un objet peut etre parfaitement decrit dans le catalogue, visible sur la
 * page, et refuse par la file pour une raison qui ne se voit nulle part ailleurs.
 *
 * C est la lecon de cette session, payee trois fois : **une classe verte ne dit rien du parcours**.
 * Une attaque spatiale ne pouvait ni partir ni arriver alors que chacune de ses pieces etait
 * eprouvee.
 *
 * ------------------------------------------------------------------------------------
 * CE QU IL EXIGE
 *
 * Que la demande soit acceptee, que la file la porte, que l achevement fasse monter le niveau **sur
 * la colonne que la surveillance lit**, et que la veille soit avertie — c est-a-dire que le
 * batiment, une fois construit, serve a quelque chose.
 */
class SurveillanceNetworkIsBuildableTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('patrols_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Le parcours entier**, de la demande au niveau qui compte.
     */
    public function testUnJoueurPeutLeDemanderEtLObtenir(): void
    {
        $objet = ObjectService::getObjectByMachineName('surveillance_network');

        // Les prerequis decides : sans eux la file refuserait, et pour la bonne raison.
        $this->playerSetResearchLevel('espionage_technology', 4);
        $this->playerSetResearchLevel('computer_technology', 2);
        $this->planetAddResources(new Resources(500_000, 500_000, 500_000, 0));

        $this->assertSame(
            0,
            $this->planetService->getObjectLevel('surveillance_network'),
            'La premisse manque : le corps porte deja un reseau.'
        );

        // **Le chemin du joueur, pas l ecrivain de bas niveau.** La route verifie elle-meme le
        // jeton — elle accepte POST et GET —, donc l essai l envoie comme le formulaire le fait.
        $reponse = $this->post(route('facilities.addbuildrequest.post'), [
            'technologyId' => $objet->id,
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(200);

        $this->assertNotSame(
            'Invalid token.',
            $reponse->json('message'),
            'Le jeton n a pas ete accepte : cet essai n eprouverait pas la file.'
        );

        $this->planetService->reloadPlanet();

        $file = resolve(BuildingQueueService::class)->retrieveQueueItems($this->planetService);

        $this->assertNotEmpty(
            $file,
            'La file a refuse le Reseau de surveillance : il est au catalogue et sur la page, mais personne ne peut le construire.'
        );

        // L achevement : le temps passe, et le travailleur du corps fait son travail.
        $attente = 0;

        foreach ($file as $element) {
            $attente = max($attente, (int)$element->time_end);
        }

        $this->assertGreaterThan(0, $attente, 'L element de file ne porte aucune echeance.');

        Date::setTestNow(Date::createFromTimestamp($attente + 1));

        $this->planetService->update();
        $this->planetService->reloadPlanet();

        $this->assertSame(
            1,
            $this->planetService->getObjectLevel('surveillance_network'),
            'Le batiment termine ne monte pas le niveau : la colonne que la surveillance lit reste a zero.'
        );
    }

    /**
     * **Une demande sans jeton ne construit rien et ne debite rien.**
     *
     * `AbstractBuildingsController::addBuildRequest()` verifie le jeton lui-meme — la route accepte
     * POST et GET — par `hash_equals($session, $request->input('_token'))`. Un champ absent rend
     * `null`, et `hash_equals()` refuse `null` : le controleur tombe en **exception**, donc en 500,
     * au lieu de rendre le refus propre qu il a pourtant ecrit juste en dessous.
     *
     * **Le defaut est anterieur a ce chantier et n est pas corrige ici** — le corriger elargirait le
     * candidat. Il part en dette, priorite haute : une erreur provoquee par une entree utilisateur
     * ne doit pas faire tomber un controleur.
     *
     * Ce que cet essai etablit, c est sa **portee**, parce qu une dette qu on garde doit etre une
     * dette qu on connait : l exception part **avant** `queue->add()`, donc aucune file ne nait et
     * aucune ressource ne bouge. Et il continuera de passer le jour ou le refus deviendra propre :
     * il n exige pas le 500, il exige que rien ne se soit produit.
     */
    public function testUneDemandeSansJetonNeConstruitRienEtNeDebiteRien(): void
    {
        $objet = ObjectService::getObjectByMachineName('surveillance_network');

        $this->playerSetResearchLevel('espionage_technology', 4);
        $this->playerSetResearchLevel('computer_technology', 2);
        $this->planetAddResources(new Resources(500_000, 500_000, 500_000, 0));
        $this->planetService->reloadPlanet();

        $metalAvant = (int)$this->planetService->metal()->get();
        $cristalAvant = (int)$this->planetService->crystal()->get();
        $fileAvant = resolve(BuildingQueueService::class)->retrieveQueueItems($this->planetService)->count();

        try {
            $this->post(route('facilities.addbuildrequest.post'), [
                'technologyId' => $objet->id,
                // Pas de `_token` : c est tout l objet de cet essai.
            ]);
        } catch (Throwable) {
            // L exception est le defaut lui-meme. Ce qui suit mesure ce qu elle a laisse derriere.
        }

        $this->planetService->reloadPlanet();

        $this->assertSame(
            $fileAvant,
            resolve(BuildingQueueService::class)->retrieveQueueItems($this->planetService)->count(),
            'Une demande sans jeton a quand meme cree un element de file.'
        );

        $this->assertSame($metalAvant, (int)$this->planetService->metal()->get(), 'Une demande sans jeton a debite du metal.');
        $this->assertSame($cristalAvant, (int)$this->planetService->crystal()->get(), 'Une demande sans jeton a debite du cristal.');

        $this->assertSame(
            0,
            $this->planetService->getObjectLevel('surveillance_network'),
            'Une demande sans jeton a fait monter un niveau.'
        );
    }

    /**
     * **Sur une planete, et nulle part ailleurs.**
     *
     * `valid_planet_types` vide ne veut pas dire « rien de precise » : `objectValidPlanetType()`
     * rend **vrai** dans ce cas, donc « partout, lune comprise ». Or `SurveillanceWatch` ne
     * regarde que des planetes — ses deux requetes filtrent sur `planet_type`. Un detecteur pose
     * sur une lune aurait donc ete construit, paye, affiche, et aveugle.
     *
     * Ce defaut est sorti d une comparaison **champ par champ** avec les onze autres batiments de
     * la station : cinq d entre eux se declarent propres aux planetes, trois propres aux lunes, et
     * celui-ci ne declarait rien. Relire l objet seul ne l aurait pas montre.
     */
    public function testIlNeSeConstruitQueSurUnePlanete(): void
    {
        $objet = ObjectService::getObjectByMachineName('surveillance_network');

        $this->assertSame(
            [PlanetType::Planet],
            $objet->valid_planet_types,
            'Le reseau se laisse construire hors d une planete : il y serait aveugle.'
        );

        // Et la regle du jeu le dit, pas seulement la declaration : c est cette fonction que la
        // page et la file interrogent.
        $this->assertTrue(
            ObjectService::objectValidPlanetType('surveillance_network', $this->planetService),
            'Le reseau est refuse sur une planete ordinaire.'
        );
    }

    /**
     * **Et une fois construit, il sert.**
     *
     * Le niveau vit dans `planets.surveillance_network`, et c est cette colonne que
     * `SurveillanceWatch` interroge. Un batiment qui monterait une autre colonne serait construit,
     * paye, affiche — et aveugle.
     */
    public function testUneFoisConstruitLaVeilleLeVoit(): void
    {
        $objet = ObjectService::getObjectByMachineName('surveillance_network');

        $this->planetService->setObjectLevel($objet->id, 1, true);
        $this->planetService->reloadPlanet();

        $coords = $this->planetService->getPlanetCoordinates();

        $this->assertSame(
            1,
            resolve(SurveillanceWatch::class)->bestTierOf($this->currentUserId, $coords->galaxy, $coords->system)?->value,
            'La veille ne voit pas le detecteur construit : le niveau et la colonne lue ne sont pas les memes.'
        );
    }
}
