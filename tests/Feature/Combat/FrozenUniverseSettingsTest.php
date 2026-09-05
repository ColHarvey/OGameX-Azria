<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CombatInstance;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Un changement d'administration pendant le ralliement ne change pas une bataille deja engagee.
 *
 * ## Le defaut que cet essai ferme
 *
 * Le moteur lisait `debris_field_from_ships` et six autres reglages sur `SettingsService` au moment
 * de calculer la bataille, c'est-a-dire a la **fermeture**. Un ralliement dure des heures : un
 * administrateur qui ajustait la part d'epaves entre l'ouverture et la fermeture changeait ce que
 * des attaquants deja partis allaient recolter. Les reglages sont desormais photographies a
 * l'ouverture (`PhotographedUniverse`, etat d'ouverture version 4).
 *
 * ## Ce que l'essai exige, et le temoin inverse
 *
 * Le combat ouvert sous 30 % d'epaves, puis ferme alors que l'administration a mis la part a **zero**,
 * produit un champ de debris **non nul** : c'est la valeur d'ouverture qui a servi. Si le moteur
 * relisait le vivant, le champ vaudrait exactement zero — le juste et le faux ne coincident pas.
 *
 * Le combat **suivant**, ouvert apres le changement, prend la nouvelle valeur : la photographie fixe
 * un combat, elle ne fige pas l'univers.
 */
final class FrozenUniverseSettingsTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /** Assez de defenses pour que l'attaque perde des vaisseaux, donc pour qu'il y ait des epaves. */
    private const int GARRISON = 400;

    private const int DEBRIS_AT_OPENING = 30;

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        $reglages = resolve(SettingsService::class);
        $reglages->set('persistent_combat_enabled', '0');
        $reglages->set('debris_field_from_ships', (string)self::DEBRIS_AT_OPENING);
        parent::tearDown();
    }

    public function testTheOpenCombatKeepsTheSettingsItWasOpenedUnderAndTheNextOneTakesTheNewOnes(): void
    {
        resolve(SettingsService::class)->set('debris_field_from_ships', (string)self::DEBRIS_AT_OPENING);

        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $this->assertSame(self::DEBRIS_AT_OPENING, OpeningStateRecorder::openingUniverseOf($combat)->debrisFieldFromShips, 'The opening did not capture the debris share in force at that moment.');

        // **L'administration change la part d'epaves pendant le ralliement.** Sur une resolution
        // fraiche : le montage du ralliement reconstruit le conteneur, et une instance capturee avant
        // ecrirait la base sans toucher le singleton que le moteur lit — le changement serait alors
        // invisible ici tout en etant reel en production, ou la fermeture tourne dans un autre
        // processus. La precondition est dite : le moteur verra bien zero s'il relit le vivant.
        resolve(SettingsService::class)->set('debris_field_from_ships', '0');
        $this->assertSame(0, resolve(SettingsService::class)->debrisFieldFromShips(), 'The administration change did not reach the settings instance the engine reads: this test would prove nothing.');

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $resultat = $this->frozenResultOf($combat);
        $this->assertGreaterThan(0, $resultat->attackerUnitsLost->getAmount(), 'The attack lost nothing: there is no debris to observe, and this test would prove nothing.');
        $this->assertGreaterThan(
            0,
            $resultat->debris->metal->get() + $resultat->debris->crystal->get(),
            'The battle produced no debris although it was opened under a 30 % share: the engine read the living setting, set to zero during the rally.'
        );

        // Le combat suivant, ouvert apres le changement, prend la nouvelle valeur.
        [$suivant] = $this->anOpenRally(self::GARRISON);
        $this->assertNotSame($combat->id, $suivant->id, 'The second rally reused the first combat.');
        $this->assertSame(0, OpeningStateRecorder::openingUniverseOf($suivant)->debrisFieldFromShips, 'A combat opened after the change did not take the new setting: the photograph froze the universe instead of one combat.');
    }

    private function frozenResultOf(CombatInstance $combat): BattleResult
    {
        $combat->refresh();

        return BattleResultCodec::fromStorage($combat->battle_result);
    }
}
