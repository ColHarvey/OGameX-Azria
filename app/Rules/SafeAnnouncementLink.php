<?php

namespace OGame\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Un lien d annonce sur : soit un chemin **local** du jeu, soit une adresse `http(s)` absolue. Rien d autre.
 *
 * ## Pourquoi une liste blanche, et jamais une liste noire
 *
 * Refuser `javascript:` et `data:` ne suffit pas : il reste `vbscript:`, `file:`, `blob:`, les schemas inventes,
 * et surtout les formes qui n ont pas l air d un schema. Ici on n interdit pas des formes fautives, on
 * **n autorise que deux formes**, et tout le reste tombe — y compris ce qui n a pas encore ete invente.
 *
 * ## Ce que le navigateur lit autrement que l oeil (precaution de Keven, 20 septembre 2026)
 *
 * - **Les antislashs.** Les navigateurs traitent `\` comme `/` dans l autorite d une URL : `/\evil.test` et
 *   `\\evil.test` partent vers un autre site alors qu ils ressemblent a des chemins locaux. Aucun antislash
 *   n est donc admis, nulle part.
 * - **Les caracteres de controle.** Un `\n`, un `\r`, un `\t` ou un octet nul au milieu de `java\tscript:`
 *   disparaissent a l analyse et laissent le schema reconstitue. Ils sont refuses avant toute autre lecture.
 * - **Les espaces de tete.** `  javascript:...` est un schema pour le navigateur ; on refuse plutot que de
 *   rogner, car rogner silencieusement transforme une saisie en une autre.
 * - **La double barre de tete.** `//evil.test` est une URL « sans schema » qui sort du site. Un chemin local
 *   commence par une barre **suivie d autre chose**.
 *
 * ## Ce que cette regle ne fait pas
 *
 * Elle ne dit pas que la cible existe, ni qu elle est sage. Elle dit que la forme ne peut pas faire sortir le
 * joueur du jeu a son insu, ni executer quoi que ce soit.
 */
class SafeAnnouncementLink implements ValidationRule
{
    /**
     * @param Closure(string):PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) || !$this->estAcceptable($value)) {
            // La clef, jamais la phrase : `translate()` attend une clef a resoudre.
            $fail('t_ingame.announcement.link_refused')->translate();
        }
    }

    private function estAcceptable(string $valeur): bool
    {
        // **Les caracteres de controle d abord**, avant toute autre lecture : ils changent ce que le
        // navigateur voit sans changer ce que l oeil lit.
        if (preg_match('/[\x00-\x1F\x7F]/u', $valeur) === 1) {
            return false;
        }

        // Aucun antislash : le navigateur les lit comme des barres dans l autorite d une URL.
        if (str_contains($valeur, '\\')) {
            return false;
        }

        // Aucune marge : on refuse plutot que de rogner, pour qu une saisie ne devienne pas une autre.
        if (trim($valeur) !== $valeur) {
            return false;
        }

        return $this->estUnCheminLocal($valeur) || $this->estUneAdresseAbsolueSure($valeur);
    }

    /**
     * Un chemin du jeu : une barre, **suivie d autre chose qu une barre**.
     *
     * `//evil.test` est une adresse sans schema, pas un chemin. `/ok`, `/overview?cp=3`, `/a/b#c` en sont.
     */
    private function estUnCheminLocal(string $valeur): bool
    {
        return str_starts_with($valeur, '/') && !str_starts_with($valeur, '//');
    }

    /**
     * Une adresse absolue, et **seulement** en `http` ou `https`, avec un hote.
     *
     * Le schema est compare en minuscules : `JaVaScRiPt:` est le meme schema que `javascript:`.
     */
    private function estUneAdresseAbsolueSure(string $valeur): bool
    {
        $parties = parse_url($valeur);
        if ($parties === false || !isset($parties['scheme'], $parties['host'])) {
            return false;
        }

        if (!in_array(strtolower($parties['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return $parties['host'] !== '';
    }
}
