<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;
use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Exceptions\MismatchedCausalEventOrder;

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
 * L'heure planifiee decide. Mais deux evenements peuvent tomber sur la meme seconde — la precision
 * metier du jeu est la seconde — et il faut alors un depart **qui ne puisse jamais etre une
 * egalite** :
 *
 *     (heure planifiee, rang du genre, identifiant de source)
 *
 * Un identifiant seul n'y suffit pas : un missile numero douze et une construction numero douze
 * viennent de tables distinctes, dont les espaces se recouvrent.
 *
 * ## Les barrieres ne sont pas des evenements
 *
 * Une ouverture, une fermeture n'ont ni genre ni identifiant de source. Elles portent le rang zero,
 * ce qui les place **avant** tout evenement reel de la meme seconde. C'est la convention retenue
 * partout ailleurs : ce qui tombe pile sur une barriere lui est posterieur. Un evenement reel ne
 * peut donc jamais porter le rang zero, et le constructeur le refuse.
 *
 * ## Trois regles invariantes, une seule versionnee
 *
 *     heure planifiee d'abord           -> invariant
 *     barriere avant tout evenement     -> invariant
 *     departage par identite persistee  -> invariant
 *     ordre entre genres                -> **versionne**
 *
 * La cle porte donc la version de l'ordre qui l'a produite, et **deux cles de versions differentes
 * refusent d'etre comparees**. Un rang 2 sous v1 et un rang 2 sous v2 ne designent pas le meme
 * genre : les mettre sur la meme echelle donnerait un resultat plausible et faux.
 */
final readonly class EffectOrderKey
{
    /**
     * @param int $plannedAt Heure planifiee de l'effet, en secondes. **Jamais l'heure a laquelle
     *                       un worker le traite** : un retard du serveur ne doit pas reclasser
     *                       les evenements.
     * @param int $typeRank Rang du genre d'evenement. Zero pour une barriere, strictement positif
     *                      pour tout evenement reel.
     * @param int $sourceId Identifiant de la mission, du missile ou de la file, stable et persiste.
     * @param string $orderVersion La version de l'ordre causal qui a produit ce rang.
     */
    private function __construct(
        public int $plannedAt,
        public int $typeRank,
        public int $sourceId,
        public string $orderVersion,
    ) {
    }

    /**
     * La cle d'un evenement reel.
     *
     * @param int $plannedAt
     * @param CombatEventType $type
     * @param int $sourceId
     * @param CausalEventOrder $order L'ordre **du combat**, relu depuis sa version persistee — jamais
     *                                la version courante prise au vol par un worker.
     * @return self
     */
    public static function forEvent(
        int $plannedAt,
        CombatEventType $type,
        int $sourceId,
        CausalEventOrder $order,
    ): self {
        if ($sourceId <= 0) {
            throw new InvalidArgumentException(
                'Un evenement doit porter un identifiant de source strictement positif : sans lui, deux evenements simultanes du meme genre seraient a egalite.'
            );
        }

        $rang = $order->rankOf($type);

        if ($rang <= 0) {
            throw new InvalidArgumentException(
                'L ordre « ' . $order->version() . ' » attribue le rang ' . $rang . ' au genre « ' . $type->value
                . ' ». Zero et les negatifs appartiennent aux barrieres : un evenement qui les porterait se '
                . 'placerait avant une fermeture tombant a la meme seconde.'
            );
        }

        return new self($plannedAt, $rang, $sourceId, $order->version());
    }

    /**
     * La cle d'une barriere : ni genre, ni source.
     *
     * Elle porte quand meme la version, pour qu'une barriere v1 ne se compare jamais a un evenement
     * v2. Le rang zero, lui, est invariant : c'est ce qui fait qu'une egalite avec une barriere
     * compte pour « apres », dans toutes les versions.
     *
     * @param int $plannedAt
     * @param CausalEventOrder $order
     * @return self
     */
    public static function barrierAt(int $plannedAt, CausalEventOrder $order): self
    {
        return new self($plannedAt, 0, 0, $order->version());
    }

    /**
     * Si cet effet precede strictement l'autre.
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
        $this->ensureSameOrderAs($other);

        return [$this->plannedAt, $this->typeRank, $this->sourceId]
            <=> [$other->plannedAt, $other->typeRank, $other->sourceId];
    }

    /**
     * Si les deux cles designent le meme instant logique.
     *
     * Deux evenements reels distincts ne peuvent jamais etre egaux : c'est ce qui fait de ce
     * classement un ordre total.
     */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * Si cette cle designe une barriere plutot qu'un evenement.
     */
    public function isBarrier(): bool
    {
        return $this->typeRank === 0;
    }

    /**
     * Refuse de comparer deux cles produites sous des ordres differents.
     *
     * @param self $other
     * @return void
     */
    private function ensureSameOrderAs(self $other): void
    {
        if ($this->orderVersion === $other->orderVersion) {
            return;
        }

        throw new MismatchedCausalEventOrder(
            'Une cle d effet de « ' . $this->orderVersion . ' » a ete comparee a une cle de « '
            . $other->orderVersion . ' ». Les rangs de genre ne veulent pas dire la meme chose d une version '
            . 'a l autre : un combat ouvert sous une version se rejoue sous cette version-la, entierement.'
        );
    }
}
