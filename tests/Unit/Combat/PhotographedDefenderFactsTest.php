<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Services\PhotographedDefender;
use OGame\Combat\Support\FrozenCombatCharacteristics;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;
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
     * **Les quatre nombres qui arment les tirs peuvent venir d ailleurs ; le chantier spatial, jamais.**
     *
     * C est ce que fait la cloture sous le gel a l admission : la garnison tire avec ce que le registre a
     * inscrit a la barriere d ouverture, et le rapport annonce ces nombres-la.
     */
    public function testTheCombatNumbersMayComeFromElsewhereButNotTheSpaceDock(): void
    {
        $defenseur = new PhotographedDefender(12, 9, 4, 2, 5);

        $releve = $defenseur->withCombatCharacteristics(new FrozenCombatCharacteristics(3, 1, 7, 0));

        $this->assertSame(
            ['weapon_level' => 3, 'shield_level' => 1, 'armor_level' => 7, 'class_combat_bonus' => 0, 'space_dock_level' => 5, 'lifeform_bonuses' => FrozenLifeformCombatBonuses::none()->toFrozenFacts()],
            $releve->toFrozenFacts()
        );
    }

    /**
     * **Les bonus de formes de vie voyagent avec le defenseur** : les unites viennent avec les tirs releves, les faits
     * du corps (population, lune, debris, epaves) restent ceux de la photographie ; un document d avant se relit sans rien.
     */
    public function testLifeformBonusesTravelWithTheDefenderAndAreAbsentFromOlderDocuments(): void
    {
        $corps = new FrozenLifeformCombatBonuses(['defence' => 5.0], 0.3, 0.1, 0.06, 0.13);
        $defenseur = new PhotographedDefender(12, 9, 4, 2, 5, $corps);

        $this->assertSame($defenseur->toFrozenFacts(), PhotographedDefender::fromFrozenFacts($defenseur->toFrozenFacts())->toFrozenFacts());
        $this->assertSame(0.3, $defenseur->withResearchLevel('weapon_technology', 14)->lifeformBonuses->protectedShare);
        $this->assertSame(0.13, $defenseur->withSpaceDockLevel(8)->lifeformBonuses->wreckRecovery);

        $releve = $defenseur->withCombatCharacteristics(new FrozenCombatCharacteristics(3, 1, 7, 0, new FrozenLifeformCombatBonuses(['light_fighter' => 3.0], null, 0.0, 0.0, 0.0)));
        $this->assertSame(['light_fighter' => 3.0], $releve->lifeformBonuses->unitStats, 'Les unites viennent avec les tirs.');
        $this->assertSame(0.3, $releve->lifeformBonuses->protectedShare, 'La population protegee reste celle du corps.');
        $this->assertSame(0.06, $releve->lifeformBonuses->debrisRecovery);

        $ancien = self::facts();
        $this->assertArrayNotHasKey('lifeform_bonuses', $ancien);
        $this->assertTrue(PhotographedDefender::fromFrozenFacts($ancien)->lifeformBonuses->isNone(), 'Un document de version 7 ne porte aucune forme de vie.');

        $this->expectException(CorruptedFrozenMoonPlan::class);
        PhotographedDefender::fromFrozenFacts(self::facts(['lifeform_bonuses' => ['unit_stats' => [], 'protected_share' => '0.3', 'moon_chance' => 0, 'debris_recovery' => 0, 'wreck_recovery' => 0]]));
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
