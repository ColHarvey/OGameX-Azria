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

    /** @var list<GroupedTallyKind> */
    private array $groups;

    /**
     * @param (Closure(string): void)|null $beforeCommit Couture d'essai : appelée avec la clef, dans la transaction
     *        d'un événement repris, juste avant la validation.
     * @param (Closure(string): void)|null $afterSelection Couture d'essai : appelée avec la clef, entre sa sélection et
     *        sa relecture verrouillée.
     * @param list<GroupedTallyKind> $groups Les genres qui vivent en groupe ; vide, ceux du jeu.
     */
    public function __construct(
        private Closure|null $beforeCommit = null,
        private Closure|null $afterSelection = null,
        array $groups = [],
    ) {
        $this->groups = $groups === [] ? [new BattleTallyGroup(), new MissileTallyGroup(), new MoonDestructionTallyGroup()] : $groups;
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

            $genre = is_array($charge) && is_string($charge['kind'] ?? null) ? $this->groupFor($charge['kind']) : null;

            if ($genre !== null && is_array($charge)) {
                return $this->replayTheGroup($clef, (string)$ligne->weighting_version, $charge, $genre);
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
     * Reprend un groupe entier — tout ou rien.
     *
     * Le genre relit les faits gardes et dit ce qu il attend ; ici, les membres en attente sous ses prefixes sont
     * verrouilles, chacun doit porter les memes faits que le meneur, le genre, le proprietaire et la version de ce
     * qui est attendu, et l ensemble des membres doit recouvrir exactement l ensemble des attendus. Un membre manquant,
     * corrompu ou divergent laisse le groupe entier en attente. Puis tout s applique dans la transaction du meneur ;
     * les autres membres, relus ensuite par la selection, sont deja clos.
     *
     * @param array<string, mixed> $charge
     */
    private function replayTheGroup(string $clef, string $version, array $charge, GroupedTallyKind $genre): string
    {
        $faits = $genre->readFacts($charge['facts'] ?? null);

        if ($faits === null) {
            return self::STILL_PENDING;
        }

        $prefixes = $genre->memberPrefixes($faits);

        if ($prefixes === []) {
            return self::STILL_PENDING;
        }

        $membres = DB::table('military_tally_events')
            ->where('status', MilitaryTallyRecorder::PENDING)
            ->where(static function ($requete) use ($prefixes): void {
                foreach ($prefixes as $prefixe) {
                    $requete->orWhere('event_key', 'like', $prefixe . '%');
                }
            })
            ->orderBy('event_key')
            ->lockForUpdate()
            ->get(['event_key', 'player_id', 'weighting_version', 'payload']);

        $attendus = $genre->expectedEvents($faits, $version);

        if ($attendus === null) {
            return self::STILL_PENDING;
        }

        $presents = [];
        $reference = $genre->storageOf($faits);

        foreach ($membres as $membre) {
            $clefMembre = (string)$membre->event_key;
            $chargeMembre = json_decode((string)$membre->payload, true);
            $attendu = $attendus[$clefMembre] ?? null;
            $faitsDuMembre = is_array($chargeMembre) ? $genre->readFacts($chargeMembre['facts'] ?? null) : null;

            if ($attendu === null || (string)$membre->weighting_version !== $version || !is_array($chargeMembre)
                || ($chargeMembre['kind'] ?? null) !== $attendu['kind'] || $attendu['owner'] !== (int)$membre->player_id
                || $faitsDuMembre === null || $genre->storageOf($faitsDuMembre) !== $reference) {
                return self::STILL_PENDING;
            }

            $presents[$clefMembre] = true;
        }

        if (!isset($presents[$clef]) || array_diff_key($attendus, $presents) !== []) {
            return self::STILL_PENDING;
        }

        $valeurs = [];

        foreach (array_keys($presents) as $clefMembre) {
            $valeurs[$clefMembre] = ['built' => $attendus[$clefMembre]['built'], 'destroyed' => $attendus[$clefMembre]['destroyed'], 'lost' => $attendus[$clefMembre]['lost']];
        }

        $this->apply($valeurs);

        if ($this->beforeCommit !== null) {
            ($this->beforeCommit)($clef);
        }

        return self::REPLAYED;
    }

    private function groupFor(string $kind): GroupedTallyKind|null
    {
        foreach ($this->groups as $groupe) {
            if (in_array($kind, $groupe->kinds(), true)) {
                return $groupe;
            }
        }

        return null;
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
