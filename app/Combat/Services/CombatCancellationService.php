<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\UnreturnableFleet;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use RuntimeException;

/**
 * L'annulation d'un combat durable : les flottes rentrent, le corps se libere, rien n'est applique.
 *
 * ## La sortie d'exploitation
 *
 * Un combat que le reglement ne sait plus appliquer — resultat fige corrompu, effectif qui ne
 * concorde plus, echelle que le stockage ne porte pas — est mis de cote apres cinq echecs. Laisse
 * tel quel, il tiendrait son corps pour toujours : aucune nouvelle barriere ne pourrait etre
 * posee, et ses flottes attendraient un reglement qui ne viendra pas.
 *
 * Ce service est ce chemin. Il **n'applique rien** : la bataille calculee n'est jamais ecrite, le
 * defenseur ne perd rien, l'attaquant ne prend rien. Les flottes attaquantes rentrent avec ce
 * qu'elles portaient, le corps est libere, et chaque joueur apprend pourquoi.
 *
 * ## Jamais par un joueur
 *
 * Aucune cause d'annulation n'est a la portee d'un joueur, et `CombatState::canBeCancelledFor()`
 * le verifie a chaque appel : un attaquant qui connait deja l'issue defavorable d'une bataille ne
 * peut pas la faire disparaitre. Un combat en cours d'application ou applique ne s'annule plus du
 * tout — ce qui a ete ecrit l'a ete.
 *
 * ## Audite
 *
 * La cause, la note de l'administrateur et l'instant sont persistes sur l'instance, a cote de
 * l'empreinte des faits geles que l'annulation abandonne. Chaque flotte renvoyee — attaquante ou
 * renfort defensif — recoit un avis avec la cause et la note ; la cible aussi. Une ligne de journal
 * nomme le combat, la cause, la note, l'empreinte abandonnee et les flottes rendues de chaque camp.
 * Une annulation qu'on ne retrouve pas apres coup n'est pas une sortie d'exploitation, c'est une
 * disparition.
 *
 * ## L'effectif se verifie avant de changer d'etat
 *
 * Les flottes a rendre sont celles que la photographie a inscrites, des deux camps, plus rien — et
 * **les deux liens qui les nomment sont confrontes** : l'inscription, qui fixe le camp, et la
 * colonne `combat_instance_id` de la mission, qui dit qui est retenu sur le corps. Une inscription
 * dont la flotte a ete effacee, une flotte retenue sans etre inscrite, une inscrite que retient un
 * autre combat, un camp double : chacun de ces ecarts arrete l'annulation avant tout changement
 * d'etat. Le corps reste tenu, et l'exploitation lit pourquoi. Liberer un corps en pretendant avoir
 * rendu un effectif qu'on ne sait pas decrire serait la perte silencieuse que ce chemin existe pour
 * eviter.
 *
 * Avant la fermeture, personne n'est encore inscrit : le lien porte seul l'effectif, et le camp se
 * lit du genre de la mission. Sans cela, **aucun combat en ralliement n'aurait pu etre annule** — ni
 * par la commande d'exploitation, ni par la suppression d'un compte — et son corps serait reste tenu.
 *
 * ## L'ordre des verrous
 *
 * Le meme que partout : barriere -> instance -> union -> missions par identifiant croissant, puis
 * **les corps qui decident du retour** — origine, planete associee, planetes du proprietaire —, eux
 * aussi par identifiant croissant.
 * Aucun corps n'est ecrit — les retours ne creditent rien avant d'arriver.
 */
final class CombatCancellationService
{
    public function __construct(
        private CombatRosterReader $roster = new CombatRosterReader(),
        private ReturnDestinationResolver $destinations = new ReturnDestinationResolver(),
    ) {
    }

