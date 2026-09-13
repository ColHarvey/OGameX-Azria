<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use Tests\UnitTestCase;

/**
 * **Ce que la manoeuvre de Hamill fait sous le moteur Rust, version par version.**
 *
 * ## Le defaut que la version protege
 *
 * Le moteur Rust retirait l Etoile de la mort de `defenderUnitsStart` — un decompte de rapport — alors que
 * l entree envoyee a la bibliotheque se compose **des flottes** : l Etoile continuait de tirer, et ne pouvait
 * plus etre comptee perdue. Le moteur par defaut etant `rust`, la manoeuvre ne detruisait rien.
 *
 * ## Pourquoi ces essais vivent du cote de la bibliotheque
 *
 * Le moteur PHP retirait deja l Etoile de la bataille : il ne distingue donc pas les deux regles, et aucun
 * essai PHP ne peut etablir la protection. Ces deux-ci passent par la vraie bibliotheque — ils sont ignores
 * ici, executes en integration continue, et le nom de la classe les fait entrer dans le groupe du moteur.
 */
final class RustHamillManoeuvreTest extends UnitTestCase
{
    use BuildsParityScenarios;

    private int $chance = 1000;

    protected function setUp(): void
    {
        // **La garde d ignorance vient apres le montage parent**, et c est mesure : posee avant, elle
        // interrompt `setUp()` alors que **le demontage s execute quand meme** — et celui-ci lit un reglage
        // sur un conteneur qui n existe pas encore. Quatre erreurs au lieu de quatre essais ignores.
        parent::setUp();

        $this->skipWhenTheRustLibraryIsUnavailable();

        $this->chance = $this->settingsService->hamillManoeuvreChance();
        $this->settingsService->set('hamill_manoeuvre_chance', 1);
    }

    protected function tearDown(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $this->chance);

        parent::tearDown();
    }

    /**
     * **Regle effective : l Etoile quitte la bataille et compte dans les pertes**, comme sous le moteur PHP.
     */
    public function testTheEffectiveRuleTakesTheDeathstarOutOfTheBattle(): void
    {
        $resultat = $this->laBatailleSous(HamillManoeuvreRule::Effective);

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame(2, $resultat->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Le depart annonce ne porte plus les deux Etoiles.');
        $this->assertSame(1, $resultat->defenderUnitsLost->getAmountByMachineName('deathstar'), 'La manoeuvre n a detruit aucune Etoile.');
        $this->assertSame(1, $resultat->defenderUnitsResult->getAmountByMachineName('deathstar'), 'La seconde Etoile aurait du survivre a la bataille.');
    }

    /**
     * **Regle telle que livree : l Etoile disparait du depart et continue de tirer.**
     *
     * C est le comportement que les combats ouverts avant la correction gardent. L essai l epingle pour que
     * la protection soit prouvee, et non affirmee.
     */
    public function testTheRuleAsDeliveredLeavesTheDeathstarFighting(): void
    {
        $resultat = $this->laBatailleSous(HamillManoeuvreRule::AsDelivered);

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame(1, $resultat->defenderUnitsStart->getAmountByMachineName('deathstar'), 'La regle livree retire une Etoile du depart annonce : elle ne le fait plus.');
        $this->assertSame(0, $resultat->defenderUnitsLost->getAmountByMachineName('deathstar'), 'Une Etoile a ete comptee perdue sous la regle livree.');
        $this->assertSame(2, $resultat->defenderUnitsResult->getAmountByMachineName('deathstar'), 'Les deux Etoiles devaient survivre : sous la regle livree, aucune ne quitte la bataille.');
    }

    private function laBatailleSous(HamillManoeuvreRule $regle): BattleResult
    {
        $bataille = $this->aGeneralWhoseHamillManoeuvreSucceeds();

        $moteur = new RustBattleEngine(
            $bataille['attaquantes'],
            $bataille['cible'],
            $bataille['defenseurs'],
            $this->settingsService,
            $bataille['contexte']
        );

        return $moteur
            ->withDraws(new SeededDraws(20260913))
            ->withHamillManoeuvreRule($regle)
            ->simulateBattle();
    }
}
