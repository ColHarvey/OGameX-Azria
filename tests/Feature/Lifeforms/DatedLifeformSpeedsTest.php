<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformRuleRevision;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\ReturnsLifeformRuleRevisions;

/**
 * **Une revision datee des vitesses gouverne les durees — ou rien ne les change en silence** (journal §166).
 *
 * Les durees de la file ne se calculent pas sur le reglage vivant mais sur les vitesses **datees**
 * (`LifeformRuleRevisions::at()`), et cette lecture a deux proprietes qu il faut connaitre : elle prefere **toute**
 * revision au reglage vivant, et quand aucune ne precede l instant demande elle retombe sur **la plus ancienne**.
 *
 * Une classe d administration qui enregistrait la page des reglages faisait naitre une revision a la vitesse du banc
 * (8) et ne la reprenait pas : toutes les durees de formes de vie du processus etaient ensuite huit fois plus courtes,
 * sans que rien ne le dise. Ce temoin tient les deux moities de la regle — la revision est **prise en compte** quand
 * elle est la, et la table est **rendue** telle qu elle a ete trouvee quand un essai en fait naitre une.
 *
 * **Un de ces essais documente un comportement existant plutot qu une regle decidee** : la retombee sur la plus
 * ancienne revision, qui laisse une configuration ecrite pour demain gouverner hier. Son nom le dit, son commentaire
 * aussi, et la question est remontee a Keven — pour que personne, dans six mois, ne prenne cette retroactivite pour
 * une regle du jeu.
 */
final class DatedLifeformSpeedsTest extends AccountTestCase
{
    use PinsSettings;
    use ReturnsLifeformRuleRevisions;

