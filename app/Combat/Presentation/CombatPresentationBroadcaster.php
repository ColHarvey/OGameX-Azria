<?php

namespace OGame\Combat\Presentation;

use Illuminate\Support\Facades\DB;
use OGame\Events\CombatLossesPublished;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatPresentationEvent;
use OGame\Services\ObjectService;
use Throwable;

/**
 * Envoie aux joueurs les pertes qui viennent de devenir visibles.
 *
 * ## Un diffuseur partage, pas un processus par bataille
 *
 * Les pertes deviennent visibles a un instant, pas a un evenement du serveur : quelqu'un doit donc
 * regarder l'heure. Ce diffuseur le fait pour toutes les batailles a la fois — il prend ce qui est
 * devenu visible et n'est pas encore parti, l'envoie a chaque joueur concerne, et marque.
 *
 * ## A qui, et quoi
 *
 * L'inscription decide : un evenement porte la clef d'un participant, et le joueur inscrit sous
 * cette clef est le seul destinataire. C'est la photographie qui fait foi, comme partout ailleurs —
 * un corps ou une flotte qui change de mains ne detourne pas les pertes deja subies. Et rien d'autre
 * ne part : ni perte future, ni calendrier, ni echeance de la bataille.
 *
 * ## Ce que « une fois » veut dire
 *
 * `broadcast_at` est ecrit **apres** l'envoi, dans une transaction : une diffusion perdue reste a
 * refaire au passage suivant, une diffusion faite n'est jamais refaite. Et le navigateur deduplique
 * de son cote par le rang de chaque perte, ce qui couvre la reconnexion.
 */
final class CombatPresentationBroadcaster
{
    /**
     * Diffuse ce qui est devenu visible a cet instant. Rend le nombre de pertes envoyees.
     */
    public function publish(int $now, int $batchSize = 500): int
    {
        $evenements = CombatPresentationEvent::query()
            ->whereNull('broadcast_at')
            ->where('visible_at', '<=', $now)
            ->orderBy('combat_instance_id')
            ->orderBy('sequence')
            ->limit($batchSize)
            ->get();

        if ($evenements->isEmpty()) {
            return 0;
        }

        // Les inscriptions des combats concernes, en une lecture : la clef d'un participant dit a
        // qui ses pertes appartiennent.
        $combats = $evenements->pluck('combat_instance_id')->unique()->all();
        $destinataires = [];

        foreach (CombatParticipant::query()->whereIn('combat_instance_id', $combats)->get(['combat_instance_id', 'participant_key', 'player_id']) as $inscription) {
            $destinataires[(int)$inscription->combat_instance_id . '|' . (string)$inscription->participant_key] = (int)$inscription->player_id;
        }

        // Un envoi par joueur et par bataille : un joueur qui perd trois types de vaisseaux dans la
        // meme periode recoit un message, pas trois.
        $lots = [];

        foreach ($evenements as $evenement) {
            $joueur = $destinataires[(int)$evenement->combat_instance_id . '|' . (string)$evenement->participant_key] ?? null;

            if ($joueur === null) {
                // Personne n'est inscrit sous cette clef : rien a envoyer, et rien a rejouer non plus.
                $this->markAsSent([(int)$evenement->id], $now);

                continue;
            }

            $lots[$joueur . '|' . (int)$evenement->combat_instance_id][] = $evenement;
        }

        $envoyees = 0;

        foreach ($lots as $clef => $lot) {
            [$joueur, $combat] = array_map('intval', explode('|', $clef));

            try {
                DB::transaction(function () use ($joueur, $combat, $lot, $now, &$envoyees): void {
                    event(new CombatLossesPublished($joueur, $combat, array_map(
                        fn (CombatPresentationEvent $evenement): array => $this->row($evenement),
                        $lot
                    )));

                    $this->markAsSent(array_map(static fn (CombatPresentationEvent $e): int => (int)$e->id, $lot), $now);
                    $envoyees += count($lot);
                });
            } catch (Throwable) {
                // La diffusion a echoue : les evenements gardent `broadcast_at` nul et repartiront au
                // passage suivant. Le navigateur, lui, rattrapera par son fil au prochain chargement.
                continue;
            }
        }

        return $envoyees;
    }

    /**
     * @param array<int, int> $identifiants
     */
    private function markAsSent(array $identifiants, int $now): void
    {
        if ($identifiants === []) {
            return;
        }

        CombatPresentationEvent::query()->whereIn('id', $identifiants)->update(['broadcast_at' => $now]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CombatPresentationEvent $evenement): array
    {
        return [
            'sequence' => (int)$evenement->sequence,
            'at' => (int)$evenement->visible_at,
            'side' => (string)$evenement->side,
            'unit' => (string)$evenement->unit,
            'unit_label' => $this->unitLabel((string)$evenement->unit),
            'amount' => (int)$evenement->amount,
        ];
    }

    private function unitLabel(string $machineName): string
    {
        try {
            return ObjectService::getUnitObjectByMachineName($machineName)->title;
        } catch (Throwable) {
            return $machineName;
        }
    }
}
