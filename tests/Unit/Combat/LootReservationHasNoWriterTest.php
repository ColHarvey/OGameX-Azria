<?php

namespace Tests\Unit\Combat;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * En premiere version, la reservation de butin n'a **aucun** ecrivain, lecteur ni effet de jeu.
 *
 * ## La decision, et ce qu'elle change pour un joueur
 *
 * Le defenseur **peut depenser ses ressources pendant le combat**. Le reglement se fait a la
 * resolution, composante par composante :
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Cette regle empeche deux abus a la fois : l'attaquant ne prend jamais la production arrivee apres
 * l'ouverture au-dela de son potentiel gele, et le defenseur sauve ce qu'il a eu le temps de
 * depenser legalement.
 *
 * ## Pourquoi une garde, et pas seulement du code retire
 *
 * Le raccordement a existe. Il avait ete ecrit de bonne foi, avec un raisonnement solide — soixante
 * secondes de ralliement suffisent a lancer un transport — et il contredisait une decision deja
 * prise. **Retirer du code ne laisse aucune trace** : rien n'empecherait le meme raisonnement de
 * reconduire au meme branchement.
 *
 * Cette garde est ce qui reste de la decision une fois le code parti.
 *
 * ## Ce qui a le droit de subsister
 *
 * La table, le modele, l'etat et l'objet de domaine : ils decrivent un mecanisme reflechi, et le
 * jeter ferait perdre ce travail. Ils n'ont simplement **aucun appelant** dans le chemin de jeu.
 * Un mecanisme semi-actif serait pire que les deux options franches.
 */
class LootReservationHasNoWriterTest extends TestCase
{
    /**
     * Les seuls fichiers autorises a nommer la reservation.
     *
     * Le modele et l'objet de domaine se decrivent eux-memes ; personne d'autre ne les appelle.
     *
     * @var array<int, string>
     */
    private const array ALLOWED = [
        'Combat/Enums/LootReservationState.php',
        'Combat/Exceptions/LootReservationRefused.php',
        'Combat/Support/LootReservation.php',
        'Models/CombatLootReservation.php',
    ];

    /**
     * Aucun service ne cree, ne lit ni ne modifie une reservation.
     */
    public function testNothingInTheGamePathTouchesAReservation(): void
    {
        $racine = dirname(__DIR__, 3) . '/app';
        $touchent = [];

        foreach ($this->phpFilesOf($racine) as $fichier) {
            $source = file_get_contents($fichier);

            if ($source === false) {
                continue;
            }

            $nomme = str_contains($source, 'CombatLootReservation')
                || str_contains($source, 'LootReservationState')
                || str_contains($source, 'LootReservation::');

            if (!$nomme) {
                continue;
            }

            $touchent[] = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($fichier, strlen($racine) + 1)
            );
        }

        sort($touchent);

        $this->assertSame(
            self::ALLOWED,
            $touchent,
            'Something in the game path touches the loot reservation. In v1 it has no writer, no '
            . 'reader and no effect: the defender may spend during the combat, and the settlement is '
            . 'min(potential loot, remaining resources) at resolution time.'
        );
    }

    /**
     * Les fichiers PHP d'un dossier, recursivement.
     *
     * @return array<int, string>
     */
    private function phpFilesOf(string $directory): array
    {
        $fichiers = [];

        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterateur as $entree) {
            if ($entree instanceof SplFileInfo && $entree->getExtension() === 'php') {
                $fichiers[] = $entree->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }
}
