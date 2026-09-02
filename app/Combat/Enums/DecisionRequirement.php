<?php

namespace OGame\Combat\Enums;

/**
 * Ce qu'il reste a consommer avant qu'une decision soit finale.
 *
 * ## Pourquoi ce n'est pas un `CombatReasonCode`
 *
 * Un `CombatReasonCode` finit sous les yeux d'un joueur : « fenetre fermee », « limite de flottes
 * atteinte », « position occupee ». Une exigence interne n'a rien a y faire — « admission en
 * attente » ne dit rien a personne, et l'ecrire dans un rapport reviendrait a publier un etat
 * intermediaire du serveur.
 *
 * Les melanger avait un cout precis : **aucun code d'attente ne doit survivre dans un
 * `FinalArrivalResolution`**, et tant qu'ils habitaient la meme enumeration, rien n'empechait
 * mecaniquement l'un de passer pour l'autre.
 */
enum DecisionRequirement: string
{
    /**
     * Le selecteur collectif du camp doit encore prononcer l'admission, sous verrou.
     *
     * Elle depend de faits persistes et partages — la liste figee a l'ouverture, l'alliance de
     * l'initiateur, les budgets du camp. Deux workers ne doivent jamais prendre ensemble la
     * derniere place.
     */
    case RallyAdmission = 'rally_admission';

    /**
     * L'ordre des evenements doit encore trancher ce que cet evenement peut toucher.
     *
     * Un recycleur prevu avant la creation de nouveaux debris ne les recolte pas parce que son
     * worker etait en retard ; un missile engage apres l'ouverture malgre le verrou est une
     * anomalie, pas une admission silencieuse.
     */
    case CausalOrder = 'causal_order';
}
