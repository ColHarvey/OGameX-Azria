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
     * **En direct, la veille du bandeau dit ce que la page dit** : la cle d une demolition en cours, sa couleur, et
     * l instant ou elle changera.
     */
    public function testTheLiveStateCarriesARunningDowngradeAndItsEnd(): void
    {
        Date::setTestNow(Date::now());
        $this->uneLigneDeFile(debut: -60, fin: 3_600, commencee: true, demolition: true);

        $etat = $this->lEtatEnDirect();

        $this->assertSame([['planetId' => $this->autrePlanete->getPlanetId(), 'downgrade' => true]], $etat['constructions']);
        $this->assertSame(3_600, $etat['nextChangeIn'], 'La veille ne sait pas quand la cle doit changer : elle attendrait trente secondes.');
    }

    /**
     * **Une construction terminee quitte l etat en direct et ne programme rien** — et la lecture ne traite pas la
     * file : la route du bandeau n ecrit rien.
     */
    public function testAFinishedBuildLeavesTheLiveStateAndSchedulesNothing(): void
    {
        $this->uneLigneDeFile(debut: -600, fin: -60, commencee: true);

        $etat = $this->lEtatEnDirect();

        $this->assertSame([], $etat['constructions'], 'La veille rallume la cle d une construction terminee.');
        $this->assertNull($etat['nextChangeIn'], 'Une construction terminee programme encore une relecture.');
        $this->assertSame(
            1,
            BuildingQueue::where('planet_id', $this->autrePlanete->getPlanetId())->where('processed', 0)->count(),
            'La route du bandeau a traite la file : elle ne devait rien ecrire.'
        );
    }

    /**
     * **Une ligne pas encore commencee garde la cle et ne programme rien** : son echeance n est pas connue tant que la
     * precedente n est pas appliquee.
     */
    public function testAQueuedItemKeepsTheLiveWrenchButSchedulesNothing(): void
    {
        $this->uneLigneDeFile(debut: -600, fin: -60, commencee: true);
        $this->uneLigneDeFile(debut: 0, fin: 0, commencee: false, niveau: 2);

        $etat = $this->lEtatEnDirect();

        $this->assertSame([['planetId' => $this->autrePlanete->getPlanetId(), 'downgrade' => false]], $etat['constructions']);
        $this->assertNull($etat['nextChangeIn']);
    }

    /**
     * **Le prochain changement est la fin la plus proche**, toutes planetes confondues.
     */
    public function testTheNextChangeIsTheEarliestEndAcrossPlanets(): void
    {
        Date::setTestNow(Date::now());
        $this->uneLigneDeFile(debut: -60, fin: 3_600, commencee: true);
        $this->uneLigneDeFile(debut: -60, fin: 120, commencee: true, planete: $this->planetService);

        $etat = $this->lEtatEnDirect();

        $this->assertSame(120, $etat['nextChangeIn'], 'Le prochain changement n est pas la fin la plus proche.');
        $this->assertEqualsCanonicalizing(
            [
                ['planetId' => $this->autrePlanete->getPlanetId(), 'downgrade' => false],
                ['planetId' => $this->planetService->getPlanetId(), 'downgrade' => false],
            ],
            $etat['constructions']
        );
    }

    /**
     * **La page pose la meme echeance sur la liste**, pour que le navigateur la programme des le chargement.
     */
    public function testThePagePublishesTheNextChangeOnThePlanetList(): void
    {
        Date::setTestNow(Date::now());
        $this->uneLigneDeFile(debut: -60, fin: 3_600, commencee: true);

        $this->get('/overview')
            ->assertStatus(200)
            ->assertSee('<div id="planetList" data-construction-next-change-in="3600">', false);
    }

    /**
     * **Une construction qui se termine a cet instant est terminee** — comme pour le traitement du jeu, qui prend
     * les lignes dont `time_end <= maintenant` (`BuildingQueueService`). Ni la page ni la veille ne rallument la cle,
     * et rien n est programme a une echeance deja atteinte.
     */
    public function testABuildEndingThisVerySecondIsFinished(): void
    {
        Date::setTestNow(Date::now());
        $this->uneLigneDeFile(debut: -600, fin: 0, commencee: true);

        $this->assertArrayNotHasKey(
            $this->autrePlanete->getPlanetId(),
            $this->lesClesAffichees($this->get('/overview')),
            'La page allume la cle d une construction terminee a cet instant.'
        );

        $etat = $this->lEtatEnDirect();

        $this->assertSame([], $etat['constructions'], 'La veille allume la cle d une construction terminee a cet instant.');
        $this->assertNull($etat['nextChangeIn'], 'Une echeance deja atteinte programme encore une relecture.');
    }

    /**
     * **Une echeance ecrite sur une ligne pas encore commencee ne programme rien.** `updateTimeEnd()` ecrit la colonne
     * sans regarder le debut : tant que la ligne n a pas commence, son echeance ne dit pas quand la cle changera.
     */
    public function testAnEndWrittenOnAQueuedItemSchedulesNothing(): void
    {
        $this->uneLigneDeFile(debut: 0, fin: 0, commencee: false);
        BuildingQueue::where('planet_id', $this->autrePlanete->getPlanetId())->update(['time_end' => (int)Date::now()->timestamp + 600]);

        $etat = $this->lEtatEnDirect();

        $this->assertSame([['planetId' => $this->autrePlanete->getPlanetId(), 'downgrade' => false]], $etat['constructions']);
        $this->assertNull($etat['nextChangeIn'], 'Une ligne pas encore commencee programme une relecture sur une echeance qui ne dit rien.');
    }

    /**
     * Une ligne de file, sur l autre planete sauf mention contraire, aux instants demandes — relatifs a maintenant.
     */
    private function uneLigneDeFile(int $debut, int $fin, bool $commencee, int $niveau = 1, bool $demolition = false, PlanetService|null $planete = null): void
    {
        $maintenant = (int)Date::now()->timestamp;

        $ligne = new BuildingQueue();
        $ligne->planet_id = ($planete ?? $this->autrePlanete)->getPlanetId();
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
     * L etat de la liste des planetes que la veille du bandeau recoit.
     *
     * @return array<string, mixed>
     */
    private function lEtatEnDirect(): array
    {
        $etat = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json('planetList');

        $this->assertIsArray($etat, 'La route du bandeau ne porte pas l etat de la liste des planetes.');

        return $etat;
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
