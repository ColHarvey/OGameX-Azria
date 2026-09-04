<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use OGame\Combat\Support\CombatParticipantKey;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\UnitTestCase;

/**
 * Le schema obtenu est-il bien celui qu'on croit avoir ecrit ?
 *
 * Ces verifications existent pour une raison precise : la migration des participants a ete
 * **entierement reecrite apres avoir ete corrompue** par une substitution automatique ratee.
 * Une suite verte prouve que le jeu fonctionne ; elle ne prouve pas qu'une colonne n'a pas
 * disparu au passage, ni qu'un index a survecu.
 *
 * On lit donc le schema reellement produit, colonne par colonne, plutot que de faire confiance
 * a la relecture d'un fichier.
 */
class CombatSchemaShapeTest extends UnitTestCase
{
    /**
     * Assert that the combat table holds every column the design requires.
     */
    public function testTheCombatTableHoldsEveryColumnTheDesignRequires(): void
    {
        $attendues = [
            'id', 'status', 'cancellation_cause', 'mission_id', 'union_id',
            'target_planet_id', 'target_type', 'galaxy', 'system', 'position',
            'started_at', 'ends_at', 'duration_seconds',
            'duration_rate', 'duration_damping', 'duration_minimum_seconds', 'duration_implausible',
            'round_schedule', 'battle_snapshot', 'battle_result', 'battle_report_id',
            'presentation_version',
            'created_at', 'updated_at',
        ];

        foreach ($attendues as $colonne) {
            $this->assertTrue(
                Schema::hasColumn('combat_instances', $colonne),
                "The column combat_instances.{$colonne} is missing: part of the design was lost."
            );
        }
    }

    /**
     * Assert that the participant table holds every column the design requires.
     */
    public function testTheParticipantTableHoldsEveryColumnTheDesignRequires(): void
    {
        $attendues = [
            'id', 'combat_instance_id', 'player_id', 'fleet_mission_id',
            'participant_key', 'side', 'participant_type', 'units_snapshot',
            'created_at', 'updated_at',
        ];

        foreach ($attendues as $colonne) {
            $this->assertTrue(
                Schema::hasColumn('combat_participants', $colonne),
                "The column combat_participants.{$colonne} is missing: part of the design was lost."
            );
        }
    }

    /**
     * Assert that participant_key is not nullable.
     *
     * C'est toute la raison de son existence : une colonne nullable ramenerait le trou qu'elle
     * a ete creee pour boucher, puisque plusieurs valeurs nulles sont permises dans un index
     * unique.
     */
    public function testTheParticipantKeyIsNotNullable(): void
    {
        $colonnes = Schema::getColumns('combat_participants');
        $cle = null;

        foreach ($colonnes as $colonne) {
            if ($colonne['name'] === 'participant_key') {
                $cle = $colonne;
                break;
            }
        }

        $this->assertNotNull($cle, 'The participant_key column is gone.');
        $this->assertFalse($cle['nullable'], 'participant_key is nullable again, which reopens the duplicate-garrison hole it exists to close.');
    }

    /**
     * Assert that the unique constraint and the foreign key survived the rewrite.
     */
    public function testTheUniqueConstraintAndForeignKeySurvivedTheRewrite(): void
    {
        $indexes = Schema::getIndexes('combat_participants');
        $unique = null;

        foreach ($indexes as $index) {
            if ($index['columns'] === ['combat_instance_id', 'participant_key']) {
                $unique = $index;
                break;
            }
        }

        $this->assertNotNull($unique, 'There is no index on (combat_instance_id, participant_key).');
        $this->assertTrue($unique['unique'], 'The index on (combat_instance_id, participant_key) exists but is not unique, so a participant can be registered twice.');

        $etrangeres = Schema::getForeignKeys('combat_participants');
        $versCombat = null;

        foreach ($etrangeres as $cle) {
            if ($cle['columns'] === ['combat_instance_id']) {
                $versCombat = $cle;
                break;
            }
        }

        $this->assertNotNull($versCombat, 'combat_participants no longer points at combat_instances: orphan participants become possible.');
        $this->assertSame('combat_instances', $versCombat['foreign_table'], 'The foreign key points at the wrong table.');

        // RESTRICT, ni cascade ni rien : supprimer un combat ne doit pas effacer silencieusement
        // qui y etait, mais un participant ne doit jamais exister sans combat.
        $this->assertNotSame('cascade', strtolower((string)$versCombat['on_delete']), 'The foreign key deletes participants in cascade, so a purge would silently erase who took part.');
    }

    /**
     * Assert that a participant key can only be built by its factory.
     *
     * `fleet:123`, `fleet:0123` et `Fleet:123` designent la meme flotte pour un lecteur et font
     * trois lignes differentes pour une contrainte d'unicite. Une cle ecrite a la main quelque
     * part dans le code rouvrirait donc le doublon, sans qu'aucun test de comportement ne le
     * voie.
     */
    public function testAParticipantKeyCanOnlyBeBuiltByItsFactory(): void
    {
        $fautifs = [];

        // Deux fichiers ecrivent legitimement ces chaines : la fabrique elle-meme, et ce
        // fichier-ci, qui doit bien nommer la forme attendue pour la verifier.
        $exemptes = ['CombatParticipantKey.php', 'CombatSchemaShapeTest.php'];

        foreach ([app_path(), base_path('tests')] as $racine) {
            $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));

            foreach ($iterateur as $fichier) {
                if (!str_ends_with($fichier->getFilename(), '.php') || in_array($fichier->getFilename(), $exemptes, true)) {
                    continue;
                }

                $contenu = file_get_contents($fichier->getPathname());

                if (!is_string($contenu)) {
                    continue;
                }

                // Une cle ecrite en clair : « 'fleet:… » ou « 'planet:… » dans une chaine.
                if (preg_match('/[\'"](?:fleet|planet):/i', $contenu)) {
                    $fautifs[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fichier->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "A participant key is written by hand instead of coming from CombatParticipantKey. Two spellings of the same source would defeat the uniqueness constraint:\n  - "
            . implode("\n  - ", $fautifs)
        );
    }

    /**
     * Assert that the factory normalises and refuses meaningless identifiers.
     */
    public function testTheFactoryNormalisesAndRefusesMeaninglessIdentifiers(): void
    {
        $this->assertSame('fleet:123', CombatParticipantKey::forFleet(123));
        $this->assertSame('planet:567', CombatParticipantKey::forPlanet(567));

        // Le meme identifiant donne toujours la meme chaine, quelle que soit sa forme d'entree.
        $this->assertSame(CombatParticipantKey::forFleet(123), CombatParticipantKey::forFleet((int)'0123'));

        foreach ([0, -1] as $absurde) {
            try {
                CombatParticipantKey::forFleet($absurde);
                $this->fail("The factory accepted the identifier {$absurde}, which designates nothing: every faulty call would share one key and look like a duplicate.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
