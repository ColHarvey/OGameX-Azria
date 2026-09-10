<?php

namespace Tests\Unit\Combat;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Chaque relecture persistee du combat a un essai qui refuse une chaine numerique ou un flottant.
 *
 * ## Pourquoi une garde, et pourquoi bornee
 *
 * Aucun fichier du depot ne declare `strict_types` : un parametre `int` accepte « 42 » et 42.0 en
 * les convertissant, et un cast `(int)` accepte n'importe quoi. Reecrire tous les constructeurs du
 * domaine en fabriques `mixed` serait une reecriture tres large qui empilerait des controles sur des
 * valeurs deja verifiees.
 *
 * La contrainte se pose donc **aux portes de confiance** — ici, les relectures de faits geles, qui
 * entrent dans l'empreinte, l'ordre causal, les cles d'idempotence, les montants et le plan lunaire.
 * A chacune, un essai doit prouver qu'une chaine numerique ou un flottant sur un champ numerique
 * critique est refuse, pas converti.
 *
 * ## Ce que cette garde attrape
 *
 * Une nouvelle methode `fromStorage`, `fromFrozenFacts`, `rehydrate` ou `fromInstance` qui apparait
 * dans `app/Combat` sans etre inscrite ici, avec le nom de l'essai qui la prouve. Et un essai inscrit
 * qui aurait disparu. Elle ne juge pas le contenu de l'essai : c'est la relecture par un humain qui
 * le fait, une fois, au moment de l'inscrire.
 *
 * Le jour ou cette garde a ete ecrite, le plan lunaire **castait** ses trois relectures. C'est elle
 * qui aurait empeche qu'une quatrieme arrive sans preuve.
 */
class PersistedRehydrationGuardTest extends TestCase
{
    /**
     * Les portes connues, et l'essai qui prouve leur refus.
     *
     * @var array<string, array{0: string, 1: string}> Classe::methode => [fichier d'essai, methode d'essai]
     */
    private const array PROVEN = [
        'Combat/Admission/FrozenAllianceMembership::fromStorage' => [
            'tests/Unit/Combat/FrozenAllianceMembershipTest.php',
            'testAStoredIdentifierOfTheWrongTypeIsRefused',
        ],
        'Combat/Allocation/ExactLootAmounts::fromStorage' => [
            'tests/Unit/Combat/LootSettlementTest.php',
            'testStoredAmountsAreNeverHydratedByCoercion',
        ],
        'Combat/Allocation/FrozenLootPotential::fromInstance' => [
            'tests/Feature/Combat/FrozenLootPotentialTest.php',
            'testACorruptedRateIsRefusedAtReading',
        ],
        'Combat/MoonDestruction/FrozenMoonDestructionAttempt::fromFrozenFacts' => [
            'tests/Unit/Combat/FrozenMoonDestructionPlanTest.php',
            'testAnAttemptWithANumericStringChanceIsRefused',
        ],
        'Combat/MoonDestruction/FrozenMoonDestructionPlan::fromFrozenFacts' => [
            'tests/Unit/Combat/FrozenMoonDestructionPlanTest.php',
            'testAPlanWithAFloatCombatIdentifierIsRefused',
        ],
        'Combat/MoonDestruction/FrozenMoonIdentity::fromFrozenFacts' => [
            'tests/Unit/Combat/FrozenMoonDestructionPlanTest.php',
            'testAMoonWithANumericStringIdentifierIsRefused',
        ],
        'Combat/Application/FrozenCombatApplicationContext::fromStorage' => [
            'tests/Unit/Combat/FrozenCombatApplicationContextTest.php',
            'testASpaceDockLevelGivenAsANumericStringIsRefused',
        ],
        'Combat/Replay/BattleFieldStateCodec::fromStorage' => [
            'tests/Unit/Combat/BattleFieldStateCodecTest.php',
            'testANumericStringIsRefused',
        ],
        'Combat/Replay/BattleResultCodec::fromStorage' => [
            'tests/Unit/Combat/BattleResultCodecTest.php',
            'testANumericStringIsRefused',
        ],
        'Combat/Replay/CombatResultIdentity::fromStorage' => [
            'tests/Unit/Combat/BattleResultCodecTest.php',
            'testAnIdentityWithANumericStringCombatIsRefused',
        ],
        'Combat/Services/PhotographedDefender::fromFrozenFacts' => [
            'tests/Unit/Combat/PhotographedDefenderFactsTest.php',
            'testANumericStringLevelIsRefused',
        ],
        'Combat/Services/MissileStrikeFacts::fromFrozenFacts' => [
            'tests/Unit/Combat/MissileStrikeFactsTest.php',
            'testANumericStringMissileCountIsRefused',
        ],
        'Combat/Services/PhotographedUniverse::fromFrozenFacts' => [
            'tests/Unit/Combat/PhotographedUniverseFactsTest.php',
            'testANumericStringSettingIsRefused',
        ],
        'Combat/Support/FrozenCombatVersionSet::fromInstance' => [
            'tests/Unit/Combat/FrozenCombatVersionSetTest.php',
            'testAnInstanceWithAMissingVersionIsRefused',
        ],
        'Combat/Support/FrozenCombatVersionSet::fromStorage' => [
            'tests/Unit/Combat/FrozenCombatVersionSetTest.php',
            'testEachOfTheFiveVersionsIsRefusedWhenItIsNotAString',
        ],
        'Combat/Support/LootContext::fromFrozenFacts' => [
            'tests/Unit/Combat/LootContextTest.php',
            'testANumericStringRateIsRefused',
        ],
        'Combat/Support/OperationKey::rehydrate' => [
            'tests/Unit/Combat/OperationKeyTest.php',
            'testANumericStringIdentifierIsRefusedAtRehydration',
        ],
        'Combat/Support/SnapshotContributionSet::fromStorage' => [
            'tests/Unit/Combat/SnapshotContributionSetTest.php',
            'testAStoredStructureThatIsNotAListIsRefused',
        ],
        'Patrol/Combat/FrozenSpatialDefence::fromFrozenFacts' => [
            'tests/Feature/SpatialOpeningStateTest.php',
            'testANumericStringInTheRosterIsRefused',
        ],
    ];

