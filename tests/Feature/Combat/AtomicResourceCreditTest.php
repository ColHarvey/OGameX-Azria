<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\CombatResolutionService;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use ReflectionClass;
use Tests\TestCase;

/**
 * Un credit de ressources ecrit en base, jamais depuis le stock qu'on avait lu.
 *
 * ## La course que le modele en memoire ne voit pas
 *
 * `addResources()` prend le stock **tel qu'il a ete charge**, y ajoute sa part, et sauve la somme.
 * Entre le chargement et la sauvegarde, le corps a pu produire, recevoir un transport ou terminer
 * une construction : ces ecritures-la ne prennent aucun verrou de combat, donc rien ne les retient,
 * et la somme recalculee les efface.
 *
 * Le Faucheur defenseur creditait ainsi la cible a la fin d'une bataille. C'est la premiere des deux
 * ecritures economiques que l'application relisait vivantes ; la seconde est la cargaison d'une
 * Defense ACS, reduite en proportion de sa capacite survivante, et cette classe garde les deux.
 *
 * ## Ce que cet essai fait, et pourquoi ainsi
 *
 * Il n'y a pas deux connexions ici : SQLite ne les donnerait pas, et la course ne se rejoue pas.
 * L'essai **simule** l'ecriture concurrente en modifiant la ligne apres le chargement du service —
 * ce qui est exactement l'etat que la course produit. Un credit qui repart du stock charge perd
 * cette modification ; un credit fait par la base la garde.
 */
class AtomicResourceCreditTest extends TestCase
{
    /**
     * Chaque essai vit dans sa transaction : les corps qu'il cree occupent des coordonnees que la
     * table impose uniques, et la base est partagee entre les essais d'un meme processus.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Une ecriture survenue apres le chargement survit au credit.
     */
    public function testACreditKeepsWhatAnotherWriteAddedAfterTheLoad(): void
    {
        $corps = $this->aBodyWith(1_000, 2_000, 3_000);
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        // Ce que le service a en memoire.
        $this->assertSame(1_000, (int)$service->metal()->get());

        // **Une autre ecriture passe**, sans verrou de combat : production, transport, chantier.
        DB::table('planets')->where('id', $corps->id)->update([
            'metal' => 1_500,
            'crystal' => 2_500,
            'deuterium' => 3_500,
        ]);

        $service->addResourcesAtomic(new Resources(100, 200, 300, 0));

        $ligne = DB::table('planets')->where('id', $corps->id)->first();
        $this->assertNotNull($ligne);

        // 1500 + 100, et non 1000 + 100 : la modification concurrente n'a pas ete effacee.
        $this->assertSame(1_600, (int)$ligne->metal, 'The credit overwrote a change made after the load.');
        $this->assertSame(2_700, (int)$ligne->crystal, 'The credit overwrote a change made after the load.');
        $this->assertSame(3_800, (int)$ligne->deuterium, 'The credit overwrote a change made after the load.');
    }

    /**
     * Le modele en memoire suit la base, pour l'appelant qui l'affiche ensuite.
     */
    public function testTheInMemoryModelFollowsTheCredit(): void
    {
        $corps = $this->aBodyWith(10, 20, 30);
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        $service->addResourcesAtomic(new Resources(5, 6, 7, 0));

        $this->assertSame(15, (int)$service->metal()->get(), 'The in-memory model did not follow the credit.');
        $this->assertSame(26, (int)$service->crystal()->get(), 'The in-memory model did not follow the credit.');
        $this->assertSame(37, (int)$service->deuterium()->get(), 'The in-memory model did not follow the credit.');
    }

    /**
     * Un credit vide n'ecrit rien du tout.
     */
    public function testAnEmptyCreditWritesNothing(): void
    {
        $corps = $this->aBodyWith(10, 20, 30);
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        $ecritures = 0;
        DB::listen(function ($requete) use (&$ecritures): void {
            if (str_starts_with(strtolower(trim($requete->sql)), 'update "planets"')) {
                $ecritures++;
            }
        });

        $service->addResourcesAtomic(new Resources(0, 0, 0, 0));

        $this->assertSame(0, $ecritures, 'An empty credit still wrote to the planets table.');
    }

