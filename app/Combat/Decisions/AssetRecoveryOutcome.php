<?php

namespace OGame\Combat\Decisions;

use InvalidArgumentException;

/**
 * Ou sont passes les actifs d'une flotte qui n'avait nulle part ou aller.
 *
 * ## Le cas que cette classe recoit
 *
 * `ArrivalDecision::requiresAssetRecovery()` est prononce quand les recours ordonnes du jeu sont
 * epuises — corps d'origine detruit, planete associee disparue, planete mere introuvable — et que
 * la flotte transporte quelque chose. L'annuler la supprimerait avec sa cargaison ; la laisser en
 * vol la ferait tourner indefiniment.
 *
 * **Le cas ne devrait pas se produire**, et c'est exactement pourquoi il lui faut un receveur. Une
 * delegation sans consommateur n'est pas une decision, c'est un trou sous un autre nom.
 *
 * ## Un echec de recuperation n'est pas une valeur ici
 *
 * S'il n'existe aucun corps ou deposer les actifs, le compte n'a plus de corps du tout — ce qui
 * contredit les regles du jeu, ou signale une base abimee. Le consommateur leve alors plutot que
 * de produire un resultat : perdre silencieusement une flotte chargee est precisement ce qu'on
 * cherche a rendre impossible.
 */
final readonly class AssetRecoveryOutcome
{
    /**
     * @param int $bodyId Le corps ou les actifs ont ete deposes.
     */
    private function __construct(public int $bodyId)
    {
        if ($bodyId < 1) {
            throw new InvalidArgumentException(
                'Une recuperation depose les actifs sur un corps persiste : sans identifiant, rien ne prouve '
                . 'qu ils existent encore apres.'
            );
        }
    }

    /**
     * Les actifs ont ete deposes sur ce corps.
     */
    public static function recoveredInto(int $bodyId): self
    {
        return new self($bodyId);
    }
}
