<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatEventType;

/**
 * Quand l'effet se produit, et dans quel ordre il s'applique.
 *
 * ## Le seul role de cet ordre
 *
 * Il decide **dans quel ordre** les effets admissibles sont appliques. Il ne decide jamais si un
 * engagement appartient a la photographie : c'est `DecisionOrder` qui s'en charge.
 *
 * ## Pourquoi le genre entre dans la cle
 *
 * Deux effets peuvent tomber a la meme seconde. Le depart doit alors etre **reproductible** et venir
 * de faits persistes : le genre d'evenement d'abord, puis l'identifiant. Un impact de missile et
 * l'achevement d'un chantier a la meme seconde doivent s'appliquer dans le meme ordre a chaque
 * lecture, sinon deux rejeux du meme combat divergent.
 *
 * Le rang par genre est **explicite** plutot que derive du nom : renommer un cas de l'enumeration ne
 * doit pas reordonner silencieusement des effets.
 */
final readonly class EffectOrderKey
{
    /**
     * @param int $effectAt L'instant ou l'effet se produit, en secondes.
     * @param CombatEventType $type Le genre d'evenement.
     * @param int $identifier L'identifiant persiste de l'evenement.
     */
    public function __construct(
        public int $effectAt,
        public CombatEventType $type,
        public int $identifier,
    ) {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                'Un effet sans identifiant persiste ne peut pas etre ordonne de facon reproductible.'
            );
        }
    }

    /**
     * Si l'effet se produit **strictement avant** cet instant.
     *
     * **L'egalite compte pour « apres ».** Un effet prevu a la seconde exacte de la fermeture est
     * exclu, et il l'est **avant meme** qu'on regarde le genre ou l'identifiant : le depart d'egalite
     * ne sert qu'a ordonner ce qui est deja admis.
     *
     * @param int $instant
     * @return bool
     */
    public function isStrictlyBefore(int $instant): bool
    {
        return $this->effectAt < $instant;
    }

    /**
     * La comparaison entre deux cles d'effet.
     *
     * @param self $other
     * @return int
     */
    public function compareTo(self $other): int
    {
        return [$this->effectAt, $this->rankOfType(), $this->identifier]
            <=> [$other->effectAt, $other->rankOfType(), $other->identifier];
    }

    /**
     * Le rang d'un genre d'evenement, a effet simultane.
     *
     * Explicite, et non derive du nom : renommer un cas ne doit pas reordonner des effets.
     */
    private function rankOfType(): int
    {
        return match ($this->type) {
            // Les caracteristiques d'abord : une unite construite a la meme seconde qu'une recherche
            // achevee se bat avec la technologie nouvelle, et non avec l'ancienne.
            CombatEventType::ResearchCompletion => 0,

            // Un chantier acheve avant qu'une flotte ne se pose : les unites construites existent
            // deja quand la livraison arrive, et non l'inverse.
            CombatEventType::QueueCompletion => 1,

            // Un missile frappe des defenses avant qu'une livraison ne s'y ajoute.
            CombatEventType::MissileImpact => 2,

            CombatEventType::FleetArrival => 3,
        };
    }
}
