<?php

namespace OGame\Military;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Reprend les événements en attente : chacun est évalué avec **sa** version de pondération, entier, une fois.
 *
 * ## La version de l'événement, jamais la courante
 *
 * Un événement mis en attente porte la version de la règle qui devait l'évaluer (`weighting_version`) et, dans sa
 * charge, le prix brut de ses unités au moment du fait. La reprise applique cette version-là, **complétée** pour
 * l'unité qui manquait (`MilitaryValue::weightOf()`), et ce prix-là : attendre ne change pas les points attribués.
 *
 * Une version que ce code ne connaît pas laisse l'événement en attente. **Aucune conversion n'est faite ici** :
 * convertir un événement vers une autre version sera une commande explicite et auditée, qui n'existe pas encore.
 *
 * ## Entier ou pas du tout
 *
 * Une unité encore inconnue de sa version, une charge dont la forme n'est pas reconnue : l'événement reste en attente,
 * et aucune de ses parts n'est écrite.
 *
 * ## Deux lectures, comme l'agrégation
 *
 * La sélection cherche sans verrou, par clef croissante, et avance par curseur : un événement qui reste en attente ne
 * bloque pas la suite. La relecture prend chaque événement par sa clef primaire `for update`, revérifie qu'il est encore
 * en attente, puis écrit valeurs, statut et `resolved_at` dans la même transaction. Un second passage ne trouve plus
 * rien à reprendre. La raison de l'attente est gardée.
 *
 * Seuls les événements du registre sont verrouillés, par clef : la reprise ne demande aucun verrou du jeu, et
 * l'agrégation ne compte jamais un événement en attente. Les courses du bac MariaDB l'éprouvent.
 */
final class MilitaryTallyReplay
{
    /** Combien de clefs une sélection lit à la fois. */
    public const int BATCH = 500;

    /** L'événement vient d'être repris et évalué. */
    public const string REPLAYED = 'repris';

    /** L'événement ne peut pas encore être évalué entier : il reste en attente. */
    public const string STILL_PENDING = 'toujours en attente';

    /** Une autre reprise l'a déjà traité entre sa sélection et sa relecture. */
    public const string ALREADY_HANDLED = 'deja repris';

    /**
     * @param (Closure(string): void)|null $beforeCommit Couture d'essai : appelée avec la clef, dans la transaction
     *        d'un événement repris, juste avant la validation.
     * @param (Closure(string): void)|null $afterSelection Couture d'essai : appelée avec la clef, entre sa sélection et
     *        sa relecture verrouillée.
     */
    public function __construct(
        private Closure|null $beforeCommit = null,
        private Closure|null $afterSelection = null,
    ) {
    }

    /**
     * Reprend tout ce qui attend.
     *
     * @return array{replayed: int, pending: int, skipped: int}
     */
    public function replay(): array
    {
        $bilan = ['replayed' => 0, 'pending' => 0, 'skipped' => 0];
        $curseur = '';

        do {
            $clefs = array_map(
                static fn (mixed $clef): string => (string)$clef,
                DB::table('military_tally_events')
                    ->where('status', MilitaryTallyRecorder::PENDING)
                    ->where('event_key', '>', $curseur)
                    ->orderBy('event_key')
                    ->limit(self::BATCH)
                    ->pluck('event_key')
                    ->all()
            );

            foreach ($clefs as $clef) {
                if ($this->afterSelection !== null) {
                    ($this->afterSelection)($clef);
                }

                $issue = $this->replayOne($clef);
                $curseur = $clef;

                match ($issue) {
                    self::REPLAYED => $bilan['replayed']++,
                    self::STILL_PENDING => $bilan['pending']++,
                    default => $bilan['skipped']++,
                };
            }
        } while (count($clefs) === self::BATCH);

        return $bilan;
    }

    /**
     * Relit un événement sous verrou, revérifie qu'il attend, et l'évalue avec sa version.
     */
    private function replayOne(string $clef): string
    {
        return DB::transaction(function () use ($clef): string {
            $ligne = DB::table('military_tally_events')
                ->where('event_key', $clef)
                ->lockForUpdate()
                ->first(['status', 'weighting_version', 'payload']);

            if ($ligne === null || $ligne->status !== MilitaryTallyRecorder::PENDING) {
                return self::ALREADY_HANDLED;
            }

            $charge = json_decode((string)$ligne->payload, true);

            if (is_array($charge) && in_array($charge['kind'] ?? null, [MilitaryBattleTally::KIND, MilitaryBattleTally::KIND_HAMILL], true)) {
                return $this->replayTheBattleGroup($clef, (string)$ligne->weighting_version, $charge);
            }

            $valeurs = $this->evaluate((string)$ligne->weighting_version, $charge);

            if ($valeurs === null) {
                return self::STILL_PENDING;
            }

            $this->apply([$clef => $valeurs]);

            if ($this->beforeCommit !== null) {
                ($this->beforeCommit)($clef);
            }

            return self::REPLAYED;
        });
    }

