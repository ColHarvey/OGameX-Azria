<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Models\Resources;
use OGame\Patrol\SurveillanceWatch;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

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
