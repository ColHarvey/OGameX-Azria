<?php

namespace Tests\Feature;

use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Combat\SpatialBattle;
use OGame\Patrol\Combat\SpatialSettlement;
use OGame\Patrol\Enums\PatrolState;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * **Une cargaison ne survit pas a ses vaisseaux** (revue de Codex relayee par Keven, 13 septembre 2026).
 *
 * ## Le defaut
 *
 * Le reglement spatial rendait a l attaquante **la colonne entiere** de sa cargaison, et laissait au segment
 * de la patrouille toute la sienne, quelles que soient les pertes des deux camps. Une flotte qui perdait ses
 * transporteurs rentrait donc avec ce qu ils portaient : des ressources naissaient de leur destruction. La
 * protection de la cargaison des survivants, decidee pour le combat contre un corps, ne va pas jusque-la.
 *
 * ## Ce que ces essais separent
 *
 * Quatre quantites, chacune nommee : la **cargaison survivante**, la **cargaison perdue** avec les vaisseaux,
 * le **butin** et la **collecte**. Destruction partielle d abord, puis totale — d un camp, puis de l autre.
 *
 * ## Le butin spatial vaut zero aujourd hui, et c est dit
 *
 * Le moteur pille les ressources protegees de la photographie, ou a defaut celles du corps vise. Un point de
 * l espace n a pas de corps, et **aucune ressource protegee ne lui est passee** : la cargaison d une
 * patrouille n est donc pas pillable, malgre ce que l en-tete du reglement annoncait. Ces essais l epinglent
 * tel quel ; ils deviendront rouges le jour ou cette decision sera prise, et c est exactement ce qu on attend
 * d eux.
 *
 * ## La collecte n existe pas non plus en espace libre
 *
 * Aucun Faucheur ne ramasse les debris d un point : ils y restent pour un recycleur. Les essais l exigent
 * aussi, sinon « rien de plus que la cargaison survivante » ne voudrait rien dire.
 */
final class SpatialCargoConservationTest extends AccountTestCase
{
    use StagesASpatialBattle;

