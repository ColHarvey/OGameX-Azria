<?php

namespace OGame\Combat\Services;

use OGame\Combat\Support\UnitQueueProduction;

/**
 * Une ligne de file — construction, vaisseaux, defenses, recherche — lue sous verrou.
 *
 * ## Pourquoi un type, et pas la ligne brute
 *
 * `DB::table()->get()` rend des objets sans forme : rien ne dit qu'ils portent `time_start`, et une
 * colonne renommee ne se verrait qu'en jeu. Ce type nomme les quatre faits dont la causalite a
 * besoin — quand l'engagement est devenu irrevocable, quand l'effet tombe, ce que l'effet vaut, et
 * s'il a deja eu lieu — et rien de plus.
 *
 * L'empreinte est celle de **l'effet**, pas de la ligne : `processed` et l'avancement en sont exclus,
 * parce qu'ils disent si l'effet a eu lieu, jamais ce qu'il vaut. Deux lectures d'une meme file, avant
 * et apres son traitement, doivent donner la meme empreinte — sinon la provenance ne reconnaitrait pas
 * a la fermeture ce qu'elle a vu a l'ouverture.
 */
final readonly class QueuedCompletion
{
    public function __construct(
        public int $id,
        public int $decidedAt,
        public int $completedAt,
        public string $effectFingerprint,
        public bool $alreadyApplied,
        public int $objectId = 0,
        public int $amount = 0,
        public int $levelTarget = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $ligne
     */
    /**
     * Les unites d'un lot terminees a un instant, par la formule meme du monde. Une file de
     * batiments ou de recherche n'a pas de quantite : zero.
     */
    public function unitsFinishedBy(int $instant): int
    {
        return UnitQueueProduction::unitsFinishedBy($this->decidedAt, $this->completedAt, $this->amount, $instant);
    }

    /**
     * L'instant ou la derniere unite terminee a `$instant` s'est achevee — le debut si aucune.
     */
    public function lastFinishInstantBy(int $instant): int
    {
        return UnitQueueProduction::finishInstantOf($this->decidedAt, $this->completedAt, $this->amount, $this->unitsFinishedBy($instant));
    }

    public static function fromRow(array $ligne): self
    {
        $faits = $ligne;
        foreach (['processed', 'building', 'time_progress', 'object_amount_progress', 'created_at', 'updated_at'] as $variable) {
            unset($faits[$variable]);
        }
        ksort($faits);

        return new self(
            (int)($ligne['id'] ?? 0),
            (int)($ligne['time_start'] ?? 0),
            (int)($ligne['time_end'] ?? 0),
            hash('sha256', (string)json_encode($faits, JSON_THROW_ON_ERROR)),
            (int)($ligne['processed'] ?? 0) === 1,
            (int)($ligne['object_id'] ?? 0),
            // Une file d'unites produit une quantite ; une file de batiments ou de recherche produit
            // un niveau, et n'ajoute rien a l'effectif. Le champ vaut alors zero, et personne ne le lit.
            (int)($ligne['object_amount'] ?? 0),
            // Une recherche et un batiment atteignent un niveau ; une file d'unites n'en a pas.
            (int)($ligne['object_level_target'] ?? 0),
        );
    }
}
