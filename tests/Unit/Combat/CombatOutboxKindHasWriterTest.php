<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\CombatOutboxKind;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\UnitTestCase;

/**
 * Chaque genre de la boite d'envoi a un ecrivain, et la boite ne double aucun canal existant.
 *
 * ## Le defaut que cet essai ferme
 *
 * L'enumeration portait quatre genres et **un seul** etait ecrit. Les trois autres — rapport de
 * bataille, destruction de lune, butin rendu — decrivaient des chemins qui n'existaient pas. Un
 * genre sans ecrivain n'est pas une place reservee : c'est un second canal apparent pour un
 * message qui passe deja ailleurs. Le rapport de bataille est ecrit dans la **meme base** et dans
 * la **meme transaction** que le debit ; l'annoncer aussi par la boite le ferait partir deux fois,
 * ou une fois de trop apres un rollback.
 *
 * ## Ce qui est verifie
 *
 * Que chaque cas de l'enumeration est effectivement produit quelque part dans `app/`, ailleurs que
 * dans sa propre declaration. Le jour ou la destruction de lune durable aura son ecrivain, son
 * genre revient — avec lui, pas avant.
 */
class CombatOutboxKindHasWriterTest extends UnitTestCase
{
    /**
     * Aucun genre ne reste sans ecrivain.
     */
    public function testEveryKindIsActuallyWrittenSomewhere(): void
    {
        $sansEcrivain = [];

        foreach (CombatOutboxKind::cases() as $genre) {
            $ecrivains = $this->filesMentioning('CombatOutboxKind::' . $genre->name);

            if ($ecrivains === []) {
                $sansEcrivain[] = $genre->name;
            }
        }

        $this->assertSame(
            [],
            $sansEcrivain,
            'A kind of outbox message is declared but never written: ' . implode(', ', $sansEcrivain)
            . '. Either write it, or remove the case until its writer exists — an unwritten kind '
            . 'suggests a second channel for a message that already travels another way.'
        );
    }

    /**
     * Le rapport de bataille ne passe pas par la boite : il est ecrit dans la transaction.
     *
     * `MessageService::sendBattleReportMessageToPlayer()` ecrit dans la meme base, appelee par la
     * resolution : invisible avant le commit, effacee avec le debit si la transaction est annulee.
     * Un depot differe serait plus faible, pas plus sur.
     */
    public function testTheBattleReportNeverTravelsThroughTheOutbox(): void
    {
        $genres = array_map(static fn (CombatOutboxKind $genre): string => $genre->value, CombatOutboxKind::cases());

        $this->assertNotContains('battle_report', $genres, 'The battle report was given an outbox kind: it is already written atomically with the debit.');
        $this->assertNotContains('loot_released', $genres, 'Released loot has no meaning in the first version: nothing is reserved.');
    }

    /**
     * Les fichiers de production qui mentionnent ce motif, sa propre declaration exclue.
     *
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

            if ($fichier->getFilename() === 'CombatOutboxKind.php') {
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
