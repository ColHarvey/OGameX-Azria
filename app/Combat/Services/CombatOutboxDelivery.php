<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMessages\Abstracts\GameMessage;
use OGame\GameMessages\CombatCancelled;
use OGame\GameMessages\CombatRallyRefused;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\MessageService;
use RuntimeException;
use Throwable;

/**
 * Livre les avis de la boite d'envoi des combats en messages du jeu.
 *
 * ## Pourquoi une boite d'envoi, et un livreur a part
 *
 * Un refus au ralliement, une annulation, s'ecrivent **dans la transaction** qui les decide : l'avis
 * est un fait, au meme titre que le retour de la flotte. Le message du jeu, lui, est une lecture de
 * ce fait — et l'ecrire dans la meme transaction lierait le sort d'une decision de combat a celui
 * d'une ligne de messagerie. Le livreur passe apres, a la minute, et transforme chaque avis en
 * message **une seule fois** : `dispatched_at` est pose dans la transaction qui cree le message.
 *
 * ## A qui l'avis est livre
 *
 * D'abord a l'inscrit sous cette clef, si le combat en a un : c'est la photographie, et c'est elle
 * qui fait foi — un corps reattribue apres la cloture ne detourne pas l'avis. Sinon, avant la
 * cloture, a la mission que la clef nomme, ou au proprietaire vivant du corps vise : personne n'est
 * encore inscrit, et le corps est le seul a savoir qui il est.
 *
 * Un avis sans destinataire ou dont le message echoue est garde, compte, et retente au passage
 * suivant ; au-dela de cinq tentatives il est laisse a l'exploitation avec sa derniere erreur.
 */
final class CombatOutboxDelivery
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private MessageService|null $messages = null,
        private PlayerServiceFactory|null $players = null,
    ) {
    }

    /**
     * Livre ce qui est disponible a cet instant. Rend le nombre d'avis livres.
     */
    public function deliver(int $now, int $batchSize = 200): int
    {
        $identifiants = CombatOutboxMessage::query()
            ->whereNull('dispatched_at')
            ->where('available_at', '<=', $now)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        $livres = 0;

        foreach ($identifiants as $id) {
            try {
                DB::transaction(function () use ($id, $now, &$livres): void {
                    $avis = CombatOutboxMessage::query()->whereKey($id)->lockForUpdate()->first();

                    // Deja livre par un autre passage entre la liste et le verrou : rien a faire.
                    if ($avis === null || $avis->dispatched_at !== null) {
                        return;
                    }

                    $destinataire = $this->recipientOf($avis);

                    if ($destinataire === null) {
                        throw new RuntimeException('Aucun destinataire pour l avis ' . $avis->id . ' (' . $avis->participant_key . ').');
                    }

                    [$classe, $params] = $this->messageFor($avis);

                    $this->messages()->sendSystemMessageToPlayer($this->players()->make($destinataire), $classe, $params);

                    $avis->dispatched_at = $now;
                    $avis->save();
                    $livres++;
                });
            } catch (Throwable $panne) {
                CombatOutboxMessage::query()->whereKey($id)->update([
                    'last_error' => mb_substr($panne::class . ': ' . $panne->getMessage(), 0, 60_000),
                ]);
                CombatOutboxMessage::query()->whereKey($id)->increment('attempts');
            }
        }

        return $livres;
    }

    /**
     * Le joueur a qui l'avis s'adresse, ou null si personne ne le porte plus.
     */
    private function recipientOf(CombatOutboxMessage $avis): int|null
    {
        $clef = (string)$avis->participant_key;

        $inscrit = CombatParticipant::query()
            ->where('combat_instance_id', $avis->combat_instance_id)
            ->where('participant_key', $clef)
            ->value('player_id');

        if ($inscrit !== null) {
            return (int)$inscrit;
        }

        $identifiant = CombatParticipantKey::identifierOf($clef);

        if ($identifiant === null) {
            return null;
        }

        $proprietaire = CombatParticipantKey::isBody($clef)
            ? Planet::query()->whereKey($identifiant)->value('user_id')
            : FleetMission::query()->whereKey($identifiant)->value('user_id');

        return $proprietaire === null ? null : (int)$proprietaire;
    }

    /**
     * La classe de message et ses parametres, d'apres le genre et le contenu fige de l'avis.
     *
     * @return array{0: class-string<GameMessage>, 1: array<string, mixed>}
     */
    private function messageFor(CombatOutboxMessage $avis): array
    {
        $contenu = is_array($avis->payload) ? $avis->payload : [];
        $coordonnees = '[coordinates]' . (int)($contenu['galaxy'] ?? 0) . ':' . (int)($contenu['system'] ?? 0) . ':' . (int)($contenu['position'] ?? 0) . '[/coordinates]';

        return match ((string)$avis->kind) {
            CombatOutboxKind::RallyRefused->value => [CombatRallyRefused::class, [
                'coordinates' => $coordonnees,
                'reason_code' => (string)($contenu['reason'] ?? 'undecided'),
            ]],
            CombatOutboxKind::CombatCancelled->value => [CombatCancelled::class, [
                'coordinates' => $coordonnees,
                'cause_code' => (string)($contenu['cause'] ?? 'administrative_decision'),
                'fingerprint' => (string)($contenu['abandoned_fingerprint'] ?? ''),
            ]],
            default => throw new RuntimeException('Genre d avis inconnu : ' . $avis->kind),
        };
    }

    private function messages(): MessageService
    {
        return $this->messages ??= resolve(MessageService::class);
    }

    private function players(): PlayerServiceFactory
    {
        return $this->players ??= resolve(PlayerServiceFactory::class);
    }
}
