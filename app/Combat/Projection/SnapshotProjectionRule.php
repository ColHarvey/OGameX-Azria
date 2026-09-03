<?php

namespace OGame\Combat\Projection;

/**
 * Comment une inclusion se lit : ce qu'un evenement apporte a la photographie.
 *
 * ## Pourquoi une regle versionnee, et non une constante
 *
 * Une projection ne dit pas ce qu'un combat **decide** — elle dit comment on **lit** ce qui a ete
 * inscrit. La distinction est reelle, mais elle ne justifie pas un second mecanisme de gel : une
 * projection modifie la photographie, donc l'idempotence, donc le resultat persistant du combat.
 *
 * Elle vit donc sous le meme regime que les quatre autres regles : choisie une fois a l'ouverture,
 * persistee avec l'instance, relue par `forVersion()`, et une version inconnue refuse le rejeu
 * plutot que de se rabattre sur la courante.
 */
interface SnapshotProjectionRule
{
    /**
     * La version de cette projection, telle qu'elle est persistee.
     */
    public function version(): string;
}
