<?php

namespace Tests\Feature;

use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Setting;
use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\SpawnsNpcBases;

/**
 * **Les bases pirates grandissent sans plafond** (decision de Keven, 20 septembre 2026).
 *
 * Le plafond historique se calcule depuis la mediane des scores des joueurs actifs multipliee par
 * `npc_maturity_ratio` : une base qui l atteint cesse de construire et rend `ACTION_CAPPED`. Le reglage
 * `npc_growth_unlimited`, arme par defaut, court-circuite ce refus — et lui seul.
 *
 * ## Ce que ces temoins tiennent, et pourquoi il en faut autant
 *
 * Le piege de ce genre de reglage est l effet de bord. `maturityOf()` sert a **trois** mecaniques : la
 * force d un raid (`max(0.1, maturite / 100)`), une porte de raid a 20 %, et la condition d essaimage
 * (`maturity < 100` lue dans les releves). Rendre cette methode nulle ou la forcer a une valeur
 * particuliere en mode illimite aurait donc change le comportement des pirates bien au-dela de leur
 * croissance. Elle garde son contrat numerique ; c est l **affichage** qui distingue les deux modes.
 *
 * L autre piege est arithmetique : rendre un plafond a zero aurait transforme « aucune limite » en
 * « tout est plafonne », puisque tout score est superieur ou egal a zero. Le refus est donc explicite.
 */
