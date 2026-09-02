<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;
use OGame\Combat\Support\EffectOrderKey;

/**
 * Les deux barrieres du ralliement.
 *
 * ## La regle, en une phrase
 *
 * Un effet appartient a la photographie si son engagement etait irrevocable **strictement avant**
 * l'ouverture et si son effet est prevu **strictement avant** la fermeture.
 *
 *     decision  < ouverture     (strict)
 *     effet     < fermeture     (strict)
 *
 * **Une egalite avec une barriere compte pour « apres ».** Sans cette convention, le sort d'un
 * evenement tombant a la seconde exacte d'une barriere dependrait d'une course entre deux workers,
 * et deux lectures du meme combat donneraient deux photographies.
 *
 * ## La fenetre nulle
 *
 * `fermeture == ouverture` est legitime : c'est le cas ou la fermeture se deroule dans la
 * transaction de l'initiateur, sans worker intermediaire. Aucun effet exterieur n'y est admis — mais
 * l'initiateur, lui, est un fait fondateur verifie separement, et il survit.
 */
final readonly class CausalWindow
{
    /**
     * @param int $openedAt L'instant d'ouverture du ralliement, en secondes.
     * @param int $closesAt L'instant de fermeture. Egal a l'ouverture pour une fenetre nulle.
     * @param CausalEventOrder $order L'ordre causal **du combat**, relu depuis sa version
     *                                persistee. Jamais la version courante prise au vol : un
     *                                worker en retard reordonnerait une ouverture deja fixee.
     */
    public function __construct(
        public int $openedAt,
        public int $closesAt,
        public CausalEventOrder $order,
    ) {
        if ($closesAt < $openedAt) {
            throw new InvalidArgumentException(
                'Une fenetre de ralliement ne se ferme pas avant de s ouvrir : ' . $closesAt . ' precede '
                . $openedAt . '.'
            );
        }
    }

    /**
     * Si un engagement etait irrevocable avant l'ouverture.
     */
    public function admitsDecision(DecisionOrder $decision): bool
    {
        return $decision->isStrictlyBefore($this->openedAt);
    }

    /**
     * Si un effet est prevu avant la fermeture.
     */
    public function admitsEffect(EffectOrderKey $effect): bool
    {
        // **La fermeture est une barriere, pas un evenement.** Elle porte le rang zero, ce qui la
        // place avant tout evenement reel de la meme seconde : un effet prevu pile a la fermeture
        // lui est donc posterieur, et exclu. C'est la convention deja retenue partout ailleurs, et
        // la reprendre evite d'en ecrire une seconde qui finirait par en differer.
        return $effect->isBefore(EffectOrderKey::barrierAt($this->closesAt, $this->order));
    }

    /**
     * Si la fenetre est nulle : la fermeture se deroule dans la transaction de l'initiateur.
     */
    public function isInstantaneous(): bool
    {
        return $this->closesAt === $this->openedAt;
    }
}
