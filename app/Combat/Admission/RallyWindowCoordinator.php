<?php

namespace OGame\Combat\Admission;

use InvalidArgumentException;

/**
 * L'heure de fermeture, tiree des deux camps a la fois.
 *
 * ## Deux selecteurs, une fenetre
 *
 * Les politiques d'admission sont separees — l'attaque et la defense n'ont ni les memes regles ni
 * les memes budgets — mais un combat n'a qu'une fermeture. Chaque camp propose sa derniere arrivee
 * admise ; le coordinateur en tire une echeance unique :
 *
 *     fermeture = min(ouverture + 60, derniere arrivee admise des deux camps + 1)
 *
 * Le **tick** ajoute donne a la derniere arrivee le temps d'entrer : sans lui, une candidate
 * arrivant exactement a la fermeture serait exclue par la barriere qu'elle vient de creer.
 *
 * ## Aucune candidate, aucune attente
 *
 * S'il n'existe aucune candidate admise dans l'un ni l'autre camp, **fermeture = ouverture**. La
 * fenetre nulle n'est pas un cas particulier : c'est le cas ou la fermeture se deroule dans la
 * transaction de l'initiateur, sans worker intermediaire.
 *
 * ## Une candidate rappelee peut raccourcir
 *
 * Elle est retiree des admises, et l'echeance se recalcule sur ce qui reste. Elle ne **promeut**
 * jamais une candidate deja exclue : la place qu'elle libere ne se redistribue pas sans une decision
 * de jeu explicite, qui n'existe pas aujourd'hui.
 */
final class RallyWindowCoordinator
{
    /**
     * Le tick ajoute apres la derniere arrivee admise, en secondes.
     */
    public const int TICK_SECONDS = 1;

    /**
     * L'heure de fermeture d'un ralliement.
     *
     * @param int $openedAt L'instant d'ouverture, en secondes.
     * @param AdmissionVerdict $attack Le verdict du camp attaquant.
     * @param AdmissionVerdict $defence Le verdict du camp defenseur.
     * @return int
     */
    public function closingInstant(int $openedAt, AdmissionVerdict $attack, AdmissionVerdict $defence): int
    {
        if ($attack->openedAt !== $openedAt || $defence->openedAt !== $openedAt) {
            throw new InvalidArgumentException(
                'Les deux camps doivent avoir ete selectionnes pour la meme ouverture : deux instants '
                . 'differents donneraient deux fenetres pour un seul combat.'
            );
        }

        $derniere = null;

        foreach ([$attack->latestAdmittedArrival(), $defence->latestAdmittedArrival()] as $proposition) {
            if ($proposition !== null && ($derniere === null || $proposition > $derniere)) {
                $derniere = $proposition;
            }
        }

        if ($derniere === null) {
            return $openedAt;
        }

        return min(
            $openedAt + AttackAdmissionSelector::MAX_WINDOW_SECONDS,
            $derniere + self::TICK_SECONDS
        );
    }
}
