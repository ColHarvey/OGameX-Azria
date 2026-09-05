<?php

namespace OGame\Combat\Presentation;

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
 * ## La garantie exacte : **au moins une fois**, jamais « exactement une fois »
 *
 * L'envoi traverse le reseau, et la marque est ecrite ensuite. Entre les deux, tout peut arriver :
 * un envoi accepte par le transport puis un processus qui meurt avant d'ecrire la marque, ou un
 * acquittement perdu alors que le message est passe. La perte repartira donc au passage suivant, et
 * le joueur pourrait la recevoir deux fois. **C'est un choix**, pas un oubli : marquer avant
 * d'envoyer echangerait cette repetition contre une perte definitive, et une perte ne se rattrape
 * pas.
 *
 * Ce qui rend la repetition inoffensive : chaque perte porte une **identite stable** — la bataille
 * et le rang, jamais le rang seul, deux batailles pouvant avoir le meme — et le navigateur ignore
 * une identite qu'il affiche deja, sans rejouer son animation. Le fil (`/ajax/combat/{id}/timeline`)
 * reste la source durable : une reconnexion le relit et retrouve tout, meme ce qui n'est jamais
 * parti.
 *
 * `broadcast_at` dit donc **« une tentative a ete acquittee par le transport »**, et rien d'autre :
 * ni que le joueur l'a lue, ni qu'elle lui est parvenue.
 *
 * ## Aucun verrou pendant un appel reseau
 *
 * L'emission ne vit dans aucune transaction. Elle ne publie que des faits deja commits — le fil est
 * ecrit a la cloture, la resolution economique est terminee depuis longtemps —, et la marque est une
 * ecriture d'une ligne, posee apres. Tenir un verrou de reglement pendant un aller-retour reseau
 * ferait attendre une bataille sur la disponibilite d'un serveur de diffusion.
 *
 * ## A qui, et quoi
 *
 * L'inscription decide : un evenement porte la clef d'un participant, et le joueur inscrit sous
 * cette clef est le seul destinataire. C'est la photographie qui fait foi — un corps ou une flotte
 * qui change de mains ne detourne pas les pertes deja subies. Et rien d'autre ne part : ni perte
 * future, ni calendrier, ni echeance de la bataille.
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
                $this->markAsAttempted([(int)$evenement->id], $now);

                continue;
            }

            $lots[$joueur . '|' . (int)$evenement->combat_instance_id][] = $evenement;
        }

        $envoyees = 0;

        foreach ($lots as $clef => $lot) {
            [$joueur, $combat] = array_map('intval', explode('|', $clef));

            try {
                // **Hors transaction, et un lot a la fois.** Le marquage suit l'envoi de ce lot
                // seulement : si le lot suivant echoue, celui-ci reste parti et marque, et l'autre
                // repartira — jamais l'inverse.
                event(new CombatLossesPublished($joueur, $combat, array_map(
                    fn (CombatPresentationEvent $evenement): array => $this->row($evenement),
                    $lot
                )));

                $this->markAsAttempted(array_map(static fn (CombatPresentationEvent $e): int => (int)$e->id, $lot), $now);
                $envoyees += count($lot);
            } catch (Throwable) {
                // Le transport a refuse : ces pertes gardent `broadcast_at` nul et repartiront au
                // passage suivant. Le navigateur, lui, les retrouve deja par son fil.
                continue;
            }
        }

        return $envoyees;
    }

    /**
     * Marque une tentative acquittee par le transport — pas une lecture par le joueur.
     *
     * @param array<int, int> $identifiants
     */
    private function markAsAttempted(array $identifiants, int $now): void
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
            // **L'identite stable d'une perte** : la bataille et le rang. Le rang seul ne suffit pas —
            // deux batailles simultanees portent chacune un rang 1, et le navigateur confondrait.
            'key' => (int)$evenement->combat_instance_id . ':' . (int)$evenement->sequence,
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