    /**
     * Aucune porte de relecture n'existe sans son essai de refus.
     */
    public function testEveryRehydrationDoorHasAProvenRefusal(): void
    {
        $racine = dirname(__DIR__, 3);
        $trouvees = [];

        // **Deux racines, et la seconde a ete ajoutee parce qu'une porte lui avait echappe.**
        // `app/Patrol/Combat` porte desormais des relectures de faits geles — la defense d'un
        // combat en espace libre —, et un inventaire limite a `app/Combat` les aurait laissees
        // entrer sans essai de refus. Les clefs portent donc la racine, ce qui rend l'oubli
        // visible : une porte non inscrite se lit avec son chemin complet.
        foreach (['Combat', 'Patrol'] as $racineRelative) {
            foreach ($this->phpFilesOf($racine . '/app/' . $racineRelative) as $fichier) {
                $source = file_get_contents($fichier);

                if ($source === false) {
                    continue;
                }

                if (preg_match_all('/public static function (fromStorage|fromFrozenFacts|rehydrate|fromInstance)\(/', $source, $m) === 0) {
                    continue;
                }

                $relatif = str_replace(DIRECTORY_SEPARATOR, '/', substr($fichier, strlen($racine . '/app/')));
                $classe = substr($relatif, 0, -4);

                foreach ($m[1] as $methode) {
                    $trouvees[] = $classe . '::' . $methode;
                }
            }
        }

        sort($trouvees);
        $inscrites = array_keys(self::PROVEN);
        sort($inscrites);

        $this->assertSame(
            $inscrites,
            $trouvees,
            'A persisted rehydration door exists without a registered refusal test, or a registered '
            . 'one is gone. Every fromStorage / fromFrozenFacts / rehydrate / fromInstance in app/Combat or app/Patrol '
            . 'must be listed here with the test that proves it refuses a numeric string or a float.'
        );
    }

    /**
     * Chaque essai inscrit existe reellement, la ou il est annonce.
     *
     * Sans cette moitie, inscrire un nom suffirait a faire taire la garde.
     */
    public function testEveryRegisteredRefusalTestExists(): void
    {
        $racine = dirname(__DIR__, 3);

        foreach (self::PROVEN as $porte => [$fichier, $methode]) {
            $source = file_get_contents($racine . '/' . $fichier);

            $this->assertIsString($source, 'The test file registered for ' . $porte . ' does not exist.');
            $this->assertStringContainsString(
                'function ' . $methode . '(',
                $source,
                'The refusal test « ' . $methode . ' » registered for ' . $porte . ' is not in ' . $fichier . '.'
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesOf(string $directory): array
    {
        $fichiers = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $entree) {
            if ($entree instanceof SplFileInfo && $entree->getExtension() === 'php') {
                $fichiers[] = $entree->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }
}
