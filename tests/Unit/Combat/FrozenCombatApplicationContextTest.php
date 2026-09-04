<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Application\FrozenCombatApplicationContext;
use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use Tests\UnitTestCase;

/**
 * La photographie des faits d'application se relit telle qu'elle a ete ecrite, ou pas du tout.
 *
 * Ces faits decident de ce que l'application ecrit : un champ d'epaves apparait ou non, sa taille
 * change, sa date de fin, le rapport nomme une classe et raconte un raid. Les relire autrement
 * qu'ecrits — un niveau devenu chaine, un drapeau devenu entier, une classe que le jeu ne connait
 * pas, une part hors de sa plage — rendrait un rejeu different de l'original, et le ferait en
 * silence.
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

    public function testTheApplicationInstantIsReadBack(): void
    {
        $this->assertSame(1_700_003_600, FrozenCombatApplicationContext::fromStorage($this->aSnapshot())->applicationInstant());
    }

    public function testTheWreckFieldFactsAreReadBack(): void
    {
        $contexte = FrozenCombatApplicationContext::fromStorage($this->aSnapshot());

        $this->assertSame(30, $contexte->debrisFieldFromShips());
        $this->assertSame(72, $contexte->wreckFieldLifetimeHours());
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

    public function testASpaceDockLevelBelowOneIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['space_docks'][7] = 0;

        $this->assertRefused($document, 'space_docks[7]');
    }

    public function testABodyWithoutAPositiveIdentifierIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['space_docks'][0] = 3;

        $this->assertRefused($document, 'space_docks');
    }

    public function testAPlayerWithoutAPositiveIdentifierIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['players'][-4] = $document['players'][4];

        $this->assertRefused($document, 'players');
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
     * Une classe que le jeu ne connait pas est refusee, au lieu de devenir « aucune » en silence.
     */
    public function testACharacterClassTheGameDoesNotKnowIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['players'][3]['character_class'] = 99;

        $this->assertRefused($document, 'character_class');
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

    public function testAReaperShareOutsideZeroToOneIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['players'][3]['reaper_debris_percentage'] = 1.5;

        $this->assertRefused($document, 'reaper_debris_percentage');
    }

    public function testAReaperShareThatIsNotFiniteIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['players'][3]['reaper_debris_percentage'] = NAN;

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

    public function testANegativeThresholdIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['wreck_field']['min_resources_loss'] = -1;

        $this->assertRefused($document, 'min_resources_loss');
    }

    public function testADebrisShareAboveOneHundredPercentIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['wreck_field']['debris_field_from_ships'] = 101;

        $this->assertRefused($document, 'debris_field_from_ships');
    }

    public function testALifetimeBelowOneHourIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['wreck_field']['lifetime_hours'] = 0;

        $this->assertRefused($document, 'lifetime_hours');
    }

    public function testAnApplicationInstantBeforeTheEpochIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['applied_at'] = 0;

        $this->assertRefused($document, 'applied_at');
    }

    public function testAnUnknownSchemaIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['schema'] = 9;

        $this->assertRefused($document, 'schema 9');
    }

    /**
     * Le schema 1 ne se convertit pas : il se refuse. Aucun document n'en a ete ecrit hors des essais.
     */
    public function testTheFirstSchemaIsRefusedRatherThanConverted(): void
    {
        $document = $this->aSnapshot();
        $document['schema'] = 1;

        $this->assertRefused($document, 'schema 1');
    }

    /**
     * Le recit se relit tel qu'il a ete tire : le motif, et la variante.
     */
    public function testTheNarrativeIsReadBackAsItWasDrawn(): void
    {
        $contexte = FrozenCombatApplicationContext::fromStorage($this->aSnapshot());

        $this->assertSame(3, $contexte->npcNarrativeVariation(5), 'The frozen variation was redrawn instead of read back.');
        $this->assertSame(3, $contexte->npcNarrativeVariation(99), 'A deployment that adds variations changed the story of a battle already fought.');
    }

    public function testANarrativeVariationGivenAsAStringIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['npc_narrative']['variation'] = '3';

        $this->assertRefused($document, 'variation');
    }

    public function testANarrativeVariationOutsideItsRangeIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['npc_narrative']['variation'] = 6;

        $this->assertRefused($document, 'variation');

        $document['npc_narrative']['variation'] = 0;

        $this->assertRefused($document, 'variation');
    }

    public function testAVariationWithoutItsRangeIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['npc_narrative']['variations'] = null;

        $this->assertRefused($document, 'plage');
    }

    public function testAMotiveWithoutAVariationIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['npc_narrative']['variation'] = null;
        $document['npc_narrative']['variations'] = null;

        $this->assertRefused($document, 'motif');
    }

    /**
     * Un combat entre joueurs n'a pas de recit : une absence explicite, et rien a raconter.
     */
    public function testAPlayerCombatHasNoNarrativeAndRefusesToInventOne(): void
    {
        $document = $this->aSnapshot();
        $document['npc_narrative'] = ['motive' => null, 'variation' => null, 'variations' => null];

        $contexte = FrozenCombatApplicationContext::fromStorage($document);
        $this->assertSame($document, $contexte->toStorage());

        try {
            $contexte->npcNarrativeVariation(5);
            $this->fail('A variation was invented for a combat that had none.');
        } catch (CorruptedFrozenApplicationContext $refus) {
            $this->assertStringContainsString('raid', $refus->defect);
        }
    }

    public function testADocumentThatIsNotAStructureIsRefused(): void
    {
        $this->assertRefused('{"schema":2}', 'structure');
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
     * La cargaison d'un renfort se relit telle qu'elle a ete photographiee.
     *
     * ## Ce que l'application relisait vivant
     *
     * A la fin d'une bataille, la cargaison d'un renfort survivant est reduite en proportion de sa
     * capacite restante. L'application lisait pour cela les colonnes de la mission **au moment ou
     * elle ecrivait** : des heures apres le calcul, ce n'etait plus la valeur sur laquelle la
     * bataille avait ete faite, et deux rejeux du meme combat ne rendaient pas la meme cargaison.
     */
    public function testAHeldFleetCargoIsReadAsItWasPhotographed(): void
    {
        $contexte = FrozenCombatApplicationContext::fromStorage($this->aSnapshot());

        $portee = $contexte->heldFleetCargo(11);

        $this->assertSame(1_000.0, $portee->metal->get());
        $this->assertSame(500.0, $portee->crystal->get());
        $this->assertSame(250.0, $portee->deuterium->get());
    }

    /**
     * Une flotte absente de la photographie est un refus, pas un repli sur le monde vivant.
     *
     * C'est la regle de toute cette photographie : un fait demande pour quelqu'un qu'elle ne porte
     * pas ne se devine pas. Retomber sur la ligne serait exactement le defaut qu'elle ferme.
     */
    public function testAFleetAbsentFromThePhotographIsRefused(): void
    {
        $contexte = FrozenCombatApplicationContext::fromStorage($this->aSnapshot());

        $this->expectException(CorruptedFrozenApplicationContext::class);
        $this->expectExceptionMessage('ne porte pas la cargaison de la flotte 12');

        $contexte->heldFleetCargo(12);
    }

    /**
     * Une cargaison negative est refusee a la relecture.
     */
    public function testANegativeHeldCargoIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['held_fleet_cargo'][11]['crystal'] = -1;

        $this->assertRefused($document, 'held_fleet_cargo[11].crystal');
    }

    /**
     * Une cargaison dont un champ manque est refusee.
     */
    public function testAHeldCargoMissingAFieldIsRefused(): void
    {
        $document = $this->aSnapshot();
        unset($document['held_fleet_cargo'][11]['deuterium']);

        $this->assertRefused($document, 'held_fleet_cargo[11].deuterium');
    }

    /**
     * Une flotte dont l'identifiant n'est pas un entier positif est refusee.
     */
    public function testAHeldCargoUnderAnInvalidFleetIdentifierIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['held_fleet_cargo'][0] = ['metal' => 1, 'crystal' => 1, 'deuterium' => 1];

        $this->assertRefused($document, 'held_fleet_cargo');
    }

    /**
     * @return array<string, mixed>
     */
    private function aSnapshot(): array
    {
        return [
            'schema' => FrozenCombatApplicationContext::SCHEMA,
            'applied_at' => 1_700_003_600,
            'players' => [
                3 => ['is_general' => true, 'reaper_debris_percentage' => 0.3, 'character_class' => 2],
                4 => ['is_general' => false, 'reaper_debris_percentage' => 0.3, 'character_class' => null],
            ],
            'space_docks' => [7 => 4, 9 => 1],
            // La cargaison des renforts retenus, gelee a la cloture : l'application la relisait
            // vivante, et deux rejeux du meme combat ne rendaient pas la meme.
            'held_fleet_cargo' => [
                11 => ['metal' => 1_000, 'crystal' => 500, 'deuterium' => 250],
            ],
            'wreck_field' => [
                'min_resources_loss' => 150_000,
                'min_fleet_percentage' => 5,
                'debris_field_from_ships' => 30,
                'lifetime_hours' => 72,
            ],
            'npc_narrative' => ['motive' => 'retaliation', 'variation' => 3, 'variations' => 5],
        ];
    }
}
