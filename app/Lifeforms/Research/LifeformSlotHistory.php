<?php

namespace OGame\Lifeforms\Research;

use OGame\Models\Lifeforms\LifeformSlotChange;

/**
 * Ce que les emplacements de recherche d une planete portaient **a un instant donne**.
 *
 * ## Une ligne par changement, jamais une deduction
 *
 * `LifeformResearchService` ecrit une ligne a chaque fois qu un emplacement change de main : choix
 * d une technologie, remise a zero d un palier (une ligne vide par emplacement), restauration. Lire
 * l occupation a un instant, c est alors prendre pour chaque emplacement **la derniere ligne dont
 * `from_at` precede cet instant** — exact par construction, et lisible sans raisonner par cas.
 *
 * Le gel d un combat en a besoin : un travailleur traite une arrivee bien apres l avoir datee, et ce
 * que le joueur fait entre les deux ne doit ni armer ni desarmer cette flotte. Une remise a zero du
 * palier faite apres l arrivee la desarmait (revue de Codex, journal §155.10).
 *
 * ## Ce qui reste hors de portee, et qui est dit
 *
 * Un emplacement ne compte que s il est **ouvert**, c est-a-dire si la population de son palier atteint
 * son exigence. La population, elle, n a pas d historique — l horloge demographique ne se remonte pas —
 * et l ouverture se juge donc sur la population **courante**. De meme pour l experience d une espece,
 * qui bouge par les decouvertes. Les deux sont dits plutot que devines.
 */
final class LifeformSlotHistory
{
    /**
     * Inscrit ce que cet emplacement porte a partir de cet instant. `$objectId` nul dit « vide ».
     *
     * Rejouer exactement la meme decision au meme instant n ajoute rien : la fermeture d une transaction
     * reprise ne doit pas doubler les lignes.
     */
    public function record(int $planetId, int $slot, int|null $objectId, int $at): void
    {
        $derniere = LifeformSlotChange::query()
            ->where('planet_id', $planetId)
            ->where('slot', $slot)
            ->latest('from_at')
            ->orderByDesc('id')
            ->first();
        if ($derniere !== null && (int)$derniere->from_at === $at && $derniere->object_id === $objectId) {
            return;
        }

        LifeformSlotChange::query()->create([
            'planet_id' => $planetId,
            'slot' => $slot,
            'object_id' => $objectId,
            'from_at' => $at,
        ]);
    }

    /**
     * L occupation des emplacements de la planete a cet instant : identifiant de technologie par numero
     * d emplacement, les emplacements vides omis.
     *
     * @return array<int, int>
     */
    public function occupantsAt(int $planetId, int $at): array
    {
        $lignes = LifeformSlotChange::query()
            ->where('planet_id', $planetId)
            ->where('from_at', '<=', $at)
            ->oldest('from_at')
            ->orderBy('id')
            ->get(['slot', 'object_id']);

        $occupation = [];
        foreach ($lignes as $ligne) {
            // Les lignes viennent dans l ordre du temps : la derniere lue pour un emplacement est la sienne.
            $occupation[(int)$ligne->slot] = $ligne->object_id === null ? null : (int)$ligne->object_id;
        }

        $resultat = [];
        foreach ($occupation as $slot => $objet) {
            if ($objet !== null) {
                $resultat[$slot] = $objet;
            }
        }

        return $resultat;
    }
}
