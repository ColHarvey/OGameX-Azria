<?php

namespace Tests\Unit\BattleEngine;

use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use Tests\UnitTestCase;

/**
 * **Ce que la manoeuvre de Hamill change reellement a une bataille.**
 *
 * ## Pourquoi un essai de plus, alors que le drapeau etait deja epingle
 *
 * Un essai qui lit `hamillManoeuvreTriggered` etablit qu un tirage a eu lieu, rien d autre. Le moteur Rust a
 * porte pendant des mois une manoeuvre qui levait ce drapeau, consommait son tirage et **ne detruisait
 * rien** — l Etoile continuait de tirer —, et aucune suite ne rougissait. La couleur d un banc ne dit pas
 * qu une regle s applique : il faut mesurer ce qu elle empeche.
 *
 * ## Comment la comparaison est rendue observable
 *
 * La meme bataille est jouee deux fois, sur la meme graine, avec pour seule difference la chance de la
 * manoeuvre : une fois sur une, puis une fois sur un million. Les rounds tirent d une **suite neuve**
 * (`BattleDraws::forRounds()`), donc le tirage de la manoeuvre ne decale pas la bataille : ce que les deux
 * passages separent, c est l Etoile, et elle seule.
 *
 * **Le premier round est le temoin des tirs.** Comparer les totaux ne prouverait rien : sans l Etoile la
 * bataille dure plus longtemps, et le total des coups melange « qui tire » et « combien de rounds ». Au
 * premier round, l effectif defensif est exactement celui du depart.
 *
 * ## Une incoherence anterieure, epinglee et non corrigee
 *
 * Le decompte global des survivants (`defenderUnitsResult`) porte encore l Etoile detruite : il part de
 * `defenderUnitsStart` et ne baisse que sur une mort en round, or la manoeuvre ne tue personne en round. La
 * perte est inscrite a part. Le resultat **par flotte** — celui que le reglement applique au corps — dit,
 * lui, la verite. Cet ecart precede ce travail, vaut pour les deux moteurs, et changer ce que le rapport
 * montre est une decision de jeu : il est epingle ici pour etre visible, pas corrige en passant.
 */
final class HamillManoeuvreEffectTest extends UnitTestCase
{
    use BuildsParityScenarios;

