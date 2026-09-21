<?php

namespace Tests\Feature;

use Tests\UnitTestCase;

/**
 * **Aucune traduction ne montre un echappement au lieu d un caractere.**
 *
 * Mesure du 20 septembre 2026 : dix-sept valeurs portaient `\u{2019}`, `\u{201C}` ou `\u{201D}` **en
 * clair** — un joueur anglophone lisait « today\u{2019}s reward ». La cause est simple et se reproduit
 * facilement : `\u{2019}` n est une sequence d echappement que dans une chaine a **guillemets doubles** ;
 * dans une chaine a guillemets simples, PHP la laisse telle quelle. Les fichiers de langue emploient les
 * guillemets simples partout, pour une autre bonne raison — l apostrophe typographique.
 *
 * Aucun essai ne voyait cela : la clef existait, la traduction existait, et elle etait meme differente de
 * l anglais. **Une des dix-sept etait deja partie dans un commit pousse.**
 *
 * Ce temoin balaie donc les valeurs elles-memes, dans toutes les langues, et refuse toute sequence
 * d echappement laissee litterale.
 */
class TranslationEscapesTest extends UnitTestCase
{
    /**
     * Les formes qui trahissent une chaine a guillemets simples ou l auteur croyait ecrire un caractere.
     *
     * @var list<string>
     */
    private const array SUSPECTES = ['\u{', '\x{', '\U+'];

    public function testNoTranslationShowsARawEscapeSequence(): void
    {
        $fichiers = glob(lang_path('*/*.php')) ?: [];
        $this->assertGreaterThan(20, count($fichiers), 'Premisse : les fichiers de langue sont bien trouves.');

        $fautes = [];
        $valeurs = 0;

        foreach ($fichiers as $chemin) {
            $table = require $chemin;
            if (!is_array($table)) {
                continue;
            }

            $this->parcourir($table, '', static function (string $clef, string $valeur) use (&$fautes, &$valeurs, $chemin): void {
                $valeurs++;
                foreach (self::SUSPECTES as $suspecte) {
                    if (str_contains($valeur, $suspecte)) {
                        $fautes[] = basename(dirname($chemin)) . '/' . basename($chemin) . " : $clef porte « $suspecte »";

                        return;
                    }
                }
            });
        }

        $this->assertGreaterThan(2000, $valeurs, 'Premisse : les valeurs sont bien parcourues en profondeur.');
        $this->assertSame(
            [],
            $fautes,
            "Des traductions montrent une sequence d echappement au lieu d un caractere :\n  "
            . implode("\n  ", $fautes)
        );
    }

    /**
     * @param array<array-key, mixed> $table
     * @param callable(string, string): void $visiter
     */
    private function parcourir(array $table, string $prefixe, callable $visiter): void
    {
        foreach ($table as $clef => $valeur) {
            $chemin = $prefixe === '' ? (string)$clef : $prefixe . '.' . $clef;

            if (is_array($valeur)) {
                $this->parcourir($valeur, $chemin, $visiter);

                continue;
            }

            if (is_string($valeur)) {
                $visiter($chemin, $valeur);
            }
        }
    }
}
