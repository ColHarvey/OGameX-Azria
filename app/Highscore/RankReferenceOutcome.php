<?php

namespace OGame\Highscore;

/**
 * L issue d une tentative de rotation de la reference du classement.
 *
 * Trois des cinq issues sont des refus, et aucune n est une panne : la tache des rangs les journalise
 * et continue. La derniere reference valide survit a toutes.
 */
enum RankReferenceOutcome
{
    /** Une nouvelle reference a remplace l ancienne, entierement, dans une seule transaction. */
    case Published;

    /** La journee serveur courante a deja sa reference. Rien n est ecrit, rien n est perdu. */
    case NotDue;

    /**
     * Un classement lu n etait pas une suite complete de 1 a N : un passage des rangs s est
     * interrompu, ou deux generateurs se sont marches dessus. Publier cela figerait une photographie
     * fausse pour une journee entiere.
     */
    case RefusedIncoherentRanking;

    /** Aucune categorie ne porte de rang : il n y a rien a photographier, et la journee reste due. */
    case RefusedNothingToPublish;

    /** La ligne d etat n existe pas. La migration la cree ; son absence est une anomalie a dire. */
    case StateMissing;

    public function message(): string
    {
        return match ($this) {
            self::Published => 'Reference du classement publiee.',
            self::NotDue => 'Reference du classement : deja publiee pour la journee serveur en cours.',
            self::RefusedIncoherentRanking => 'Reference du classement refusee : un classement lu n etait pas complet.',
            self::RefusedNothingToPublish => 'Reference du classement : aucun rang a photographier.',
            self::StateMissing => 'Reference du classement : ligne d etat absente.',
        };
    }
}
