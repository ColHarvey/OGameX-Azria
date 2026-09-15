<?php

namespace OGame\Military;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\Combat\Exceptions\CorruptedBattleResult;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CombatInstance;
use stdClass;

/**
 * La conversion explicite et auditee des batailles en attente d une manoeuvre de Hamill non nommee.
 *
 * ## Jamais deviner
 *
 * Une attente ne se convertit que si des faits conserves etablissent **exactement** la victime et l auteur :
 *
 * - la regle de manoeuvre de l epoque est lue sur l instance du combat durable et doit retirer l Etoile d une
 *   flotte (`v2`, `v3`) — sous `v1` aucune flotte ne la perd, et une bataille instantanee ne conserve pas sa regle :
 *   ces attentes restent **explicitement non resolues** ;
 * - le resultat gele du combat, s il porte deja la manoeuvre nommee (schema 6), la donne tel quel ;
 * - sinon la victime est **l unique** flotte defensive dont les pertes definitives depassent la somme de ses rounds
 *   d exactement une Etoile, toutes les autres ayant un ecart nul ; l auteur est la premiere flotte attaquante des
 *   faits conserves, et l ordre est confronte a celui du resultat gele — la regle du moteur, identique sous toutes
 *   ses versions, est « la flotte dont le General a ete consulte est la premiere dans l ordre canonique ».
 *
 * Tout ce qui n est pas etabli ainsi prend la raison `hamill_unresolved`, avec le detail, et aucun credit.
 *
 * ## Un groupe, tout ou rien
 *
 * Les evenements de bataille d un fait recoivent les memes faits convertis dans une transaction, l evenement
 * nomme de l auteur est ajoute s il manque (jamais s il existe : la clef est celle du fait), et la reprise les
 * applique ensemble. La conversion n ecrit rien sans `apply` : un passage a blanc dit ce qu il ferait.
 */
final class HamillPendingConversion
{
    public const string RULE = 'difference_exacte_v1';

    public const string CONVERTED = 'hamill_converted';

    public const string UNRESOLVED = 'hamill_unresolved';

    public const string CONVERTIBLE = 'convertible';

    /**
     * @return array{groups: list<array{group: string, outcome: string, detail: string}>, converted: int, unresolved: int}
     */
    public function convert(bool $apply): array
    {
        $bilan = ['groups' => [], 'converted' => 0, 'unresolved' => 0];
        $vus = [];

        $lignes = DB::table('military_tally_events')
            ->where('status', MilitaryTallyRecorder::PENDING)
            ->where('reason', BattleTallyEvaluation::HAMILL_VICTIM_UNNAMED)
            ->orderBy('event_key')
            ->get(['event_key', 'payload']);

        foreach ($lignes as $ligne) {
            $charge = json_decode((string)$ligne->payload, true);

            if (!is_array($charge) || ($charge['kind'] ?? null) !== MilitaryBattleTally::KIND) {
                continue;
            }

            $faits = BattleTallyFacts::fromStorage($charge['facts'] ?? null);

            if ($faits === null || isset($vus[$faits->eventKeyPrefix()])) {
                continue;
            }

            $vus[$faits->eventKeyPrefix()] = true;
            $derivation = $this->derive($faits);
            $groupe = $faits->space . ':' . $faits->id;

            if (is_string($derivation)) {
                $bilan['unresolved']++;
                $bilan['groups'][] = ['group' => $groupe, 'outcome' => self::UNRESOLVED, 'detail' => $derivation];

                if ($apply) {
                    $this->markUnresolved($faits, $derivation);
                }

                continue;
            }

            $bilan['converted']++;
            $bilan['groups'][] = [
                'group' => $groupe,
                'outcome' => $apply ? self::CONVERTED : self::CONVERTIBLE,
                'detail' => 'victime ' . $derivation['victim'] . ', auteur ' . $derivation['author'] . ', regle ' . $derivation['rule'],
            ];

            if ($apply) {
                $this->rewrite($faits, $derivation);
            }
        }

        return $bilan;
    }

