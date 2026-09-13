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
    public function testTheCurrentRuleTakesTheDestroyedStarOutOfTheSurvivors(): void
    {
        $this->assertSame(HamillManoeuvreRule::OutOfTheSurvivors, HamillManoeuvreRule::current(), 'Un combat neuf s ouvrirait sous une regle depassee.');
    }

    /**
     * **Aucune regle ne reste sans reponse.**
     *
     * Les deux questions que les moteurs posent a la regle sont resolues par un `match` sans repli : une
     * version ajoutee sans etre traitee **arrete** le calcul au lieu de retomber en silence sur l ancien.
     * Cet essai parcourt les regles declarees, et la table ci-dessous doit etre tenue a jour avec elles —
     * c est la comparaison des clefs qui l impose.
     */
    public function testEveryRuleAnswersBothQuestionsInsteadOfFallingBackSilently(): void
    {
        $attendu = [
            'v1' => ['quitte la bataille' => false, 'compte comme survivante' => true],
            'v2' => ['quitte la bataille' => true, 'compte comme survivante' => true],
            'v3' => ['quitte la bataille' => true, 'compte comme survivante' => false],
        ];

        $this->assertSame(
            array_keys($attendu),
            array_map(static fn (HamillManoeuvreRule $regle): string => $regle->value, HamillManoeuvreRule::cases()),
            'Une regle a ete ajoutee ou retiree sans que cette table le dise.'
        );

        foreach (HamillManoeuvreRule::cases() as $regle) {
            $this->assertSame(
                $attendu[$regle->value]['quitte la bataille'],
                $regle->theManoeuvreLeavesTheBattle(),
                'La regle ' . $regle->value . ' ne dit pas si l Etoile quitte la bataille.'
            );
            $this->assertSame(
                $attendu[$regle->value]['compte comme survivante'],
                $regle->theDestroyedDeathstarStillCountsAsASurvivor(),
                'La regle ' . $regle->value . ' ne dit pas si l Etoile detruite compte encore parmi les survivants.'
            );
        }
    }

    public function testACombatCarriesTheRuleItsOpeningWrote(): void
    {
        $this->assertSame(HamillManoeuvreRule::AsDelivered, HamillManoeuvreRule::fromInstance($this->unCombatPortant('v1')));
        $this->assertSame(HamillManoeuvreRule::Effective, HamillManoeuvreRule::fromInstance($this->unCombatPortant('v2')));
        $this->assertSame(HamillManoeuvreRule::OutOfTheSurvivors, HamillManoeuvreRule::fromInstance($this->unCombatPortant('v3')));
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
