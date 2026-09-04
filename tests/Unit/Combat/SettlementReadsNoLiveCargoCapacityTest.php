<?php

namespace Tests\Unit\Combat;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Le reglement ne relit aucune capacite de fret sur un joueur vivant.
 *
 * ## Ce que la garde protege
 *
 * Une capacite de fret depend de la classe et de l'hyperespace de son proprietaire. Chacune decide
 * d'un transfert reel : ce qu'un retour rapporte, ce qu'un renfort garde de sa cargaison, combien
 * de debris un Faucheur ramasse et a qui ils vont. Sur le chemin durable, des heures separent le
 * calcul de la bataille de son application : une capacite relue a l'echeance porte les bonus
 * acquis **apres** la bataille, et deux rejeux du meme resultat gele ne rendent pas le meme nombre.
 *
 * Toutes ces capacites sont donc prises une fois, dans le moteur, a l'instant ou la bataille est
 * calculee (`BattleEngine::freezeCargoCapacities()`), et persistees avec le resultat. Le reglement
 * ne fait que les lire.
 *
 * ## Ce que la garde n'interdit pas
 *
 * Le reglement continue de faire des joueurs : pour leur envoyer un avis, pour construire un
 * retour, pour lire un contexte gele a leur nom. Seule une **capacite de fret calculee sur un
 * joueur** est interdite ici — c'est elle, et elle seule, qui deciderait d'un debit, d'un credit ou
 * d'une collecte sur une valeur que la bataille n'a pas vue.
 *
 * ## Pourquoi une garde, et pas seulement du code retire
 *
 * Neuf sites relisaient ces capacites vivantes, ecrits de bonne foi sur le chemin instantane ou
 * la relecture est sans danger. Rien dans le code ne dit qu'un dixieme serait faux : cette garde
 * le dit.
 */
class SettlementReadsNoLiveCargoCapacityTest extends TestCase
{
    /**
     * Les fichiers qui appliquent un resultat gele, et qui n'ont donc aucune capacite a mesurer.
     *
     * @var array<int, string>
     */
    private const array APPLICATORS = [
        'app/Combat/Services/CombatResolutionService.php',
        'app/Combat/Services/CombatSettlementService.php',
    ];

    /**
     * Les deux manieres de mesurer une capacite de fret sur un joueur.
     *
     * @var array<int, string>
     */
    private const array LIVE_READS = [
        'getTotalCargoCapacity(',
        'capacity->calculate(',
    ];

    public function testNoApplicatorMeasuresACargoCapacityOnAPlayer(): void
    {
        $racine = dirname(__DIR__, 3);
        $lectures = [];

        foreach (self::APPLICATORS as $fichier) {
            $source = file_get_contents($racine . '/' . $fichier);
            $this->assertNotFalse($source, $fichier . ' cannot be read: the guard would protect nothing.');

            foreach (explode("\n", $source) as $numero => $ligne) {
                foreach (self::LIVE_READS as $lecture) {
                    if (str_contains($ligne, $lecture)) {
                        $lectures[] = $fichier . ':' . ($numero + 1) . ' ' . trim($ligne);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $lectures,
            "An applicator measures a cargo capacity on a live player; the battle froze it and the settlement must read it:\n" . implode("\n", $lectures)
        );
    }

    /**
     * Le moteur partage est le seul endroit ou les capacites gelees s'ecrivent.
     *
     * Un second ecrivain — un reglement qui « corrigerait » une capacite avant de la lire, par
     * exemple — reintroduirait exactement la relecture que la garde precedente interdit.
     */
    public function testOnlyTheSharedEngineWritesAFrozenCapacity(): void
    {
        $racine = dirname(__DIR__, 3);
        $ecrivains = [];

        $champs = [
            '->attackerSurvivingCargoCapacity =',
            '->attackerReaperCargoCapacity =',
            '->defenderReaperCargoCapacity =',
            '->startingCargoCapacity =',
            '->survivingCargoCapacity =',
        ];

        foreach ($this->phpFilesUnder($racine . '/app') as $fichier) {
            $source = file_get_contents($fichier);

            if ($source === false) {
                continue;
            }

            foreach ($champs as $champ) {
                if (str_contains($source, $champ)) {
                    $ecrivains[] = str_replace([$racine . DIRECTORY_SEPARATOR, $racine . '/', DIRECTORY_SEPARATOR], ['', '', '/'], $fichier);
                    break;
                }
            }
        }

        sort($ecrivains);

        $this->assertSame(
            [
                'app/Combat/Replay/BattleResultCodec.php',
                'app/GameMissions/BattleEngine/BattleEngine.php',
            ],
            $ecrivains,
            'A frozen cargo capacity is written somewhere other than the shared engine and the codec that re-reads it.'
        );
    }

    /**
     * @return iterable<int, string>
     */
    private function phpFilesUnder(string $dossier): iterable
    {
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS));

        foreach ($iterateur as $entree) {
            if ($entree instanceof SplFileInfo && $entree->getExtension() === 'php') {
                yield $entree->getPathname();
            }
        }
    }
}
