<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use OGame\Models\Resources;
use OGame\Queues\QueueCapacity;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\ResearchQueueService;
use Tests\AccountTestCase;

/**
 * **Ce qu une file accepte, et ce que le Commandant y change** (regle d Azria arretee par Keven, 19 septembre 2026,
 * journal §171).
 *
 * - construction (batiments ordinaires et de formes de vie) : 1 travail en cours et **4 en attente**, **8 en attente**
 *   avec un Commandant embauche ;
 * - recherche : 1 en cours et 4 en attente, **sans extension** ;
 * - l expiration du Commandant **n annule rien** : les travaux en file restent, seuls les ajouts nouveaux sont refuses.
 *
 * Avant cette regle, la limite etait ecrite en dur a trois endroits, aucun ne consultait les officiers — alors que la
 * page des officiers vend le Commandant en promettant « file de construction portee de 4 a 8 ». Le vocabulaire compte :
 * « le cinquieme » designe ici le cinquieme travail **en attente**, donc le sixieme de la file.
 */
class QueueCapacityTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->planetAddResources(new Resources(50000000, 50000000, 50000000, 0));
    }

    private function ajouterUnBatiment(): TestResponse
    {
        return $this->post('/resources/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => ObjectService::getObjectByMachineName('metal_mine')->id,
        ]);
    }

    private function ajouterUneRecherche(): TestResponse
    {
        return $this->post('/research/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => ObjectService::getObjectByMachineName('energy_technology')->id,
        ]);
    }

    private function enAttente(): int
    {
        return resolve(BuildingQueueService::class)->retrieveQueue($this->planetService)->waitingCount();
    }

    private function embaucherUnCommandant(string $quand = '+1 hour'): void
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $utilisateur = $joueur->getUser();
        $utilisateur->commander_until = Date::parse($quand);
        $utilisateur->save();
        // Le joueur est garde par la fabrique : sans relecture, il lirait encore l officier d avant.
        $joueur->load($this->currentUserId);
    }

    /**
     * Ajoute des travaux jusqu au refus, et rend le nombre accepte **en attente** plus le message du refus.
     *
     * @return array{0: int, 1: string}
     */
    private function jusquAuRefus(callable $ajout, int $bornes = 15): array
    {
        $accepte = 0;
        for ($i = 0; $i < $bornes; $i++) {
            $reponse = $ajout();
            $reponse->assertStatus(200);
            if ($reponse->json('success') === false) {
                return [$accepte, (string)$reponse->json('message')];
            }
            $accepte++;
        }

        $this->fail('La file a tout accepte : aucune limite ne mord.');
    }

    public function testABuildingQueueTakesFourWaitingJobsAndRefusesTheFifth(): void
    {
        [$accepte, $message] = $this->jusquAuRefus(fn (): TestResponse => $this->ajouterUnBatiment());

        // Un travail demarre aussitot ; les quatre suivants attendent. Le cinquieme en attente — le sixieme de la
        // file — est refuse.
        $this->assertSame(5, $accepte, 'Un travail en cours et quatre en attente.');
        $this->assertSame(QueueCapacity::WAITING_BASE, $this->enAttente(), 'Quatre travaux attendent.');
        $this->assertStringContainsString('4', $message, 'Le refus dit combien de travaux peuvent attendre.');
        $this->assertTrue(resolve(BuildingQueueService::class)->retrieveQueue($this->planetService)->isQueueFull(), 'L interface dit « pleine » exactement quand le serveur refuse.');
    }

    public function testACommanderRaisesTheBuildingQueueToEightWaitingJobs(): void
    {
        $this->embaucherUnCommandant();

        [$accepte, $message] = $this->jusquAuRefus(fn (): TestResponse => $this->ajouterUnBatiment(), 20);

        $this->assertSame(9, $accepte, 'Un travail en cours et huit en attente : ce que la page des officiers promet.');
        $this->assertSame(QueueCapacity::WAITING_WITH_COMMANDER, $this->enAttente());
        $this->assertStringContainsString('8', $message);
    }

    public function testTheCommanderDoesNotExtendTheResearchQueue(): void
    {
        $this->embaucherUnCommandant();
        $this->planetSetObjectLevel('research_lab', 3);

        [$accepte] = $this->jusquAuRefus(fn (): TestResponse => $this->ajouterUneRecherche(), 12);

        $this->assertSame(5, $accepte, 'La recherche reste a un travail en cours et quatre en attente, Commandant ou non.');
        $this->assertSame(QueueCapacity::WAITING_BASE, resolve(ResearchQueueService::class)->retrieveQueue($this->planetService)->waitingCount());
    }

    public function testAnExpiredCommanderCancelsNothingAndOnlyRefusesNewJobs(): void
    {
        $this->embaucherUnCommandant();
        $this->jusquAuRefus(fn (): TestResponse => $this->ajouterUnBatiment(), 20);
        $this->assertSame(QueueCapacity::WAITING_WITH_COMMANDER, $this->enAttente(), 'Premisse : huit travaux attendent.');

        $this->embaucherUnCommandant('-1 minute');

        // Rien n est annule : les huit travaux restent en file.
        $this->assertSame(QueueCapacity::WAITING_WITH_COMMANDER, $this->enAttente(), 'L expiration du Commandant n annule aucun travail.');
        // Mais plus rien ne s ajoute tant que la file depasse la limite ordinaire.
        $refus = $this->ajouterUnBatiment();
        $refus->assertStatus(200);
        $this->assertFalse($refus->json('success'), 'Un ajout nouveau est refuse.');
        $this->assertStringContainsString('4', (string)$refus->json('message'), 'Et le refus parle de la limite ordinaire.');
    }
}
