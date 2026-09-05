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
        'Admission/FrozenAllianceMembership::fromStorage' => [
            'tests/Unit/Combat/FrozenAllianceMembershipTest.php',
            'testAStoredIdentifierOfTheWrongTypeIsRefused',
        ],
        'Allocation/ExactLootAmounts::fromStorage' => [
            'tests/Unit/Combat/LootSettlementTest.php',
            'testStoredAmountsAreNeverHydratedByCoercion',
        ],
        'Allocation/FrozenLootPotential::fromInstance' => [
            'tests/Feature/Combat/FrozenLootPotentialTest.php',
            'testACorruptedRateIsRefusedAtReading',
        ],
        'MoonDestruction/FrozenMoonDestructionAttempt::fromFrozenFacts' => [
            'tests/Unit/Combat/FrozenMoonDestructionPlanTest.php',
            'testAnAttemptWithANumericStringChanceIsRefused',
        ],
        'MoonDestruction/FrozenMoonDestructionPlan::fromFrozenFacts' => [
            'tests/Unit/Combat/FrozenMoonDestructionPlanTest.php',
            'testAPlanWithAFloatCombatIdentifierIsRefused',
        ],
        'MoonDestruction/FrozenMoonIdentity::fromFrozenFacts' => [
            'tests/Unit/Combat/FrozenMoonDestructionPlanTest.php',
            'testAMoonWithANumericStringIdentifierIsRefused',
        ],
        'Application/FrozenCombatApplicationContext::fromStorage' => [
            'tests/Unit/Combat/FrozenCombatApplicationContextTest.php',
            'testASpaceDockLevelGivenAsANumericStringIsRefused',
        ],
        'Replay/BattleResultCodec::fromStorage' => [
            'tests/Unit/Combat/BattleResultCodecTest.php',
            'testANumericStringIsRefused',
        ],
        'Replay/CombatResultIdentity::fromStorage' => [
            'tests/Unit/Combat/BattleResultCodecTest.php',
            'testAnIdentityWithANumericStringCombatIsRefused',
        ],
        'Services/PhotographedDefender::fromFrozenFacts' => [
            'tests/Unit/Combat/PhotographedDefenderFactsTest.php',
            'testANumericStringLevelIsRefused',
        ],
        'Services/MissileStrikeFacts::fromFrozenFacts' => [
            'tests/Unit/Combat/MissileStrikeFactsTest.php',
            'testANumericStringMissileCountIsRefused',
        ],
        'Services/PhotographedUniverse::fromFrozenFacts' => [
            'tests/Unit/Combat/PhotographedUniverseFactsTest.php',
            'testANumericStringSettingIsRefused',
        ],
        'Support/FrozenCombatVersionSet::fromInstance' => [
            'tests/Unit/Combat/FrozenCombatVersionSetTest.php',
            'testAnInstanceWithAMissingVersionIsRefused',
        ],
        'Support/FrozenCombatVersionSet::fromStorage' => [
            'tests/Unit/Combat/FrozenCombatVersionSetTest.php',
            'testEachOfTheFiveVersionsIsRefusedWhenItIsNotAString',
        ],
        'Support/LootContext::fromFrozenFacts' => [
            'tests/Unit/Combat/LootContextTest.php',
            'testANumericStringRateIsRefused',
        ],
        'Support/OperationKey::rehydrate' => [
            'tests/Unit/Combat/OperationKeyTest.php',
            'testANumericStringIdentifierIsRefusedAtRehydration',
        ],
        'Support/SnapshotContributionSet::fromStorage' => [
            'tests/Unit/Combat/SnapshotContributionSetTest.php',
            'testAStoredStructureThatIsNotAListIsRefused',
        ],
    ];

    /**
     * Aucune porte de relecture n'existe sans son essai de refus.
     */
    public function testEveryRehydrationDoorHasAProvenRefusal(): void
    {
        $racine = dirname(__DIR__, 3);
        $trouvees = [];

        foreach ($this->phpFilesOf($racine . '/app/Combat') as $fichier) {
            $source = file_get_contents($fichier);

            if ($source === false) {
                continue;
            }

            if (preg_match_all('/public static function (fromStorage|fromFrozenFacts|rehydrate|fromInstance)\(/', $source, $m) === 0) {
                continue;
            }

            $relatif = str_replace(DIRECTORY_SEPARATOR, '/', substr($fichier, strlen($racine . '/app/Combat/')));
            $classe = substr($relatif, 0, -4);

            foreach ($m[1] as $methode) {
                $trouvees[] = $classe . '::' . $methode;
            }
        }

        sort($trouvees);
        $inscrites = array_keys(self::PROVEN);
        sort($inscrites);

        $this->assertSame(
            $inscrites,
            $trouvees,
            'A persisted rehydration door exists without a registered refusal test, or a registered '
            . 'one is gone. Every fromStorage / fromFrozenFacts / rehydrate / fromInstance in app/Combat '
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