    /**
     * Une bataille et sa manoeuvre de Hamill se reprennent **d un bloc** : tous les evenements en attente du fait —
     * un par participant classe, plus l evenement nomme de l auteur — sont verrouilles, evalues une fois sur les
     * memes faits, et appliques ensemble ou pas du tout. Un membre sans credit, un membre dont le proprietaire ne
     * repond pas au credit, un credit sans membre en attente, une forme de charge etrangere : rien n est ecrit.
     * Ni credit partiel, ni doublon — les clefs sont celles du fait, et un membre applique ne l est jamais deux fois.
     *
     * @param array<string, mixed> $charge
     */
    private function replayTheBattleGroup(string $clef, string $version, array $charge): string
    {
        $faits = BattleTallyFacts::fromStorage($charge['facts'] ?? null);

        if ($faits === null) {
            return self::STILL_PENDING;
        }

        $membres = DB::table('military_tally_events')
            ->where('status', MilitaryTallyRecorder::PENDING)
            ->where(static function ($requete) use ($faits): void {
                $requete->where('event_key', 'like', $faits->eventKeyPrefix() . '%')
                    ->orWhere('event_key', 'like', $faits->hamillEventKeyPrefix() . '%');
            })
            ->orderBy('event_key')
            ->lockForUpdate()
            ->get(['event_key', 'player_id', 'weighting_version', 'payload']);

        $issue = (new BattleTallyEvaluation())->evaluate($faits, $version);

        if ($issue->isPending()) {
            return self::STILL_PENDING;
        }

        $attendus = [];

        foreach ($issue->credits() as $participant => $credit) {
            $attendus[$faits->eventKeyFor($participant)] = ['kind' => MilitaryBattleTally::KIND, 'owner' => $credit['owner'], 'destroyed' => $credit['destroyed'], 'lost' => $credit['lost']];
        }

        $hamill = $issue->hamillCredit();

        if ($hamill !== null) {
            $attendus[$faits->hamillEventKeyFor($hamill['author'])] = ['kind' => MilitaryBattleTally::KIND_HAMILL, 'owner' => $hamill['owner'], 'destroyed' => $hamill['destroyed'], 'lost' => 0];
        }

        $presents = [];
        $reference = $faits->toStorage();

        foreach ($membres as $membre) {
            $clefMembre = (string)$membre->event_key;
            $chargeMembre = json_decode((string)$membre->payload, true);
            $attendu = $attendus[$clefMembre] ?? null;

            // Chaque membre porte les memes faits que le meneur, lisibles : un membre illisible ou qui raconte une
            // autre bataille retient tout le groupe.
            $faitsDuMembre = is_array($chargeMembre) ? BattleTallyFacts::fromStorage($chargeMembre['facts'] ?? null) : null;

            if ($attendu === null || (string)$membre->weighting_version !== $version || !is_array($chargeMembre)
                || ($chargeMembre['kind'] ?? null) !== $attendu['kind'] || $attendu['owner'] !== (int)$membre->player_id
                || $faitsDuMembre === null || $faitsDuMembre->toStorage() !== $reference) {
                return self::STILL_PENDING;
            }

            $presents[$clefMembre] = true;
        }

        if (!isset($presents[$clef]) || array_diff_key($attendus, $presents) !== []) {
            return self::STILL_PENDING;
        }

        $valeurs = [];

        foreach (array_keys($presents) as $clefMembre) {
            $valeurs[$clefMembre] = ['built' => 0, 'destroyed' => $attendus[$clefMembre]['destroyed'], 'lost' => $attendus[$clefMembre]['lost']];
        }

        $this->apply($valeurs);

        if ($this->beforeCommit !== null) {
            ($this->beforeCommit)($clef);
        }

        return self::REPLAYED;
    }

    /**
     * @param array<string, array{built: int, destroyed: int, lost: int}> $valeurs
     */
    private function apply(array $valeurs): void
    {
        $maintenant = Date::now();

        foreach ($valeurs as $clef => $valeur) {
            DB::table('military_tally_events')->where('event_key', $clef)->update([
                'status' => MilitaryTallyRecorder::APPLIED,
                'built_value' => $valeur['built'],
                'destroyed_value' => $valeur['destroyed'],
                'lost_value' => $valeur['lost'],
                'resolved_at' => (int)$maintenant->timestamp,
                'updated_at' => $maintenant,
            ]);
        }
    }

    /**
     * La valeur d'un événement évalué avec sa version, ou `null` s'il ne peut pas encore l'être entier.
     *
     * @return array{built: int, destroyed: int, lost: int}|null
     */
    private function evaluate(string $version, mixed $charge): array|null
    {
        if (!is_array($charge) || ($charge['kind'] ?? null) !== MilitaryBuildTally::KIND) {
            return null;
        }

        $objet = $charge['object'] ?? null;
        $de = $charge['from'] ?? null;
        $a = $charge['to'] ?? null;
        $prix = $charge['raw_price'] ?? null;

        if (!is_string($objet) || !is_int($de) || !is_int($a) || !is_int($prix) || $de < 0 || $a <= $de || $prix < 0) {
            return null;
        }

        $poids = MilitaryValue::weightOf($objet, $version);

        if ($poids === null) {
            return null;
        }

        return ['built' => $prix * ($a - $de) * $poids, 'destroyed' => 0, 'lost' => 0];
    }
}