    /**
     * @return array{victim: string, author: string, rule: string}|string La manoeuvre etablie, ou la raison de ne pas l etablir.
     */
    private function derive(BattleTallyFacts $faits): array|string
    {
        if ($faits->hamill !== null) {
            return 'la manoeuvre est deja nommee';
        }

        if (!$faits->hamillTriggered) {
            return 'les faits ne declarent aucune manoeuvre';
        }

        if ($faits->space !== BattleTallyFacts::SPACE_COMBAT) {
            return 'bataille instantanee : la regle de manoeuvre de l epoque n est pas conservee';
        }

        $instance = CombatInstance::query()->find($faits->id);

        if ($instance === null) {
            return 'le combat ' . $faits->id . ' est introuvable';
        }

        $regle = HamillManoeuvreRule::tryFrom((string)$instance->hamill_rule_version);

        if ($regle === null) {
            return 'le combat ' . $faits->id . ' porte une regle de manoeuvre inconnue';
        }

        if (!$regle->theManoeuvreLeavesTheBattle()) {
            return 'regle ' . $regle->value . ' : l Etoile n est retiree d aucune flotte, la victime ne se deduit pas';
        }

        try {
            $gele = BattleResultCodec::fromStorage($instance->battle_result);
        } catch (CorruptedBattleResult $defaut) {
            return 'le resultat gele du combat ' . $faits->id . ' est illisible : ' . $defaut->getMessage();
        }

        if (!$gele->hamillManoeuvreTriggered) {
            return 'le resultat gele du combat ' . $faits->id . ' ne declare aucune manoeuvre';
        }

        if ($gele->hamill->isNamed()) {
            return ['victim' => (string)$gele->hamill->victim, 'author' => (string)$gele->hamill->author, 'rule' => $regle->value];
        }

        $premiere = $gele->attackerFleetResults[0] ?? null;
        $auteur = $faits->firstAttackerKey();

        if ($premiere === null || $auteur === null) {
            return 'aucune flotte attaquante dans le resultat gele ou dans les faits';
        }

        $premiereClef = $premiere->fleetMissionId === 0 ? CombatParticipantKey::EPHEMERAL_ATTACKER : CombatParticipantKey::forFleet($premiere->fleetMissionId);

        if ($premiereClef !== $auteur) {
            return 'l ordre canonique des attaquantes ne se retrouve pas exactement (' . $premiereClef . ' dans le resultat gele, ' . $auteur . ' dans les faits)';
        }

        $victime = $this->uniqueVictimOf($faits);

        if (is_string($victime)) {
            return $victime;
        }

        return ['victim' => $victime['victim'], 'author' => $auteur, 'rule' => $regle->value];
    }

    /**
     * L unique flotte defensive dont les pertes definitives depassent la somme de ses rounds d exactement une Etoile —
     * sa clef, ou la raison pour laquelle aucune ne s impose.
     *
     * @return array{victim: string}|string
     */
    private function uniqueVictimOf(BattleTallyFacts $faits): array|string
    {
        $candidates = [];

        foreach ($faits->participants as $participant) {
            if ($participant['side'] !== BattleTallyFacts::SIDE_DEFENDER) {
                continue;
            }

            $ecart = $participant['lost'];

            foreach ($faits->rounds as $round) {
                foreach ($round[$participant['key']] ?? [] as $nom => $nombre) {
                    $ecart[$nom] = ($ecart[$nom] ?? 0) - $nombre;
                }
            }

            foreach ($faits->preRoundLosses[$participant['key']] ?? [] as $nom => $nombre) {
                $ecart[$nom] = ($ecart[$nom] ?? 0) - $nombre;
            }

            $ecart = array_filter($ecart, static fn (int $n): bool => $n !== 0);
            ksort($ecart);

            if ($ecart === []) {
                continue;
            }

            if ($ecart !== ['deathstar' => 1]) {
                return 'la flotte ' . $participant['key'] . ' presente un ecart qui n est pas exactement une Etoile (' . json_encode($ecart) . ')';
            }

            $candidates[] = $participant['key'];
        }

        if (count($candidates) !== 1) {
            return count($candidates) === 0
                ? 'aucune flotte defensive ne presente l ecart d une Etoile'
                : 'plusieurs flottes defensives presentent l ecart d une Etoile (' . implode(', ', $candidates) . ')';
        }

        return ['victim' => $candidates[0]];
    }

