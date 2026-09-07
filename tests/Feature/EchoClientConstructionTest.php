<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Le client temps reel se construit, et son echec n'emporte personne.
 *
 * ## Le defaut que ces temoins ferment
 *
 * `echo.iife.js` n'expose pas la classe : il rend un espace de noms dont `.default` porte la
 * classe. `echo.js` faisait `new Echo({...})` sur cet espace de noms, ce qui levait « Echo is not
 * a constructor ».
 *
 * Le bundle du jeu est une **simple concatenation** de fichiers — pas une resolution de modules —
 * et cette exception non rattrapee arretait le script a cette ligne. Les trois fichiers suivants,
 * `chat.js`, `combat.js` et `messages-badge.js`, ne s'executaient jamais. Le temps reel n'a donc
 * jamais fonctionne dans le navigateur : le chat n'avait aucun secours, et le combat retombait sur
 * son sondage periodique, ce qui donnait l'illusion que le canal direct marchait.
 *
 * ## Pourquoi une lecture de source, et non un essai de navigateur
 *
 * Ce poste n'a ni Node ni navigateur : la seule preuve accessible ici est la forme du code. Elle
 * suffit a fermer ce defaut-la, parce qu'il est **textuel** — une construction sur le mauvais
 * objet, et une absence de garde. Ce qu'elle ne prouve pas, et qu'aucun essai d'ici ne prouvera,
 * c'est que la connexion s'etablit : cela se verifie a l'ecran, et nulle part ailleurs.
 */
class EchoClientConstructionTest extends TestCase
{
    private const string FICHIER = 'resources/js/ingame/echo.js';

    /**
     * La classe est resolue, jamais supposee.
     *
     * `new Echo(` est refuse : c'est exactement l'ecriture qui construisait l'espace de noms.
     */
    public function testTheConstructorIsResolvedInsteadOfAssumed(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString(
            'new Echo(',
            $source,
            'The client is built straight from the library namespace: this is the write that threw "Echo is not a constructor".'
        );

        $this->assertStringContainsString(
            'Echo.default',
            $source,
            'Nothing reads the .default export, so the shape the library really exposes is never handled.'
        );
    }

    /**
     * Un echec de construction ne sort pas de ce fichier.
     *
     * C'est la moitie qui compte le plus : sans elle, une bibliotheque qui change de forme ou une
     * configuration invalide reprivent les trois modules suivants de leur execution — secours
     * compris.
     */
    public function testAFailureNeverEscapesThisFile(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            '/try\s*\{.*?new\s+Constructeur\s*\(.*?\}\s*catch/s',
            $source,
            'The construction is not guarded: an exception here stops the concatenated bundle and kills chat.js, combat.js and the mail badge.'
        );
    }

    /**
     * Les modules qui suivent restent les seuls a decider, et ils lisent `window.Echo`.
     *
     * Ce temoin epingle le contrat entre ce fichier et eux : sans client utilisable, `window.Echo`
     * doit **rester absent** plutot que de porter l'espace de noms — sinon leur garde
     * `typeof window.Echo.private !== 'function'` serait leur seul recours, et une forme
     * inattendue passerait.
     */
    public function testNothingIsPublishedWhenNoClientCouldBeBuilt(): void
    {
        $source = $this->source();

        $position = strpos($source, 'window.Echo =');
        $this->assertNotFalse($position, 'The client is never published, so no module could use it.');

        $avant = substr($source, 0, $position);

        $this->assertStringContainsString(
            'return;',
            $avant,
            'No early exit precedes the publication: an unusable library would still be published as a client.'
        );
    }

    /**
     * Les reglages du temps reel sont declares avant le bundle qui les lit.
     *
     * **C'est le defaut qui a reellement bloque**, et il est d'ordre, pas de logique. `echo.js`
     * annoncait depuis toujours « expected to be set in the main Blade layout before this script
     * loads » ; personne ne l'avait verifie. `@vite` chargeait le bundle dans l'en-tete et les
     * quatre variables etaient declarees dans le corps, plusieurs centaines de lignes plus bas.
     *
     * `echo.js` sortait alors a sa premiere ligne, **en silence**, et les trois consommateurs se
     * taisaient a leur tour. Aucun essai ne pouvait le voir : le code etait juste, seule sa place
     * dans la page etait fausse.
     */
    public function testTheRealtimeSettingsAreDeclaredBeforeTheBundle(): void
    {
        $gabarit = (string)file_get_contents(base_path('resources/views/ingame/layouts/main.blade.php'));

        $reglages = strpos($gabarit, 'var reverbAppKey');
        $bundle = strpos($gabarit, "@vite(['resources/css/ingame.css'");

        $this->assertNotFalse($reglages, 'The real-time settings are gone from the layout: the client can never be configured.');
        $this->assertNotFalse($bundle, 'The bundle is no longer loaded by @vite: this guard no longer measures anything.');

        $this->assertLessThan(
            $bundle,
            $reglages,
            'The real-time settings are declared after the bundle that reads them: echo.js exits silently and chat, combat and the mail badge all stay mute.'
        );
    }

    /**
     * Le combat attend la page avant de s'abonner.
     *
     * Meme famille que le defaut ci-dessus, autre variable : `combat.js` lisait `playerId` au
     * chargement de l'en-tete, ou il n'existe pas encore. Il sortait par sa garde, et son sondage
     * de secours donnait l'illusion d'un temps reel qui n'avait jamais eu lieu.
     */
    public function testTheCombatSubscriptionWaitsForThePage(): void
    {
        $source = (string)file_get_contents(base_path('resources/js/ingame/combat.js'));

        $this->assertStringContainsString(
            "document.addEventListener('DOMContentLoaded', ecouter)",
            $source,
            'The combat subscription runs at bundle time, before playerId exists: it would silently fall back to polling.'
        );
    }

    /**
     * Le code du fichier, ses commentaires retires.
     *
     * **Un garde qui lit la prose se trompe de cible.** Le commentaire de `echo.js` cite
     * l ecriture fautive pour l expliquer ; sans ce nettoyage, l essai la trouverait la et
     * echouerait sur une explication au lieu d un defaut. Le retrait est volontairement naif —
     * blocs et lignes de commentaire seulement —, ce qui suffit pour un fichier de cette taille
     * et qui ne contient aucune chaine portant une double barre.
     */
    private function source(): string
    {
        $chemin = base_path(self::FICHIER);

        $this->assertFileExists($chemin, self::FICHIER . ' is gone: the real-time client is no longer built anywhere.');

        $brut = (string)file_get_contents($chemin);
        $sansBlocs = (string)preg_replace('#/\*.*?\*/#s', '', $brut);
        $lignes = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $sansBlocs)) as $ligne) {
            if (!str_starts_with(trim($ligne), '//')) {
                $lignes[] = $ligne;
            }
        }

        $code = implode("\n", $lignes);

        $this->assertStringContainsString('window.Echo', $code, 'Stripping the comments left no code: the guard would pass on an empty string.');

        return $code;
    }
}
