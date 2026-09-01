<?php

namespace Tests\Feature;

use Tests\UnitTestCase;

/**
 * Les traductions du fork passent-elles par des cles, ou sont-elles ecrites en dur ?
 *
 * Notre fork avait traduit des messages d'exception et des libelles d'administration en
 * **remplacant un texte anglais code en dur par un texte francais code en dur**. Deux
 * consequences : la regle du projet — aucun texte affiche en dur — etait violee, et sept
 * tests amont qui verifient la chaine anglaise echouaient en permanence.
 *
 * Ces echecs etaient devenus « connus », c'est-a-dire toleres : un echec permanent finit par
 * ne plus rien signaler du tout, et masque celui qui compte.
 */
class ForkTranslationsTest extends UnitTestCase
{
    /**
     * Assert that the alliance exception messages follow the locale.
     */
    public function testTheAllianceExceptionMessagesFollowTheLocale(): void
    {
        $cas = [
            'err_tag_taken' => ['en' => 'Alliance tag is already taken', 'fr' => 'Ce tag est déjà utilisé'],
            'err_already_in_alliance' => ['en' => 'User is already in an alliance', 'fr' => 'Ce joueur est déjà membre d\'une alliance'],
            'err_cannot_kick_founder' => ['en' => 'Cannot kick the alliance founder', 'fr' => 'Impossible d\'exclure le fondateur de l\'alliance'],
            'err_member_not_found' => ['en' => 'Member not found in alliance', 'fr' => 'Membre introuvable dans l\'alliance'],
        ];

        foreach ($cas as $cle => $attendu) {
            foreach ($attendu as $locale => $texte) {
                $this->assertSame(
                    $texte,
                    trans('t_ingame.alliance.' . $cle, [], $locale),
                    "The alliance message {$cle} is wrong in {$locale}: upstream tests assert the English wording, players read the French one."
                );
            }
        }
    }

