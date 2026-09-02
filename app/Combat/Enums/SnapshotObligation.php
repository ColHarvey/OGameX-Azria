<?php

namespace OGame\Combat\Enums;

/**
 * Ce que l'arrivee doit encore obtenir au sujet de la photographie.
 *
 * ## Pourquoi une obligation, et pas seulement une interdiction
 *
 * Une premiere version se contentait d'interdire a la matrice de rendre `LandOutsideSnapshot`
 * pendant le ralliement. C'etait juste, et **insuffisant** : interdire un mauvais resultat n'oblige
 * personne a en demander un bon. Un appelant qui ne voyait que `AllowNormally` pouvait inclure ou
 * exclure la flotte de son propre chef, et rien ne le lui reprochait.
 *
 * L'obligation est donc portee **a cote du mouvement**, dans le meme verdict. Un appelant ne peut
 * plus obtenir l'un sans l'autre.
 */
enum SnapshotObligation: string
{
    /**
     * La question ne se pose pas : aucun combat, ou une cible qui n'est pas un corps sous verrou.
     */
    case NotConcerned = 'not_concerned';

    /**
     * Le reconciliateur causal doit trancher la contribution de cette arrivee.
     *
     * La photographie n'est pas prise. Une flotte qui se pose maintenant y figure ou non selon les
     * deux barrieres — engagement irrevocable avant l'ouverture, effet planifie strictement avant la
     * fermeture, une egalite comptant pour « apres ». Ni la matrice ni l'appelant ne les evaluent.
     */
    case RequiresCausalDecision = 'requires_causal_decision';

    /**
     * La question est deja tranchee : la photographie est prise, cette arrivee n'y figure pas.
     *
     * D'ou l'obligation d'appliquer les pertes en **difference sur les unites photographiees**,
     * jamais en remplacant le contenu du corps celeste : sinon ces vaisseaux-la, arrives apres coup,
     * seraient effaces par la resolution.
     */
    case SettledOutsideSnapshot = 'settled_outside_snapshot';
}