    /**
     * Annule le combat, ou explique pourquoi il n'y avait rien a annuler.
     *
     * @param string $note Ce que l'administrateur a constate. **Jamais vide** : une annulation sans
     *                     note est une annulation dont personne ne saura la raison concrete.
     * @param Closure(FleetMission, int, int, ResolvedReturnDestination): void $creerRetour Cree la
     *        mission retour d'une flotte tenue : la duree du trajet aller, l'instant du depart et le
     *        corps ou le plan de repli la fait atterrir lui sont imposes.
     */
    public function cancel(int $combatInstanceId, CombatCancellationCause $cause, string $note, Closure $creerRetour, int $now): CombatCancellationOutcome
    {
        if (trim($note) === '') {
            throw new RuntimeException('Une annulation exige une note d exploitation : rien n est annule sans dire ce qui a ete constate.');
        }

        // **Le journal se remplit dedans et s'ecrit apres le commit.** Une ligne posee dans la
        // transaction survivrait a son annulation : un echec tardif laisserait une trace affirmant
        // une annulation que la base n'a jamais enregistree, et l'exploitation chercherait un combat
        // toujours en cours. Les avis, eux, restent dans l'outbox transactionnelle — ils doivent
        // disparaitre avec elle.
        //
        // L'ecriture est remise a `DB::afterCommit`, et non placee apres l'appel : ce service est
        // parfois **imbrique** — la suppression d'un compte annule plusieurs combats dans une seule
        // transaction proprietaire —, et la sortie de la transaction interne n'est alors qu'un
        // relachement de point de sauvegarde. Sortir de la fermeture ne prouverait plus rien.
        $journal = [];

        $resultat = DB::transaction(function () use ($combatInstanceId, $cause, $note, $creerRetour, $now, &$journal): CombatCancellationOutcome {
            $barriere = CelestialBodyCombatBarrier::query()
                ->where('combat_instance_id', $combatInstanceId)
                ->lockForUpdate()
                ->first();

            $combat = CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()->first();

            if ($combat === null) {
                return CombatCancellationOutcome::unknownCombat();
            }

            if ($combat->status->isFinal()) {
                return CombatCancellationOutcome::alreadyOver();
            }

            // **La machine d'etats tranche, pas ce service.** Elle refuse toute cause a la portee
            // d'un joueur, et tout combat dont l'application a commence.
            if (!$combat->status->canBeCancelledFor($cause)) {
                throw new RuntimeException(
                    'Le combat ' . $combat->id . ' en « ' . $combat->status->value . ' » ne peut pas etre annule pour « '
                    . $cause->value . ' ».'
                );
            }

            if ($combat->union_id !== null) {
                FleetUnion::query()->whereKey($combat->union_id)->lockForUpdate()->first();
            }

            // **L'effectif des deux camps, tel que la photographie l'a inscrit.** Les renforts
            // defensifs inscrits sont engages autant que les attaquantes : la bataille etait calculee
            // avec eux, et une sortie d'exploitation qui ne les rendrait pas les laisserait
            // stationner sur un corps qui ne tient plus de combat.
            //
            // **Les deux liens sont confrontes**, et pas seulement l'inscription. Une inscription
            // dont la flotte a ete effacee, une flotte retenue par le combat sans y etre inscrite,
            // une flotte inscrite ici mais retenue ailleurs, un camp double : chacun de ces ecarts
            // arrete l'annulation avant tout changement d'etat. La cle etrangere ne garantissait que
            // la validite d'un pointeur, jamais son existence, et la seule presence de l'initiatrice
            // ne disait rien d'une attaquante secondaire ou d'un renfort disparu.
            [$attaquantes, $defensives] = $this->roster->enrolmentOf($combat);

            if (!in_array((int)$combat->mission_id, $attaquantes, true)) {
                throw new RuntimeException(
                    'La mission initiatrice ' . $combat->mission_id . ' n est pas inscrite parmi les attaquants du combat '
                    . $combat->id . ' : l effectif ne se verifie pas, rien n est annule.'
                );
            }

            $identifiants = array_values(array_unique(array_merge($attaquantes, $defensives)));
            sort($identifiants);

            // Chaque inscrite existe : `enrolmentOf()` vient de le verifier sur les deux liens, et
            // s'est arrete si l'un des deux nommait une flotte que l'autre ignore.
            $missions = FleetMission::query()
                ->whereIn('id', $identifiants)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // **Tous les plans d'abord, l'etat ensuite.** Une flotte sans destination legitime, ou
            // dont le trajet aller ne se lit pas, arrete l'annulation : le corps reste tenu et rien
            // n'est ecrit. Liberer le corps en pretendant que toutes les flottes sont rendues serait
            // exactement la perte silencieuse que ce chemin existe pour eviter.
            //
            // **Deux passes, et un verrou entre les deux.** La premiere dit quelles lignes il faut
            // tenir ; la seconde decide, ces lignes tenues. Un plan qui change entre les deux est un
            // corps qui a bouge sous nos pieds : on s'arrete plutot que d'ecrire une destination qui
            // n'est deja plus celle qu'on a choisie.
            $aRendre = [];

            foreach ($missions as $mission) {
                if ((int)$mission->processed === 1) {
                    continue;
                }

                $trajet = (int)$mission->time_arrival - (int)$mission->time_departure;

                if ($trajet < 1) {
                    throw new UnreturnableFleet($combat->id, $mission->id, $trajet);
                }

                $aRendre[$mission->id] = [
                    $mission,
                    $trajet,
                    $this->destinations->foreseeFor($mission),
                ];
            }

            // 5. **Les corps qui decident du retour**, par identifiant croissant, apres les missions.
            //
            // Pas seulement la destination retenue : **tout ce dont l'etat a fait pencher le choix**.
            // Le recours suit un ordre — origine, planete associee, planete mere — et tenir le seul
            // gagnant rendrait sa ligne stable sans figer la raison pour laquelle il a gagne.
            $decisifs = [];

            foreach ($aRendre as [, , $pressenti]) {
                foreach ($pressenti->decidingBodyIds as $identifiant) {
                    $decisifs[$identifiant] = true;
                }
            }

            $corpsDeRetour = array_keys($decisifs);
            sort($corpsDeRetour);

            // **L'union de tous les corps decisifs, d'un seul coup.** Verrouiller mission par
            // mission romprait l'ordre croissant global : deux annulations concurrentes
            // s'attendraient mutuellement. C'est la seule raison pour laquelle ce chemin compose
            // les trois etapes au lieu d'appeler la resolution complete.
            $this->destinations->holdTheDecidingBodies($corpsDeRetour);

            $plans = [];

            foreach ($aRendre as $identifiant => [$mission, $trajet, $pressenti]) {
                $plans[$identifiant] = [
                    $this->destinations->confirm($mission, $pressenti, $combat->id),
                    $trajet,
                ];
            }

            $combat->status = CombatState::Cancelled;
            $combat->cancellation_cause = $cause;
            $combat->cancellation_note = trim($note);
            $combat->cancelled_at = $now;
            $combat->save();

            // **L'empreinte que cette annulation abandonne.** Les faits geles a l'ouverture, et la
            // bataille calculee sur eux, ne seront jamais appliques : l'avis et le journal portent
            // leur empreinte pour que l'audit relie l'annulation a ce qu'elle a ecarte.
            $empreinteAbandonnee = $combat->frozen_facts_fingerprint;

            // **Le corps se libere.** C'est tout l'objet : sans cela, l'annulation laisserait la
            // barriere qu'elle existe pour lever.
            $barriere?->delete();

            $rendues = 0;
            $renfortsRendus = 0;
            $dejaParties = 0;

            foreach ($missions as $mission) {
                // **Une inscrite deja traitee n'existe que par corruption ou reparation manuelle** :
                // l'arrivee ne traite pas, le rappel d'une engagee est refuse, et le reglement ne
                // traite qu'en rendant le combat final. C'est precisement l'etat qu'une sortie
                // d'exploitation rencontre — elle le tolere sans creer un second retour, et le dit.
                if ((int)$mission->processed === 1) {
                    $dejaParties++;

                    continue;
                }

                CombatOutboxMessage::query()->updateOrCreate(
                    [
                        'combat_instance_id' => $combat->id,
                        'participant_key' => CombatParticipantKey::forFleet($mission->id),
                        'kind' => CombatOutboxKind::CombatCancelled->value,
                    ],
                    [
                        'payload' => $this->noticePayload($combat, $cause, $note, $now, $empreinteAbandonnee),
                        'available_at' => $now,
                    ]
                );

                // **Le retour se decrit, il ne se fabrique pas en reecrivant l'aller.** Il part de
                // l'instant d'annulation, dure le trajet aller, et se pose la ou son plan le dit ; la
                // mission aller garde son heure d'arrivee, qui est un fait de l'admission, de l'ordre
                // causal et de l'audit.
                [$destination, $trajet] = $plans[$mission->id];

                $mission->processed = 1;
                $mission->save();

                ($creerRetour)($mission, $trajet, $now, $destination);

                if (in_array((int)$mission->id, $defensives, true)) {
                    $renfortsRendus++;
                } else {
                    $rendues++;
                }
            }

            // **La cible l'apprend aussi.** Un combat qui disparait sans un mot laisse le defenseur
            // devant un corps redevenu libre sans savoir pourquoi ; l'avis porte la meme cause, la
            // meme note et la meme empreinte que ceux des flottes.
            CombatOutboxMessage::query()->updateOrCreate(
                [
                    'combat_instance_id' => $combat->id,
                    'participant_key' => CombatParticipantKey::forPlanet((int)$combat->target_planet_id),
                    'kind' => CombatOutboxKind::CombatCancelled->value,
                ],
                [
                    'payload' => $this->noticePayload($combat, $cause, $note, $now, $empreinteAbandonnee),
                    'available_at' => $now,
                ]
            );

            $journal = [
                'combat_instance_id' => $combat->id,
                'cause' => $cause->value,
                'note' => trim($note),
                'abandoned_fingerprint' => $empreinteAbandonnee,
                'target_body_id' => $combat->target_planet_id,
                'fleets_sent_home' => $rendues,
                'defenders_sent_home' => $renfortsRendus,
                'fleets_already_gone' => $dejaParties,
                'at' => $now,
            ];

            return CombatCancellationOutcome::cancelled($rendues, $dejaParties, $renfortsRendus);
        });

        if ($journal !== []) {
            DB::afterCommit(static function () use ($journal): void {
                Log::warning('Combat durable annule.', $journal);
            });
        }

        return $resultat;
    }

    /**
     * Ce que tout avis d'annulation porte : la cause, la note, l'instant, l'empreinte abandonnee.
     *
     * @return array<string, mixed>
     */
    private function noticePayload(CombatInstance $combat, CombatCancellationCause $cause, string $note, int $now, string|null $empreinteAbandonnee): array
    {
        return [
            'cause' => $cause->value,
            'note' => trim($note),
            'cancelled_at' => $now,
            'abandoned_fingerprint' => $empreinteAbandonnee,
            'target_body_id' => $combat->target_planet_id,
            'galaxy' => $combat->galaxy,
            'system' => $combat->system,
            'position' => $combat->position,
        ];
    }
}
