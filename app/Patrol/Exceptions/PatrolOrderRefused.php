<?php

namespace OGame\Patrol\Exceptions;

use RuntimeException;

/**
 * Un ordre de patrouille refuse, avec la raison que le joueur lira.
 *
 * La raison est une **clef**, pas une phrase : elle se traduit la ou elle est montree
 * (`t_ingame.patrol.refusal_*`), et elle traverse les couches sans se figer dans une langue.
 * Le message technique, lui, sert au journal et aux essais.
 */
final class PatrolOrderRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Ordre de patrouille refuse : ' . $reason . '.');
    }
}
