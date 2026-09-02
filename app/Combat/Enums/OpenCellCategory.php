<?php

namespace OGame\Combat\Enums;

/**
 * Pourquoi une case de la matrice n'est pas une action immediate.
 *
 * ## Ce que cette distinction evite
 *
 * Compter les cases ouvertes ne suffit pas : une pourrait se fermer pendant qu'une autre se
 * rouvre, et le total resterait le meme. Le manifeste est donc **trie et nomme**, et chaque
 * identite porte sa categorie — on constate ce qui bouge, pas seulement combien.
 *
 * ## Trois de ces quatre categories sont des decisions fermees
 *
 * Seule `MissingRule` designe un trou. Les trois autres sont des **directives** : la matrice a
 * decide, et ce qu'elle a decide est de deleguer a un mecanisme nomme. Une delegation ne compte
 * comme tranchee que si son consommateur existe et traite **exhaustivement** ses resultats ;
 * sans cela elle n'est qu'un trou sous un autre nom.
 */
enum OpenCellCategory: string
{
    /**
     * Personne n'a encore decide ce qui doit arriver. Le seul vrai trou.
     */
    case MissingRule = 'missing_rule';

    /**
     * L'admission depend d'un selecteur collectif, sous verrou.
     *
     * Elle ne se lit pas dans une situation : elle depend de faits persistes et partages — la
     * liste figee a l'ouverture, l'alliance de l'initiateur, les budgets d'un camp. Deux workers
     * ne doivent jamais pouvoir prendre ensemble la derniere place.
     */
    case NeedsRallyAdmission = 'needs_rally_admission';

    /**
     * Le resultat depend de l'ordre des evenements, pas de l'etat courant.
     *
     * Un recycleur prevu avant la creation de nouveaux debris ne doit pas les recolter parce que
     * son worker etait en retard ; un missile cree apres l'ouverture malgre le verrou est une
     * anomalie, pas une admission silencieuse.
     */
    case NeedsCausalEligibility = 'needs_causal_eligibility';

    /**
     * La situation ne releve pas de cette matrice, ou ne peut pas se produire.
     *
     * L'espace profond ne porte aucun corps celeste ; un missile n'a pas d'etape de retour. Une
     * entree reelle qui presenterait une telle situation est une violation d'invariant, pas une
     * arrivee a traiter.
     */
    case StructurallyNotApplicable = 'structurally_not_applicable';

    /**
     * Des actifs sont a preserver et aucune destination n'existe.
     *
     * Une decision fermee, mais qui delegue a une recuperation plutot qu'a une regle de jeu. La
     * ranger avec les situations impossibles laisserait croire qu'elle ne se produit jamais ; la
     * ranger avec les actions immediates laisserait croire qu'elle est ordinaire.
     */
    case NeedsAssetRecovery = 'needs_asset_recovery';
}
