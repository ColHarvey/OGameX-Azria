<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\UnknownSnapshotProjection;
use OGame\Combat\Support\SnapshotProjection;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * La projection se choisit a l'ouverture, et nulle part ailleurs.
 *
 * ## Ce que cette garde protege
 *
 * L'unicite d'une inclusion porte sur combat / evenement / **version de projection**. Un chemin de
 * fermeture qui lirait la version courante deux heures apres l'ouverture ferait donc entrer le meme
 * evenement une seconde fois dans le meme combat — et l'unicite ne verrait rien, puisque c'est
 * precisement ce qu'elle separe. La garnison serait comptee deux fois.
 *
 * La garde des quatre registres surveille `current()` et `currentVersion()`, des appels de methode.
 * Celle-ci surveille une **constante**, que cette garde-la ne pouvait pas voir.
 */
class SnapshotProjectionTest extends TestCase
{
    /**
     * La version courante est lue par l'ouverture, et par personne d'autre.
     */
    public function testTheCurrentProjectionIsOnlyReadWhereTheOpeningIsFixed(): void
    {
        // **Un seul fichier, et c'est le service qui cree l'ouverture durable.** La classe qui porte
        // la constante n'y figure pas : elle se cite par `self::`, jamais par son propre nom.
        $autorises = [
            'Combat/Services/CombatOpeningService.php',
        ];

        $racine = dirname(__DIR__, 3) . '/app';
        $lecteurs = [];

        foreach ($this->phpFilesOf($racine) as $fichier) {
            $source = file_get_contents($fichier);

            if ($source === false || !str_contains($source, 'SnapshotProjection::CURRENT')) {
                continue;
            }

            $lecteurs[] = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($fichier, strlen($racine) + 1)
            );
        }

        sort($lecteurs);

        $this->assertSame(
            $autorises,
            $lecteurs,
            'A path outside the durable opening reads the current projection. Its inclusions would '
            . 'carry a version the combat never began with, and the same event could enter the '
            . 'snapshot twice.'
        );
    }

    /**
     * La version persistee est rendue telle quelle.
     */
    public function testAKnownProjectionIsReturnedUnchanged(): void
    {
        $this->assertSame(
            SnapshotProjection::CURRENT,
            SnapshotProjection::ensureKnown(SnapshotProjection::CURRENT)
        );
    }

    /**
     * Une version inconnue s'arrete.
     *
     * Le repli sur la version courante marcherait presque toujours — les projections se ressemblent
     * d'une version a l'autre. C'est ce qui le rend dangereux : le jour ou elles different, rien ne
     * le dirait.
     */
    public function testAnUnknownProjectionStops(): void
    {
        $this->expectException(UnknownSnapshotProjection::class);

        SnapshotProjection::ensureKnown('v-inconnue');
    }

    /**
     * Une version vide n'est pas une version.
     */
    public function testAnEmptyProjectionStops(): void
    {
        $this->expectException(UnknownSnapshotProjection::class);

        SnapshotProjection::ensureKnown('');
    }

    /**
     * Les fichiers PHP d'un dossier, recursivement.
     *
     * @return array<int, string>
     */
    private function phpFilesOf(string $directory): array
    {
        $fichiers = [];

        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterateur as $entree) {
            if ($entree instanceof SplFileInfo && $entree->getExtension() === 'php') {
                $fichiers[] = $entree->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }
}
