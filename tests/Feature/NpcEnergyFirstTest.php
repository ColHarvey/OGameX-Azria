<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Resources;
use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\SpawnsNpcBases;

/**
 * Une base a court de courant economise pour sa centrale, elle n achete rien d autre.
 *
 * ## Le defaut mesure en production
 *
 * Le 12 septembre 2026, un rapport d espionnage sur une base pirate : mine de metal 13, mine de
 * cristal 12, synthetiseur 10, centrale solaire 12 — **solde d energie -592**, donc un facteur de
 * production de 56 %. Les trois mines tournaient a un peu plus de la moitie de leur rendement.
 *
 * Le plan proposait bien la centrale **en premier**, mais l appelant descend la liste jusqu a
 * trouver un candidat payable : centrale hors de prix, il tombait sur le suivant — un entrepot, un
 * batiment du plan. La base depensait donc le metal dont elle avait besoin pour se remettre a flot,
 * et restait a rendement reduit bien plus longtemps.
 *
 * ## Ce que ce banc etablit
 *
 * Que sous la marge, **rien d autre que du courant** n est mis en file ; que la base economise
 * plutot que d acheter quand la centrale est hors de prix ; et que la regle ne fige pas une base
 * dont les champs sont pleins — sinon elle ne batirait jamais le terraformeur qui les rendrait.
 *
 * ## Le plafond, pose et dit
 *
 * Une base dont le score depasse le plafond du serveur ne grandit plus du tout
 * (`ACTION_CAPPED`) : sans un plafond releve, ce banc mesurerait ce refus-la et pas la regle de
 * l energie. Il est donc pose explicitement.
 */
