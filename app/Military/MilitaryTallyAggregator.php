<?php

namespace OGame\Military;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute au compteur de chaque compte les événements appliqués qu'il ne compte pas encore — une fois chacun.
 *
 * ## Deux lectures, qui ne font pas le même travail
 *
 * 1. **La sélection des candidats** est une recherche : une lecture ordinaire, sans verrou, bornée, par joueur puis
 *    par clef. Elle ne décide rien. Une lecture verrouillée sur une plage poserait des verrous d'intervalle dans les
 *    index du registre et ferait attendre les insertions du jeu.
 * 2. **La relecture** de chaque candidat se fait par sa clef primaire, `for update`, dans la transaction qui crédite.
 *    Son statut y est **revérifié** : appliqué, pas encore compté, même joueur. Un candidat compté entre-temps par
 *    une autre agrégation, ou dont le statut a changé, est laissé tel quel.
 *
 * ## Crédit et marquage dans la même transaction
 *
 * L'ajout au compteur du compte et la marque `aggregated_at` des événements retenus partagent la transaction. Une
 * interruption avant la validation n'écrit ni l'un ni l'autre ; la passe suivante retrouve les mêmes candidats et
 * les compte une fois. Un événement marqué est compté, un événement compté est marqué.
 *
 * ## L'ordre des verrous
 *
 * Joueur par joueur, par identifiant croissant ; à l'intérieur, les événements dans l'ordre de la sélection, puis
 * la ligne du compteur. L'agrégation ne demande aucun verrou du jeu — ni compte, ni corps, ni ligne de file : une
 * transaction du jeu ne peut l'attendre que si elle réinscrit une clef existante, ce que l'avancement relu sous
 * verrou empêche. Deux agrégations prennent les mêmes lignes dans le même ordre. Ce sont des affirmations sur le
 * moteur, pas des garanties : les courses du bac MariaDB les éprouvent sur le schéma réel.
 *
 * ## Un compte supprimé
 *
 * Ses événements sont marqués comptés sans compteur : il n'a plus de classement, et recréer sa ligne ne servirait
 * à personne.
 */
final class MilitaryTallyAggregator
{
    /** Combien de candidats une passe lit à la fois. */
    public const int BATCH = 500;

    /**
     * @param (Closure(int, list<string>): void)|null $afterSelection Couture d'essai : appelée avec les candidats d'un
     *        joueur, entre leur sélection et leur relecture verrouillée.
     * @param (Closure(int, list<string>): void)|null $beforeCommit Couture d'essai : appelée dans la transaction, après
     *        le crédit et la marque, juste avant la validation.
     */
    public function __construct(
        private Closure|null $afterSelection = null,
        private Closure|null $beforeCommit = null,
    ) {
    }

    /**
     * Compte tout ce qui attend, passe après passe.
     *
     * @return int Le nombre d'événements comptés par cet appel.
     */
    public function aggregate(): int
    {
        $comptes = 0;

        do {
            $candidats = DB::table('military_tally_events')
                ->where('status', MilitaryTallyRecorder::APPLIED)
                ->whereNull('aggregated_at')
                ->orderBy('player_id')
                ->orderBy('event_key')
                ->limit(self::BATCH)
                ->get(['event_key', 'player_id']);

            /** @var array<int, list<string>> $parJoueur */
            $parJoueur = [];
            foreach ($candidats as $candidat) {
                $parJoueur[(int)$candidat->player_id][] = (string)$candidat->event_key;
            }
            ksort($parJoueur);

            $cettePasse = 0;
            foreach ($parJoueur as $joueur => $clefs) {
                if ($this->afterSelection !== null) {
                    ($this->afterSelection)($joueur, $clefs);
                }

                $cettePasse += $this->countFor($joueur, $clefs);
            }

            $comptes += $cettePasse;

            // Une passe pleine qui a compté quelque chose laisse peut-être des candidats derrière elle. Une passe qui
            // n'a rien compté s'arrête : ses candidats ont été pris par une autre agrégation, et la suivante verra le reste.
        } while ($cettePasse > 0 && count($candidats) === self::BATCH);

        return $comptes;
    }

    /**
     * Relit, revérifie, crédite et marque les candidats d'un joueur — dans une seule transaction.
     *
     * @param list<string> $clefs
     */
    private function countFor(int $joueur, array $clefs): int
    {
        return DB::transaction(function () use ($joueur, $clefs): int {
            $retenues = [];
            $construits = 0;
            $detruits = 0;
            $perdus = 0;

            foreach ($clefs as $clef) {
                $ligne = DB::table('military_tally_events')
                    ->where('event_key', $clef)
                    ->lockForUpdate()
                    ->first(['player_id', 'status', 'aggregated_at', 'built_value', 'destroyed_value', 'lost_value']);

                // **Le statut se revérifie sous le verrou.** La sélection ne décide rien : entre elle et cette relecture,
                // une autre agrégation a pu compter la ligne.
                if ($ligne === null
                    || $ligne->status !== MilitaryTallyRecorder::APPLIED
                    || $ligne->aggregated_at !== null
                    || (int)$ligne->player_id !== $joueur
                ) {
                    continue;
                }

                $retenues[] = $clef;
                $construits += (int)$ligne->built_value;
                $detruits += (int)$ligne->destroyed_value;
                $perdus += (int)$ligne->lost_value;
            }

            if ($retenues === []) {
                return 0;
            }

            $maintenant = Date::now();

            DB::table('military_tally_events')
                ->whereIn('event_key', $retenues)
                ->update(['aggregated_at' => (int)$maintenant->timestamp, 'updated_at' => $maintenant]);

            if (DB::table('users')->where('id', $joueur)->exists()) {
                DB::table('military_tallies')->upsert(
                    [[
                        'player_id' => $joueur,
                        'built_value' => $construits,
                        'destroyed_value' => $detruits,
                        'lost_value' => $perdus,
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ]],
                    ['player_id'],
                    [
                        'built_value' => DB::raw('built_value + ' . $construits),
                        'destroyed_value' => DB::raw('destroyed_value + ' . $detruits),
                        'lost_value' => DB::raw('lost_value + ' . $perdus),
                        'updated_at' => $maintenant,
                    ]
                );
            }

            if ($this->beforeCommit !== null) {
                ($this->beforeCommit)($joueur, $retenues);
            }

            return count($retenues);
        });
    }
}
