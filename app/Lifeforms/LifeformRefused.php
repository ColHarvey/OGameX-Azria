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

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct(trim("Formes de vie : refus « $reason ». $detail"));
    }

    public function translationKey(): string
    {
        return 't_lifeforms_ui.refused.' . $this->reason;
    }
}
