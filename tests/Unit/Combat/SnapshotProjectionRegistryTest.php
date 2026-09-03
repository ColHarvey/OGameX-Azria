<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Exceptions\UnknownSnapshotProjection;
use OGame\Combat\Projection\SnapshotProjectionRegistry;
use OGame\Combat\Projection\SnapshotProjectionRule;
use OGame\Combat\Projection\SnapshotProjectionV1;
use PHPUnit\Framework\TestCase;

/**
 * La projection vit sous le meme regime que les quatre autres regles.
 *
 * ## Ce qui a change, et pourquoi
 *
 * La projection etait portee par une constante de classe et une liste de versions connues. Cela
 * refusait bien une version inconnue, mais laissait **un second mecanisme de gel** a cote de
 * l'ensemble : deux facons de choisir une version, deux facons de la relire, deux facons de la
 * faire entrer dans une empreinte.
 *
 * Or une projection modifie la photographie, donc l'idempotence, donc le resultat persistant du
 * combat. Elle merite exactement le meme traitement que l'ordre causal ou l'allocateur de butin.
 *
 * ## Ce que la garde surveille desormais
 *
 * `SnapshotProjection::CURRENT` n'existe plus, et sa garde dediee non plus : la garde des registres
 * couvre `current()` et `currentVersion()` sur les **cinq**, ce qui est la meme protection sans le
 * cas particulier.
 */
class SnapshotProjectionRegistryTest extends TestCase
{
    /**
     * Le registre du jeu connait sa version courante et sait la relire.
     */
    public function testTheGameRegistryKnowsItsCurrentVersion(): void
    {
        $registre = SnapshotProjectionRegistry::default();

        $this->assertSame(SnapshotProjectionV1::VERSION, $registre->currentVersion());
        $this->assertSame(
            SnapshotProjectionV1::VERSION,
            $registre->forVersion(SnapshotProjectionV1::VERSION)->version()
        );
    }

    /**
     * Une version inconnue arrete le rejeu au lieu de se rabattre sur la courante.
     *
     * Le repli marcherait presque toujours — les projections se ressemblent d'une version a
     * l'autre. C'est exactement ce qui le rend dangereux : le jour ou elles different, rien ne le
     * dirait.
     */
    public function testAnUnknownProjectionStopsTheReplay(): void
    {
        $this->expectException(UnknownSnapshotProjection::class);

        SnapshotProjectionRegistry::default()->forVersion('projection_inexistante');
    }

    /**
     * Une version vide n'est pas une version.
     */
    public function testAnEmptyVersionIsUnknown(): void
    {
        $this->expectException(UnknownSnapshotProjection::class);

        SnapshotProjectionRegistry::default()->forVersion('');
    }

    /**
     * Un ancien combat reste lisible quand une v2 devient courante.
     *
     * **C'est la garantie centrale du versionnement.** Un combat ouvert sous v1 doit se relire sous
     * v1, meme des mois plus tard.
     */
    public function testAnOlderProjectionStaysReadableWhenANewerBecomesCurrent(): void
    {
        $registre = SnapshotProjectionRegistry::of(
            [new SnapshotProjectionV1(), $this->aProjectionOn('projection_v2')],
            'projection_v2'
        );

        $this->assertSame('projection_v2', $registre->currentVersion());
        $this->assertSame(
            SnapshotProjectionV1::VERSION,
            $registre->forVersion(SnapshotProjectionV1::VERSION)->version(),
            'A combat opened under v1 became unreadable the day v2 arrived.'
        );
    }

    /**
     * Deux implementations ne peuvent pas se reclamer d'une meme version.
     *
     * Une version qui designe deux lectures ne dit plus rien de ce qui a ete inscrit.
     */
    public function testTwoRulesCannotClaimTheSameVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SnapshotProjectionRegistry::of(
            [$this->aProjectionOn('meme'), $this->aProjectionOn('meme')],
            'meme'
        );
    }

    /**
     * Une version par defaut absente des implementations est refusee.
     *
     * Les nouveaux combats se reclameraient d'une lecture que rien ne sait faire — et le defaut ne
     * se verrait qu'a leur relecture, bien plus tard.
     */
    public function testADefaultVersionThatIsNotImplementedIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SnapshotProjectionRegistry::of([new SnapshotProjectionV1()], 'projection_absente');
    }

    /**
     * Une projection factice, qui ne porte qu'une version.
     */
    private function aProjectionOn(string $version): SnapshotProjectionRule
    {
        return new class ($version) implements SnapshotProjectionRule {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }
        };
    }
}
