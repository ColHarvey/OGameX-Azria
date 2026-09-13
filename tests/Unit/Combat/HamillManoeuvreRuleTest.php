<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\Combat\Exceptions\UnknownHamillManoeuvreRule;
use OGame\Models\CombatInstance;
use Tests\UnitTestCase;

/**
 * La regle de la manoeuvre de Hamill se lit sur le combat, ou ne se lit pas.
 *
 * ## Pourquoi une porte de relecture
 *
 * Un combat durable s ouvre, puis se calcule des heures plus tard. La regle qu il a promise est ecrite a son
 * ouverture ; l interpreter par defaut ferait jouer une bataille sous des regles que personne ne lui a
 * donnees — et, dans ce cas precis, ferait tomber une Etoile de la mort la ou l attaquant ne pouvait pas y
 * compter.
 */
final class HamillManoeuvreRuleTest extends UnitTestCase
{
    public function testTheCurrentRuleIsTheEffectiveOne(): void
    {
        $this->assertSame(HamillManoeuvreRule::Effective, HamillManoeuvreRule::current(), 'Un combat neuf s ouvrirait sous la regle inerte.');
    }

    public function testACombatCarriesTheRuleItsOpeningWrote(): void
    {
        $this->assertSame(HamillManoeuvreRule::AsDelivered, HamillManoeuvreRule::fromInstance($this->unCombatPortant('v1')));
        $this->assertSame(HamillManoeuvreRule::Effective, HamillManoeuvreRule::fromInstance($this->unCombatPortant('v2')));
    }

    public function testAnAbsentRuleIsRefusedRatherThanAssumed(): void
    {
        try {
            HamillManoeuvreRule::fromInstance($this->unCombatPortant(null));
            $this->fail('Une colonne vide a ete interpretee comme une regle.');
        } catch (UnknownHamillManoeuvreRule $refus) {
            $this->assertStringContainsString('null', $refus->getMessage());
        }
    }

    /**
     * **Une regle qui n est pas une chaine est refusee, jamais convertie.**
     *
     * Aucun fichier du depot ne declare `strict_types` : `2` traverserait une signature `string` en devenant
     * « 2 », et un combat jouerait alors une regle que personne ne lui a ecrite.
     */
    public function testARuleThatIsNotAStringIsRefused(): void
    {
        foreach ([2, 2.0, true] as $valeur) {
            $combat = new CombatInstance();
            $combat->setRawAttributes(['id' => 7, 'hamill_rule_version' => $valeur], true);

            try {
                HamillManoeuvreRule::fromInstance($combat);
                $this->fail('La valeur ' . var_export($valeur, true) . ' a ete lue comme une regle.');
            } catch (UnknownHamillManoeuvreRule $refus) {
                $this->assertStringContainsString('non un nom', $refus->getMessage());
            }
        }
    }

    public function testARuleThisCodeDoesNotKnowIsRefused(): void
    {
        try {
            HamillManoeuvreRule::fromInstance($this->unCombatPortant('v9'));
            $this->fail('Une regle inconnue a ete acceptee.');
        } catch (UnknownHamillManoeuvreRule $refus) {
            $this->assertStringContainsString('v9', $refus->getMessage());
        }
    }

    private function unCombatPortant(string|null $version): CombatInstance
    {
        $combat = new CombatInstance();
        $combat->setRawAttributes(['id' => 7, 'hamill_rule_version' => $version], true);

        return $combat;
    }
}
