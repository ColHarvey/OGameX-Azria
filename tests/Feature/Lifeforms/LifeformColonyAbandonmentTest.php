<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Exceptions\UnknownAdmissionHistory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Bonuses\LifeformPurgedBodies;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Combat\LifeformCombatPhotographer;
use OGame\Lifeforms\LifeformHistoryUnavailable;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Services\PlanetService;
use RuntimeException;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Une colonie abandonnee ne change pas le passe, et une colonie purgee ne le reduit pas en silence** (constats de
 * Keven, 19 septembre 2026, journal §167).
 *
 * Trois defauts, trois moities de la meme regle :
 *
 * 1. **La selection a l instant.** Les bonus d un compte a un instant passe (l arrivee d une flotte) prenaient les
 *    colonies **encore actives aujourd hui** : une colonie abandonnee apres l arrivee disparaissait du calcul, et la
 *    flotte perdait un bonus qu elle avait. Elles se choisissent desormais a l instant demande.
 * 2. **La memoire des bonus.** L abandon ne la videait pas : un processus qui avait deja calcule les bonus gardait
 *    ceux de la colonie jusqu a expiration. L observateur de planete la vide a l abandon comme a la suppression.
 * 3. **La purge.** Vingt-quatre heures au moins apres l abandon, la purge efface la colonie et, en cascade, tous ses
 *    faits. Une lecture a un instant ou elle existait rendait alors un bonus reduit, sans un mot. Une trace garde
 *    desormais « cette colonie a existe de A a B », et la lecture de cet intervalle dit qu elle ne peut plus etre
 *    reconstruite — le combat se suspend, il ne s arme pas au rabais.
 *
 * La meme technologie, au meme niveau, est posee sur la planete mere et sur la colonie : leurs deux apports sont egaux,
 * donc « les deux comptent » vaut exactement le double de « la planete mere seule ». Un calcul faux ne tombe pas
 * par hasard sur cette egalite.
 */
final class LifeformColonyAbandonmentTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    /** Batteries volcaniques (Rock tal) : +production d energie, un apport par planete qui la porte. */
    private const int VOLCANIC_BATTERIES = 12201;

    /** L emplacement 1 : palier 1, position 1, celui des Batteries volcaniques. */
    private const int SLOT = 1;

    private const float DELTA = 1e-9;

    private int $mere;

    private int $colonie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1]);
        $this->mere = $this->currentPlanetId;
        $this->colonie = (int)$this->secondPlanetService?->getPlanetId();
        $this->assertGreaterThan(0, $this->colonie, 'Premisse : le compte a une colonie.');
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        DB::table('lifeform_purged_bodies')->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * **Lecture historique, memoire videe** : avant l abandon les deux colonies comptent, apres seule la conservee.
     *
     * La memoire est videe a la main apres l abandon, a dessein : ce temoin mesure la **selection**, et une valeur
     * gardee en memoire depuis la premiere lecture le ferait passer meme avec le defaut.
     */
    public function testAColonyCountsUntilItsAbandonmentAndNotAfter(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $abandon = $this->aLittleLater(3600);

        // Premisses, lues AVANT l abandon aux instants qui seront relus : la population y est reconstituable.
        $avant = $this->energyAt($abandon - 60);
        $this->assertGreaterThan(0.0, $avant, 'Premisse : les deux technologies comptent avant l abandon.');

        $this->abandonTheColony();
        $this->aLittleLater(120);
        LifeformBonusCache::invalidate();

        $this->assertEqualsWithDelta($avant, $this->energyAt($abandon - 60), self::DELTA, 'La colonie existait a cet instant : elle compte encore — le passe ne change pas.');
        $meresSeule = $this->energyAt($abandon + 60);
        $this->assertEqualsWithDelta($avant / 2, $meresSeule, self::DELTA, 'Apres l abandon, seule la planete mere compte : la moitie exacte.');
        $this->assertGreaterThan(0.0, $meresSeule, 'La planete mere, elle, compte toujours.');
        // La frontiere : un fait date exactement de l instant compte comme deja survenu (`LifeformLevels::levelsAt()`).
        $this->assertEqualsWithDelta($meresSeule, $this->energyAt($abandon), self::DELTA, 'A l instant meme de l abandon, la colonie ne compte plus.');
    }

    /**
     * **Lecture actuelle, sans vider la memoire** : precharger, abandonner, relire aussitot.
     *
     * La memoire suit l horloge reelle (`time()`), que le banc ne gele pas : un passage long ferait expirer l entree
     * d elle-meme et le temoin passerait sans invalidation. Il exige donc **les deux** : la generation de la memoire
     * avance pendant l abandon — ce que seule une invalidation fait, quelle que soit la duree —, et les bonus relus
     * sont ceux de la planete mere seule.
     */
    public function testAnAbandonmentReleasesTheMemoryAtOnceAndKeepsTheOtherColonies(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $resolveur = resolve(LifeformBonusResolver::class);
        $avant = $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION);
        $this->assertGreaterThan(0.0, $avant, 'Premisse : la lecture actuelle est en memoire, avec les deux planetes.');
        $generation = LifeformBonusCache::generation();

        $this->abandonTheColony();

        $this->assertGreaterThan($generation, LifeformBonusCache::generation(), 'L abandon a vide la memoire des bonus.');
        $apres = $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION);
        $this->assertEqualsWithDelta($avant / 2, $apres, self::DELTA, 'La contribution de la colonie a disparu, celle de la planete mere reste.');
    }

    /**
     * La suppression definitive vide la memoire elle aussi.
     */
    public function testAPurgeReleasesTheMemoryToo(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $this->abandonTheColony();
        $this->aLittleLater(86400 + 60);
        resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId);
        $generation = LifeformBonusCache::generation();

        $this->purgeTheColony();

        $this->assertGreaterThan($generation, LifeformBonusCache::generation(), 'La purge a vide la memoire des bonus.');
    }

    /**
     * **Une suppression directe, sans abandon, relache aussi la memoire — et les valeurs suivent.** C est le cas ou
     * l invalidation au `deleted` change ce que le joueur lit : la colonie comptait encore au present (aucun abandon
     * ne l avait retiree), et seule la suppression la retire. Precharger, supprimer, relire aussitot sans vider.
     */
    public function testADirectDeletionReleasesTheMemoryAndTheValuesFollowAtOnce(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $resolveur = resolve(LifeformBonusResolver::class);
        $avant = $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION);
        $this->assertGreaterThan(0.0, $avant, 'Premisse : la lecture actuelle est en memoire, avec les deux planetes.');
        $generation = LifeformBonusCache::generation();

        $this->purgeTheColony();

        $this->assertGreaterThan($generation, LifeformBonusCache::generation(), 'La suppression a vide la memoire des bonus.');
        $apres = $resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION);
        $this->assertEqualsWithDelta($avant / 2, $apres, self::DELTA, 'La colonie supprimee ne compte plus, la planete mere reste.');
    }

    /**
     * **Suppression definitive apres abandon** : une lecture dans `[A, B)` dit que les faits manquent, jamais un bonus
     * reduit ; une lecture hors de l intervalle n en est pas genee.
     */
    public function testAPurgedColonyMakesItsPastUnknownInsteadOfSmaller(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $naissance = (int)Planet::query()->whereKey($this->colonie)->first()?->created_at?->getTimestamp();
        $abandon = $this->aLittleLater(3600);
        $avant = $this->energyAt($abandon - 60);
        $this->abandonTheColony();
        $purge = $this->aLittleLater(86400 + 60);

        $this->purgeTheColony();

        $trace = DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->first();
        $this->assertNotNull($trace, 'La purge laisse une trace.');
        $this->assertSame($this->currentUserId, (int)$trace->user_id, 'Le proprietaire historique.');
        $this->assertSame($naissance, (int)$trace->existed_from, 'A : la date historique de naissance, pas celle de la table.');
        $this->assertSame($abandon, (int)$trace->existed_until, 'B : l abandon, pas la purge.');
        $this->assertSame($purge, (int)$trace->purged_at);
        $this->assertNull(Planet::query()->find($this->colonie), 'Premisse : le corps a disparu.');
        // L intervalle est [A, B) : A compris, B exclu. Avant A, la colonie n existait pas : rien ne manque.
        $this->assertNull(LifeformPurgedBodies::purgedAt($this->currentUserId, $naissance - 1), 'Avant A, la colonie n existait pas.');
        $this->assertNotNull(LifeformPurgedBodies::purgedAt($this->currentUserId, $naissance), 'A est compris.');
        $this->assertNotNull(LifeformPurgedBodies::purgedAt($this->currentUserId, $abandon - 1), 'Juste avant B, les faits manquent.');
        $this->assertNull(LifeformPurgedBodies::purgedAt($this->currentUserId, $abandon), 'B est exclu.');

        LifeformBonusCache::invalidate();
        try {
            resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $abandon - 60);
            $this->fail('Une lecture a un instant ou la colonie existait a rendu un bonus : il valait ' . $avant . ' et ses faits sont purges.');
        } catch (LifeformHistoryUnavailable $manque) {
            $this->assertStringContainsString((string)$this->colonie, $manque->getMessage(), 'Le signal nomme la colonie purgee.');
        }

        // La photographie d un combat traduit ce manque en suspension explicite.
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertNotNull($joueur);
        try {
            resolve(LifeformCombatPhotographer::class)->ofPlayer($joueur, $abandon - 60);
            $this->fail('La photographie a gele un bonus reduit au lieu de suspendre.');
        } catch (UnknownAdmissionHistory) {
            $this->addToAssertionCount(1);
        }

        // Hors de [A, B) : la colonie n existait plus, rien ne manque.
        $this->assertGreaterThan(0.0, $this->energyAt($abandon + 60), 'Apres B, la lecture n est pas genee : seule la planete mere comptait.');
        $this->assertEqualsWithDelta($avant / 2, $this->energyAt($abandon + 60), self::DELTA);
    }

    /**
     * **Une suppression directe, sans abandon**, ferme l intervalle a l instant de la suppression.
     */
    public function testADirectDeletionWithoutAbandonmentEndsTheIntervalAtTheDeletion(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $suppression = $this->aLittleLater(600);

        $this->purgeTheColony();

        $this->assertSame($suppression, (int)DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->value('existed_until'), 'B est l instant de la suppression.');
        LifeformBonusCache::invalidate();
        $this->expectException(LifeformHistoryUnavailable::class);
        resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $suppression - 60);
    }

    /**
     * **Une naissance inconnue n est jamais inventee** : A reste vide, et la trace couvre tout instant anterieur a B —
     * on ne peut pas prouver que la colonie n existait pas encore.
     */
    public function testAnUnknownBirthIsNeverInventedAndCoversEverythingBeforeTheEnd(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        DB::table('planets')->where('id', $this->colonie)->update(['created_at' => null]);
        $abandon = $this->aLittleLater(600);
        $this->abandonTheColony();
        $this->aLittleLater(86400 + 60);

        $this->purgeTheColony();

        $this->assertNotNull(DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->first(), 'Premisse : la trace existe.');
        $this->assertNull(DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->value('existed_from'), 'A reste vide.');
        // Eprouve sur la requete de la trace, et non par une lecture des bonus : trente jours avant, la population n est
        // pas reconstituable, et la lecture leverait pour CETTE raison — le temoin passerait sans rien prouver.
        $this->assertNotNull(LifeformPurgedBodies::purgedAt($this->currentUserId, $abandon - 86400 * 30), 'Sans naissance connue, la trace couvre tout instant anterieur a B.');
        $this->assertNull(LifeformPurgedBodies::purgedAt($this->currentUserId, $abandon), 'A B, la colonie ne compte plus : rien ne manque.');
    }

    /**
     * **La suppression d un compte laisse la trace de ses colonies** : elle efface ses corps en masse, sans passer par
     * aucun observateur, et doit donc l ecrire elle-meme — B est l instant de la suppression, puisqu aucune n etait
     * abandonnee.
     */
    public function testDeletingAnAccountLeavesTheTraceOfItsLifeformColonies(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $compte = $this->currentUserId;
        $suppression = $this->aLittleLater(600);

        $joueur = resolve(PlayerServiceFactory::class)->make($compte, true);
        $this->assertNotNull($joueur);
        $joueur->delete();

        $this->assertSame(0, Planet::query()->where('user_id', $compte)->count(), 'Premisse : les corps du compte sont supprimes.');
        $traces = DB::table('lifeform_purged_bodies')->where('user_id', $compte)->orderBy('planet_id')->get();
        $this->assertSame([$this->mere, $this->colonie], $traces->pluck('planet_id')->map(static fn ($id): int => (int)$id)->all(), 'Les deux planetes peuplees laissent leur trace, et elles seules.');
        foreach ($traces as $trace) {
            $this->assertSame($suppression, (int)$trace->existed_until, 'Sans abandon, B est l instant de la suppression.');
        }
    }

    /**
     * **Si la suppression echoue, aucune fausse trace** : trace et suppression partent ensemble, ou ne partent pas.
     */
    public function testAFailedDeletionLeavesNoTraceAndTheBodyStays(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $this->aLittleLater(600);

        try {
            DB::transaction(function (): void {
                $this->purgeTheColony();
                throw new RuntimeException('echec simule apres la suppression');
            });
        } catch (RuntimeException) {
        }

        $this->assertNotNull(Planet::query()->find($this->colonie), 'La suppression annulee a rendu le corps.');
        $this->assertSame(0, DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->count(), 'Aucune fausse trace.');
    }

    /**
     * **Un effacement refuse par la base ne laisse rien derriere lui** : le releve pris avant lui ne survit pas pour
     * fabriquer, plus tard, la trace d un autre effacement.
     *
     * La base refuse ici par sa propre contrainte — le compte designe encore la colonie comme planete courante
     * (`users.planet_current`) : le crochet `deleting` a releve, `deleted` ne vient jamais. La colonie perd ensuite ses
     * formes de vie, puis s efface pour de bon : elle n armait plus rien, elle ne laisse rien.
     */
    public function testADeletionRefusedByTheDatabaseLeavesNoReadingForTheNextOne(): void
    {
        $this->theSameTechnologyOnBothPlanets();
        $this->aLittleLater(600);
        $this->assertTrue(LifeformPlanet::query()->where('planet_id', $this->colonie)->exists(), 'Premisse : la colonie porte des formes de vie.');

        $courante = DB::table('users')->where('id', $this->currentUserId)->value('planet_current');
        DB::table('users')->where('id', $this->currentUserId)->update(['planet_current' => $this->colonie]);
        $refusee = false;
        try {
            Planet::query()->findOrFail($this->colonie)->delete();
        } catch (QueryException) {
            $refusee = true;
        }
        DB::table('users')->where('id', $this->currentUserId)->update(['planet_current' => $courante]);
        $this->assertTrue($refusee, 'Premisse : la base a refuse l effacement.');
        $this->assertNotNull(Planet::query()->find($this->colonie), 'Le corps est toujours la.');
        $this->assertSame(0, DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->count(), 'Un effacement refuse ne laisse aucune trace.');

        LifeformPlanet::query()->where('planet_id', $this->colonie)->delete();
        $this->aLittleLater(600);
        $this->purgeTheColony();

        $this->assertNull(Planet::query()->find($this->colonie), 'Premisse : la colonie est effacee.');
        $this->assertSame(0, DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->count(), 'Sans formes de vie a son effacement, elle ne laisse rien — pas meme la trace relevee avant le refus.');
    }

    /**
     * Un corps sans formes de vie ne laisse rien : il n armait rien.
     */
    public function testABodyWithoutLifeformsLeavesNoTrace(): void
    {
        LifeformPlanet::query()->where('planet_id', $this->colonie)->delete();
        $this->aLittleLater(600);

        $this->purgeTheColony();

        $this->assertSame(0, DB::table('lifeform_purged_bodies')->where('planet_id', $this->colonie)->count());
    }

    private function theSameTechnologyOnBothPlanets(): void
    {
        foreach ([$this->mere, $this->colonie] as $planete) {
            // Une population **stationnaire** : logement et ferme a son niveau. Une population posee seule fondait une heure
            // plus tard au rejeu, l emplacement se refermait, et le temoin aurait mesure la demographie au lieu de l abandon.
            $this->sustainLifeformPopulation($planete, Species::Rocktal, 2000000.0, (int)Date::now()->timestamp);
            $this->placeLifeformSlot($planete, self::SLOT, self::VOLCANIC_BATTERIES, (int)Date::now()->timestamp);
            resolve(LifeformLevels::class)->setLevel($planete, LifeformKind::Technology, self::VOLCANIC_BATTERIES, 10);
        }
        LifeformBonusCache::invalidate();
    }

    private function energyAt(int $instant): float
    {
        return resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId, $instant)->fraction(LifeformEffect::ENERGY_PRODUCTION);
    }

    private function abandonTheColony(): void
    {
        $this->colonyService()->abandonPlanet();
    }

    private function purgeTheColony(): void
    {
        $this->colonyService()->permanentlyDeletePlanet();
    }

    private function colonyService(): PlanetService
    {
        $service = resolve(PlanetServiceFactory::class)->make($this->colonie, true);
        $this->assertNotNull($service);

        return $service;
    }

    /**
     * Avance l horloge du banc et rend le nouvel instant.
     */
    private function aLittleLater(int $secondes): int
    {
        $this->travelTo(Date::now()->addSeconds($secondes));

        return (int)Date::now()->timestamp;
    }
}
