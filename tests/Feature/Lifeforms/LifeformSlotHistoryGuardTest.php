<?php

namespace Tests\Feature\Lifeforms;

use OGame\Lifeforms\Research\LifeformSlotHistory;
use OGame\Lifeforms\Services\LifeformResearchService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\UnitTestCase;

/**
 * **Personne n ecrit une occupation d emplacement sans sa ligne d historique.**
 *
 * L occupation a un instant se lit dans `lifeform_slot_history`, jamais dans la colonne : c est ce qui
 * empeche une remise a zero faite apres une arrivee de desarmer la flotte qui arrivait (journal §155.10).
 * Un fichier qui ecrirait `lifeform_slots` sans ecrire l historique laisserait donc le passe vide — et
 * **tout ce qui lit le passe repondrait « aucune technologie » sans se plaindre**. Un banc serait vert et
 * ne prouverait rien.
 *
 * C est exactement la faute des lignes de classe et d appartenance, payee une fois par un rouge de CI et
 * un intermittent (§154.11). La garde ferme la classe au lieu de compter sur la vigilance.
 */
final class LifeformSlotHistoryGuardTest extends UnitTestCase
{
    /**
     * Les deux ecrivains legitimes, plus ce fichier-ci qui doit nommer le motif pour le chercher.
     *
     * @var array<int, string>
     */
    private const array ECRIVAINS = [
        'LifeformResearchService.php',
        'LifeformSlotHistory.php',
        'PlacesLifeformSlots.php',
        'LifeformSlotHistoryGuardTest.php',
    ];

    public function testNoOneWritesASlotWithoutItsHistoryLine(): void
    {
        $fautifs = [];

        foreach ([app_path(), base_path('tests'), base_path('database')] as $racine) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine)) as $fichier) {
                $nom = $fichier->getFilename();
                if (!str_ends_with($nom, '.php') || in_array($nom, self::ECRIVAINS, true)) {
                    continue;
                }
                $contenu = file_get_contents($fichier->getPathname());
                if (!is_string($contenu)) {
                    continue;
                }
                // Une ecriture de l occupation : `LifeformSlot::query()->` suivi d un verbe d ecriture,
                // ou une affectation de `object_id` sur un emplacement.
                $ecrit = preg_match('/LifeformSlot::query\(\)[^;]*->(?:create|updateOrCreate|firstOrCreate|insert)\(/', $contenu) === 1
                    || preg_match('/LifeformSlot::query\(\)[^;]*->update\(\s*\[[^\]]*[\'"]object_id[\'"]/', $contenu) === 1;
                if ($ecrit) {
                    $fautifs[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fichier->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "Ces fichiers posent une technologie dans un emplacement sans ecrire son historique. Passer par "
            . LifeformResearchService::class . ' au jeu, ou par le trait PlacesLifeformSlots au banc ; '
            . LifeformSlotHistory::class . " est le seul ecrivain de l historique.\n" . implode("\n", $fautifs)
        );
    }

    /**
     * La garde ne vaut que si son motif reconnait la faute : on lui donne le texte fautif a lire.
     */
    public function testTheGuardRecognisesAWriteWithoutHistory(): void
    {
        $fautif = "LifeformSlot::query()->updateOrCreate(['planet_id' => 1, 'slot' => 2], ['object_id' => 11201]);";
        $this->assertSame(1, preg_match('/LifeformSlot::query\(\)[^;]*->(?:create|updateOrCreate|firstOrCreate|insert)\(/', $fautif));

        $lecture = "LifeformSlot::query()->where('planet_id', 1)->get();";
        $this->assertSame(0, preg_match('/LifeformSlot::query\(\)[^;]*->(?:create|updateOrCreate|firstOrCreate|insert)\(/', $lecture), 'Une lecture n est pas une ecriture.');
    }
}
