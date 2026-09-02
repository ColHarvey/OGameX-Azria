<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;

/**
 * Selectionne et ordonne les faits d'une fermeture. Rien d'autre.
 *
 * ## Ce que cette classe ne touche jamais
 *
 * Ni base, ni horloge, ni journal, ni verrou. Elle recoit des faits **deja relus sous verrou** par
 * `RallyClosureService`, et rend un plan. Deux appels avec les memes entrees donnent le meme plan,
 * qu'ils aient lieu a une seconde d'intervalle ou deux heures plus tard — c'est ce qui rend un
 * worker en retard inoffensif.
 *
 * Elle **n'applique rien non plus**. Refaire ici la livraison d'un transport, l'impact d'un missile
 * ou l'achevement d'un chantier creerait une seconde implementation de chacun, et les deux
 * divergeraient. Les effets sont appliques par leurs gestionnaires canoniques, rendus idempotents.
 *
 * ## La regle
 *
 * Pour tout effet autre que l'initiateur :
 *
 *     decision irrevocable   strictement avant l'ouverture
 * ET  effet planifie         strictement avant la fermeture
 * ET  evenement encore valide
 * ET  effet pas deja contenu dans l'etat protege
 *
 * L'initiateur est un fait fondateur, verifie separement : il n'a pas a preceder une ouverture qu'il
 * a lui-meme provoquee, et il survit a une fenetre nulle.
 *
 * ## Les deux ordres ne se melangent jamais
 *
 *     DecisionOrder  -> **si** l'engagement appartient a la photographie
 *     EffectOrderKey -> **dans quel ordre** les effets admissibles s'appliquent
 *
 * Trier les effets par ordre de decision donnerait un resultat plausible et faux : un transport
 * decide hier et arrivant dans dix secondes ne s'applique pas avant un transport decide il y a une
 * heure et arrive il y a une minute.
 *
 * ## Le double comptage, et pourquoi il survit aux comparaisons justes
 *
 * L'etat protege pris a l'ouverture contient deja les effets appliques avant elle. Un transport qui
 * a livre 100 metal hier satisfait les **deux** barrieres : engagement anterieur a l'ouverture,
 * effet anterieur a la fermeture. Sans provenance, ses 100 seraient ajoutes une seconde fois.
 *
 * `AlreadyInOpeningState` est donc une issue distincte : l'evenement **entre** dans la photographie,
 * mais son effet ne doit pas etre rejoue.
 */
final class CausalOrderReconciler
{
    /**
     * Le plan causal d'une fermeture.
     *
     * @param ProtectedOpeningState $protectedOpeningState L'etat d'ouverture, avec sa provenance.
     * @param string $verifiedOpener L'identite de l'engagement fondateur, verifiee ailleurs.
     * @param CausalWindow $causalWindow Les deux barrieres.
     * @param CompleteEventSlice $completeEventSlice Tous les candidats, declares complets.
     * @return CausallyReconciledSnapshot
     */
    public function reconcile(
        ProtectedOpeningState $protectedOpeningState,
        string $verifiedOpener,
        CausalWindow $causalWindow,
        CompleteEventSlice $completeEventSlice,
    ): CausallyReconciledSnapshot {
        if ($verifiedOpener === '') {
            throw new InvalidArgumentException(
                'Un combat a toujours un engagement fondateur. Sans lui, la photographie n aurait aucun '
                . 'attaquant et le combat n aurait pas eu lieu d etre ouvert.'
            );
        }

        $evenements = $completeEventSlice->all();
        $fondateurTrouve = false;

        $reconcilies = [];

        foreach ($evenements as $evenement) {
            if ($evenement->identity === $verifiedOpener) {
                $fondateurTrouve = true;
            }

            $reconcilies[] = $this->decideOf($evenement, $verifiedOpener, $protectedOpeningState, $causalWindow);
        }

        if (!$fondateurTrouve) {
            throw new InvalidArgumentException(
                'L engagement fondateur « ' . $verifiedOpener . ' » ne figure pas dans la tranche. Une tranche '
                . 'qui omet l evenement ayant ouvert le combat n est pas complete, quoi qu elle en dise.'
            );
        }

        // **L'ordre des effets, jamais celui des decisions.** Un tri stable n'est pas requis : la cle
        // d'effet departage deja les egalites par genre puis par identifiant, et deux evenements ne
        // peuvent pas partager les trois.
        usort(
            $reconcilies,
            static fn (ReconciledEvent $a, ReconciledEvent $b): int
                => $a->event->effect->compareTo($b->event->effect)
        );

        return new CausallyReconciledSnapshot($protectedOpeningState, $causalWindow, $reconcilies);
    }

    /**
     * Ce qu'il advient d'un evenement.
     *
     * @param CausalEvent $event
     * @param string $verifiedOpener
     * @param ProtectedOpeningState $opening
     * @param CausalWindow $window
     * @return ReconciledEvent
     */
    private function decideOf(
        CausalEvent $event,
        string $verifiedOpener,
        ProtectedOpeningState $opening,
        CausalWindow $window,
    ): ReconciledEvent {
        // L'initiateur d'abord : il n'a pas a preceder une ouverture qu'il a provoquee, et il survit
        // a une fenetre nulle.
        if ($event->identity === $verifiedOpener) {
            return new ReconciledEvent(
                $event,
                CausalAdmission::FoundingInitiator,
                'Engagement fondateur, verifie separement des deux barrieres.'
            );
        }

        if (!$event->stillValid) {
            return new ReconciledEvent(
                $event,
                CausalAdmission::NotApplicable,
                'Annule ou remplace depuis : le rejouer appliquerait un effet que le jeu a retire.'
            );
        }

        if (!$opening->coversTheTargetOf($event)) {
            return new ReconciledEvent(
                $event,
                CausalAdmission::NotApplicable,
                'Vise un autre corps, ou une cible qui n est pas un corps celeste. Une egalite de '
                . 'coordonnees ne fait entrer personne dans une photographie.'
            );
        }

        // **La provenance avant les barrieres.** Un evenement deja reflete les satisfait toutes les
        // deux : c'est precisement pourquoi le tester apres les aurait laisse passer en
        // « a appliquer », et son effet aurait ete compte une seconde fois.
        if ($opening->provenance->alreadyReflects($event)) {
            return new ReconciledEvent(
                $event,
                CausalAdmission::AlreadyInOpeningState,
                'Deja reflete dans l etat d ouverture : il entre dans la photographie sans etre rejoue.'
            );
        }

        if (!$window->admitsDecision($event->decision)) {
            return new ReconciledEvent(
                $event,
                CausalAdmission::OutsideSnapshot,
                'Engagement pris a l ouverture ou apres : une egalite avec la barriere compte pour apres.'
            );
        }

        if (!$window->admitsEffect($event->effect)) {
            return new ReconciledEvent(
                $event,
                CausalAdmission::OutsideSnapshot,
                'Effet prevu a la fermeture ou apres : l exclusion precede le departage par genre et par '
                . 'identifiant.'
            );
        }

        if (!$event->touchesTheSnapshot()) {
            return new ReconciledEvent(
                $event,
                CausalAdmission::OutsideSnapshot,
                'Admissible dans le temps, mais il n apporte rien a la photographie.'
            );
        }

        return new ReconciledEvent(
            $event,
            CausalAdmission::AppliedBeforeSnapshot,
            'Engage avant l ouverture et prevu avant la fermeture : applique, puis photographie.'
        );
    }
}
