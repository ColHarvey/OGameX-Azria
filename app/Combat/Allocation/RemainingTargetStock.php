<?php

namespace OGame\Combat\Allocation;

use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Models\Planet;

/**
 * Ce que la cible porte encore, relu sous verrou et converti une fois en unites entieres.
 *
 * ## La seconde moitie de la regle
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Le restant est le seul fait que le reglement relit dans le monde courant — et c'est voulu : le
 * defenseur a eu le droit de depenser pendant le combat, et ce qu'il a depense n'est plus la. Tout le
 * reste vient des faits geles.
 *
 * ## Ce que cette classe fait, et ce qu'elle laisse a l'orchestrateur
 *
 * Elle convertit une ligne de planete en montants exacts. **Elle ne la verrouille pas** : c'est
 * l'orchestrateur qui prend le verrou, dans l'ordre global — barriere, instance, union, missions,
 * puis la cible — et lui passe la ligne qu'il tient. Une classe qui verrouillerait elle-meme
 * deciderait de l'ordre a la place de celui qui connait les autres verrous.
 *
 * ## La frontiere des soldes vivants, pas celle des faits geles
 *
 * Un solde de planete est un `double`, et il peut porter un artefact de moins d'une unite sous zero,
 * laisse par un arrondi de production. `wholeUnitsOfLivingStock()` le ramene a zero et le dit ; un
 * negatif materiel, lui, reste un refus. C'est la tolerance qu'un fait gele n'a pas, et c'est ici
 * qu'elle a sa place : on lit le monde, pas un resultat.
 *
 * Les diagnostics de conversion voyagent a cote des montants, jamais dedans.
 */
final readonly class RemainingTargetStock
{
    /**
     * Le moment fonctionnel de la conversion, pour situer un diagnostic.
     */
    public const string PHASE = 'remaining_stock';

    /**
     * @param ExactLootAmounts $amounts Ce que la cible porte, en unites entieres.
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que la conversion a rencontre.
     */
    private function __construct(
        public ExactLootAmounts $amounts,
        public ResourceNormalizationDiagnostics $diagnostics,
    ) {
    }

    /**
     * Le restant lu sur cette ligne, que l'appelant tient sous verrou.
     *
     * @param Planet $lockedRow La ligne relue **apres** acquisition du verrou. Une ligne lue avant
     *                          porterait un solde que le verrou n'a pas protege.
     * @param string $subject Le corps concerne, pour situer un diagnostic.
     */
    public static function readFrom(Planet $lockedRow, string $subject = ''): self
    {
        $diagnostics = ResourceNormalizationDiagnostics::none();
        $entiers = [];

        foreach (['metal', 'crystal', 'deuterium'] as $composante) {
            $normalise = ResourceBoundary::wholeUnitsOfLivingStock(
                (float)$lockedRow->{$composante},
                $composante,
                self::PHASE,
                $subject
            );

            $entiers[] = $normalise->units;
            $diagnostics = $diagnostics->mergedWith($normalise->diagnostics);
        }

        return new self(ExactLootAmounts::of(...$entiers), $diagnostics);
    }
}
