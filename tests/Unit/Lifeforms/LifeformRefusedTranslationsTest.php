<?php

namespace Tests\Unit\Lifeforms;

use OGame\Lifeforms\LifeformRefused;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * **Chaque refus des formes de vie a sa phrase, en francais et en anglais** (revue de §162, journal §163).
 *
 * Un refus arrive au joueur par `__('t_lifeforms_ui.refused.<code>')` : une clef absente rend la clef elle-meme, sans
 * erreur — une phrase de code a l ecran. Les essais tournent en anglais, donc la traduction francaise d un refus neuf
 * n etait prouvee par rien. Ce temoin lit les deux fichiers de langue eux-memes et confronte chaque constante de
 * `LifeformRefused` a leur entree.
 */
final class LifeformRefusedTranslationsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function refusals(): array
    {
        $codes = [];
        foreach ((new ReflectionClass(LifeformRefused::class))->getConstants() as $nom => $code) {
            $codes[$nom] = $code;
        }

        return $codes;
    }

    /**
     * @return array<string, string>
     */
    private static function sentencesOf(string $locale): array
    {
        $fichier = dirname(__DIR__, 3) . '/resources/lang/' . $locale . '/t_lifeforms_ui.php';
        self::assertFileExists($fichier);
        $langue = require $fichier;
        self::assertIsArray($langue);
        self::assertIsArray($langue['refused'] ?? null, "$locale : la section « refused » existe.");

        return $langue['refused'];
    }

    public function testEveryRefusalCodeHasAFrenchAndAnEnglishSentence(): void
    {
        $codes = self::refusals();
        $this->assertGreaterThanOrEqual(26, count($codes), 'Premisse : les refus connus, dont OWN_PLANET.');
        $this->assertSame('own_planet', $codes['OWN_PLANET']);

        foreach (['fr', 'en'] as $locale) {
            $phrases = self::sentencesOf($locale);
            foreach ($codes as $nom => $code) {
                $this->assertArrayHasKey($code, $phrases, "$locale : le refus $nom ($code) n a pas de phrase.");
                $this->assertIsString($phrases[$code]);
                $this->assertNotSame('', trim($phrases[$code]), "$locale : la phrase de $code est vide.");
                $this->assertStringNotContainsString('t_lifeforms_ui', $phrases[$code], "$locale : la phrase de $code est une clef.");
            }
        }
    }

    public function testTheOwnPlanetRefusalReadsInFrenchAndInEnglishAsWritten(): void
    {
        $this->assertSame('Un vaisseau d’exploration ne se lance pas vers vos propres planètes.', self::sentencesOf('fr')['own_planet']);
        $this->assertSame('An exploration ship cannot be sent to your own planets.', self::sentencesOf('en')['own_planet']);
    }

    public function testTheFrenchSentencesUseTheTypographicApostrophe(): void
    {
        // Une apostrophe droite dans une chaine a guillemets simples casse le fichier ; le fichier emploie l apostrophe
        // typographique partout, et une phrase neuve doit faire de meme.
        foreach (self::sentencesOf('fr') as $code => $phrase) {
            $this->assertStringNotContainsString("'", $phrase, "fr : la phrase de $code porte une apostrophe droite.");
        }
    }
}
