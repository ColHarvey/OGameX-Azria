<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\UnitTestCase;

/**
 * Toute phrase que le code demande par sa chaine existe en francais.
 *
 * ## Le defaut que cette garde ferme
 *
 * Laravel a deux mecanismes de traduction. `__('t_fichier.cle')` lit un tableau PHP et **le dit**
 * quand la clef manque : la chaine rendue porte le nom de la clef, qui saute aux yeux. Mais
 * `__('Une phrase anglaise')` lit `resources/lang/fr.json` et, quand la clef manque, **rend la clef
 * elle-meme** — c'est-a-dire la phrase anglaise, parfaitement lisible, sans le moindre signe qu'une
 * traduction manque.
 *
 * C'est ainsi qu'un joueur francais a lu « Votre flotte revient de planet … vers planet … » et
 * « Espionage report from … » pendant des mois. Rien n'echouait. Aucun essai ne tombait. Seul un
 * joueur pouvait le voir, et seulement s'il y prenait garde.
 *
 * Cette garde lit donc le code, la ou l'observation ne peut rien : elle releve chaque appel a
 * `__()`, `@lang()` et `trans()` portant une chaine litterale, et exige que le francais la
 * connaisse.
 *
 * ## Ce qu'elle laisse volontairement passer
 *
 * **Les prefixes de concatenation.** `__('t_messages.' . $key)` fait apparaitre le litteral
 * `'t_messages.'`, qui n'est pas une clef mais la moitie d'une. Ils se reconnaissent a leur point
 * final.
 *
 * **Les quelques chaines identiques dans les deux langues.** « R », « G », « B » nomment les canaux
 * d'un selecteur de couleur, « Ok » un bouton. Les traduire par elles-memes n'apprendrait rien et
 * ferait croire a un travail fait. Elles sont nommees ici une par une : ajouter une phrase a cette
 * liste est un geste visible en revue, ce qu'une exception large ne serait pas.
 */
class FrenchStringTranslationCoverageTest extends UnitTestCase
{
    /**
     * Les chaines qui s'ecrivent de la meme facon en francais et en anglais.
     *
     * @var array<int, string>
     */
    private const array IDENTICAL_IN_BOTH_LANGUAGES = ['R', 'G', 'B', 'Ok'];

