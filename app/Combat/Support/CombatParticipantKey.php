<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;
use OGame\Services\PlanetService;

/**
 * L'identite d'un participant a un combat, construite ici et nulle part ailleurs.
 *
 * Une cle libre serait un piege silencieux : `fleet:123`, `fleet:0123` et `Fleet:123` designent
 * la meme flotte pour un lecteur, mais font trois lignes differentes pour une contrainte
 * d'unicite. Le doublon qu'on croyait interdit passerait alors sans bruit, et la resolution
 * compterait deux fois les memes vaisseaux.
 *
 * D'ou une fabrique unique, qui prend un identifiant entier et rend une forme normalisee. Ce
 * n'est pas une precaution theorique : la contrainte d'unicite ne protege que ce qui est ecrit
 * de la meme facon.
 *
 * Ces cles ne doivent **jamais** venir d'une requete, d'une saisie, ou d'une chaine ecrite a la
 * main. `CombatParticipantKeyTest` verifie qu'aucune ne se construit ailleurs dans le code.
 */
class CombatParticipantKey
{
    /**
     * Le prefixe d'une flotte, identifiee par sa mission.
     */
    public const string FLEET_PREFIX = 'fleet';

    /**
     * Le prefixe d'une garnison stationnaire, identifiee par sa planete.
     */
    public const string PLANET_PREFIX = 'planet';

    /**
     * Le nom reserve d'un corps sans identifiant, rencontre uniquement dans les bancs d'essai.
     */
    public const string UNIDENTIFIED_BODY = 'body:unidentified';

    /**
     * Le nom d'une flotte attaquante qui n'a pas de mission.
     *
     * Une seule en produit en jeu : la sonde ephemere du contre-espionnage, qui combat sans avoir
     * ete lancee. Les bancs d'essai en font aussi. Un combat durable n'en admet aucune — son
     * effectif est fait d'inscriptions, et une inscription nomme une mission —, mais la chronologie
     * d'un round doit pouvoir nommer cette flotte-la sans lui inventer un identifiant.
     */
    public const string EPHEMERAL_ATTACKER = 'attacker:ephemeral';

    /**
     * Build the identity of a fleet taking part in a combat.
     *
     * Une flotte est identifiee par sa mission : c'est ce qui la distingue de toutes les autres,
     * y compris des autres flottes du meme joueur dans la meme union.
     *
     * @param int $fleetMissionId
     * @return string
     */
    public static function forFleet(int $fleetMissionId): string
    {
        return self::build(self::FLEET_PREFIX, $fleetMissionId);
    }

    /**
     * Build the identity of a stationary garrison taking part in a combat.
     *
     * La garnison n'a pas de mission. Sans cette cle, l'unicite aurait porte sur une colonne
     * nulle — et plusieurs valeurs nulles sont permises dans un index unique, donc la garnison
     * aurait pu etre inscrite deux fois.
     *
     * @param int $planetId
     * @return string
     */
    public static function forPlanet(int $planetId): string
    {
        return self::build(self::PLANET_PREFIX, $planetId);
    }

    /**
     * Build the identity of a celestial body taking part in a combat.
     *
     * Une fonction de **nommage**, pas d'observation : elle ne lit que l'identifiant du corps, et les
     * deux chemins — instantane et persistant — doivent la partager. Sans cela, chacun ecrirait sa
     * variante, et l'empreinte de photographie cesserait de correspondre d'un chemin a l'autre.
     *
     * Un corps sans identifiant ne se rencontre que dans les bancs d'essai. Lui donner un nom
     * reserve vaut mieux que de refuser : le controle d'appariement reste utile entre deux montages
     * differents, et aucune ligne de base ne porte ce nom.
     *
     * @param PlanetService $body
     * @return string
     */
    public static function forBody(PlanetService $body): string
    {
        $identifiant = $body->getPlanetId();

        if ($identifiant < 1) {
            return self::UNIDENTIFIED_BODY;
        }

        return self::forPlanet($identifiant);
    }

    /**
     * Assemble a normalised key.
     *
     * Un identifiant nul ou negatif ne designe rien : le laisser passer produirait une cle
     * valide en apparence, partagee par tous les appels fautifs, et donc un faux doublon.
     *
     * @param string $prefixe
     * @param int $identifiant
     * @return string
     */
    private static function build(string $prefixe, int $identifiant): string
    {
        if ($identifiant <= 0) {
            throw new InvalidArgumentException('A combat participant key needs a real identifier, got ' . $identifiant . '.');
        }

        return $prefixe . ':' . $identifiant;
    }

    /**
     * La clef designe-t-elle un corps — la garnison — plutot qu'une flotte ?
     *
     * Le lecteur du panneau s'en sert pour dire le role d'un defenseur inscrit : le proprietaire
     * du corps vise, ou un renfort venu d'ailleurs. La forme est celle que `forPlanet()` ecrit.
     */
    public static function isBody(string $key): bool
    {
        return str_starts_with($key, 'planet:');
    }

    /**
     * Dit si une chaine relue est une cle que cette classe aurait pu produire.
     *
     * Une porte de confiance — la relecture d'un resultat gele, par exemple — recoit des clefs
     * ecrites par un autre processus. Une clef qui ne nomme ni un corps ni une flotte, ou qui
     * porte un identifiant nul, un zero en tete ou une majuscule, n'aurait jamais ete produite
     * ici : elle ne designe personne, et une chronologie batie dessus attribuerait des pertes a un
     * fantome. Le nom reserve des bancs d'essai est admis, puisqu'il sort d'ici aussi.
     *
     * @param string $key
     * @return bool
     */
    public static function isWellFormed(string $key): bool
    {
        if ($key === self::UNIDENTIFIED_BODY || $key === self::EPHEMERAL_ATTACKER) {
            return true;
        }

        $parts = explode(':', $key, 2);

        if (count($parts) !== 2 || !in_array($parts[0], [self::FLEET_PREFIX, self::PLANET_PREFIX], true)) {
            return false;
        }

        return preg_match('/^[1-9][0-9]*$/', $parts[1]) === 1;
    }
}
