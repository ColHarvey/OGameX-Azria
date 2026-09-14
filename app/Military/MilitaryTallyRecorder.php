<?php

namespace OGame\Military;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Le seul écrivain du registre des cumuls militaires : construits, détruits, perdus.
 *
 * ## Une ligne du registre, et rien d'autre
 *
 * Un crédit écrit **un événement** dans la transaction de l'effet, avec ses valeurs, et rien d'autre : ni la ligne
 * du compte, ni un compteur. Le compteur de chaque compte est une projection du registre, tenue hors du jeu par
 * `MilitaryTallyAggregator`. Un compteur écrit ici relierait entre eux tous les corps d'un joueur, et un compteur
 * sur `users` croiserait le lancement de flotte, qui prend le compte avant le corps.
 *
 * ## L'idempotence n'est pas une option
 *
 * Un service unique ne suffirait pas : une reprise, deux travailleurs, un rejeu d'un règlement recommenceraient
 * le crédit. Chaque crédit porte donc une **clef d'événement**, clef primaire du registre — `build:<ligne>:<avant>-<après>`,
 * `combat:<id>:lost:<participant>`, `missile:<mission>:destroyed` —, écrite dans la **même transaction** que
 * l'effet. Une clef déjà présente ne compte rien et le dit par `false`.
 *
 * **La clef ne protège que d'un rejeu identique.** Deux tranches de construction qui se chevauchent
 * (`0-10` et `0-5`) portent des clefs différentes : c'est à l'appelant de relire l'avancement **sous verrou**
 * et de n'écrire qu'une tranche à la fois, dans cette transaction-ci (`MilitaryBuildTally`).
 *
 * ## L'instant qui compte est celui du fait, pas celui du traitement
 *
 * Chaque crédit porte l'instant **logique** de l'événement : la fin de la bataille, la dernière unité d'une tranche
 * de construction. Un fait antérieur à l'activation de la collecte n'entre pas parce qu'un travailleur en retard le
 * traite après. La décision ne dépend que de l'instant du fait et de la date d'activation : un rejeu tranche
 * exactement pareil, et rien n'est écrit dans ce cas.
 *
 * ## Rien d'inventé, rien de partiel
 *
 * Si une unité n'appartient à aucune famille de pondération, l'événement est mis **en attente** (`defer()`) avec
 * ses faits et la version de la règle : **aucune** de ses trois parts n'est créditée — un cumul partiel
 * deviendrait un double crédit à la reprise. L'agrégation ne compte jamais un événement en attente ; une reprise
 * idempotente le rendra `applique`, entier, une fois le catalogue corrigé.
 *
 * ## Ce qui n'est jamais compté
 *
 * Une mission annulée qui rend ses unités, une suppression de compte, une correction de données : rien de tout
 * cela ne passe par ici. Seule une destruction effective, ou une construction terminée, crédite.
 *
 * ## La date de la collecte
 *
 * `military_tallies_since` est posé une seule fois, à l'**activation** (`ogamex:military:demarrer-cumuls`), quand
 * tous les chemins de crédit sont raccordés. Ni la migration ni le premier crédit ne le posent : si personne ne
 * construit pendant deux jours après l'activation, ces deux jours sont couverts, à zéro.
 */
final class MilitaryTallyRecorder
{
    /** Le réglage qui garde l'instant d'activation de la collecte. */
    public const string SINCE_KEY = 'military_tallies_since';

    /** Un événement dont les valeurs sont connues : l'agrégation le comptera. */
    public const string APPLIED = 'applique';

    /** Un événement qu'on ne sait pas encore évaluer : il ne crédite rien. */
    public const string PENDING = 'en_attente';

