<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
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
 * essai PHP ne peut etablir la protection. Ceux-ci passent par la vraie bibliotheque — ils sont ignores
 * ici, executes en integration continue, et le nom de la classe les fait entrer dans le groupe du moteur.
 *
 * ## Ce qu ils mesurent, et pourquoi le drapeau ne suffisait pas
 *
 * `hamillManoeuvreTriggered` dit qu un tirage a eu lieu, rien d autre : c est exactement ce que le moteur
 * fautif levait. Les chiffres compares ici sont ceux que le moteur PHP produit — mesures, non supposes — et
 * l un des essais rejoue la meme bataille **sans** manoeuvre pour que « l Etoile ne tire plus » soit une
 * difference observable, pas une affirmation. Le banc de parite tient ensuite l egalite des deux moteurs sur
 * l ensemble de la projection.
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
     *
     * Les quatre chiffres sont ceux du moteur PHP sur ce scenario. Le decompte global des survivants en porte
     * deux : il part du depart annonce et ne baisse que sur une mort en round, or la manoeuvre ne tue
     * personne en round. **C est un defaut d affichage anterieur**, commun aux deux moteurs, epingle par
     * `HamillManoeuvreEffectTest` et non corrige ici — le corriger changerait ce que les joueurs lisent.
     */
    public function testTheEffectiveRuleTakesTheDeathstarOutOfTheBattle(): void
    {
        $resultat = $this->laBatailleSous(HamillManoeuvreRule::Effective);

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame(2, $resultat->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Le depart annonce ne porte plus les deux Etoiles.');
        $this->assertSame(1, $resultat->defenderUnitsLost->getAmountByMachineName('deathstar'), 'La manoeuvre n a detruit aucune Etoile.');
        $this->assertSame(2, $resultat->defenderUnitsResult->getAmountByMachineName('deathstar'), 'Le decompte global des survivants ne dit plus la meme chose que le moteur PHP.');

        // **Le seul chiffre qui change le monde** : c est ce resultat par flotte que le reglement applique au
        // corps. Sans lui, une Etoile « perdue » au rapport resterait posee sur la planete.
        $garnison = $this->laFlotteDefensive($resultat, 0);
        $this->assertSame(0, $garnison->unitsResult->getAmountByMachineName('deathstar'), 'La garnison garde son Etoile : la manoeuvre n a rien detruit.');
        $this->assertSame(1, $garnison->unitsLost->getAmountByMachineName('deathstar'), 'L Etoile n est pas comptee perdue dans la flotte qui la portait.');
        $this->assertTrue($garnison->completelyDestroyed, 'La garnison avait perdu son Etoile et ses lanceurs : elle devait etre entierement detruite.');

        // La manoeuvre en prend **une**, celle de la premiere flotte dans l ordre canonique. Prendre celle du
        // renfort serait une autre bataille : les deux Etoiles n ont pas les memes technologies.
        $renfort = $this->laFlotteDefensive($resultat, 2_000);
        $this->assertSame(1, $renfort->unitsResult->getAmountByMachineName('deathstar'), 'La manoeuvre a pris l Etoile du renfort au lieu de celle de la garnison.');
        $this->assertSame(0, $renfort->unitsLost->getAmountByMachineName('deathstar'), 'Le renfort a perdu une Etoile qu il devait garder.');
    }

    /**
     * **L Etoile prise ne tire plus**, et cela se mesure : la meme bataille, sur la meme graine, jouee une
     * fois avec la manoeuvre et une fois sans.
     *
     * Le premier round est le temoin : sans l Etoile la bataille dure plus longtemps, donc un total de coups
     * melangerait « qui tire » et « combien de rounds ». Au premier round, l effectif defensif est exactement
     * celui du depart.
     */
    public function testUnderTheEffectiveRuleTheDeathstarStopsFiring(): void
    {
        $avec = $this->laBatailleSous(HamillManoeuvreRule::Effective);

        $this->settingsService->set('hamill_manoeuvre_chance', 1_000_000);
        $sans = $this->laBatailleSous(HamillManoeuvreRule::Effective);

        $this->assertTrue($avec->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : la comparaison ne prouverait rien.');
        $this->assertFalse($sans->hamillManoeuvreTriggered, 'La manoeuvre s est jouee dans le passage temoin : les deux passages seraient identiques.');

        $this->assertLessThan(
            $sans->rounds[0]->hitsDefender,
            $avec->rounds[0]->hitsDefender,
            'La defense a porte autant de coups au premier round : l Etoile detruite tire encore.'
        );

        $this->assertLessThan(
            $sans->rounds[0]->attackerLossesInRound->getAmount(),
            $avec->rounds[0]->attackerLossesInRound->getAmount(),
            'L attaquante a perdu autant d unites au premier round : l Etoile detruite tire encore.'
        );

        $this->assertGreaterThan(
            (int)$sans->debris->metal->get(),
            (int)$avec->debris->metal->get(),
            'Le champ de debris est identique : la coque de l Etoile detruite n y tombe pas.'
        );
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

        // Et le corps garde la sienne : c est bien la bataille livree, pas la corrigee.
        $garnison = $this->laFlotteDefensive($resultat, 0);
        $this->assertSame(1, $garnison->unitsResult->getAmountByMachineName('deathstar'), 'La correction a ete appliquee a un combat ouvert sous la regle livree.');
        $this->assertSame(0, $garnison->unitsLost->getAmountByMachineName('deathstar'), 'La garnison a perdu une Etoile sous la regle livree.');
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

    private function laFlotteDefensive(BattleResult $resultat, int $fleetMissionId): DefenderFleetResult
    {
        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte;
            }
        }

        $this->fail('La flotte defensive ' . $fleetMissionId . ' manque au resultat.');
    }
}
