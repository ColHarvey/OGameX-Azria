<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\FleetDispositionKind;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\UnitTestCase;

/**
 * Chaque mouvement declare est un mouvement que quelqu'un ecrit, et que quelqu'un execute.
 *
 * ## Ce qu'un genre orphelin ferait croire
 *
 * Une disposition dit ce qu'une flotte **doit faire**. Un genre que personne n'ecrit decrit un
 * chemin qui n'existe pas ; un genre que personne ne consomme decrit une decision qui ne se
 * produirait jamais. Dans les deux cas, la lecture du code laisse croire a une mecanique absente —
 * exactement ce qui est arrive a la boite d'envoi, ou trois genres sur quatre n'avaient aucun
 * ecrivain.
 *
 * Le jour ou un second mouvement existera, son cas revient avec son ecrivain et son consommateur,
 * pas avant.
 */
class FleetDispositionHasWriterTest extends UnitTestCase
{
    /**
     * Aucun mouvement ne reste sans ecrivain.
     */
    public function testEveryMovementIsActuallyWrittenSomewhere(): void
    {
        $sansEcrivain = [];

        foreach (FleetDispositionKind::cases() as $mouvement) {
            if ($this->filesMentioning('FleetDispositionKind::' . $mouvement->name) === []) {
                $sansEcrivain[] = $mouvement->name;
            }
        }

        $this->assertSame(
            [],
            $sansEcrivain,
            'A fleet movement is declared but never written: ' . implode(', ', $sansEcrivain)
            . '. Either write it, or remove the case until its writer exists — a movement nobody '
            . 'decides describes a path that does not exist.'
        );
    }

    /**
     * La decision s'ecrit a la fermeture, et se consomme au traitement de la mission.
     *
     * Les deux bouts comptent : une disposition ecrite que personne ne consomme laisserait une
     * flotte immobile en croyant lui avoir donne un ordre.
     */
    public function testTheRegistryIsBothWrittenAndConsumed(): void
    {
        $this->assertNotSame(
            [],
            $this->filesMentioning('->record('),
            'Nothing writes a fleet disposition: the closure would refuse fleets without saying what they must do.'
        );

        $this->assertNotSame(
            [],
            $this->filesMentioning('->consume('),
            'Nothing consumes a fleet disposition: a decided movement would never happen.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function filesMentioning(string $motif): array
    {
        $trouves = [];
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($iterateur as $fichier) {
            if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }

            // La declaration elle-meme et le registre qui la porte ne comptent pas comme usages.
            if (in_array($fichier->getFilename(), ['FleetDispositionKind.php', 'FleetDispositionRegistry.php'], true)) {
                continue;
            }

            $contenu = file_get_contents($fichier->getPathname());

            if (is_string($contenu) && str_contains($contenu, $motif)) {
                $trouves[] = $fichier->getFilename();
            }
        }

        return $trouves;
    }
}
