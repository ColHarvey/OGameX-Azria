<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\UnitCharacteristicsRule;
use OGame\Combat\Exceptions\UnknownUnitCharacteristicsRule;
use OGame\Models\CombatInstance;
use Tests\TestCase;

/**
 * La regle qui compose les unites d un combat se relit telle qu elle est ecrite, ou pas du tout.
 *
 * ## Pourquoi refuser plutot que supposer
 *
 * Une colonne vide lue comme « premiere regle » ferait tirer sans bonus un combat ouvert apres la
 * decision ; lue comme « gel a l entree », elle ferait chercher des caracteristiques que personne n a
 * inscrites. Les deux replis sont plausibles, et aucun n est vrai.
 */
class UnitCharacteristicsRuleTest extends TestCase
{
    /**
     * Un combat qui porte cette valeur brute dans sa colonne, sans passer par aucune conversion.
     */
    private static function aCombatCarrying(mixed $valeur): CombatInstance
    {
        $combat = new CombatInstance();
        $combat->setRawAttributes(['id' => 42, 'unit_characteristics_version' => $valeur]);

        return $combat;
    }

    /**
     * Un combat ouvert aujourd hui gele ses caracteristiques a l entree.
     */
    public function testACombatOpenedTodayFreezesItsCharacteristicsAtEntry(): void
    {
        $this->assertSame(UnitCharacteristicsRule::FrozenAtEntry, UnitCharacteristicsRule::current());
        $this->assertSame('v2', UnitCharacteristicsRule::current()->value);
        $this->assertSame('v1', UnitCharacteristicsRule::FirstRule->value, 'The first rule changed its name: the migration backfills « v1 ».');
    }

    /**
     * Les deux regles ecrites se relisent chacune pour elle-meme.
     */
    public function testBothWrittenRulesAreReadBack(): void
    {
        $this->assertSame(UnitCharacteristicsRule::FirstRule, UnitCharacteristicsRule::fromInstance(self::aCombatCarrying('v1')));
        $this->assertSame(UnitCharacteristicsRule::FrozenAtEntry, UnitCharacteristicsRule::fromInstance(self::aCombatCarrying('v2')));
    }

    /**
     * Une colonne vide est refusee, jamais interpretee.
     */
    public function testAnEmptyRuleIsRefusedRatherThanAssumed(): void
    {
        $this->expectException(UnknownUnitCharacteristicsRule::class);

        UnitCharacteristicsRule::fromInstance(self::aCombatCarrying(null));
    }

    /**
     * Un nom que ce code ne connait pas est refuse.
     */
    public function testAnUnknownRuleIsRefused(): void
    {
        $this->expectException(UnknownUnitCharacteristicsRule::class);

        UnitCharacteristicsRule::fromInstance(self::aCombatCarrying('v3'));
    }

    /**
     * **Ni un entier, ni un flottant, ni une chaine numerique, ni un booleen.**
     */
    public function testARuleThatIsNotAStringIsRefused(): void
    {
        foreach ([2, 2.0, '2', true] as $valeur) {
            try {
                UnitCharacteristicsRule::fromInstance(self::aCombatCarrying($valeur));
                $this->fail('A rule stored as ' . get_debug_type($valeur) . ' was read back instead of refused.');
            } catch (UnknownUnitCharacteristicsRule) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
