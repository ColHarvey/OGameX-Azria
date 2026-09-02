<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;

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
}
