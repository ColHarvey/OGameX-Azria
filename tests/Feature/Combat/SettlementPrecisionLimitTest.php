<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Exceptions\UnsettleableAtThisScale;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Pourquoi le reglement refuse au-dela de deux puissance cinquante-trois.
 *
 * ## Le fait de stockage, d'abord
 *
 * Les soldes des corps celestes et les cargaisons des missions sont des **colonnes flottantes** —
 * `2024_04_13_173549` pour les planetes, `2025_01_18_230622` pour les missions, deux migrations du
 * depot amont prises pour accepter de tres grandes fortunes. Un flottant double distingue chaque
 * entier jusqu'a 2^53 ; au-dela, deux montants voisins deviennent le meme nombre.
 *
 * ## Ce que cela implique pour la promesse d'exactitude
 *
 * Le combat durable promet que ce qui est debite a la cible est exactement ce qui est embarque et
 * exactement ce que le rapport raconte. A cette echelle, **aucune ecriture ne peut tenir cette
 * promesse** : la perte survient dans la colonne, pas dans le calcul. Un vecteur entier interne, un
 * plan de repartition exact, un overlay type — rien n'y change quoi que ce soit.
 *
 * Le reglement s'arrete donc, et le combat part en quarantaine avec sa raison. Cet essai etablit le
 * fait qui justifie ce refus ; `CombatSettlementServiceTest` prouve que le refus a bien lieu.
 */
class SettlementPrecisionLimitTest extends TestCase
{
    /**
     * La colonne ne distingue pas deux montants voisins au-dela de 2^53.
     *
     * L'essai ecrit `2^53 + 1` et relit `2^53` : c'est la mesure, pas une supposition. Si un jour le
     * stockage devient exact, cet essai tombera — et le refus du reglement pourra tomber avec lui.
     */
    public function testThePlanetColumnCannotTellTwoNeighbouringAmountsApartBeyondTwoToTheFiftyThree(): void
    {
        $planete = Planet::factory()->create([
            'user_id' => User::factory()->create()->id,
            'galaxy' => 7,
            'system' => 499,
            'planet' => 15,
        ]);

        $exact = 9_007_199_254_740_993; // 2^53 + 1
        DB::table('planets')->where('id', $planete->id)->update(['metal' => $exact]);

        $relu = DB::table('planets')->where('id', $planete->id)->value('metal');

        $this->assertNotSame(
            $exact,
            (int)$relu,
            'The resource column now holds this amount exactly: the settlement may stop refusing at this scale.'
        );
        $this->assertSame(
            9_007_199_254_740_992, // 2^53
            (int)$relu,
            'The column degraded the amount to something other than the nearest representable double.'
        );
    }

    /**
     * Le refus nomme le combat et l'endroit ou la precision a ete perdue.
     */
    public function testTheRefusalNamesTheCombatAndWhereThePrecisionWasLost(): void
    {
        $refus = new UnsettleableAtThisScale(42, 'le solde restant de la cible');

        $this->assertSame(42, $refus->combatInstanceId);
        $this->assertStringContainsString('42', $refus->getMessage());
        $this->assertStringContainsString('le solde restant de la cible', $refus->getMessage());
    }
}