    /**
     * La resolution ne credite aucun corps depuis le stock qu'elle a lu.
     *
     * ## Pourquoi une garde de source, et non une observation
     *
     * Rejouer la course demanderait deux connexions concurrentes pendant une bataille : SQLite ne
     * les donne pas, et l'epreuve appartient a MariaDB. Ce que l'on peut tenir ici, c'est qu'aucun
     * credit de la resolution ne repasse par le modele en memoire — la forme exacte du defaut que
     * le Faucheur defenseur portait.
     *
     * Elle ne prouve pas que le credit atomique tient sous concurrence ; elle prouve qu'un futur
     * passage ne remettra pas le credit en memoire sans que personne ne le voie.
     */
    public function testTheResolutionNeverCreditsABodyFromTheStockItRead(): void
    {
        $fichier = (new ReflectionClass(CombatResolutionService::class))->getFileName();
        $this->assertNotFalse($fichier);

        $source = (string)file_get_contents($fichier);

        $this->assertStringNotContainsString(
            '->addResources(',
            $source,
            'The resolution credits a body from the stock it had read: a concurrent write would be erased.'
        );
        $this->assertStringContainsString(
            '->addResourcesAtomic(',
            $source,
            'The resolution no longer credits the defender Reapers at all.'
        );
    }

    /**
     * La cargaison d'un renfort vient du contexte, jamais de la ligne de la mission.
     *
     * ## Pourquoi une garde de source ici aussi
     *
     * L'observer de bout en bout demanderait une bataille reglee dont un renfort **survit** : une
     * issue que le moteur tire, et qu'un essai ne peut fixer sans devenir une epreuve du moteur
     * plutot que de la couture. Cette preuve-la appartient au lot des simulations, ou l'issue se
     * commande.
     *
     * Ce que l'on tient ici : la resolution demande la cargaison de depart au contexte
     * d'application — gele sur le chemin durable, vivant sur le chemin instantane — et ne relit plus
     * les colonnes de la mission au moment ou elle les reecrit.
     */
    public function testTheResolutionAsksTheContextForAHeldFleetCargo(): void
    {
        $fichier = (new ReflectionClass(CombatResolutionService::class))->getFileName();
        $this->assertNotFalse($fichier);

        $source = preg_replace('/\s+/', ' ', (string)file_get_contents($fichier));
        $this->assertNotNull($source);

        $this->assertStringContainsString(
            '$cargaisonDeDepart = $context->heldFleetCargo((int)$defendMission->id);',
            $source,
            'The resolution no longer asks the context for the cargo a reinforcement was carrying.'
        );

        foreach (['metal', 'crystal', 'deuterium'] as $champ) {
            $this->assertStringNotContainsString(
                '$defendMission->' . $champ . ' * $survivalRate',
                $source,
                'The resolution scales the ' . $champ . ' it re-read from the mission row.'
            );
        }
    }

    private function aBodyWith(int $metal, int $crystal, int $deuterium): Planet
    {
        // **Une position libre, cherchee et non supposee.** La table impose l'unicite des
        // coordonnees, et la base est partagee entre les essais d'un meme processus : une position
        // ecrite en clair finit par etre occupee par une autre classe, et l'essai echoue sur une
        // contrainte qui n'a rien a voir avec ce qu'il verifie.
        $systeme = (int)DB::table('planets')->where('galaxy', 9)->max('system');
        $systeme = max($systeme, 0) + 1;

        $planete = Planet::factory()->create([
            'user_id' => User::factory()->create()->id,
            'galaxy' => 9,
            'system' => $systeme,
            'planet' => 1,
            'planet_type' => 1,
            'metal' => $metal,
            'crystal' => $crystal,
            'deuterium' => $deuterium,
            // L'horloge de production est mise au futur : sans cela, la relecture du service
            // ajouterait la production ecoulee et les nombres attendus ne tiendraient plus.
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        return $planete;
    }
}
