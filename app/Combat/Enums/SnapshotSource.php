<?php

namespace OGame\Combat\Enums;

/**
 * D'ou vient ce qui entre dans la photographie.
 *
 * **Cette dimension existe a cause d'un trou reel.** Une premiere version n'exigeait, pour
 * retenir la fenetre ouverte, qu'une contribution de type flotte combattante. Or `DefendingFleet`
 * designe aussi bien une Defense ACS candidate qu'un retour personnel charge : rien n'empechait
 * donc un retour de maintenir la fenetre ouverte **pour s'y inclure lui-meme**.
 *
 * La provenance leve l'ambiguite. Une flotte peut prolonger le ralliement seulement si elle etait
 * candidate au depart et a survecu a la selection collective — pas parce qu'elle se trouve du bon
 * cote au bon moment.
 */
enum SnapshotSource: string
{
    /**
     * Une candidate au ralliement, retenue par la selection collective de son camp.
     *
     * La seule provenance qui puisse retenir la fenetre ouverte.
     */
    case SelectedRallyCandidate = 'selected_rally_candidate';

    /**
     * Ce qui etait deja la : garnison, defenses au sol, ressources de la cible.
     *
     * Rien de tout cela n'est attendu — c'est present, donc photographie.
     */
    case ExistingTargetState = 'existing_target_state';

    /**
     * Une arrivee de passage : retour personnel, deploiement, transport.
     *
     * Elle entre dans la photographie si elle arrive avant une echeance **deja fixee par
     * d'autres**, mais elle ne contribue jamais a fixer cette echeance.
     */
    case IncidentalArrival = 'incidental_arrival';
}
