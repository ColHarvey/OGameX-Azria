<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Application\FrozenCombatApplicationContext;
use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use Tests\UnitTestCase;

/**
 * La photographie des faits d'application se relit telle qu'elle a ete ecrite, ou pas du tout.
 *
 * Ces faits decident de ce que l'application ecrit : un champ d'epaves apparait ou non, sa taille
 * change, le rapport nomme une classe. Les relire autrement qu'ecrits — un niveau devenu chaine,
 * un drapeau devenu entier — rendrait un rejeu different de l'original, et le ferait en silence.
 */
class FrozenCombatApplicationContextTest extends UnitTestCase
{
    /**
     * Un document juste traverse et revient identique.
     */
    public function testAWellFormedSnapshotComesBackIdentical(): void
    {
        $document = $this->aSnapshot();

        $this->assertSame($document, FrozenCombatApplicationContext::fromStorage($document)->toStorage());
    }

    /**
     * Un niveau de chantier spatial donne en chaine numerique est refuse.
     */
    public function testASpaceDockLevelGivenAsANumericStringIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['space_docks'][7] = '4';

        $this->assertRefused($document, 'space_docks[7]');
    }

    /**
     * Un drapeau de classe qui n'est pas un booleen est refuse.
     */
    public function testAGeneralFlagThatIsNotABooleanIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['players'][3]['is_general'] = 1;

        $this->assertRefused($document, 'is_general');
    }

    /**
     * Une part de Faucheur donnee en chaine est refusee.
     */
    public function testAReaperShareGivenAsAStringIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['players'][3]['reaper_debris_percentage'] = '0.30';

        $this->assertRefused($document, 'reaper_debris_percentage');
    }

    /**
     * Une part ecrite en entier est acceptee : `0` revient entier du decodeur JSON, pas `0.0`.
     */
    public function testAReaperShareWrittenAsAnIntegerIsAccepted(): void
    {
        $document = $this->aSnapshot();
        $document['players'][3]['reaper_debris_percentage'] = 0;

        $this->assertSame(0.0, FrozenCombatApplicationContext::fromStorage($document)->toStorage()['players'][3]['reaper_debris_percentage']);
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['engine'] = 'rust';

        $this->assertRefused($document, 'engine');
    }

    public function testAMissingWreckFieldThresholdIsRefused(): void
    {
        $document = $this->aSnapshot();
        unset($document['wreck_field']['min_fleet_percentage']);

        $this->assertRefused($document, 'min_fleet_percentage');
    }

    public function testAnUnknownSchemaIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['schema'] = 9;

        $this->assertRefused($document, 'schema 9');
    }

    public function testADocumentThatIsNotAStructureIsRefused(): void
    {
        $this->assertRefused('{"schema":1}', 'structure');
    }

    private function assertRefused(mixed $document, string $attendu): void
    {
        try {
            FrozenCombatApplicationContext::fromStorage($document);
            $this->fail('A corrupted application snapshot was read (expected a refusal naming « ' . $attendu . ' »).');
        } catch (CorruptedFrozenApplicationContext $refus) {
            $this->assertStringContainsString($attendu, $refus->defect, 'The refusal does not name what is wrong.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function aSnapshot(): array
    {
        return [
            'schema' => FrozenCombatApplicationContext::SCHEMA,
            'players' => [
                3 => ['is_general' => true, 'reaper_debris_percentage' => 0.3, 'character_class' => 2],
                4 => ['is_general' => false, 'reaper_debris_percentage' => 0.3, 'character_class' => null],
            ],
            'space_docks' => [7 => 4, 9 => 1],
            'wreck_field' => ['min_resources_loss' => 150_000, 'min_fleet_percentage' => 5],
        ];
    }
}
