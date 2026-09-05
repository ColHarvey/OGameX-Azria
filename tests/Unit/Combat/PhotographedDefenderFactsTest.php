<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Services\PhotographedDefender;
use PHPUnit\Framework\TestCase;

/**
 * La porte de relecture des faits du defenseur refuse ce qui n'est pas un entier.
 *
 * ## Pourquoi un refus, et pas un transtypage
 *
 * `(int)'4'`, `(int)4.7` et `(int)true` valent tous 4. Un document abime — une valeur ecrite par une
 * version anterieure, un JSON relu autrement, une main qui a corrige la base — passerait donc pour un
 * document valide, et la bataille se jouerait sur des niveaux que personne n'a ecrits. Aux portes de
 * confiance, l'exactitude se tient par un refus.
 *
 * Le tour complet est verifie aussi : ce qui est ecrit se relit identique, sinon le refus ne
 * protegerait qu'un chemin que le jeu n'emprunte jamais.
 */
final class PhotographedDefenderFactsTest extends TestCase
{
    public function testANumericStringLevelIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMoonPlan::class);

        PhotographedDefender::fromFrozenFacts(self::facts(['weapon_level' => '7']));
    }

    public function testAFloatLevelIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMoonPlan::class);

        PhotographedDefender::fromFrozenFacts(self::facts(['space_dock_level' => 3.0]));
    }

    public function testAMissingFactIsRefused(): void
    {
        $faits = self::facts();
        unset($faits['class_combat_bonus']);

        $this->expectException(CorruptedFrozenMoonPlan::class);

        PhotographedDefender::fromFrozenFacts($faits);
    }

    public function testWhatIsWrittenIsReadBackIdentical(): void
    {
        $defenseur = new PhotographedDefender(12, 9, 4, 2, 5);

        $relu = PhotographedDefender::fromFrozenFacts($defenseur->toFrozenFacts());

        $this->assertSame($defenseur->toFrozenFacts(), $relu->toFrozenFacts());
    }

    /**
     * Les relevements ne descendent jamais : une recherche atteint un niveau, elle ne le rend pas.
     */
    public function testARaiseNeverLowersALevel(): void
    {
        $defenseur = new PhotographedDefender(12, 9, 4, 2, 5);

        $this->assertSame(12, $defenseur->withResearchLevel('weapon_technology', 3)->weaponLevel);
        $this->assertSame(14, $defenseur->withResearchLevel('weapon_technology', 14)->weaponLevel);
        $this->assertSame(5, $defenseur->withSpaceDockLevel(2)->spaceDockLevel);
        $this->assertSame(8, $defenseur->withSpaceDockLevel(8)->spaceDockLevel);
    }

    /**
     * Une recherche qui n'entre pas dans la bataille ne change rien : la lever silencieusement
     * ailleurs ferait dependre le combat d'un fait qu'il ne consomme pas.
     */
    public function testAResearchTheBattleDoesNotUseChangesNothing(): void
    {
        $defenseur = new PhotographedDefender(12, 9, 4, 2, 5);

        $this->assertSame($defenseur->toFrozenFacts(), $defenseur->withResearchLevel('computer_technology', 30)->toFrozenFacts());
    }

    /**
     * @param array<string, mixed> $remplacements
     * @return array<string, mixed>
     */
    private static function facts(array $remplacements = []): array
    {
        return $remplacements + [
            'weapon_level' => 1,
            'shield_level' => 2,
            'armor_level' => 3,
            'class_combat_bonus' => 0,
            'space_dock_level' => 1,
        ];
    }
}
