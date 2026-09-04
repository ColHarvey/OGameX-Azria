<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * L'effectif annonce un renfort dont la mission n'existe pas au moment de photographier.
 *
 * ## Pourquoi ce n'est pas une flotte vide
 *
 * La photographie d'application gele la cargaison de chaque renfort retenu. Le contexte vivant
 * rendait une cargaison **nulle** quand la mission demandee ne se relisait pas : la photographie
 * inscrivait alors la clef attendue avec des zeros, et le controle de couverture passait — il prouve
 * que toutes les clefs de l'effectif figurent dans le document, pas que la source vivante de chacune
 * existait.
 *
 * Une flotte annoncee par l'effectif et introuvable en base n'est pas une flotte qui ne porte rien.
 * C'est une incoherence entre l'effectif et les missions, et geler des zeros a sa place ferait
 * disparaitre une cargaison reelle sans que rien ne le dise. La cloture s'arrete donc avant toute
 * ecriture : aucune photographie partielle n'est enregistree.
 *
 * **A ne pas confondre avec une cargaison reellement nulle.** Une mission presente qui ne porte rien
 * est un fait ordinaire, et elle se gele a zero comme n'importe quelle autre valeur.
 */
class MissingHeldFleetCargo extends RuntimeException
{
    /**
     * @param int $fleetMissionId
     * @return self
     */
    public static function because(int $fleetMissionId): self
    {
        return new self(
            'L effectif annonce le renfort ' . $fleetMissionId . ' mais sa mission ne se relit pas : sa cargaison '
            . 'ne peut pas etre gelee. Inscrire zero a sa place ferait disparaitre ce qu il porte, et la couverture '
            . 'de la photographie n y verrait rien.'
        );
    }
}
