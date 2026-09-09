<?php

namespace Tests\Unit\Support;

use OGame\Models\Setting;
use OGame\Services\SettingsService;
use Tests\Support\PinsSettings;
use Tests\UnitTestCase;

/**
 * Le mecanisme qui pose des reglages les rend, et ce temoin est ce qui le prouve.
 *
 * ## Pourquoi il existe
 *
 * Un `tearDown()` qui restaure n a **aucun temoin** : le retirer ne fait rougir personne, puisque
 * l essai qui l emploie ne regarde jamais l apres. La garantie serait tenue par la seule discipline,
 * et le depot a deja paye ce genre de promesse. Sortir le mecanisme dans un trait le rend
 * observable : on pose, on lit, on rend, on relit.
 *
 * ## Cet essai se nettoie derriere lui, a la main
 *
 * Il ecrit des valeurs pour se donner un monde de depart, et il ne peut pas confier ce nettoyage au
 * mecanisme qu il teste — ce serait lui demander de se porter garant de lui-meme, et un defaut du
 * trait effacerait la trace de son propre defaut. Les valeurs d avant sont donc relevees au montage
 * et rendues au demontage, independamment.
 */
class PinsSettingsTest extends UnitTestCase
{
    use PinsSettings;

    /**
     * Les reglages que cet essai touche, avec la valeur par defaut de leur service.
     *
     * La valeur par defaut sert de repli quand la ligne n existait pas : c est ce qu un lecteur
     * obtiendrait, donc rendre cela, c est rendre le monde d avant.
     *
     * @var array<string, int>
     */
    private const array TOUCHES = [
        'debris_field_from_ships' => 30,
        'defense_repair_rate' => 70,
        'maximum_moon_chance' => 20,
    ];

    /**
     * @var array<string, string>
     */
    private array $avantEssai = [];

    protected function setUp(): void
    {
        parent::setUp();

        $reglages = resolve(SettingsService::class);

        foreach (self::TOUCHES as $clef => $defaut) {
            $this->avantEssai[$clef] = $reglages->get($clef, (string)$defaut);
        }
    }

    protected function tearDown(): void
    {
        // Le mecanisme sous essai d abord — c est ce qu un essai ordinaire ferait...
        $this->restorePinnedSettings();

        // ...puis le nettoyage propre a cet essai, qui ne depend pas de lui.
        $reglages = resolve(SettingsService::class);

        foreach ($this->avantEssai as $clef => $valeur) {
            $reglages->set($clef, $valeur);
        }

        $this->avantEssai = [];

        // La clef temoin n appartient a personne : elle part avec l essai qui l a inventee.
        Setting::query()->where('key', 'temoin_isolation_reglages')->delete();

        parent::tearDown();
    }

    public function testAPinnedSettingIsGivenBackWithItsFormerValue(): void
    {
        $reglages = resolve(SettingsService::class);

        // Un monde de depart qui n est pas la valeur posee : sans cela, « rendu » et « laisse tel
        // quel » coincideraient, et le temoin ne prouverait rien.
        $reglages->set('debris_field_from_ships', 42);

        $this->pinSettings(['debris_field_from_ships' => 30]);
        $this->assertSame('30', $reglages->get('debris_field_from_ships', ''), 'The setting was not pinned at all.');

        $this->restorePinnedSettings();
        $this->assertSame('42', $reglages->get('debris_field_from_ships', ''), 'The former value was not given back.');
    }

    /**
     * **La premiere pose fait foi.** Deux poses successives ne doivent pas enregistrer comme
     * « avant » ce que la premiere vient d ecrire, sans quoi le retour rendrait une valeur de
     * l essai lui-meme.
     */
    public function testTwoPinsInARowStillGiveBackTheOriginalValue(): void
    {
        $reglages = resolve(SettingsService::class);

        $reglages->set('defense_repair_rate', 13);

        $this->pinSettings(['defense_repair_rate' => 70]);
        $this->pinSettings(['defense_repair_rate' => 100]);

        $this->restorePinnedSettings();

        $this->assertSame('13', $reglages->get('defense_repair_rate', ''), 'The restore gave back a value the test itself had written.');
    }

    /**
     * Rendre sans avoir pose ne touche a rien.
     */
    public function testRestoringWithoutPinningChangesNothing(): void
    {
        $reglages = resolve(SettingsService::class);

        $reglages->set('maximum_moon_chance', 17);

        $this->restorePinnedSettings();

        $this->assertSame('17', $reglages->get('maximum_moon_chance', ''), 'An empty restore wrote something.');
    }

    /**
     * **Un reglage qui n existait pas redevient absent**, et le service cesse de le servir.
     *
     * La valeur effective ne suffit pas : une ligne ecrite explicitement survivrait a un changement
     * du defaut, et dirait alors l ancienne valeur pour toujours. Le depot l a paye sur le delai
     * d alliance, passe de trois a sept jours.
     *
     * Deux assertions, et la seconde n est pas la premiere : la table peut avoir perdu sa ligne
     * pendant que le service, lui, garde la valeur en memoire.
     */
    public function testASettingThatDidNotExistIsAbsentAgainAfterTheRestore(): void
    {
        $clef = 'temoin_isolation_reglages';

        // Monde de depart : cette clef n existe pas. L essai l etablit au lieu de l esperer.
        Setting::query()->where('key', $clef)->delete();
        $this->assertFalse(Setting::query()->where('key', $clef)->exists());

        $this->pinSettings([$clef => 5]);

        $this->assertTrue(Setting::query()->where('key', $clef)->exists(), 'The pin wrote no row at all.');
        $this->assertSame('5', resolve(SettingsService::class)->get($clef, 'defaut'));

        $this->restorePinnedSettings();

        $this->assertFalse(
            Setting::query()->where('key', $clef)->exists(),
            'A row this test created outlived it: the day the default changes, that row would keep saying the old value.'
        );

        $this->assertSame(
            'defaut',
            resolve(SettingsService::class)->get($clef, 'defaut'),
            'The service still serves a value the table no longer carries: its cache was left incoherent.'
        );
    }
}