    /** Sanctuaire (Kaelesh) : un batiment de palier 1, sans prerequis. */
    private const int SANCTUARY = 14101;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1, 'research_speed' => 1]);
        $this->rememberLifeformRuleRevisions();
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(10000000, 10000000, 10000000, 0));
    }

    protected function tearDown(): void
    {
        $this->returnLifeformRuleRevisions();
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * Sans revision, la duree suit la vitesse vivante — celle que cette classe epingle.
     */
    public function testWithoutARevisionTheDurationFollowsTheLiveSpeed(): void
    {
        $this->assertSame(0, LifeformRuleRevision::query()->count(), 'Premisse : aucune revision, pas meme laissee par une voisine.');

        $this->assertSame($this->durationAtSpeed(1.0), $this->queuedDuration(), 'La vitesse vivante vaut 1 : la duree est la duree brute.');
    }

    /**
     * **Une revision fantome est prise en compte, pas ignoree** : la duree est celle de sa vitesse, entiere, jamais un
     * entre-deux. C est la moitie « correctement prise en compte » de la regle.
     */
    public function testAGhostRevisionGovernsTheDurationInFull(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $this->aRevisionAt($maintenant - 60, 8.0);

        $this->assertSame(8.0, resolve(LifeformRuleRevisions::class)->at($maintenant)->economy, 'La revision gouverne la lecture.');
        $this->assertSame($this->durationAtSpeed(8.0), $this->queuedDuration(), 'La duree suit la revision, huit fois plus courte.');
        $this->assertNotSame($this->durationAtSpeed(1.0), $this->durationAtSpeed(8.0), 'Premisse : les deux vitesses ne donnent pas la meme duree.');
    }

    /**
     * **Entre deux revisions qui precedent l instant, c est la plus recente qui gouverne.**
     *
     * Avec une seule revision, « la derniere qui precede » et « la plus ancienne » rendent la meme chose : la regle
     * juste et la regle fausse coincident, et une mutation y survit. Il en faut deux pour que la lecture se prononce
     * (journal §166.1).
     */
    public function testBetweenTwoRevisionsTheLatestOneThatPrecedesGoverns(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $this->aRevisionAt($maintenant - 7200, 2.0);
        $this->aRevisionAt($maintenant - 60, 8.0);

        $this->assertSame(8.0, resolve(LifeformRuleRevisions::class)->at($maintenant)->economy, 'La plus recente qui precede gouverne, pas la plus ancienne.');
        $this->assertSame($this->durationAtSpeed(8.0), $this->queuedDuration());
        $this->assertNotSame($this->durationAtSpeed(2.0), $this->durationAtSpeed(8.0), 'Premisse : les deux revisions ne donnent pas la meme duree.');
    }

    /**
     * **Comportement EXISTANT, documente ici — pas une regle de jeu tranchee.**
     *
     * Une revision datee APRES l instant gouverne quand meme : `at()` retombe sur **la plus ancienne** quand aucune ne
     * precede. Une configuration ecrite « pour demain » reecrit donc le passe, et c est ce qui a rendu le defaut du
     * 19 septembre si difficile a voir. Le meme mecanisme apparait ailleurs : ouvrir les formes de vie sans passer par
     * la page d administration ne pose aucune revision de depart, et la premiere ecrite plus tard s appliquerait alors
     * a toute la demographie deja ecoulee (defaut 8 de l audit de la procedure).
     *
     * **Ce temoin ne dit pas que cette retroactivite est voulue.** Il epingle ce que le code fait aujourd hui, pour
     * qu un changement soit une **decision** et non un accident : le jour ou Keven tranche — retomber sur les vitesses
     * vivantes plutot que sur la plus ancienne, ou poser une revision de depart a l ouverture —, c est ce temoin qu il
     * faudra reecrire, et son nom le dit. La question lui est remontee (journal §166.1).
     */
    public function testARevisionDatedForTomorrowAlreadyGovernsTodayWhichIsExistingBehaviourNotADecision(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $this->aRevisionAt($maintenant + 86400, 8.0);

        $this->assertSame(8.0, resolve(LifeformRuleRevisions::class)->at($maintenant)->economy, 'Aucune revision ne precede : la plus ancienne gouverne.');
        $this->assertSame($this->durationAtSpeed(8.0), $this->queuedDuration());
    }

    /**
     * Et l essai qui fait naitre une revision la rend : la table revient exactement a ce qu elle etait, les lignes
     * trouvees comprises.
     */
    public function testTheTraitGivesBackOnlyWhatTheTestAdded(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $ancienne = $this->aRevisionAt($maintenant - 600, 2.0);

        // Le trait a releve la table AVANT cette ligne : elle est donc « ajoutee ». Une ligne plus ancienne encore,
        // relevee apres coup, doit survivre au retour.
        $this->rememberLifeformRuleRevisions();
        $apres = $this->aRevisionAt($maintenant - 300, 4.0);

        $this->returnLifeformRuleRevisions();

        $this->assertNull(LifeformRuleRevision::query()->find($apres), 'Ce que l essai a ajoute apres le releve repart.');
        $this->assertNotNull(LifeformRuleRevision::query()->find($ancienne), 'Ce que l essai a trouve reste.');

        // **Et cet essai rend aussi ce qu il a fait passer pour « trouve ».** La ligne ci-dessus survit au trait par
        // construction — c est ce qu il prouve —, mais elle ne doit pas survivre a la CLASSE : laissee la, elle
        // gouvernerait les durees de toutes les classes de formes de vie du processus. C est exactement le defaut que
        // ce fichier ferme, et il a rougi ici avant d etre vu : les durees des voisines valaient 2 au lieu de 8.
        LifeformRuleRevision::query()->whereKey($ancienne)->delete();
    }

    /**
     * La duree que la file inscrit pour un Sanctuaire de niveau 1, sur cette planete.
     */
    private function queuedDuration(): int
    {
        $maintenant = (int)Date::now()->timestamp;
        $element = resolve(LifeformQueueService::class)->add($this->planetService, self::SANCTUARY, $maintenant);
        $duree = (int)$element->time_end - $maintenant;
        resolve(LifeformQueueService::class)->cancel($this->planetService, (int)$element->id, $maintenant);
        $this->planetService->reloadPlanet();

        return $duree;
    }

    /**
     * La duree de la formule a cette vitesse de construction — la reference, calculee hors de la file.
     */
    private function durationAtSpeed(float $vitesse): int
    {
        return LifeformFormulas::buildingDuration(
            LifeformCatalogue::byId(self::SANCTUARY),
            1,
            $this->planetService->getObjectLevel('robot_factory'),
            $this->planetService->getObjectLevel('nano_factory'),
            $vitesse
        );
    }

    private function aRevisionAt(int $instant, float $economie): int
    {
        $revision = LifeformRuleRevision::query()->create([
            'effective_at' => $instant,
            'economy_speed' => $economie,
            'research_speed' => 1.0,
            'build_multiplier' => 1.0,
            'research_multiplier' => 1.0,
            'discovery_multiplier' => 1.0,
            'changed_by' => null,
            'note' => 'temoin des vitesses datees',
        ]);

        return (int)$revision->id;
    }
}