    /**
     * @param array{victim: string, author: string, rule: string} $manoeuvre
     */
    private function rewrite(BattleTallyFacts $faits, array $manoeuvre): void
    {
        DB::transaction(function () use ($faits, $manoeuvre): void {
            $membres = $this->lockedMembersOf($faits);
            $convertis = $faits->withHamillNamed($manoeuvre);
            $maintenant = Date::now();
            $audit = [
                'rule' => self::RULE,
                'at' => (int)$maintenant->timestamp,
                'victim' => $manoeuvre['victim'],
                'author' => $manoeuvre['author'],
                'hamill_rule_version' => $manoeuvre['rule'],
                'from_facts_schema' => BattleTallyFacts::SCHEMA - 1,
            ];
            $auteurClasse = null;

            foreach ($faits->participants as $participant) {
                if ($participant['key'] === $manoeuvre['author'] && $participant['owner'] !== null && !$participant['npc']) {
                    $auteurClasse = $participant['owner'];
                }
            }

            foreach ($membres as $membre) {
                $charge = json_decode((string)$membre->payload, true);
                $charge = is_array($charge) ? $charge : [];
                $charge['facts'] = $convertis->toStorage();
                $charge['conversion'] = $audit;

                DB::table('military_tally_events')->where('event_key', $membre->event_key)->update([
                    'reason' => self::CONVERTED,
                    'payload' => json_encode($charge, JSON_THROW_ON_ERROR),
                    'updated_at' => $maintenant,
                ]);
            }

            $clefHamill = $convertis->hamillEventKeyFor($manoeuvre['author']);

            if ($auteurClasse !== null && !DB::table('military_tally_events')->where('event_key', $clefHamill)->exists()) {
                DB::table('military_tally_events')->insert([
                    'event_key' => $clefHamill,
                    'player_id' => $auteurClasse,
                    'status' => MilitaryTallyRecorder::PENDING,
                    'reason' => self::CONVERTED,
                    'payload' => json_encode(['kind' => MilitaryBattleTally::KIND_HAMILL, 'participant' => $manoeuvre['author'], 'detail' => 'ajoute par la conversion', 'facts' => $convertis->toStorage(), 'conversion' => $audit], JSON_THROW_ON_ERROR),
                    'weighting_version' => (string)($membres[0]->weighting_version ?? MilitaryValue::WEIGHTING_VERSION),
                    'built_value' => 0,
                    'destroyed_value' => 0,
                    'lost_value' => 0,
                    'recorded_at' => (int)$maintenant->timestamp,
                    'aggregated_at' => null,
                    'resolved_at' => null,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            }
        });
    }

    private function markUnresolved(BattleTallyFacts $faits, string $detail): void
    {
        DB::transaction(function () use ($faits, $detail): void {
            $maintenant = Date::now();

            foreach ($this->lockedMembersOf($faits) as $membre) {
                $charge = json_decode((string)$membre->payload, true);
                $charge = is_array($charge) ? $charge : [];
                $charge['unresolved'] = ['rule' => self::RULE, 'at' => (int)$maintenant->timestamp, 'detail' => $detail];

                DB::table('military_tally_events')->where('event_key', $membre->event_key)->update([
                    'reason' => self::UNRESOLVED,
                    'payload' => json_encode($charge, JSON_THROW_ON_ERROR),
                    'updated_at' => $maintenant,
                ]);
            }
        });
    }

    /**
     * @return array<int, stdClass>
     */
    private function lockedMembersOf(BattleTallyFacts $faits): array
    {
        return DB::table('military_tally_events')
            ->where('status', MilitaryTallyRecorder::PENDING)
            ->where(static function ($requete) use ($faits): void {
                $requete->where('event_key', 'like', $faits->eventKeyPrefix() . '%')
                    ->orWhere('event_key', 'like', $faits->hamillEventKeyPrefix() . '%');
            })
            ->orderBy('event_key')
            ->lockForUpdate()
            ->get(['event_key', 'player_id', 'weighting_version', 'payload'])
            ->all();
    }
}
