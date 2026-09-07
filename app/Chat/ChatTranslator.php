<?php

namespace OGame\Chat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le traducteur des messages du chat general.
 *
 * ## Pourquoi le serveur, et pas le navigateur
 *
 * La premiere version employait le traducteur integre de Chrome : rien a heberger, rien a payer, et
 * le message ne quittait pas la machine du joueur. **Mesure faite sur le navigateur de Keven :
 * `typeof Translator` rend `'undefined'`.** L'interface n'existe ni sous Brave, ni sous Firefox, ni
 * sous Safari, ni sous un Chrome dont le drapeau n'est pas leve — donc chez presque personne.
 *
 * Le moteur est donc un LibreTranslate qui tourne a cote du jeu. Decision de Keven : plutot un
 * service de plus a maintenir qu'un tiers a qui confier les messages de ses joueurs.
 *
 * ## Ce que ce service garantit
 *
 * **L'adresse est interne.** Le conteneur n'a aucun port publie ; le navigateur ne lui parle jamais.
 * Un joueur passe par une route du jeu, qui exige une session et borne le debit — sans quoi
 * n'importe qui pourrait se servir du traducteur comme d'une API gratuite.
 *
 * **Une panne ne casse rien.** Adresse absente, service arrete, delai depasse, reponse inattendue :
 * le service rend `null`, la route repond « traduction indisponible », et le joueur garde son
 * message d'origine. Le chat n'a jamais besoin du traducteur pour fonctionner.
 */
final class ChatTranslator
{
    /**
     * Le traducteur est-il configure ?
     *
     * Sans adresse, la fonctionnalite s'eteint : la page n'affiche meme pas le bouton, plutot que
     * d'offrir une action qui echouerait a chaque fois.
     */
    public function configured(): bool
    {
        return trim((string)config('services.libretranslate.url')) !== '';
    }

    /**
     * Le texte traduit, ou `null` si rien n'a pu etre fait.
     *
     * La langue source n'est pas donnee : `auto` laisse le moteur la reconnaitre. Une detection
     * separee serait un aller-retour de plus pour la meme information.
     *
     * @return array{texte: string, source: string}|null
     */
    public function translate(string $texte, string $cible): array|null
    {
        $texte = trim($texte);

        if ($texte === '' || !$this->configured()) {
            return null;
        }

        $adresse = rtrim((string)config('services.libretranslate.url'), '/') . '/translate';

        try {
            $reponse = Http::timeout((int)config('services.libretranslate.timeout', 8))
                ->asJson()
                ->post($adresse, [
                    'q' => $texte,
                    'source' => 'auto',
                    'target' => $cible,
                    'format' => 'text',
                ]);
        } catch (Throwable $panne) {
            // **Le detail reste au serveur.** Un message d'exception porte l'adresse interne du
            // service et parfois le texte envoye ; le joueur, lui, lit une phrase.
            Log::warning('Traduction du chat indisponible : ' . $panne->getMessage());

            return null;
        }

        if (!$reponse->successful()) {
            Log::warning('Traduction du chat refusee, code ' . $reponse->status());

            return null;
        }

        $charge = $reponse->json();

        if (!is_array($charge) || !isset($charge['translatedText']) || !is_string($charge['translatedText'])) {
            return null;
        }

        // La langue reconnue, quand le moteur la rend. Elle sert a distinguer « deja dans ta
        // langue » d'une vraie traduction — deux issues que le joueur ne doit pas confondre.
        $source = '';

        if (isset($charge['detectedLanguage']) && is_array($charge['detectedLanguage'])) {
            $source = (string)($charge['detectedLanguage']['language'] ?? '');
        }

        return ['texte' => $charge['translatedText'], 'source' => $source];
    }
}
