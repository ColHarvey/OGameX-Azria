<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Services\RefusedFleetHomecoming;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameMissions\AttackMission;
use ReflectionClass;
use Tests\UnitTestCase;

/**
 * Chaque genre de mission qui recoit un mouvement a l'ecrivain et le consommateur de ce mouvement.
 *
 * ## Pourquoi une table, et non une garde globale
 *
 * La garde precedente cherchait **quelque part** un `record()` et **quelque part** un `consume()`.
 * Elle etait donc satisfaite par un seul genre de mission — et c'est exactement ce qui a masque le
 * defaut : la fermeture ecrivait des dispositions pour les vagues attaquantes comme pour les
 * Defenses ACS, mais seule la Defense ACS les consommait. Les verdicts attaquants restaient « en
 * attente » pour toujours, et une occurrence dans un autre genre suffisait a rendre l'essai vert.
 *
 * La table nomme donc, genre par genre, qui ecrit et qui execute. Un genre ajoute sans consommateur
 * n'a plus d'endroit ou se cacher.
 *
 * ## Ce que « consommer » veut dire ici
 *
 * Le protocole d'execution est unique (`RefusedFleetHomecoming`) : la destination sous verrou,
 * l'avis derive de la raison persistee, l'aller marque, un seul retour, `consumed_at` pose — le tout
 * dans une transaction. Un genre de mission « consomme » en passant par lui, jamais en refaisant le
 * protocole de son cote.
 */
class FleetDispositionHasWriterTest extends UnitTestCase
{
    /**
     * Aucun mouvement declare ne reste sans ecrivain ni sans consommateur.
     *
     * @return void
     */
    public function testEveryDeclaredMovementHasAWriterAndAConsumer(): void
    {
        // genre de mission -> [ce qui ecrit la decision, ce qui l'execute]
        $table = [
            FleetDispositionKind::ReturnToOrigin->name => [
                'la vague attaquante' => [RallyClosureService::class, AttackMission::class],
                'la Defense ACS' => [RallyClosureService::class, AcsDefendMission::class],
            ],
        ];

        $sansTable = [];

        foreach (FleetDispositionKind::cases() as $mouvement) {
            if (!isset($table[$mouvement->name])) {
                $sansTable[] = $mouvement->name;
            }
        }

        $this->assertSame(
            [],
            $sansTable,
            'A fleet movement is declared but this table names neither its writer nor its consumer: '
            . implode(', ', $sansTable) . '. Add the row with both, or remove the case until they exist.'
        );

        foreach ($table as $mouvement => $genres) {
            foreach ($genres as $genre => [$ecrivain, $consommateur]) {
                $this->assertStringContainsString(
                    'FleetDispositionKind::' . $mouvement,
                    $this->sourceOf($ecrivain),
                    "Nothing writes the « {$mouvement} » movement for {$genre}."
                );

                $this->assertStringContainsString(
                    'RefusedFleetHomecoming::class)->sendHome(',
                    $this->sourceOf($consommateur),
                    "{$genre} does not carry out the « {$mouvement} » movement through the common protocol."
                );
            }
        }
    }

    /**
     * Le protocole d'execution est unique, et c'est lui qui consomme.
     *
     * Un genre de mission qui appellerait `consume()` de son cote refabriquerait le protocole —
     * destination, avis, marquage, retour — et les deux copies pourraient diverger. Le seul
     * consommateur du registre est donc le chemin commun.
     */
    public function testOnlyTheCommonProtocolConsumesADisposition(): void
    {
        foreach ([AttackMission::class, AcsDefendMission::class] as $genre) {
            $this->assertStringNotContainsString(
                '->consume(',
                $this->sourceOf($genre),
                'A mission kind consumes a disposition itself instead of going through the common protocol.'
            );
        }

        $this->assertStringContainsString(
            '->consume(',
            $this->sourceOf(RefusedFleetHomecoming::class),
            'The common protocol no longer consumes the disposition it carries out.'
        );
    }

    /**
     * @param class-string $classe
     */
    private function sourceOf(string $classe): string
    {
        $fichier = (new ReflectionClass($classe))->getFileName();
        $this->assertNotFalse($fichier);

        $source = file_get_contents($fichier);
        $this->assertIsString($source);

        return preg_replace('/\s+/', ' ', $source) ?? '';
    }
}
