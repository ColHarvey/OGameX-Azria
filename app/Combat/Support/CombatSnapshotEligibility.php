<?php

namespace OGame\Combat\Support;

/**
 * Ce qui entre dans la photographie du champ de bataille, et selon quels deux instants.
 *
 * ## Le defaut que cette regle empeche
 *
 * Il serait naturel de photographier en relisant l'etat de la cible au moment de la fermeture.
 * C'est faux, et exploitable : le defenseur dispose alors de la duree du ralliement pour depenser
 * ses ressources, lancer des constructions ou faire partir ce qui l'arrange, et **reduire ce qui
 * participera au combat**. La photographie deviendrait une chose que la cible peut retoucher.
 *
 * ## Les deux instants
 *
 * Ils ne servent pas a la meme chose, et c'est toute la regle :
 *
 * - **l'ouverture est la barriere des decisions.** Ce qui compte, c'est ce qui etait deja engage
 *   a cet instant. Une decision prise apres n'entre plus, meme si son effet arrive a temps ;
 * - **la fermeture est le moment ou cette photographie logique se materialise.** Un engagement
 *   pris a temps mais dont l'effet arrive apres la fermeture n'y figure pas non plus.
 *
 *     photographie = etat protege de la cible a l'ouverture
 *                  + l'initiateur du combat
 *                  + effets des engagements pris avant l'ouverture
 *                    et planifies strictement avant la fermeture
 *
 * **L'initiateur est une donnee fondatrice, pas un candidat.** Il ne franchit aucune barriere :
 * il les pose. Le preciser evite une lecture qui serait fausse — « ralliement de duree nulle,
 * donc rien n'entre » ne veut pas dire qu'aucun attaquant n'entre, mais qu'**aucun effet
 * secondaire** ne franchit les barrieres.
 *
 * Les deux conditions sont necessaires, et aucune ne suffit. Un transport lance pendant le
 * ralliement echoue sur la premiere ; un transport lance la veille mais arrivant apres la
 * fermeture echoue sur la seconde.
 *
 * ## Ce que cela donne concretement
 *
 * Entrent : les ressources, la garnison, les defenses et les technologies presentes a
 * l'ouverture ; un retour, un deploiement ou un transport lance avant l'ouverture et arrivant
 * avant la fermeture ; une Defense ACS deja en vol et retenue ; un missile deja lance frappant
 * avant la fermeture ; une construction ou une recherche engagee avant l'ouverture et terminee
 * avant la fermeture.
 *
 * N'entrent pas : la production posterieure a l'ouverture ; une construction ou une recherche
 * commencee apres ; une flotte produite apres ; un transport ou un retour arrivant a la fermeture
 * ou apres ; toute acceleration, annulation ou nouvelle decision prise apres la barriere.
 *
 * Une construction commencee apres l'ouverture continue normalement — le jeu ne s'arrete pas —
 * mais ses unites sont **posterieures a la photographie** et survivent a ce combat. Elles restent
 * neanmoins immobilisees par le verrou jusqu'a la resolution.
 *
 * ## Pourquoi une sequence plutot qu'un horodatage
 *
 * Deux evenements peuvent porter la meme seconde. Comparer des horodatages laisserait alors
 * l'ordre au hasard du worker qui traite en premier. La comparaison se fait donc sur une
 * **sequence monotone** : celle qui gagne le verrou en premier appartient au passe admissible,
 * l'autre est posterieure. Les parametres portent des entiers pour cette raison — c'est un rang,
 * pas une horloge.
 */
final class CombatSnapshotEligibility
{
    /**
     * Si un engagement et son effet tombent tous deux du bon cote des deux barrieres.
     *
     * **L'initiateur est une exception, et elle est explicite.** La flotte qui ouvre le combat
     * n'est pas une candidate parmi d'autres : c'est la donnee fondatrice. Sans elle il n'y a pas
     * de bataille du tout. Elle ne franchit donc aucune barriere — elle les pose.
     *
     * Le cas se voit surtout quand le ralliement est de duree nulle, celui de l'attaquant isole :
     * ouverture et fermeture coincident, aucun effet secondaire ne peut entrer, et il serait
     * absurde d'en conclure que l'attaquant lui-meme n'y est pas.
     *
     * Le drapeau est un parametre obligatoire plutot qu'un cas particulier laisse a l'appelant :
     * on ne peut pas oublier de se poser la question.
     *
     * @param bool $isCombatOpener Si cet engagement est celui qui a ouvert le combat.
     * @param int $decisionOrder Rang de l'engagement : le moment ou la decision est devenue
     *                           irrevocable. Doit preceder strictement l'ouverture.
     * @param EffectOrderKey $effect Quand l'effet doit logiquement se produire — heure planifiee
     *                               et departage. Doit preceder strictement la fermeture.
     * @param int $openingOrder Rang de l'ouverture du ralliement, barriere des decisions.
     * @param EffectOrderKey $closure Cle de la fermeture, ou la photographie se materialise.
     * @return bool
     */
    public static function entersSnapshot(
        bool $isCombatOpener,
        int $decisionOrder,
        EffectOrderKey $effect,
        int $openingOrder,
        EffectOrderKey $closure,
    ): bool {
        if ($isCombatOpener) {
            return true;
        }

        return self::wasCommittedBeforeTheBarrier($decisionOrder, $openingOrder)
            && self::takesEffectBeforeTheSnapshot($effect, $closure);
    }

    /**
     * Si la decision etait deja prise quand le combat s'est ouvert.
     *
     * Strictement avant : une decision prise a l'instant meme de l'ouverture est posterieure. La
     * borne est fermee du meme cote que celle de la fenetre de ralliement, pour qu'il n'y ait
     * qu'une convention a retenir dans tout le systeme.
     *
     * @param int $committedAt
     * @param int $openedAt
     * @return bool
     */
    public static function wasCommittedBeforeTheBarrier(int $committedAt, int $openedAt): bool
    {
        return $committedAt < $openedAt;
    }

    /**
     * Si l'effet se produit avant que la photographie ne soit prise.
     *
     * La comparaison porte sur l'heure planifiee puis le departage — jamais sur l'heure a
     * laquelle un worker traite l'evenement.
     *
     * @param EffectOrderKey $effect
     * @param EffectOrderKey $closure
     * @return bool
     */
    public static function takesEffectBeforeTheSnapshot(EffectOrderKey $effect, EffectOrderKey $closure): bool
    {
        return $effect->isBefore($closure);
    }
}