    private int $chance = 1_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chance = $this->settingsService->hamillManoeuvreChance();
    }

    protected function tearDown(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $this->chance);

        parent::tearDown();
    }

    /**
     * **L Etoile prise par la manoeuvre ne tire pas** : au premier round, la defense porte moins de coups et
     * l attaquante perd moins d unites.
     */
    public function testTheDeathstarTheManoeuvreTakesNeverFires(): void
    {
        $avec = $this->laBataille(1);
        $sans = $this->laBataille(1_000_000);

        $this->assertTrue($avec->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : la comparaison ne prouverait rien.');
        $this->assertFalse($sans->hamillManoeuvreTriggered, 'La manoeuvre s est jouee dans le passage temoin : les deux passages seraient identiques.');

        $premierAvec = $avec->rounds[0];
        $premierSans = $sans->rounds[0];

        $this->assertLessThan(
            $premierSans->hitsDefender,
            $premierAvec->hitsDefender,
            'La defense a porte autant de coups au premier round : l Etoile detruite tire encore.'
        );

        $this->assertLessThan(
            $premierSans->attackerLossesInRound->getAmount(),
            $premierAvec->attackerLossesInRound->getAmount(),
            'L attaquante a perdu autant d unites au premier round : l Etoile detruite tire encore.'
        );
    }

    /**
     * **L Etoile est comptee perdue la ou le reglement lit** : dans le resultat de la flotte qui la portait.
     *
     * C est ce resultat-la qui devient l effectif du corps apres la bataille. Un essai qui ne regarderait que
     * le decompte global manquerait le seul chiffre qui change le monde.
     */
    public function testTheGarrisonLosesTheDeathstarInTheResultTheSettlementReads(): void
    {
        $avec = $this->laBataille(1);
        $sans = $this->laBataille(1_000_000);

        $garnisonAvec = $this->laFlotteDefensive($avec, 0);
        $garnisonSans = $this->laFlotteDefensive($sans, 0);

        $this->assertSame(1, $garnisonSans->unitsResult->getAmountByMachineName('deathstar'), 'Sans la manoeuvre, l Etoile de la garnison devait survivre : le scenario ne mesure rien.');
        $this->assertSame(0, $garnisonSans->unitsLost->getAmountByMachineName('deathstar'), 'Sans la manoeuvre, aucune Etoile ne devait etre perdue.');

        $this->assertSame(0, $garnisonAvec->unitsResult->getAmountByMachineName('deathstar'), 'La garnison garde son Etoile : la manoeuvre n a rien detruit.');
        $this->assertSame(1, $garnisonAvec->unitsLost->getAmountByMachineName('deathstar'), 'L Etoile n est pas comptee perdue dans la flotte qui la portait.');
        $this->assertTrue($garnisonAvec->completelyDestroyed, 'La garnison avait perdu son Etoile et ses lanceurs : elle devait etre entierement detruite.');

        // Le renfort garde la sienne : la manoeuvre en prend **une**, et c est celle de la premiere flotte
        // dans l ordre canonique. Les deux moteurs doivent prendre la meme, sinon ce sont deux batailles.
        $renfort = $this->laFlotteDefensive($avec, 2_000);
        $this->assertSame(1, $renfort->unitsResult->getAmountByMachineName('deathstar'), 'La manoeuvre a pris l Etoile du renfort au lieu de celle de la garnison.');
        $this->assertSame(0, $renfort->unitsLost->getAmountByMachineName('deathstar'), 'Le renfort a perdu une Etoile qu il devait garder.');
    }

    /**
     * **La coque de l Etoile detruite tombe dans le champ de debris**, et le rapport la compte perdue.
     */
    public function testTheDestroyedDeathstarFeedsTheDebrisFieldAndTheReport(): void
    {
        $avec = $this->laBataille(1);
        $sans = $this->laBataille(1_000_000);

        $this->assertSame(1, $avec->defenderUnitsLost->getAmountByMachineName('deathstar'), 'Le rapport ne compte aucune Etoile perdue.');
        $this->assertSame(0, $sans->defenderUnitsLost->getAmountByMachineName('deathstar'), 'Le passage temoin compte une Etoile perdue sans manoeuvre.');

        $this->assertGreaterThan(
            (int)$sans->debris->metal->get(),
            (int)$avec->debris->metal->get(),
            'Le champ de debris est identique : la coque de l Etoile detruite n y tombe pas.'
        );
    }

    /**
     * **L incoherence d affichage, epinglee.** Elle precede ce travail et n est pas corrigee ici : la
     * corriger changerait ce que tout joueur lit dans un rapport de combat.
     */
    public function testTheOverallSurvivorCountStillShowsTheDestroyedDeathstar(): void
    {
        $avec = $this->laBataille(1);

        $this->assertSame(2, $avec->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Le depart annonce doit porter les deux Etoiles.');

        // **Defaut connu, remonte, non corrige.** Le decompte global part du depart et ne baisse que sur une
        // mort en round : l Etoile prise par la manoeuvre y reste, alors que la meme bataille la compte
        // perdue et que le corps la perd. Si cet essai rougit un jour, c est que le comportement a change —
        // et ce changement est une decision de jeu, a prendre explicitement.
        $this->assertSame(2, $avec->defenderUnitsResult->getAmountByMachineName('deathstar'), 'Le decompte global des survivants a change : c est une decision de jeu, pas un detail.');
    }

    private function laBataille(int $chance): BattleResult
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $chance);

        return $this->fight(PhpBattleEngine::class, $this->aGeneralWhoseHamillManoeuvreSucceeds());
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