    /**
     * Aucune phrase demandee par le code ne manque au francais.
     */
    public function testEveryStringKeyTheCodeAsksForExistsInFrench(): void
    {
        $french = json_decode((string)file_get_contents(base_path('resources/lang/fr.json')), true);

        $this->assertIsArray($french, 'resources/lang/fr.json could not be read as JSON.');
        $this->assertNotEmpty($french, 'The French JSON translations are empty: the guard would prove nothing.');

        $missing = [];

        foreach ($this->stringKeysUsedInTheCode() as $key => $places) {
            if (array_key_exists($key, $french)) {
                continue;
            }

            // Une moitie de clef, pas une phrase : `__('t_messages.' . $suffixe)`.
            if (str_ends_with($key, '.')) {
                continue;
            }

            if (in_array($key, self::IDENTICAL_IN_BOTH_LANGUAGES, true)) {
                continue;
            }

            $missing[] = $key . '  (' . implode(', ', array_slice($places, 0, 2)) . ')';
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These strings are shown to French players in English, and nothing fails when they are:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * Aucune balise BBCode n'a ete traduite dans les fichiers de langue.
     *
     * ## Pourquoi c'est pire qu'une phrase non traduite
     *
     * Une phrase anglaise reste lisible. Une balise traduite, elle, n'est plus reconnue par le
     * remplacement — `replacePlaceholders()` cherche `[coordinates]`, pas `[coordonnees]` — et le
     * joueur lit le code source du message a la place de ses coordonnees.
     *
     * Le cas s'est produit dans le message de demenagement de planete.
     *
     * ## La regle, et pourquoi elle n'a pas de liste a tenir
     *
     * Une balise traduite est, par definition, **une balise qui existe en francais et pas en
     * anglais** : l'anglais porte les noms que le code reconnait. Comparer les deux jeux suffit
     * donc, sans enumerer les balises admises — une liste qu'il faudrait tenir a jour, et qu'on
     * oublierait le jour ou une balise neuve apparait.
     */
    public function testNoBbcodeTagWasTranslated(): void
    {
        $english = $this->bracketedNamesIn('en');
        $french = $this->bracketedNamesIn('fr');

        $this->assertNotEmpty($english, 'No bracketed name was found in the English files: the comparison would prove nothing.');
        $this->assertNotEmpty($french, 'No bracketed name was found in the French files: the comparison would prove nothing.');

        $unknown = array_values(array_diff(array_keys($french), array_keys($english)));
        sort($unknown);

        $this->assertSame(
            [],
            $unknown,
            "These bracketed names exist in French but not in English, so the message parser does not know them "
            . "and the player sees the raw markup:\n  "
            . implode("\n  ", array_map(fn (string $name): string => '[' . $name . ']  ' . $french[$name], $unknown))
        );
    }

    /**
     * Le source prive de ses commentaires.
     *
     * Sans cela, la garde reclamerait une traduction pour une chaine qui n'apparait que dans de la
     * prose — un commentaire qui *decrit* un appel a `__()` serait pris pour l'appel lui-meme. Le
     * cas s'est presente des le premier passage, sur le commentaire qui explique ce defaut-la.
     *
     * Les commentaires Blade sont retires par motif, faute d'etre du PHP ; les commentaires PHP le
     * sont par le lexeur, qui ne se laisse pas tromper par un `//` dans une chaine.
     *
     * @param string $source
     * @return string
     */
    private function withoutComments(string $source): string
    {
        // **Les sauts de ligne d'un commentaire sont conserves**, seul son texte disparait : les
        // numeros de ligne signales doivent rester ceux du fichier reel.
        $blank = fn (string $text): string => str_repeat("\n", substr_count($text, "\n"));
        $source = (string)preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m): string => $blank($m[0]), $source);
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $kept .= $blank($token[1]);

                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * Les noms entre crochets d'une langue, avec l'endroit ou chacun apparait d'abord.
     *
     * @param string $locale
     * @return array<string, string>
     */
    private function bracketedNamesIn(string $locale): array
    {
        $names = [];

        foreach (glob(base_path('resources/lang/' . $locale) . '/*.php') ?: [] as $file) {
            $lines = explode("\n", str_replace("\r\n", "\n", (string)file_get_contents($file)));

            foreach ($lines as $number => $line) {
                if (preg_match_all('/\[([^\]\[]{1,24})\]/u', $line, $found) === 0) {
                    continue;
                }

                foreach ($found[1] as $name) {
                    $names[$name] ??= basename($file) . ':' . ($number + 1);
                }
            }
        }

        return $names;
    }

    /**
     * Chaque chaine litterale passee a une fonction de traduction, avec ses emplacements.
     *
     * @return array<string, array<int, string>>
     */
    private function stringKeysUsedInTheCode(): array
    {
        $found = [];

        foreach (['app', 'resources/views'] as $directory) {
            $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

            /** @var SplFileInfo $file */
            foreach ($walker as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $source = $this->withoutComments((string)file_get_contents($file->getPathname()));
                $path = str_replace([base_path() . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());

                if (preg_match_all("/(?:__|@lang|trans)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[1] as $capture) {
                    $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $capture[0]);

                    // Une clef de fichier de langue — `t_ingame.messages.spy_player` — ne passe pas
                    // par le fichier JSON. Elle se reconnait a sa forme : minuscules pointees, sans
                    // espace. Une vraie phrase en porte toujours un, ou commence par une majuscule.
                    if ($key === '' || preg_match('/^[a-z0-9_]+(\.[a-zA-Z0-9_*-]+)+$/', $key) === 1) {
                        continue;
                    }

                    $found[$key][] = $path . ':' . (substr_count(substr($source, 0, $capture[1]), "\n") + 1);
                }
            }
        }

        return $found;
    }
}
