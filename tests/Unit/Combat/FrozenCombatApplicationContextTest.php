<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Application\FrozenCombatApplicationContext;
use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
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

    /**
     * **Le schema 5 se relit et se reecrit tel quel** : aucune forme de vie n existait a sa cloture, et la part protegee
     * qu il ne porte pas est nulle — la population d un corps clos sous lui n est pas touchee.
     */
    public function testASchemaFiveDocumentReadsAndRewritesWithoutLifeform(): void
    {
        $document = $this->aSnapshot();
        $document['schema'] = FrozenCombatApplicationContext::SCHEMA_WITHOUT_LIFEFORM;
        unset($document['lifeform']);

        $contexte = FrozenCombatApplicationContext::fromStorage($document);
        $this->assertSame($document, $contexte->toStorage());
        $this->assertNull($contexte->lifeformProtectedShareOf($this->createMock(PlanetService::class)));
    }

    public function testTheProtectedShareIsReadBackAndTheCorpsIsNotReread(): void
    {
        $contexte = FrozenCombatApplicationContext::fromStorage($this->aSnapshot());
        $this->assertSame(0.3, $contexte->lifeformProtectedShareOf($this->createMock(PlanetService::class)));

        $document = $this->aSnapshot();
        $document['lifeform']['protected_share'] = null;
        $this->assertNull(FrozenCombatApplicationContext::fromStorage($document)->lifeformProtectedShareOf($this->createMock(PlanetService::class)), 'Nulle : le corps ne porte aucune forme de vie.');
    }

    public function testALifeformShareOnAnOlderSchemaOrOutOfRangeOrAsAStringIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['schema'] = FrozenCombatApplicationContext::SCHEMA_WITHOUT_LIFEFORM;
        $this->assertRefused($document, 'part protegee');

        $document = $this->aSnapshot();
        $document['lifeform']['protected_share'] = 1.5;
        $this->assertRefused($document, 'lifeform.protected_share');

        $document = $this->aSnapshot();
        $document['lifeform']['protected_share'] = '0.3';
        $this->assertRefused($document, 'lifeform.protected_share');

        $document = $this->aSnapshot();
        unset($document['lifeform']);
        $this->assertRefused($document, 'lifeform');
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

    /**
     * **La classe General se relit flotte par flotte.** Le joueur 3 est General, mais sa flotte 21 est entree
     * sans l etre : c est la flotte qui decide de son champ d epaves, pas la ligne du joueur.
     */
    public function testTheGeneralClassIsReadFleetByFleet(): void
    {
        $contexte = FrozenCombatApplicationContext::fromStorage($this->aSnapshot());

        $this->assertFalse($contexte->isGeneralForFleet(21, $this->aPlayerNumbered(3)), 'The General class of the player decided for a fleet admitted without it.');
        $this->assertTrue($contexte->isGeneralForFleet(22, $this->aPlayerNumbered(4)), 'A fleet admitted as a General lost it to the class of its player.');
    }

    public function testAFleetAbsentFromTheGeneralClassesIsRefused(): void
    {
        try {
            FrozenCombatApplicationContext::fromStorage($this->aSnapshot())->isGeneralForFleet(99, $this->aPlayerNumbered(3));
            $this->fail('The General class of a fleet absent from the photograph was read.');
        } catch (CorruptedFrozenApplicationContext $refus) {
            $this->assertStringContainsString('flotte 99', $refus->defect);
        }
    }

    /**
     * **Le schema 4 se relit tel qu il a ete ecrit** : il a ete ecrit en production, sa classe General est celle
     * de chaque joueur, et il se reecrit au schema 4.
     */
    public function testTheFourthSchemaIsStillReadWithItsGeneralClassByPlayer(): void
    {
        $document = $this->aSnapshot();
        $document['schema'] = 4;
        unset($document['attacker_generals'], $document['lifeform']);

        $contexte = FrozenCombatApplicationContext::fromStorage($document);

        $this->assertTrue($contexte->isGeneralForFleet(21, $this->aPlayerNumbered(3)), 'A fourth schema document no longer answers with the class its combat was closed under.');
        $this->assertFalse($contexte->isGeneralForFleet(21, $this->aPlayerNumbered(4)));
        $this->assertSame($document, $contexte->toStorage(), 'A fourth schema document was rewritten into a schema its closure never wrote.');
    }

    public function testAFourthSchemaCarryingGeneralClassesByFleetIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['schema'] = 4;

        $this->assertRefused($document, 'classe General par flotte');
    }

    public function testAFifthSchemaWithoutGeneralClassesByFleetIsRefused(): void
    {
        $document = $this->aSnapshot();
        unset($document['attacker_generals']);

        $this->assertRefused($document, 'attacker_generals');
    }

    public function testAGeneralClassByFleetThatIsNotABooleanIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['attacker_generals'][21] = 1;

        $this->assertRefused($document, 'attacker_generals[21]');
    }

    public function testAGeneralClassUnderAnInvalidFleetIdentifierIsRefused(): void
    {
        $document = $this->aSnapshot();
        $document['attacker_generals'][0] = true;

        $this->assertRefused($document, 'attacker_generals');
    }

    /**
     * Un joueur fictif, sous un identifiant donne : sans base, la photographie ne lit que son identifiant.
     */
    private function aPlayerNumbered(int $id): PlayerService
    {
        $joueur = resolve(PlayerService::class, ['player_id' => 0]);
        $joueur->getUser()->id = $id;

        return $joueur;
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
            // La duree du retour naturel de chaque attaquante, gelee a la cloture : relue sur le joueur
            // vivant, une propulsion recherchee pendant la bataille changeait l'heure du retour.
            'return_durations' => [21 => 3_600, 22 => 0],
            // La classe General de chaque attaquante, lue sur son propre combattant : deux flottes d un meme
            // joueur peuvent etre entrees sous deux classes.
            'attacker_generals' => [21 => false, 22 => true],
            // La part de population protegee par les formes de vie, photographiee a l ouverture et figee a la cloture.
            'lifeform' => ['protected_share' => 0.3],
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
