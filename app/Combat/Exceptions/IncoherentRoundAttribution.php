<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * Un round dont les pertes par flotte ne recouvrent pas les pertes du camp.
 *
 * ## Ce que cela veut dire
 *
 * Chaque moteur attribue chaque vaisseau detruit a la flotte qui le portait. Si la somme de ces
 * attributions n'est pas exactement la perte du camp pour ce round, le moteur a perdu des
 * vaisseaux sans dire de qui — ou en a attribue qui n'ont pas ete perdus. Une chronologie batie
 * sur ce round mentirait a un defenseur sur ce qu'il a perdu, et un banc de parite comparerait
 * des projections dont l'une est incomplete.
 *
 * ## Pourquoi un refus, et non un complement
 *
 * Completer en versant le reste a la garnison rendrait le defaut invisible : c'est exactement ce
 * qu'un moteur qui ne suit pas les flottes produirait, et rien ne le distinguerait d'un moteur
 * juste. Le resultat ne se produit pas ; l'appelant voit le moteur et le round fautifs.
 */
final class IncoherentRoundAttribution extends LogicException
{
    /**
     * @param array<string, int> $attribue Les pertes attribuees, par nom d'unite.
     * @param array<string, int> $perdu Les pertes du camp, par nom d'unite.
     */
    public static function inRound(int $round, string $side, array $attribue, array $perdu): self
    {
        return new self(sprintf(
            'Round %d, camp %s : les pertes attribuees par flotte (%s) ne sont pas les pertes du camp (%s).',
            $round,
            $side,
            self::describe($attribue),
            self::describe($perdu)
        ));
    }

    /**
     * @param array<string, int> $unites
     */
    private static function describe(array $unites): string
    {
        if ($unites === []) {
            return 'aucune';
        }

        ksort($unites);
        $parts = [];

        foreach ($unites as $nom => $montant) {
            $parts[] = $nom . ' x' . $montant;
        }

        return implode(', ', $parts);
    }
}