class NpcUnlimitedGrowthTest extends AccountTestCase
{
    use SpawnsNpcBases;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = resolve(SettingsService::class);
        $settings->set('npc_enabled', 1);
        $settings->set('npc_growth_enabled', 1);
        // Un plafond volontairement bas : la base le depassera des ses premieres mines.
        $settings->set('npc_min_score_fixed', 1);
        $settings->set('npc_maturity_ratio', '1.00');
    }

    protected function tearDown(): void
    {
        // Le reglage est global : une classe voisine ne doit pas heriter de ce que celle-ci a pose.
        resolve(SettingsService::class)->set('npc_growth_unlimited', 1);

        parent::tearDown();
    }

    /**
     * Une base deja bien au-dessus de l ancien plafond, pour que la difference entre les deux modes
     * soit observable et non supposee.
     */
    private function uneBaseAuDessusDuPlafond(): PlanetService
    {
        $base = $this->aSpawnedBase();
        $planet = resolve(PlanetServiceFactory::class)->make($base->getPlanetId(), true);
        $this->assertNotNull($planet);

        foreach (['metal_mine' => 12, 'crystal_mine' => 11, 'solar_plant' => 12] as $batiment => $niveau) {
            $planet->setObjectLevel(ObjectService::getObjectByMachineName($batiment)->id, $niveau, true);
        }
        $planet->updateResourceProductionStats();

        $planet = resolve(PlanetServiceFactory::class)->make($planet->getPlanetId(), true);
        $this->assertNotNull($planet);

        return $planet;
    }

    public function testABaseAboveTheOldCeilingKeepsGrowingWhenTheSettingIsArmed(): void
    {
        $croissance = resolve(NpcGrowthService::class);
        $planet = $this->uneBaseAuDessusDuPlafond();

        // La premisse, etablie et non affirmee : sans le reglage, cette base serait bien au plafond.
        resolve(SettingsService::class)->set('npc_growth_unlimited', 0);
        $this->assertTrue(
            $croissance->isAtCeiling($planet),
            'Premisse tombee : la base ne depasse pas le plafond, ce banc ne mesurerait rien.'
        );

        resolve(SettingsService::class)->set('npc_growth_unlimited', 1);

        $this->assertFalse($croissance->isAtCeiling($planet), 'Sans plafond, aucune base n y est.');
    }

    public function testTheCapReturnsExactlyAsBeforeWhenTheSettingIsOff(): void
    {
        $croissance = resolve(NpcGrowthService::class);
        $planet = $this->uneBaseAuDessusDuPlafond();

        resolve(SettingsService::class)->set('npc_growth_unlimited', 0);

        $this->assertTrue($croissance->isAtCeiling($planet));
        $resultat = $croissance->grow($planet);
        $this->assertSame(
            NpcGrowthService::ACTION_CAPPED,
            $resultat['action'],
            'Reglage desarme, le plafond doit reprendre la main exactement comme avant.'
        );
    }

    public function testTheBaseNeverReportsCappedBecauseOfItsPowerWhenUnlimited(): void
    {
        $croissance = resolve(NpcGrowthService::class);
        $planet = $this->uneBaseAuDessusDuPlafond();

        resolve(SettingsService::class)->set('npc_growth_unlimited', 1);

        $resultat = $croissance->grow($planet);

        $this->assertNotSame(
            NpcGrowthService::ACTION_CAPPED,
            $resultat['action'],
            'Une base sans plafond ne peut pas etre refusee pour cause de puissance : ' . $resultat['detail']
        );
    }

    /**
     * **Le seul comportement modifie est la croissance.** La maturite numerique nourrit la force des
     * raids, leur porte a 20 % et la condition d essaimage : elle ne doit pas bouger d un point.
     */
    public function testArmingTheSettingChangesNeitherTheNumericMaturityNorTheRaidThresholds(): void
    {
        $croissance = resolve(NpcGrowthService::class);
        $reglages = resolve(SettingsService::class);
        $planet = $this->uneBaseAuDessusDuPlafond();

        // **Le faux doit etre observable.** Sur une base AU-DESSUS du plafond, la maturite vaut 100 dans les
        // deux modes : une maturite forcee a 100 en mode illimite y passerait inapercue — mutation mesuree le
        // 20 septembre 2026, elle survivait. On eleve donc le plafond bien au-dessus de la base.
        $reglages->set('npc_growth_unlimited', 0);
        $reference = $croissance->powerCeiling();
        $this->assertGreaterThan(0, $reference, 'Premisse : il faut un plafond de reference pour le calibrer.');
        // Un plafond a peu pres deux fois le score de la base : la maturite tombe alors dans la zone ou une
        // valeur forcee a 100 se voit.
        $reglages->set('npc_maturity_ratio', (string)round(2 * $planet->getPlanetScore() / $reference, 4));

        $reglages->set('npc_growth_unlimited', 0);
        $plafonne = $croissance->maturityOf($planet);
        $this->assertLessThan(100, $plafonne, 'Premisse : la base doit etre SOUS le plafond pour que la mesure separe le juste du faux.');
        $this->assertGreaterThan(0, $plafonne, 'Et au-dessus de zero, sinon elle ne distingue rien non plus.');
        $facteurPlafonne = max(0.1, $plafonne / 100);
        $porteePlafonnee = $plafonne < 20;

        $reglages->set('npc_growth_unlimited', 1);
        $illimite = $croissance->maturityOf($planet);

        $this->assertSame($plafonne, $illimite, 'La maturite numerique ne depend pas du mode de croissance.');
        $this->assertSame($facteurPlafonne, max(0.1, $illimite / 100), 'La force d un raid est inchangee.');
        $this->assertSame($porteePlafonnee, $illimite < 20, 'La porte de raid a 20 % est inchangee.');
    }

    /**
     * L affichage, lui, ne raconte pas une maturite qui n a plus de reference — ni un plafond a zero.
     */
    public function testTheDisplaySaysThereIsNoCeilingInsteadOfAFigure(): void
    {
        $croissance = resolve(NpcGrowthService::class);
        $reglages = resolve(SettingsService::class);
        $planet = $this->uneBaseAuDessusDuPlafond();

        $reglages->set('npc_growth_unlimited', 1);
        $this->assertSame('sans plafond', $croissance->ceilingLabel());
        $this->assertSame('sans plafond', $croissance->maturityLabel($planet));

        $reglages->set('npc_growth_unlimited', 0);
        $this->assertStringContainsString('points', $croissance->ceilingLabel(), 'Plafonne, le plafond se dit en points.');
        $this->assertStringEndsWith('%', $croissance->maturityLabel($planet), 'Et la maturite en pourcentage.');
        $this->assertSame($croissance->maturityOf($planet) . '%', $croissance->maturityLabel($planet));
    }

    /**
     * **Sans ligne en base, le reglage est arme.** C est ainsi que la production le recoit : le deploiement
     * n ecrit aucun reglage, et le defaut doit donc etre celui que Keven a demande.
     */
    public function testTheSettingIsArmedWhenNothingIsStored(): void
    {
        Setting::query()->where('key', 'npc_growth_unlimited')->delete();
        $this->assertNull(
            Setting::query()->where('key', 'npc_growth_unlimited')->first(),
            'Premisse : aucune ligne ne doit rester.'
        );

        // **Le service garde tous les reglages en memoire des la premiere lecture** : sans instance neuve,
        // ce temoin relirait la valeur posee par un essai voisin et ne dirait rien du defaut. Mesure faite
        // par mutation le 20 septembre 2026 — le defaut passe a zero, l essai restait vert.
        app()->forgetInstance(SettingsService::class);

        $this->assertTrue(
            app(SettingsService::class)->npcGrowthUnlimited(),
            'Un serveur qui n a jamais touche ce reglage laisse les pirates grandir sans plafond.'
        );
    }

    /**
     * **Le piege des booleens de configuration.** Le projet lit `=== '1'` : toute autre valeur desarme.
     * Une chaine « true » ou « yes » ne doit surtout pas passer pour un oui.
     */
    public function testOnlyTheStringOneArmsTheSetting(): void
    {
        $croissance = resolve(NpcGrowthService::class);
        $reglages = resolve(SettingsService::class);

        foreach (['0', 'false', 'true', 'yes', ''] as $valeur) {
            $reglages->set('npc_growth_unlimited', $valeur);
            $this->assertFalse(
                $croissance->isGrowthUnlimited(),
                "La valeur « $valeur » ne doit pas armer le reglage."
            );
        }

        $reglages->set('npc_growth_unlimited', 1);
        $this->assertTrue($croissance->isGrowthUnlimited());
    }
}
