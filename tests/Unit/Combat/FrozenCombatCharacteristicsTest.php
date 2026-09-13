<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Support\FrozenCombatCharacteristics;
use Tests\TestCase;

/**
 * Ce qu un participant apporte a ses tirs se relit champ par champ, sans conversion.
 *
 * ## Le refus attrape est celui du lecteur, et lui seul
 *
 * La premiere version attrapait `RuntimeException`. Or `$this->fail()` leve une exception de PHPUnit qui
 * **en est une** : l echec de l essai etait attrape par l essai lui-meme, et son message — qui nomme le
 * champ — satisfaisait l assertion suivante. Une relecture transtypee survivait (mutation M30). On attrape
 * donc l exception que `FrozenFact` leve, `CorruptedFrozenMoonPlan`, et rien d autre.
 */
class FrozenCombatCharacteristicsTest extends TestCase
{
    /**
     * Une ligne telle que le registre l ecrit, avec des valeurs toutes differentes : un champ lu a la
     * place d un autre se verrait.
     *
     * @param array<string, mixed> $remplace
     * @return array<string, mixed>
     */
    private static function aLine(array $remplace = []): array
    {
        return $remplace + ['weapon_level' => 7, 'shield_level' => 5, 'armor_level' => 3, 'class_combat_bonus' => 2];
    }

    /**
     * Une ligne ecrite se relit a l identique, champ par champ.
     */
    public function testAWrittenLineIsReadBackFieldByField(): void
    {
        $faits = FrozenCombatCharacteristics::fromStorage(self::aLine());

        $this->assertSame(7, $faits->weaponLevel);
        $this->assertSame(5, $faits->shieldLevel);
        $this->assertSame(3, $faits->armorLevel);
        $this->assertSame(2, $faits->classCombatBonus);
        $this->assertSame(self::aLine(), $faits->toStorage());
    }

    /**
     * **Une chaine numerique, un flottant, un booleen ou un vide sont refuses**, pour chacun des quatre
     * champs. Un `(int)` les aurait tous rendus plausibles.
     */
    public function testANumericStringOrAFloatLevelIsRefused(): void
    {
        foreach (['weapon_level', 'shield_level', 'armor_level', 'class_combat_bonus'] as $champ) {
            foreach (['7', 7.0, true, null] as $valeur) {
                try {
                    FrozenCombatCharacteristics::fromStorage(self::aLine([$champ => $valeur]));
                    $this->fail('« ' . $champ . ' » stored as ' . get_debug_type($valeur) . ' was read back instead of refused.');
                } catch (CorruptedFrozenMoonPlan $refus) {
                    $this->assertStringContainsString($champ, $refus->getMessage(), 'The refusal does not name the field it refused.');
                }
            }
        }
    }

    /**
     * Un champ absent est refuse, et le refus le nomme.
     */
    public function testAMissingLevelIsRefused(): void
    {
        foreach (['weapon_level', 'shield_level', 'armor_level', 'class_combat_bonus'] as $champ) {
            $ligne = self::aLine();
            unset($ligne[$champ]);

            try {
                FrozenCombatCharacteristics::fromStorage($ligne);
                $this->fail('A line without « ' . $champ . ' » was read back instead of refused.');
            } catch (CorruptedFrozenMoonPlan $refus) {
                $this->assertStringContainsString($champ, $refus->getMessage());
            }
        }
    }
}
