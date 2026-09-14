<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use OGame\Models\BuildingQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\AccountTestCase;

/**
 * **La cle a molette de la liste des planetes dit ce qui se construit vraiment.**
 *
 * ## Le defaut, tel que Keven l a vu
 *
 * Une construction lancee sur une seconde planete se termine ; la cle a molette reste allumee a cote de cette
 * planete, et **aucun rechargement ne l enleve**. Il faut cliquer sur la planete pour qu elle disparaisse.
 *
 * ## Pourquoi
 *
 * Deux faits qui, separement, sont normaux :
 *
 * - l intergiciel du jeu ne met a jour que **la planete courante** (`GlobalGame`), et c est cette mise a jour
 *   qui marque une file terminee comme traitee ;
 * - l icone se lisait sur le **nombre de lignes non traitees** de la file, sans regarder leur echeance.
 *
 * Ensemble, ils donnent une icone qui survit a la fin du travail : la ligne reste « non traitee » tant que le
 * joueur ne va pas sur la planete, et l icone la suit. Ce n est pas un defaut de temps reel — c est un
 * affichage qui ment, meme apres un rechargement complet.
 *
 * ## Ce que ces essais tiennent
 *
 * L icone suit **le travail qui reste**, pas les lignes qui restent : une echeance passee ne compte plus, une
 * ligne pas encore commencee compte, et la couleur suit le meme travail que l icone.
 */
class PlanetListConstructionIconTest extends AccountTestCase
{
    private PlanetService $autrePlanete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autrePlanete = $this->createPlanetAtSafeCoordinate($this->currentUserId);
    }

    /**
     * **Le defaut lui-meme** : une construction terminee sur une autre planete n allume plus la cle.
     */
    public function testAFinishedBuildOnAnotherPlanetNoLongerShowsTheWrench(): void
    {
        $this->uneLigneDeFile(debut: -600, fin: -60, commencee: true);

        $reponse = $this->get('/overview');
        $reponse->assertStatus(200);

        // **La premisse qui fait tout le defaut** : la ligne est toujours la, non traitee. Si le chargement de
        // la page l avait traitee, cet essai passerait pour une autre raison que celle qu il vise.
        $this->assertSame(
            1,
            BuildingQueue::where('planet_id', $this->autrePlanete->getPlanetId())->where('processed', 0)->count(),
            'La file de l autre planete a ete traitee par ce chargement : le defaut vise n est pas reproduit.'
        );

        $this->assertArrayNotHasKey(
            $this->autrePlanete->getPlanetId(),
            $this->lesClesAffichees($reponse),
            'La cle a molette reste allumee sur une planete dont la construction est terminee.'
        );
    }

    /**
     * **Le temoin contraire** : une construction en cours allume bien la cle.
     *
     * Sans lui, le precedent passerait aussi bien si l icone avait disparu pour toujours.
     */
    public function testABuildStillRunningShowsTheWrench(): void
    {
        $this->uneLigneDeFile(debut: -60, fin: 3_600, commencee: true);

        $cles = $this->lesClesAffichees($this->get('/overview'));

        $this->assertArrayHasKey($this->autrePlanete->getPlanetId(), $cles, 'Une construction en cours n allume pas la cle a molette.');
        $this->assertFalse($cles[$this->autrePlanete->getPlanetId()], 'La cle est rouge alors que rien n est en demolition.');
    }

    /**
     * **Une ligne pas encore commencee est du travail** : elle demarrera des que la precedente sera appliquee.
     */
    public function testAQueuedItemBehindAFinishedOneKeepsTheWrench(): void
    {
        $this->uneLigneDeFile(debut: -600, fin: -60, commencee: true);
        $this->uneLigneDeFile(debut: 0, fin: 0, commencee: false, niveau: 2);

        $this->assertArrayHasKey(
            $this->autrePlanete->getPlanetId(),
            $this->lesClesAffichees($this->get('/overview')),
            'Une construction en attente derriere une construction terminee n allume plus la cle.'
        );
    }

    /**
     * **La couleur suit le travail en cours**, pas une demolition deja finie.
     */
    public function testTheRedWrenchFollowsTheWorkThatRemains(): void
    {
        $this->uneLigneDeFile(debut: -600, fin: -60, commencee: true, demolition: true);
        $this->uneLigneDeFile(debut: -60, fin: 3_600, commencee: true, niveau: 2);

        $cles = $this->lesClesAffichees($this->get('/overview'));

        $this->assertArrayHasKey($this->autrePlanete->getPlanetId(), $cles, 'La construction en cours n allume pas la cle.');
        $this->assertFalse($cles[$this->autrePlanete->getPlanetId()], 'La cle est rouge a cause d une demolition deja terminee.');
    }

    /**
     * **Et une demolition en cours, elle, rend bien la cle rouge.**
     */
    public function testARunningDowngradeShowsTheRedWrench(): void
    {
        $this->uneLigneDeFile(debut: -60, fin: 3_600, commencee: true, demolition: true);

        $cles = $this->lesClesAffichees($this->get('/overview'));

        $this->assertArrayHasKey($this->autrePlanete->getPlanetId(), $cles, 'La demolition en cours n allume pas la cle.');
        $this->assertTrue($cles[$this->autrePlanete->getPlanetId()], 'Une demolition en cours ne rend pas la cle rouge.');
    }

    /**
     * Une ligne de file sur l autre planete, aux instants demandes — relatifs a maintenant.
     */
    private function uneLigneDeFile(int $debut, int $fin, bool $commencee, int $niveau = 1, bool $demolition = false): void
    {
        $maintenant = (int)Date::now()->timestamp;

        $ligne = new BuildingQueue();
        $ligne->planet_id = $this->autrePlanete->getPlanetId();
        $ligne->object_id = ObjectService::getObjectByMachineName('metal_mine')->id;
        $ligne->object_level_target = $niveau;
        $ligne->time_duration = max(0, $fin - $debut);
        $ligne->time_start = $commencee ? $maintenant + $debut : 0;
        $ligne->time_end = $commencee ? $maintenant + $fin : 0;
        $ligne->building = $commencee ? 1 : 0;
        $ligne->processed = 0;
        $ligne->canceled = 0;
        $ligne->is_downgrade = $demolition;
        $ligne->save();
    }

    /**
     * Les planetes dont la liste affiche une cle a molette, et si elle est rouge.
     *
     * @return array<int, bool> identifiant de la planete => cle rouge
     */
    private function lesClesAffichees(TestResponse $reponse): array
    {
        preg_match_all('#<a class="constructionIcon.*?</a>#s', (string)$reponse->getContent(), $ancres);

        $cles = [];

        foreach ($ancres[0] as $ancre) {
            if (preg_match('/cp=(\d+)/', $ancre, $trouve) === 1) {
                $cles[(int)$trouve[1]] = str_contains($ancre, 'icon_wrench_red');
            }
        }

        return $cles;
    }
}