    protected function setUp(): void
    {
        parent::setUp();

        // Les arrivees suivent la naissance des comptes : une attaque datee de la seconde ou nait son
        // proprietaire se ferait suspendre par le gel a l admission.
        $this->travelTo(now()->addHour());

        resolve(SettingsService::class)->set('patrols_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Destruction partielle : ce que portaient les vaisseaux detruits ne rentre pas**, des deux cotes.
     */
    public function testUneDestructionPartielleLaisseSurPlaceLaCargaisonDesVaisseauxDetruits(): void
    {
        $emporte = new Resources(40_000, 0, 0, 0);
        $portee = new Resources(4_000, 2_000, 0, 0);

        [$patrouille, $segment] = $this->unePatrouillePosee(['battle_ship' => 40, 'small_cargo' => 25], cargaison: $portee);
        $attaque = $this->uneAttaqueArrivee($patrouille, $segment, ['cruiser' => 90, 'small_cargo' => 30], $emporte);

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $flotte = $resultat->attackerFleetResults[0];
        $defense = $this->defenceResultOf($resultat, (int)$segment->id);

        // --- Les premisses : sans elles, le juste et le faux coincideraient ---
        $this->assertGreaterThan(0, count($resultat->rounds), 'Aucun round : les deux camps ne se sont pas rencontres.');
        $this->assertGreaterThan(0, $flotte->unitsResult->getAmount(), 'L attaquante est entierement detruite : ce n est pas une destruction partielle.');
        $this->assertGreaterThan(0, $flotte->unitsLost->getAmount(), 'L attaquante n a rien perdu : la cargaison perdue serait nulle.');
        $this->assertLessThan($flotte->startingCargoCapacity, $flotte->survivingCargoCapacity, 'Aucune capacite attaquante detruite : la part perdue ne se verrait pas.');
        $this->assertGreaterThan(0, $defense->unitsResult->getAmount(), 'La patrouille est entierement detruite : ce n est pas une destruction partielle.');
        $this->assertLessThan($defense->startingCargoCapacity, $defense->survivingCargoCapacity, 'Aucune capacite defensive detruite : la part perdue ne se verrait pas.');

        // --- Le butin et la collecte, nommes pour eux-memes ---
        $this->assertSame(0.0, $resultat->loot->sum(), 'Une cargaison de patrouille a ete pillee : la decision de la rendre pillable n a pas ete prise.');
        $this->assertSame(0.0, $flotte->lootShare->sum(), 'Une part de butin a ete attribuee la ou il n y a pas de butin.');

        $ramene = $this->settleAndCaptureTheReturn($resultat, $attaque, $patrouille, $segment);

        $this->assertNotNull($ramene, 'L attaquante survivante n a pas de retour.');

        // --- Cargaison survivante, cargaison perdue ---
        $this->assertSame((int)$flotte->survivingCargo->metal->get(), (int)$ramene->metal->get(), 'Le retour ne porte pas la cargaison des survivants.');
        $this->assertSame(0, (int)$ramene->crystal->get() + (int)$ramene->deuterium->get(), 'Le retour porte plus que la cargaison survivante et le butin.');
        $this->assertLessThan((int)$emporte->metal->get(), (int)$ramene->metal->get(), 'La cargaison des vaisseaux detruits est rentree avec les survivants.');
        $this->assertGreaterThan(0, (int)$ramene->metal->get(), 'Rien n est rentre : la part survivante serait perdue elle aussi.');

        // La part perdue est exactement le complement, et elle n est creditee nulle part.
        $perdue = (int)$emporte->metal->get() - (int)$ramene->metal->get();
        $this->assertGreaterThan(0, $perdue, 'Aucune cargaison perdue : la premisse de destruction est tombee.');

        // --- Et la patrouille, de son cote, perd la meme proportion ---
        $segment->refresh();
        $part = $defense->survivingCargoCapacity / $defense->startingCargoCapacity;

        $this->assertSame((int)((int)$portee->metal->get() * $part), (int)$segment->metal, 'La patrouille garde le metal de ses vaisseaux detruits.');
        $this->assertSame((int)((int)$portee->crystal->get() * $part), (int)$segment->crystal, 'La patrouille garde le cristal de ses vaisseaux detruits.');
        $this->assertLessThan((int)$portee->metal->get(), (int)$segment->metal, 'La cargaison de la patrouille n a pas bouge malgre ses pertes.');
    }

    /**
     * **Destruction totale de l attaquante : rien ne rentre, et rien n est credite ailleurs.**
     */
    public function testUneAttaquanteEntierementDetruiteNeRameneRien(): void
    {
        $emporte = new Resources(20_000, 0, 0, 0);

        [$patrouille, $segment] = $this->unePatrouillePosee(['battle_ship' => 60]);
        $attaque = $this->uneAttaqueArrivee($patrouille, $segment, ['small_cargo' => 10], $emporte);

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame(0, $resultat->attackerUnitsResult->getAmount(), 'L attaquante devait etre ecrasee.');

        $ramene = $this->settleAndCaptureTheReturn($resultat, $attaque, $patrouille, $segment);

        $this->assertNull($ramene, 'Une flotte entierement detruite a cree un retour.');

        // La cargaison meurt avec elle : elle n est creditee ni a la patrouille, ni au point.
        $segment->refresh();
        $this->assertSame(4_000, (int)$segment->metal, 'La cargaison de l attaquante detruite a ete versee a la patrouille.');

        $relue = FleetMission::query()->findOrFail($attaque->id);
        $this->assertSame(1, (int)$relue->processed, 'La mission de la flotte detruite n est pas traitee.');
    }

    /**
     * **Destruction totale de la patrouille : sa cargaison meurt avec elle**, et l attaquante ne la prend pas.
     */
    public function testUnePatrouilleEntierementDetruiteEmporteSaCargaison(): void
    {
        $emporte = new Resources(10_000, 0, 0, 0);
        $portee = new Resources(4_000, 2_000, 0, 0);

        [$patrouille, $segment] = $this->unePatrouillePosee(['light_fighter' => 5], cargaison: $portee);
        $attaque = $this->uneAttaqueArrivee($patrouille, $segment, ['battle_ship' => 60], $emporte);

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertSame(0, $resultat->defenderUnitsResult->getAmount(), 'La patrouille devait etre ecrasee.');

        $flotte = $resultat->attackerFleetResults[0];
        $ramene = $this->settleAndCaptureTheReturn($resultat, $attaque, $patrouille, $segment);

        $this->assertNotNull($ramene, 'La flotte victorieuse doit repartir.');

        // Ce qui rentre est **sa** cargaison survivante, et rien de la patrouille : aucun butin n est pris a
        // une patrouille aujourd hui.
        $this->assertSame((int)$flotte->survivingCargo->metal->get(), (int)$ramene->metal->get(), 'Le retour ne porte pas exactement la cargaison survivante de l attaquante.');
        $this->assertSame(0, (int)$ramene->crystal->get(), 'Le cristal de la patrouille detruite est rentre avec l attaquante.');
        $this->assertLessThanOrEqual((int)$emporte->metal->get(), (int)$ramene->metal->get(), 'Le retour porte plus que ce que l attaquante emportait.');

        $patrouille->refresh();
        $this->assertSame(PatrolState::Destroyed->value, $patrouille->state->value ?? $patrouille->state);
    }

    /**
     * Regle la bataille et rend ce que le retour emporte, ou `null` s il n y en a pas.
     */
    private function settleAndCaptureTheReturn(BattleResult $resultat, FleetMission $attaque, Patrol $patrouille, FleetMission $segment): Resources|null
    {
        $ramene = null;

        resolve(SpatialSettlement::class)->settle(
            $resultat,
            $attaque,
            $patrouille,
            $segment,
            function (FleetMission $mission, Resources $ressources, UnitCollection $unites) use (&$ramene): void {
                $ramene = $ressources;
            }
        );

        return $ramene;
    }

    /**
     * Le resultat de la flotte defensive qui porte la patrouille.
     */
    private function defenceResultOf(BattleResult $resultat, int $fleetMissionId): DefenderFleetResult
    {
        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte;
            }
        }

        $this->fail('Le resultat ne porte pas la flotte defensive ' . $fleetMissionId . '.');
    }
}
