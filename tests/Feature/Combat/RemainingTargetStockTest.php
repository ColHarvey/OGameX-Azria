<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\RemainingTargetStock;
use OGame\Combat\Exceptions\CorruptedResourceAmount;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Le restant de la cible : relu sous verrou, converti une fois, avec la tolerance des soldes vivants.
 *
 * ## La seconde moitie de la regle
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Le restant est le seul fait que le reglement relit dans le monde courant. Le defenseur a eu le droit
 * de depenser pendant le combat ; ce qu'il a depense n'est plus la, et c'est voulu.
 *
 * ## Vivant, pas gele
 *
 * Un solde de planete est un `double`, et la production laisse parfois un artefact de moins d'une
 * unite sous zero. La frontiere des soldes vivants le ramene a zero **et le dit** ; un negatif
 * materiel reste un refus. C'est la tolerance qu'un fait gele n'a pas.
 */
class RemainingTargetStockTest extends TestCase
{
    private const int BEYOND_EXACT_FLOAT = 36_028_797_018_963_968;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Le solde est arrondi vers le bas, composante par composante.
     *
     * On ne prend pas une fraction d'unite qui n'existe pas encore.
     */
    public function testTheStockIsFlooredComponentByComponent(): void
    {
        $ligne = $this->aLockedPlanetWith(1_000.9, 500.1, 200.5);

        $restant = RemainingTargetStock::readFrom($ligne);

        $this->assertSame(1_000, $restant->amounts->metal);
        $this->assertSame(500, $restant->amounts->crystal);
        $this->assertSame(200, $restant->amounts->deuterium);
        $this->assertFalse($restant->diagnostics->any());
    }

    /**
     * Un artefact de moins d'une unite sous zero devient zero, et c'est dit.
     *
     * C'est la tolerance propre aux soldes vivants : un arrondi de production peut laisser -0.4.
     * Le refuser bloquerait des reglements pour un defaut qui n'en est pas un ; le taire cacherait
     * qu'il existe.
     */
    public function testASubUnitNegativeArtifactBecomesZeroAndIsReported(): void
    {
        $ligne = $this->aLockedPlanetWith(1_000.0, -0.4, 200.0);

        $restant = RemainingTargetStock::readFrom($ligne);

        $this->assertSame(0, $restant->amounts->crystal);
        $this->assertSame(1_000, $restant->amounts->metal);

        $this->assertTrue($restant->diagnostics->any(), 'A normalised artifact went unreported.');
        $this->assertArrayHasKey(
            ResourceNormalizationDiagnostics::NEGATIVE_ARTIFACT_NORMALIZED,
            $restant->diagnostics->groupedByCode()
        );
    }

    /**
     * Un negatif materiel reste un refus, meme sur un solde vivant.
     */
    public function testAMateriallyNegativeStockIsRefused(): void
    {
        $ligne = $this->aLockedPlanetWith(1_000.0, 500.0, -5.0);

        $this->expectException(CorruptedResourceAmount::class);

        RemainingTargetStock::readFrom($ligne);
    }

    /**
     * Au-dela de deux puissance cinquante-trois, la degradation est dite, et le montant reste entier.
     *
     * ## Ce que cet essai ne peut pas prouver ici, et pourquoi
     *
     * Sa premiere version exigeait de relire **exactement** 2^55. Elle a echoue avec
     * `36028797018964000` : ce n'est pas la frontiere qui a perdu les unites, c'est le pilote. PDO
     * pour SQLite rend une colonne `REAL` sous forme de chaine a quinze chiffres significatifs, et la
     * valeur arrive deja degradee avant d'etre convertie.
     *
     * Sous MariaDB, un `DOUBLE` revient en flottant natif, exact tant que la valeur l'est. La preuve
     * de l'exactitude a cette hauteur appartient donc a l'epreuve MariaDB. Ce que SQLite permet de
     * prouver, c'est le contrat de la frontiere : un entier, et une degradation **dite**.
     */
    public function testAStockBeyondExactFloatPrecisionIsReadAndTheDegradationIsSaid(): void
    {
        $ligne = $this->aLockedPlanetWith((float)self::BEYOND_EXACT_FLOAT, 0.0, 0.0);

        $restant = RemainingTargetStock::readFrom($ligne);

        // Pas d'`assertIsInt` : la propriete est typee `int`, et PHPStan a raison de refuser une
        // assertion que le type garantit deja. Ce qui se prouve ici, c'est la hauteur et l'aveu.
        $this->assertGreaterThanOrEqual(
            9_007_199_254_740_992,
            $restant->amounts->metal,
            'A stock beyond exact float precision was read as something below that threshold.'
        );
        $this->assertArrayHasKey(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            $restant->diagnostics->groupedByCode(),
            'A degraded conversion went unreported: the settlement would treat this amount as exact.'
        );
    }

    /**
     * Une planete relue sous verrou, avec ces soldes.
     *
     * Le verrou est pris ici comme l'orchestrateur le prendra — `Planet::whereKey()->lockForUpdate()`
     * — puis la ligne relue est passee au lecteur. SQLite n'y pose aucun verrou de ligne ; c'est le
     * chemin d'appel qui est eprouve, pas la contention.
     */
    private function aLockedPlanetWith(float $metal, float $crystal, float $deuterium): Planet
    {
        $planete = Planet::factory()->create([
            'user_id' => User::factory()->create()->id,
            'galaxy' => 9,
            'system' => 250,
            'planet' => 5,
        ]);

        // Ecrit brut, pour poser exactement ces flottants — y compris un negatif que le modele
        // n'aurait aucune raison de produire lui-meme.
        DB::table('planets')->where('id', $planete->id)->update([
            'metal' => $metal,
            'crystal' => $crystal,
            'deuterium' => $deuterium,
        ]);

        $ligne = Planet::query()->whereKey($planete->id)->lockForUpdate()->first();

        $this->assertNotNull($ligne);

        return $ligne;
    }
}
