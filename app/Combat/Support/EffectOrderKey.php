<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatEventType;

/**
 * Quand l'effet d'un engagement doit logiquement se produire.
 *
 * **A ne pas confondre avec l'ordre des decisions.** Ce sont deux classements distincts, et les
 * melanger produirait des resultats faux dans les deux sens :
 *
 * - **l'ordre des decisions** dit quand un engagement est devenu irrevocable. Il tranche ce qui
 *   appartient au passe admissible au moment ou le combat s'ouvre ;
 * - **l'ordre des effets**, celui-ci, dit quand la chose arrive. Il tranche ce qui figure dans la
 *   photographie.
 *
 * Une mission creee en premier peut parfaitement etre prevue **apres** une mission creee plus
 * tard : un transport lent lance a midi arrive apres un transport rapide lance a treize heures.
 * Classer les effets par rang de creation inverserait leur ordre reel.
 *
 * ## Un ordre reellement total
 *
 * L'heure planifiee decide. Mais deux evenements peuvent tomber sur la meme seconde — la
 * precision metier du jeu est la seconde — et il faut alors un depart **qui ne puisse jamais etre
 * une egalite**.
 *
 * Un identifiant seul n'y suffit pas : un missile numero douze et une construction numero douze
 * viennent de tables distinctes, dont les espaces se recouvrent. La cle porte donc trois
 * composantes, comparees dans cet ordre :
 *
 *     (heure planifiee, rang du type d'evenement, identifiant de source)
 *
 * Sans cela, l'ordre de deux evenements simultanes dependrait du worker qui prend le verrou en
 * premier, c'est-a-dire du hasard de la charge serveur — et rejouer les memes evenements dans un
 * autre ordre donnerait un autre resultat.
 *
 * ## Les barrieres ne sont pas des evenements
 *
 * Une ouverture, une fermeture n'ont ni type ni identifiant de source. Elles portent le rang zero,
 * ce qui les place **avant** tout evenement reel de la meme seconde. C'est exactement la
 * convention retenue partout ailleurs : ce qui tombe pile sur une barriere lui est posterieur.
 *
 * Un evenement reel ne peut donc jamais porter le rang zero, et le constructeur le refuse.
 */
final readonly class EffectOrderKey
{
    /**
     * @param int $plannedAt Heure planifiee de l'effet, en secondes. **Jamais l'heure a laquelle
     *                       un worker le traite** : un retard du serveur ne doit pas reclasser
     *                       les evenements.
     * @param int $typeRank Rang du type d'evenement. Zero pour une barriere, strictement positif
     *                      pour tout evenement reel.
     * @param int $sourceId Identifiant de la mission, du missile ou de la file, stable et
     *                      persiste.
     */
    private function __construct(
        public int $plannedAt,
        public int $typeRank,
        public int $sourceId,
    ) {
    }

    /**
     * La cle d'un evenement reel.
     *
     * @param int $plannedAt
     * @param CombatEventType $type
     * @param int $sourceId
     * @return self
     */
    public static function forEvent(int $plannedAt, CombatEventType $type, int $sourceId): self
    {
        if ($sourceId <= 0) {
            throw new InvalidArgumentException(
                'Un evenement doit porter un identifiant de source strictement positif : sans lui, deux evenements simultanes du meme type seraient a egalite.'
            );
        }

        return new self($plannedAt, $type->rank(), $sourceId);
    }

    /**
     * La cle d'une barriere : ni type, ni source.
     *
     * @param int $plannedAt
     * @return self
     */
    public static function barrierAt(int $plannedAt): self
    {
        return new self($plannedAt, 0, 0);
    }

    /**
     * Si cet effet precede strictement l'autre.
     *
     * Comparaison lexicographique sur les trois composantes.
     *
     * @param self $other
     * @return bool
     */
    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Comparaison a trois voies, pour trier une liste d'evenements.
     *
     * @param self $other
     * @return int
     */
    public function compareTo(self $other): int
    {
        return [$this->plannedAt, $this->typeRank, $this->sourceId]
            <=> [$other->plannedAt, $other->typeRank, $other->sourceId];
    }

    /**
     * Si les deux cles designent le meme instant logique.
     *
     * Deux evenements reels distincts ne peuvent jamais etre egaux : c'est ce qui fait de ce
     * classement un ordre total.
     *
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * Si cette cle designe une barriere plutot qu'un evenement.
     *
     * @return bool
     */
    public function isBarrier(): bool
    {
        return $this->typeRank === 0;
    }
}
