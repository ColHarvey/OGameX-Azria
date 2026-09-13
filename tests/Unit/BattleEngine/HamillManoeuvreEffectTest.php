<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use Tests\UnitTestCase;

/**
 * **Ce que la manoeuvre de Hamill change reellement a une bataille, regle par regle.**
 *
 * ## Pourquoi ces essais existent
 *
 * Un essai qui lit `hamillManoeuvreTriggered` etablit qu un tirage a eu lieu, rien d autre. Le moteur Rust a
 * porte une manoeuvre qui levait ce drapeau, consommait son tirage et **ne detruisait rien**, sans qu aucune
 * suite ne rougisse. La couleur d un banc ne dit pas qu une regle s applique : il faut mesurer ce qu elle
 * empeche.
 *
 * ## Comment « elle ne tire pas » est rendu observable sans hasard
 *
 * La meme bataille est jouee deux fois, avec pour seule difference la chance de la manoeuvre : une fois sur
 * une, puis une fois sur un million. Le temoin des tirs est le montage ou l Etoile est **le dernier
 * defenseur** : sans manoeuvre elle tire au premier round et son premier coup detruit une unite ; avec, la
 * defense est vide et aucun round ne se joue. **Comparer des nombres de coups** dans une bataille ou une
 * Etoile survit ne prouverait rien : son tir rapide rend ce nombre geometrique, et une graine suffit a
 * inverser l ordre — la CI de `95740b99` l a montre sur le moteur Rust.
 *
 * ## Les trois regles, et ce que chacune doit rendre
 *
 * - `v1`, telle que livree : hors sujet ici, le moteur PHP ne la distingue pas de `v2`.
 * - `v2`, effective : l Etoile quitte la bataille mais **reste comptee survivante** — le jeu refuse alors la
 *   victoire, donc le butin. Les essais de protection l epinglent : un combat ouvert sous elle doit encore
 *   se regler ainsi.
 * - `v3`, hors des survivants : l Etoile quitte aussi le decompte, et sa perte est comptee **une seule fois**.
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
     * **L Etoile detruite quitte le decompte des survivants, et sa perte est comptee exactement une fois.**
     *
     * Les deux vont ensemble : retirer l Etoile des survivants sans retirer l inscription separee de sa perte
     * la compterait deux fois ; retirer l inscription sans retirer l Etoile ne la compterait pas du tout.
     */
    public function testTheDestroyedDeathstarLeavesTheSurvivorCountAndIsLostExactlyOnce(): void
    {
        $deuxEtoiles = $this->laBataille(1);

        $this->assertSame(2, $deuxEtoiles->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Le depart annonce doit porter les deux Etoiles : c est lui qui ne bouge pas.');
        $this->assertSame(1, $deuxEtoiles->defenderUnitsResult->getAmountByMachineName('deathstar'), 'L Etoile detruite figure encore parmi les survivants.');
        $this->assertSame(1, $deuxEtoiles->defenderUnitsLost->getAmountByMachineName('deathstar'), 'La perte de l Etoile n est pas comptee exactement une fois.');

        // Chaque round dit la meme chose que le total : elle n est plus la, des le premier.
        $this->assertNotSame([], $deuxEtoiles->rounds, 'Aucun round : le decompte par round ne serait pas eprouve.');
        $this->assertSame(1, $deuxEtoiles->rounds[0]->defenderShips->getAmountByMachineName('deathstar'), 'Le premier round montre encore les deux Etoiles.');

        // Et le cas sans round, ou aucun decompte de round ne peut la retirer.
        $dernierDefenseur = $this->laBatailleDuDernierDefenseur(1);

        $this->assertSame([], $dernierDefenseur->rounds, 'Un round s est joue : le chemin sans round n est pas eprouve.');
        $this->assertSame(1, $dernierDefenseur->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Le depart annonce a perdu son Etoile.');
        $this->assertSame(0, $dernierDefenseur->defenderUnitsResult->getAmount(), 'Le decompte des survivants porte encore l Etoile detruite.');
        $this->assertSame(1, $dernierDefenseur->defenderUnitsLost->getAmountByMachineName('deathstar'), 'La perte de l Etoile n est pas comptee exactement une fois.');
    }

    /**
     * **La victoire et le butin reviennent a l attaquante** quand la manoeuvre prend le dernier defenseur.
     *
     * Le temoin de controle est une garnison vide sur le meme corps : meme stock, meme flotte, aucun round.
     * Sans lui, un butin nul pourrait venir d ailleurs que du decompte.
     */
    public function testTheAttackerWinsAndLootsWhenTheManoeuvreTakesTheLastDefender(): void
    {
        $vide = $this->fight(PhpBattleEngine::class, $this->aGeneralAttackingAStockedBody([]));
        $prise = $this->laBatailleDuDernierDefenseur(1);

        // Les premisses.
        $this->assertSame([], $vide->rounds, 'La garnison vide a combattu : les deux batailles ne sont plus comparables.');
        $this->assertGreaterThan(0.0, $vide->loot->sum(), 'Une garnison vide ne rend aucun butin : le temoin ne mesure rien.');
        $this->assertTrue($prise->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertTrue($this->laFlotteDefensive($prise, 0)->completelyDestroyed, 'La garnison n est pas detruite : le butin aurait une autre cause.');

        // La regle retablie : plus rien ne survit, donc l attaquante gagne et pille.
        $this->assertSame(0, $prise->defenderUnitsResult->getAmount(), 'Le decompte des survivants n est pas vide : la victoire restera refusee.');
        $this->assertSame(
            [(int)$vide->loot->metal->get(), (int)$vide->loot->crystal->get(), (int)$vide->loot->deuterium->get()],
            [(int)$prise->loot->metal->get(), (int)$prise->loot->crystal->get(), (int)$prise->loot->deuterium->get()],
            'Le butin d une manoeuvre qui prend le dernier defenseur ne vaut pas celui d une garnison vide.'
        );
    }

    /**
     * **La protection des batailles deja ouvertes** : sous la regle d hier, l Etoile detruite reste comptee
     * survivante et l attaquante ne pille pas.
     *
     * C est le comportement exact d un combat ouvert avant la correction. Si cet essai rougit, c est qu une
     * bataille deja engagee a change de regles en cours de route.
     */
    public function testACombatOpenedUnderTheEarlierRuleKeepsTheStarAmongTheSurvivors(): void
    {
        $deuxEtoiles = $this->laBataille(1, HamillManoeuvreRule::Effective);

        $this->assertSame(2, $deuxEtoiles->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Le depart annonce doit porter les deux Etoiles.');
        $this->assertSame(2, $deuxEtoiles->defenderUnitsResult->getAmountByMachineName('deathstar'), 'Sous la regle d hier, l Etoile detruite restait parmi les survivants.');
        $this->assertSame(1, $deuxEtoiles->defenderUnitsLost->getAmountByMachineName('deathstar'), 'Sous la regle d hier, la perte etait inscrite a part, une seule fois.');

        $dernierDefenseur = $this->laBatailleDuDernierDefenseur(1, HamillManoeuvreRule::Effective);

        $this->assertSame(1, $dernierDefenseur->defenderUnitsResult->getAmount(), 'Sous la regle d hier, l Etoile detruite comptait encore comme survivante.');
        $this->assertSame(0.0, $dernierDefenseur->loot->sum(), 'Sous la regle d hier, l attaquante ne pillait pas : la protection ne tient plus.');
        $this->assertSame(1, $dernierDefenseur->defenderUnitsLost->getAmountByMachineName('deathstar'), 'Sous la regle d hier aussi, la perte se compte une seule fois.');
    }

    private function laBataille(int $chance, HamillManoeuvreRule|null $regle = null): BattleResult
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $chance);

        return $this->fight(PhpBattleEngine::class, $this->aGeneralWhoseHamillManoeuvreSucceeds(), $regle);
    }

    private function laBatailleDuDernierDefenseur(int $chance, HamillManoeuvreRule|null $regle = null): BattleResult
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $chance);

        return $this->fight(PhpBattleEngine::class, $this->aGeneralWhoseHamillManoeuvreTakesTheLastDefender(), $regle);
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
