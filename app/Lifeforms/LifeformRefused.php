<?php

namespace OGame\Lifeforms;

use RuntimeException;

/**
 * Un refus des formes de vie, avec sa raison typee.
 *
 * La raison est un code ; le message que le joueur lit est traduit a l affichage
 * (`t_lifeforms.refused.<code>`). Un refus n est jamais une panne : il est attendu, propre, et
 * laisse l etat tel qu il etait.
 */
final class LifeformRefused extends RuntimeException
{
    public const string CLOSED = 'closed';
    public const string ALREADY_CHOSEN = 'already_chosen';
    public const string NO_SPECIES = 'no_species';
    public const string WRONG_SPECIES = 'wrong_species';
    public const string NOT_A_PLANET = 'not_a_planet';
    public const string UNKNOWN_OBJECT = 'unknown_object';
    public const string QUEUE_FULL = 'queue_full';
    public const string REQUIREMENTS_UNMET = 'requirements_unmet';
    public const string POPULATION_UNMET = 'population_unmet';
    public const string INSUFFICIENT_RESOURCES = 'insufficient_resources';
    public const string NOT_IN_QUEUE = 'not_in_queue';
    public const string SLOT_LOCKED = 'slot_locked';
    public const string SLOT_TAKEN = 'slot_taken';
    public const string WRONG_SLOT = 'wrong_slot';
    public const string NO_DISCOVERED_SPECIES = 'no_discovered_species';
    public const string NOT_ENOUGH_ARTIFACTS = 'not_enough_artifacts';
    public const string RESET_TOO_SOON = 'reset_too_soon';
    public const string NOTHING_TO_RESET = 'nothing_to_reset';
    public const string RESEARCH_IN_PROGRESS = 'research_in_progress';
    public const string RESTORE_EXPIRED = 'restore_expired';
    public const string DISCOVERY_LOCKED = 'discovery_locked';
    public const string QUOTA_EXHAUSTED = 'quota_exhausted';
    public const string RECENTLY_EXPLORED = 'recently_explored';
    public const string BAD_COORDINATES = 'bad_coordinates';

    /** La position porte une planete ou une lune du compte : on n explore pas chez soi (decision de Keven, journal §162). */
    public const string OWN_PLANET = 'own_planet';

    /** L objet ne porte qu un effet non applique : il ne se vend pas (journal §155.26). */
    public const string NOT_AVAILABLE = 'not_available';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct(trim("Formes de vie : refus « $reason ». $detail"));
    }

    public function translationKey(): string
    {
        return 't_lifeforms_ui.refused.' . $this->reason;
    }
}
