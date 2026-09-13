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
 * porte une manoeuvre qui levait ce drapeau, consommait son tirage et **ne detruisait rien** — l Etoile
 * continuait de tirer —, et aucune suite ne rougissait. La couleur d un banc ne dit pas qu une regle
 * s applique : il faut mesurer ce qu elle empeche.
 *
 * ## Comment « elle ne tire pas » est rendu observable sans hasard
 *
 * La meme bataille est jouee deux fois, avec pour seule difference la chance de la manoeuvre : une fois sur
 * une, puis une fois sur un million. Le temoin des tirs est le montage ou l Etoile est **le dernier
 * defenseur** : sans manoeuvre elle tire au premier round et son premier coup detruit une unite ; avec, la
 * defense est vide et aucun round ne se joue. **Comparer des nombres de coups** dans une bataille ou une Etoile
 * survit ne prouverait rien : son tir rapide rend ce nombre geometrique, et une graine suffit a inverser
 * l ordre — la CI de `95740b99` l a montre sur le moteur Rust.
 *
 * ## Une incoherence anterieure, epinglee et non corrigee
 *
 * Le decompte global des survivants (`defenderUnitsResult`) porte encore l Etoile detruite : il part de
 * `defenderUnitsStart` et ne baisse que sur une mort en round, or la manoeuvre ne tue personne en round. La
 * perte est inscrite a part. Le resultat **par flotte** — celui que le reglement applique au corps — dit,
 * lui, la verite. Cet ecart precede ce travail et vaut pour les deux moteurs ; le corriger change l issue de
 * batailles, donc c est une livraison a part, versionnee, sur decision : il est epingle ici pour etre
 * visible, pas corrige en passant.
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
     * **L Etoile prise par la manoeuvre ne tire pas** : quand elle est le dernier defenseur, aucun round ne se
     * joue et l attaquante ne perd rien — alors que sans manoeuvre, elle tire et tue.
     */
    public function testTheDeathstarTheManoeuvreTakesNeverFires(): void
    {
        $avec = $this->laBatailleDuDernierDefenseur(1);
        $sans = $this->laBatailleDuDernierDefenseur(1_000_000);

        $this->assertTrue($avec->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : la comparaison ne prouverait rien.');
        $this->assertFalse($sans->hamillManoeuvreTriggered, 'La manoeuvre s est jouee dans le passage temoin : les deux passages seraient identiques.');

        // Le temoin : sans manoeuvre, l Etoile tire et tue.
        $this->assertNotSame([], $sans->rounds, 'Sans manoeuvre, aucun round ne s est joue : le temoin ne mesure rien.');
        $this->assertGreaterThan(0, $sans->rounds[0]->hitsDefender, 'Sans manoeuvre, l Etoile n a pas tire : le temoin ne mesure rien.');
        $this->assertGreaterThan(0, $sans->attackerUnitsLost->getAmount(), 'Sans manoeuvre, l attaquante n a rien perdu : le temoin ne mesure rien.');

        // Avec : personne ne tire.
        $this->assertSame([], $avec->rounds, 'Un round s est joue alors que la manoeuvre avait pris le dernier defenseur : l Etoile a combattu.');
        $this->assertSame(0, $avec->attackerUnitsLost->getAmount(), 'L attaquante a perdu des unites : l Etoile detruite a tire.');

        $garnison = $this->laFlotteDefensive($avec, 0);
        $this->assertSame(0, $garnison->unitsResult->getAmount(), 'La garnison garde une unite apres que la manoeuvre l a videe.');
        $this->assertSame(1, $garnison->unitsLost->getAmountByMachineName('deathstar'), 'L Etoile n est pas comptee perdue dans la garnison.');
        $this->assertTrue($garnison->completelyDestroyed, 'Une garnison videe par la manoeuvre n est pas dite detruite.');
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
     * **L incoherence du decompte global, epinglee.** Elle precede ce travail et n est pas corrigee ici : la
     * corriger changerait l issue de batailles.
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

    /**
     * **Ce que le decompte fantome coute a l attaquante, mesure contre un temoin.**
     *
     * Meme corps, meme stock, meme flotte, et aucun round dans les deux cas : une garnison vide rend un butin, une
     * garnison dont la manoeuvre a pris la seule Etoile n en rend aucun. Le jeu decide la victoire sur
     * `defenderUnitsResult` — le pillage ici, et ailleurs la destruction de lune
     * (`MoonDestructionMission::didAttackerWinBattle()`, `CombatEngagementService`) et la chute d une base pirate
     * (`NpcDestructionService::isDefeatedInBattle()`) —, et ce decompte-la porte encore l Etoile detruite.
     *
     * **Defaut anterieur, commun aux deux moteurs, non corrige** : le corriger changerait l issue de batailles,
     * donc c est une livraison versionnee, sur decision. Cet essai rougira ce jour-la, et c est voulu.
     */
    public function testAManoeuvreThatTakesTheLastDefenderStillDeniesTheAttackerItsLoot(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', 1);

        $vide = $this->fight(PhpBattleEngine::class, $this->aGeneralAttackingAStockedBody([]));
        $prise = $this->laBatailleDuDernierDefenseur(1);

        // Les premisses : sans elles, un butin nul pourrait venir d ailleurs que du decompte.
        $this->assertSame([], $vide->rounds, 'La garnison vide a combattu : les deux batailles ne sont plus comparables.');
        $this->assertGreaterThan(0.0, $vide->loot->sum(), 'Une garnison vide ne rend aucun butin : le temoin ne mesure rien.');
        $this->assertTrue($prise->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame([], $prise->rounds, 'Un round s est joue : l Etoile n a pas ete prise avant la bataille.');
        $this->assertSame(0, $prise->attackerUnitsLost->getAmount(), 'L attaquante a perdu des unites : ce n est plus une victoire sans combat.');
        $this->assertTrue($this->laFlotteDefensive($prise, 0)->completelyDestroyed, 'La garnison n est pas detruite : le butin nul aurait une autre cause.');

        // Le defaut, epingle.
        $this->assertSame(1, $prise->defenderUnitsResult->getAmount(), 'Le decompte global ne porte plus l Etoile detruite : c est une decision de jeu, pas un detail.');
        $this->assertSame(0.0, $prise->loot->sum(), 'L attaquante pille apres une manoeuvre qui a pris le dernier defenseur : le comportement a change, c est une decision de jeu.');
    }

    private function laBataille(int $chance): BattleResult
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $chance);

        return $this->fight(PhpBattleEngine::class, $this->aGeneralWhoseHamillManoeuvreSucceeds());
    }

    private function laBatailleDuDernierDefenseur(int $chance): BattleResult
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $chance);

        return $this->fight(PhpBattleEngine::class, $this->aGeneralWhoseHamillManoeuvreTakesTheLastDefender());
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