    /**
     * Assert that no French wording is hardcoded back into the alliance service.
     *
     * C'est la moitie que la comparaison de traductions ne couvre pas : les cles peuvent etre
     * justes pendant que le code leve une chaine ecrite a la main juste a cote.
     */
    public function testTheAllianceServiceThrowsNoHardcodedFrench(): void
    {
        $source = file_get_contents(app_path('Services/AllianceService.php'));
        $this->assertIsString($source);

        preg_match_all('/throw new Exception\((.+)\);/', $source, $trouves);

        foreach ($trouves[1] as $argument) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[\'"].*(déjà|deja|Vous n\\\\\'avez|Membre introuvable|caractères|caracteres)/u',
                trim($argument),
                'An exception message is hardcoded in French again instead of using a translation key: ' . trim($argument)
            );
        }
    }

    /**
     * Assert that every translatable slot of the admin views goes through a translation call.
     *
     * La premiere version de ce test cherchait des lettres accentuees. C'etait insuffisant :
     * « Recherche », « Ajouter » ou « Annuler » seraient passes sans etre vus, et un libelle
     * anglais recode en dur ne l'aurait jamais ete. On verifie donc **structurellement** que
     * les zones destinees a l'oeil passent par `@lang()` ou `__()`.
     *
     * La regle : dans une zone traduisible, une fois retires les appels de traduction et
     * toutes les expressions Blade, il ne doit plus rester de mot. Ce qui subsiste est ecrit
     * en dur, quelle que soit sa langue et qu'il porte ou non des accents.
     */
    public function testTheAdminViewsTranslateEveryVisibleString(): void
    {
        $vues = [
            'views/ingame/admin/server-administration.blade.php',
            'views/ingame/layouts/admin-menu.blade.php',
        ];

        foreach ($vues as $vue) {
            $source = file_get_contents(resource_path($vue));
            $this->assertIsString($source);

            foreach ($this->zonesTraduisibles($source) as $zone) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\p{L}{2,}/u',
                    $zone['texte'],
                    "A visible string in {$vue} is hardcoded instead of going through @lang() or __(): "
                    . '[' . $zone['type'] . '] ' . $zone['texte']
                );
            }
        }
    }

    /**
     * Assert that the administration labels resolve in both languages.
     *
     * Le controle structurel ci-dessus verifie qu'une cle est employee ; il ne dit rien de sa
     * valeur. Sans ce test, une vue entierement passee en cles pourrait s'afficher en anglais
     * en jeu, faute de traduction francaise.
     */
    public function testTheAdministrationLabelsResolveInBothLanguages(): void
    {
        $cas = [
            'Server admin' => 'Administration',
            'Server settings' => 'Paramètres',
            'Developer shortcuts' => 'Raccourcis',
            'Recover to Homeworld' => 'Renvoyer à la planète mère',
            'Username' => 'Pseudo',
            'Shared IP Groups' => "Groupes d'IP partagées",
        ];

        foreach ($cas as $cle => $francais) {
            $this->assertSame($cle, trans($cle, [], 'en'), "The English label for \"{$cle}\" changed, so upstream tests that assert it would fail.");
            $this->assertSame($francais, trans($cle, [], 'fr'), "The French label for \"{$cle}\" is missing, so the panel shows English to a French administrator.");
        }
    }

    /**
     * Extract the slots of a Blade template that a reader actually sees.
     *
     * Sont exclus : les scripts, les styles, les blocs `@php`, les commentaires Blade, et les
     * attributs techniques — classe, identifiant, type, style, lien. L'attribut `value` n'est
     * retenu que sur un bouton, ou il porte le libelle lu ; ailleurs c'est une donnee envoyee
     * au serveur (« permanent », « shared_ip »), pas du texte.
     *
     * @param string $source
     * @return list<array{type: string, texte: string}>
     */
    private function zonesTraduisibles(string $source): array
    {
        $s = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $source);
        $s = (string) preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $s);
        $s = (string) preg_replace('/@php\b.*?@endphp/s', ' ', $s);
        $s = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $s);

        // Directives Blade, parentheses imbriquees comprises : @if($u->hasRole('admin')).
        // Les appels de traduction disparaissent avec elles, et c'est exactement le point :
        // ce qui reste ensuite n'est pas traduit.
        $s = (string) preg_replace('/@[a-zA-Z]+\s*(\((?:[^()]++|(?1))*\))?/', ' ', $s);
        $s = (string) preg_replace('/\b__\s*(\((?:[^()]++|(?1))*\))/', ' ', $s);

        // Les interpolations sont masquees plutot que supprimees : « -> » et « => » portent
        // un « > » qui couperait le decoupage des balises en plein milieu d'une expression.
        $s = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $s);

        $zones = [];

        if (preg_match_all('/(title|placeholder|alt)="([^"]*)"/', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $zones[] = ['type' => $x[1], 'texte' => $this->nettoyer($x[2])];
            }
        }

        if (preg_match_all('/<(?:input|button)\b[^>]*\btype="(?:submit|button)"[^>]*>/i', $s, $m)) {
            foreach ($m[0] as $balise) {
                if (preg_match('/\bvalue="([^"]*)"/', $balise, $v)) {
                    $zones[] = ['type' => 'value', 'texte' => $this->nettoyer($v[1])];
                }
            }
        }

        if (preg_match_all('/>([^<>]+)</', $s, $m)) {
            foreach ($m[1] as $t) {
                $zones[] = ['type' => 'texte', 'texte' => $this->nettoyer($t)];
            }
        }

        return $zones;
    }

    /**
     * Strip HTML entities and collapse whitespace.
     *
     * @param string $texte
     * @return string
     */
    private function nettoyer(string $texte): string
    {
        $t = (string) preg_replace('/&[a-zA-Z#0-9]+;/', ' ', $texte);

        return trim((string) preg_replace('/\s+/', ' ', $t));
    }
}
