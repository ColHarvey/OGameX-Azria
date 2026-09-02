<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;
use JsonException;

/**
 * L'empreinte des faits economiques d'un combat, calculee de facon canonique.
 *
 * ## Contre quoi elle protege, et contre quoi elle ne protege pas
 *
 * Elle attrape une **incoherence de programmation** : un contexte photographie pour un combat
 * applique aux flottes d'un autre, une composition qui a change entre la photographie et le calcul,
 * une version de regle qui ne correspond plus. Ce sont les erreurs qu'un systeme a deux chemins —
 * instantane et persistant — produit naturellement.
 *
 * Elle **ne protege pas** contre quelqu'un capable de modifier la base : cette personne reecrirait
 * les faits et l'empreinte ensemble. Ce n'est pas une signature cryptographique, et la presenter
 * comme telle donnerait une fausse assurance.
 *
 * ## Pourquoi une canonicalisation, et pas un simple `json_encode`
 *
 * Deux photographies des memes faits doivent donner la meme empreinte, quel que soit l'ordre dans
 * lequel les flottes sont arrivees ou les cles ont ete ecrites. A l'inverse, un fait qui change doit
 * changer l'empreinte. D'ou :
 *
 * - les flottes sont **triees par identifiant de mission** — leur ordre d'arrivee n'est pas un fait ;
 * - les dictionnaires sont **tries par cle**, recursivement ;
 * - les listes ordonnees metier, s'il y en a, conservent leur ordre : un tri generique transformerait
 *   une sequence en ensemble et effacerait une information ;
 * - **aucun flottant n'est accepte.** La conversion en unites entieres a lieu en amont, a la
 *   frontiere ; un flottant qui arriverait jusqu'ici signalerait que cette conversion a ete oubliee,
 *   et `1.0` contre `1` produirait deux empreintes pour un meme fait.
 *
 * ## La version de schema
 *
 * Elle est incluse dans le contenu hache. Ajouter un champ change donc toutes les empreintes, ce qui
 * est voulu : une empreinte calculee sous un schema n'est pas comparable a une empreinte calculee
 * sous un autre, et il vaut mieux le constater qu'esperer que personne ne s'en apercoive.
 */
final class SnapshotFingerprint
{
    /**
     * La version du schema de l'empreinte.
     */
    public const int SCHEMA = 1;

    /**
     * L'empreinte de ces faits.
     *
     * @param array<string, mixed> $facts
     * @return string
     */
    public static function of(array $facts): string
    {
        $canonique = self::canonicalise($facts, 'racine');

        try {
            $json = json_encode(
                ['schema' => self::SCHEMA, 'faits' => $canonique],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Les faits de la photographie ne sont pas serialisables : ' . $e->getMessage()
            );
        }

        return hash('sha256', $json);
    }

    /**
     * La forme canonique d'une valeur.
     *
     * Les tableaux associatifs sont tries par cle ; les listes conservent leur ordre, parce qu'une
     * liste metier ordonnee porte une information que le tri detruirait. C'est a l'appelant de trier
     * ce qui doit l'etre — les flottes par identifiant, par exemple — avant d'arriver ici.
     *
     * @param mixed $valeur
     * @param string $chemin Le chemin, pour nommer precisement ce qui est refuse.
     * @return mixed
     */
    private static function canonicalise(mixed $valeur, string $chemin): mixed
    {
        if (is_float($valeur)) {
            throw new InvalidArgumentException(
                'Le champ « ' . $chemin . ' » de la photographie est un flottant (' . $valeur . '). Les faits '
                . 'economiques doivent etre convertis en unites entieres a la frontiere : un flottant ici '
                . 'signale une conversion oubliee, et « 1.0 » donnerait une autre empreinte que « 1 ».'
            );
        }

        if (is_int($valeur) || is_string($valeur) || is_bool($valeur) || $valeur === null) {
            return $valeur;
        }

        if (!is_array($valeur)) {
            throw new InvalidArgumentException(
                'Le champ « ' . $chemin . ' » de la photographie porte une valeur de type ' . get_debug_type($valeur)
                . ', que la forme canonique ne sait pas representer.'
            );
        }

        $estUneListe = array_is_list($valeur);
        $canonique = [];

        foreach ($valeur as $cle => $element) {
            $canonique[$cle] = self::canonicalise($element, $chemin . '.' . $cle);
        }

        if (!$estUneListe) {
            ksort($canonique, SORT_STRING);
        }

        return $canonique;
    }
}
