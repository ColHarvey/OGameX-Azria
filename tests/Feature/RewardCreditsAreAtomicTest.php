<?php

namespace Tests\Feature;

use OGame\Services\EventMissionService;
use OGame\Services\StarterAidService;
use ReflectionClass;
use Tests\UnitTestCase;

/**
 * Les recompenses creditent par la base, jamais par le modele charge.
 *
 * ## Le defaut que cette garde ferme
 *
 * `addResources()` et `addUnit()` additionnent sur le modele charge, puis `save()` reecrit la
 * colonne avec **une valeur absolue** calculee sur un stock lu avant la transaction. Toute ecriture
 * survenue entre-temps disparait.
 *
 * Le scenario n'a rien de theorique : encaisser une recompense pendant qu'une construction se paie
 * fait ecrire `stock d'avant + recompense`, ce qui **efface la depense**. Le joueur garde son
 * batiment et les ressources qu'il a coutees. Il suffit de deux onglets.
 *
 * `addResourcesAtomic()` et `addUnitAtomicIfStillOwnedBy()` font l'addition en base : elles lisent
 * la ligne au moment ou elles l'ecrivent, et rien ne peut s'y perdre.
 *
 * ## Pourquoi lire le code plutot que l'observer
 *
 * De l'exterieur, un credit atomique et un credit qui perd des ecritures rendent exactement le meme
 * resultat — **tant qu'il n'y a pas de concurrence**. La difference ne s'observe qu'avec deux
 * processus simultanes, donc sur MariaDB. Ici on ferme la porte a la source : ces deux services ne
 * doivent pas appeler les variantes non atomiques, et c'est verifiable sans course.
 *
 * C'est le meme raisonnement que `SettlementReadsNoLiveCargoCapacityTest`, qui interdit au reglement
 * de mesurer une capacite vivante.
 */
class RewardCreditsAreAtomicTest extends UnitTestCase
{
    /**
     * Les ecritures non atomiques, avec ce qu'il faut employer a la place.
     *
     * @var array<string, string>
     */
    private const array FORBIDDEN_CALLS = [
        '->addResources(' => 'addResourcesAtomic()',
        '->addUnit(' => 'addUnitAtomicIfStillOwnedBy()',
        '->addUnits(' => 'addUnitAtomicIfStillOwnedBy()',
        '->addResource(' => 'addResourcesAtomic()',
    ];

    /**
     * Aucun service de recompense ne credite par le modele charge.
     */
    public function testNoRewardServiceCreditsThroughTheLoadedModel(): void
    {
        $offenders = [];

        foreach ([StarterAidService::class, EventMissionService::class] as $class) {
            $path = (new ReflectionClass($class))->getFileName();
            $this->assertIsString($path, $class . ' has no file on disk: nothing could be read.');

            $lines = explode("\n", str_replace("\r\n", "\n", (string)file_get_contents($path)));
            $seen = 0;

            foreach ($lines as $number => $line) {
                // Une mention dans un commentaire explique le defaut, elle ne le commet pas.
                $code = trim($line);
                if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                    continue;
                }

                foreach (self::FORBIDDEN_CALLS as $call => $replacement) {
                    if (str_contains($line, $call)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . '  ' . $call . '  -> use ' . $replacement;
                    }
                }

                if (str_contains($line, 'addResourcesAtomic(') || str_contains($line, 'addUnitAtomicIfStillOwnedBy(')) {
                    $seen++;
                }
            }

            // **Sans cette exigence, la garde serait vide de sens** : un service qui ne crediterait
            // plus rien du tout la passerait sans reserve.
            $this->assertGreaterThan(
                0,
                $seen,
                $class . ' no longer credits anything atomically: either the guard is looking at the wrong code, or the rewards stopped paying.'
            );
        }

        $this->assertSame(
            [],
            $offenders,
            "These reward credits go through the loaded model, so a concurrent write is silently erased:\n  "
            . implode("\n  ", $offenders)
        );
    }
}
