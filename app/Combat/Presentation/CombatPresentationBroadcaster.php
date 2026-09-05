<?php

namespace OGame\Combat\Presentation;

use Closure;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Events\CombatLossesPublished;
use OGame\Events\CombatStateChanged;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
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
 * ## Le debut et la fin s'annoncent aussi, meme sans aucune perte
 *
 * Un canal qui ne porterait que des pertes laisserait le premier combat — et le rapport, a la
 * fin — attendre le rafraichissement de secours. `publishStateChanges()` compare l'etat de chaque
 * bataille au dernier etat annonce (`broadcast_status`) et envoie la difference a **toutes** les
 * parties. La meme garantie s'applique : au moins une fois, marque posee apres l'envoi ; une annonce
 * d'etat repetee est sans effet, le navigateur ne fait que relire sa carte.
 *
 * ## La garde, avant chaque lot
 *
 * Sous bail, un diffuseur peut etre suspendu pendant un appel reseau plus longtemps que la
 * tolerance, et un autre prend alors la releve sans qu'il le sache. Le bail ne prouve qu'un
 * detenteur en base, pas un seul emetteur : c'est pourquoi la garde — un battement conditionne au
 * detenteur — est consultee **avant chaque lot**, et non une fois par tour. Un lot deja engage
 * peut donc partir deux fois, une fois par chacun ; c'est admis, le navigateur deduplique. Ce qui
 * n'est jamais admis : qu'un diffuseur qui a perdu son bail commence un lot de plus.
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
    /**
     * @param Closure(): bool|null $stillHolds Rend faux des que ce diffuseur ne doit plus emettre ;
     *                                          consultee avant chaque lot.
     */
    public function publish(int $now, int $batchSize = 500, Closure|null $stillHolds = null): int
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
            // **La garde avant chaque lot** : un bail perdu pendant le lot precedent arrete ici, et
            // ce qui reste repartira par le detenteur suivant.
            if ($stillHolds !== null && !$stillHolds()) {
                break;
            }

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
     * Annonce les batailles dont l'etat a change depuis la derniere annonce. Rend le nombre
     * d'annonces envoyees.
     */
    /**
     * @param Closure(): bool|null $stillHolds Rend faux des que ce diffuseur ne doit plus emettre ;
     *                                          consultee avant chaque bataille.
     */
    public function publishStateChanges(int $now, int $batchSize = 200, Closure|null $stillHolds = null): int
    {
        $batailles = CombatInstance::query()
            ->where(function ($requete): void {
                $requete->whereNull('broadcast_status')->orWhereColumn('broadcast_status', '!=', 'status');
            })
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        $annonces = 0;

        foreach ($batailles as $bataille) {
            if ($stillHolds !== null && !$stillHolds()) {
                break;
            }

            $etat = $bataille->status;
            $rapport = $etat === CombatState::Resolved && $bataille->battle_report_id !== null;
            $libelle = __('t_ingame.combat.status_' . $etat->value);

            try {
                foreach ($this->partiesOf($bataille) as $joueur) {
                    event(new CombatStateChanged($joueur, (int)$bataille->id, $etat->value, $libelle, $rapport));
                    $annonces++;
                }
            } catch (Throwable) {
                // Une partie n'a pas ete jointe : l'etat reste a annoncer, et le passage suivant
                // le renverra a toutes — une annonce repetee ne coute rien au navigateur.
                continue;
            }

            CombatInstance::query()->whereKey($bataille->id)->update(['broadcast_status' => $etat->value]);
        }

        return $annonces;
    }

    /**
     * Les joueurs qui sont partie a cette bataille, quel que soit son etat.
     *
     * Les inscrits d'abord — la photographie, qui fige le joueur. Puis les proprietaires des
     * missions liees et l'initiateur, seules traces pendant le ralliement ou personne n'est encore
     * inscrit. Et le proprietaire vivant du corps vise, **seulement** si la garnison n'est pas
     * inscrite : avant la cloture il n'existe aucune autre source, et le corps n'a pas encore pu
     * changer de mains sous une bataille qui n'existait pas.
     *
     * @return array<int, int>
     */
    private function partiesOf(CombatInstance $bataille): array
    {
        $joueurs = [];
        $garnisonInscrite = false;
        $clefGarnison = $bataille->target_planet_id === null ? null : CombatParticipantKey::forPlanet((int)$bataille->target_planet_id);

        foreach (CombatParticipant::query()->where('combat_instance_id', $bataille->id)->get(['participant_key', 'player_id']) as $inscription) {
            $joueurs[(int)$inscription->player_id] = true;

            if ($clefGarnison !== null && (string)$inscription->participant_key === $clefGarnison) {
                $garnisonInscrite = true;
            }
        }

        foreach (FleetMission::query()->where('combat_instance_id', $bataille->id)->pluck('user_id') as $proprietaire) {
            $joueurs[(int)$proprietaire] = true;
        }

        if ($bataille->mission_id !== null) {
            $initiateur = FleetMission::query()->whereKey($bataille->mission_id)->value('user_id');

            if ($initiateur !== null) {
                $joueurs[(int)$initiateur] = true;
            }
        }

        if (!$garnisonInscrite && $bataille->target_planet_id !== null) {
            $proprietaire = Planet::query()->whereKey($bataille->target_planet_id)->value('user_id');

            if ($proprietaire !== null) {
                $joueurs[(int)$proprietaire] = true;
            }
        }

        unset($joueurs[0]);

        return array_keys($joueurs);
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