class NpcEnergyFirstTest extends AccountTestCase
{
    use SpawnsNpcBases;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = resolve(SettingsService::class);
        $settings->set('npc_enabled', 1);
        $settings->set('npc_growth_enabled', 1);
        // Le plafond, hors du chemin : ce banc juge la regle de l energie, pas la maturite.
        $settings->set('npc_min_score_fixed', 100000000);
        $settings->set('npc_maturity_ratio', '1.30');
    }

    /**
     * Une base a court de courant qui peut payer sa centrale la batit — et rien d autre.
     */
    public function testABaseShortOfPowerBuildsThePlantAndNothingElse(): void
    {
        $planet = $this->uneBaseEnManqueDeCourant();
        $planet->addResources(new Resources(5000000, 5000000, 5000000, 0));
        $planet = $this->relire($planet);

        $this->assertLessThan(0, $planet->energy()->get(), 'La premisse tombe : la base ne manque pas de courant.');

        $resultat = resolve(NpcGrowthService::class)->grow($planet);

        $this->assertSame(NpcGrowthService::ACTION_BUILDING, $resultat['action'], 'La base n a rien bati : ' . $resultat['detail']);
        $this->assertSame('solar_plant', $resultat['detail'], 'Une base en deficit d energie a bati autre chose que sa centrale.');
    }

    /**
     * **Le coeur du correctif** : centrale hors de prix, laboratoire abordable — la base n achete rien.
     *
     * Les niveaux sont ceux du rapport d espionnage du 12 septembre 2026, et le banc les reproduit
     * au chiffre pres : solde **-592**. La centrale suivante coute 9 730 de metal ; le laboratoire,
     * 200. Avant ce correctif la base montait le laboratoire et repoussait d autant son retour a
     * plein rendement.
     */
    public function testABaseThatCannotAffordThePlantBuysNothingAtAll(): void
    {
        $planet = $this->uneBaseCommeCelleDuRapport();

        $centrale = ObjectService::getObjectPrice('solar_plant', $planet);
        $laboratoire = ObjectService::getObjectPrice('research_lab', $planet);

        $this->assertGreaterThan($laboratoire->metal->get(), $centrale->metal->get(), 'La premisse tombe : le laboratoire n est pas meilleur marche que la centrale, le choix ne se pose pas.');

        // Juste de quoi payer le laboratoire et l entrepot, jamais la centrale.
        $planet->deductResources($planet->getResources());
        $planet->addResources(new Resources(2500, 2500, 500000, 0));

        $planet = $this->relire($planet);

        $this->assertEqualsWithDelta(-592, $planet->energy()->get(), 1.0, 'La premisse tombe : le banc ne reproduit plus la base du rapport.');
        $this->assertTrue($planet->hasResources($laboratoire), 'La premisse tombe : la base ne peut meme pas payer le laboratoire, et le choix ne se pose pas.');
        $this->assertFalse($planet->hasResources($centrale), 'La premisse tombe : la base peut payer la centrale.');

        $avant = [
            'research_lab' => $planet->getObjectLevel('research_lab'),
            'shipyard' => $planet->getObjectLevel('shipyard'),
            'metal_store' => $planet->getObjectLevel('metal_store'),
            'metal_mine' => $planet->getObjectLevel('metal_mine'),
        ];

        $resultat = resolve(NpcGrowthService::class)->grow($planet);

        $this->assertNotSame(NpcGrowthService::ACTION_BUILDING, $resultat['action'], 'La base a bati « ' . $resultat['detail'] . ' » au lieu d economiser pour sa centrale.');

        $planet = $this->relire($planet);

        foreach ($avant as $batiment => $niveau) {
            $this->assertSame($niveau, $planet->getObjectLevel($batiment), 'La base a monte ' . $batiment . ' : elle a depense ce qu il lui faut pour se remettre a flot.');
        }
    }

    /**
     * Champs pleins : la regle se leve, sinon la base ne batirait jamais le terraformeur.
     */
    public function testAFullPlanetIsNotFrozenByTheEnergyRule(): void
    {
        $planet = $this->uneBaseEnManqueDeCourant();
        $planet->addResources(new Resources(5000000, 5000000, 5000000, 0));

        // Les champs, reduits a ce que la base occupe deja : plus une seule case libre.
        DB::table('planets')->where('id', $planet->getPlanetId())->update(['field_max' => $planet->getBuildingCount()]);

        $planet = $this->relire($planet);

        $this->assertLessThanOrEqual(0, $planet->getPlanetFieldMax() - $planet->getBuildingCount(), 'La premisse tombe : il reste des champs libres.');
        $this->assertLessThan(0, $planet->energy()->get(), 'La premisse tombe : la base ne manque pas de courant.');

        // La base rend une decision lisible, et ne se fige pas sur une centrale impossible.
        $resultat = resolve(NpcGrowthService::class)->grow($planet);

        $this->assertContains($resultat['action'], [
            NpcGrowthService::ACTION_BUILDING,
            NpcGrowthService::ACTION_RESEARCH,
            NpcGrowthService::ACTION_UNITS,
            NpcGrowthService::ACTION_BUSY,
            NpcGrowthService::ACTION_NOTHING,
        ], 'Une base aux champs pleins ne rend plus de decision lisible.');

        $this->assertNotSame('solar_plant', $resultat['detail'], 'La base s obstine sur une centrale que les champs interdisent.');
    }

    /**
     * La base du rapport d espionnage, au chiffre pres : mines 13 / 12 / 10, centrale solaire 12,
     * usine de robots 5. Solde d energie **-592**, facteur de production 56 %.
     */
    private function uneBaseCommeCelleDuRapport(): PlanetService
    {
        $base = $this->aSpawnedBase();
        $planet = resolve(PlanetServiceFactory::class)->make($base->getPlanetId(), true);
        $this->assertNotNull($planet);

        foreach (['metal_mine' => 13, 'crystal_mine' => 12, 'deuterium_synthesizer' => 10, 'solar_plant' => 12, 'robot_factory' => 5] as $batiment => $niveau) {
            $planet->setObjectLevel(ObjectService::getObjectByMachineName($batiment)->id, $niveau, true);
        }

        $planet->updateResourceProductionStats();

        return $this->relire($planet);
    }

    /**
     * Une base pirate dont les mines consomment plus que sa centrale ne produit.
     *
     * Les niveaux sont volontairement modestes : c est le **signe** du solde qui compte.
     */
    private function uneBaseEnManqueDeCourant(): PlanetService
    {
        $base = $this->aSpawnedBase();
        $planet = resolve(PlanetServiceFactory::class)->make($base->getPlanetId(), true);
        $this->assertNotNull($planet);

        foreach (['metal_mine' => 8, 'crystal_mine' => 7, 'deuterium_synthesizer' => 6, 'solar_plant' => 4] as $batiment => $niveau) {
            $planet->setObjectLevel(ObjectService::getObjectByMachineName($batiment)->id, $niveau, true);
        }

        $planet->updateResourceProductionStats();

        return $this->relire($planet);
    }

    private function relire(PlanetService $planet): PlanetService
    {
        $relu = resolve(PlanetServiceFactory::class)->make($planet->getPlanetId(), true);
        $this->assertNotNull($relu);

        return $relu;
    }
}