    /**
     * Inscrit un crédit, une fois pour cette clef d'événement.
     *
     * @param string $eventKey La clef qui rend le crédit rejouable sans effet.
     * @param int $playerId Le compte crédité, tel qu'il est au moment du fait.
     * @param int $occurredAt L'instant **du fait** : fin de bataille, dernière unité d'une tranche de construction.
     * @param int $built Valeur construite, en demi-unités de ressources.
     * @param int $destroyed Valeur détruite chez l'adversaire, en demi-unités.
     * @param int $lost Valeur perdue, en demi-unités.
     * @return bool Vrai si l'événement vient d'être inscrit ; faux s'il l'était déjà, s'il précède la collecte, ou
     *              s'il n'y avait rien à inscrire.
     */
    public function credit(string $eventKey, int $playerId, int $occurredAt, int $built = 0, int $destroyed = 0, int $lost = 0): bool
    {
        if ($playerId <= 0 || ($built <= 0 && $destroyed <= 0 && $lost <= 0) || !$this->laCollecteCouvre($occurredAt)) {
            return false;
        }

        return $this->dansUneTransaction(fn (): bool => $this->inscrire($eventKey, $playerId, self::APPLIED, null, null, max(0, $built), max(0, $destroyed), max(0, $lost)));
    }

    /**
     * Met un événement **en attente** : ses faits sont gardés, rien n'est crédité.
     *
     * @param int $occurredAt L'instant du fait ; un fait antérieur à la collecte n'est pas même mis en attente.
     * @param string $reason Pourquoi il n'a pas pu être évalué — par exemple une unité hors des familles connues.
     * @param array<string, mixed> $payload Ce qu'il faut pour recalculer exactement plus tard.
     */
    public function defer(string $eventKey, int $playerId, int $occurredAt, string $reason, array $payload): bool
    {
        if ($playerId <= 0 || !$this->laCollecteCouvre($occurredAt)) {
            return false;
        }

        return $this->dansUneTransaction(fn (): bool => $this->inscrire($eventKey, $playerId, self::PENDING, $reason, $payload, 0, 0, 0));
    }

    /**
     * Combien d'événements attendent d'être évalués — ce que le classement doit dire.
     */
    public function pendingCount(): int
    {
        return DB::table('military_tally_events')->where('status', self::PENDING)->count();
    }

    /**
     * L'instant d'activation de la collecte, ou `null` si elle n'a pas commencé.
     */
    public function collectingSince(): int|null
    {
        $valeur = (int)(DB::table('settings')->where('key', self::SINCE_KEY)->value('value') ?? 0);

        return $valeur > 0 ? $valeur : null;
    }

    /**
     * La collecte couvre-t-elle ce fait ? Un fait antérieur à l'activation n'entre jamais, même traité en retard.
     */
    private function laCollecteCouvre(int $occurredAt): bool
    {
        $depuis = $this->collectingSince();

        return $depuis !== null && $occurredAt >= $depuis;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function inscrire(string $eventKey, int $playerId, string $statut, string|null $reason, array|null $payload, int $built, int $destroyed, int $lost): bool
    {
        try {
            DB::table('military_tally_events')->insert([
                'event_key' => $eventKey,
                'player_id' => $playerId,
                'status' => $statut,
                'reason' => $reason,
                'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                'weighting_version' => MilitaryValue::WEIGHTING_VERSION,
                'built_value' => $built,
                'destroyed_value' => $destroyed,
                'lost_value' => $lost,
                'recorded_at' => (int)Date::now()->timestamp,
                'aggregated_at' => null,
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Déjà inscrit : c'est exactement ce que la clef existe pour dire.
            return false;
        }

        return true;
    }

    /**
     * @param Closure(): bool $geste
     */
    private function dansUneTransaction(Closure $geste): bool
    {
        // L'appelant tient déjà sa transaction quand il livre ce qu'il crédite ; un appel isolé ouvre la sienne.
        // L'effet et son événement ne doivent jamais être séparés par une panne.
        return DB::transactionLevel() > 0 ? $geste() : DB::transaction($geste);
    }
}
