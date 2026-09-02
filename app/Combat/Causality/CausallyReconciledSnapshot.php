<?php

namespace OGame\Combat\Causality;

use LogicException;
use OGame\Combat\Enums\SnapshotContribution;

/**
 * Le seul etat a partir duquel une photographie peut etre prise.
 *
 * ## Pourquoi ce type existe
 *
 * `FinalArrivalResolution` n'accepte que lui, jamais l'etat brut d'ouverture. Le consommateur causal
 * devient ainsi **impossible a contourner** : il n'existe aucun chemin qui parte du solde vivant ou
 * de l'etat d'ouverture seul.
 *
 * ## Ce qu'il porte, et pourquoi separement
 *
 *     a appliquer  -> les effets qui doivent encore etre produits, dans l'ordre des effets
 *     deja reflete -> ceux que l'etat d'ouverture contient, a ne surtout pas rejouer
 *     hors photo   -> ceux qui ont lieu mais n'entrent pas dans ce combat
 *
 * Confondre les deux premiers ferait compter deux fois une livraison deja encaissee. C'est le defaut
 * que toute cette classe existe pour rendre impossible.
 *
 * ## Les contributions sont des traces, pas des additions
 *
 * Un effet n'est jamais ajoute deux fois au total global. Les contributions listees ici disent **ce
 * qu'un evenement apporte**, pour l'audit et le rapport ; l'etat global, lui, est declare une seule
 * fois, par le service de fermeture.
 */
final readonly class CausallyReconciledSnapshot
{
    /**
     * @param ProtectedOpeningState $opening L'etat d'ouverture, avec sa provenance.
     * @param CausalWindow $window Les deux barrieres.
     * @param array<int, ReconciledEvent> $reconciled Tous les evenements, avec leur issue.
     */
    public function __construct(
        public ProtectedOpeningState $opening,
        public CausalWindow $window,
        public array $reconciled,
    ) {
        $vues = [];
        $precedent = null;

        foreach ($reconciled as $event) {
            if (isset($vues[$event->event->identity])) {
                throw new LogicException(
                    'L evenement « ' . $event->event->identity . ' » apparait deux fois dans un etat reconcilie : '
                    . 'son effet serait compte deux fois.'
                );
            }

            $vues[$event->event->identity] = true;

            if ($precedent !== null && $precedent->compareTo($event->event->effect) > 0) {
                throw new LogicException(
                    'Les evenements d un etat reconcilie sont ordonnes par cle d effet. Les trier autrement — '
                    . 'par ordre de decision, par identifiant SQL ou par heure du worker — donnerait un '
                    . 'resultat plausible et faux.'
                );
            }

            $precedent = $event->event->effect;
        }
    }

    /**
     * Les effets qui doivent encore etre produits, dans l'ordre des effets.
     *
     * @return array<int, ReconciledEvent>
     */
    public function toApply(): array
    {
        return array_values(array_filter(
            $this->reconciled,
            static fn (ReconciledEvent $event): bool => $event->admission->requiresApplication()
        ));
    }

    /**
     * Ceux que l'etat d'ouverture reflete deja, et qu'il ne faut pas rejouer.
     *
     * @return array<int, ReconciledEvent>
     */
    public function alreadyReflected(): array
    {
        return array_values(array_filter(
            $this->reconciled,
            static fn (ReconciledEvent $event): bool => $event->admission === CausalAdmission::AlreadyInOpeningState
        ));
    }

    /**
     * Tout ce qui entre dans la photographie, applique ou deja reflete.
     *
     * @return array<int, ReconciledEvent>
     */
    public function inTheSnapshot(): array
    {
        return array_values(array_filter(
            $this->reconciled,
            static fn (ReconciledEvent $event): bool => $event->admission->entersTheSnapshot()
        ));
    }

    /**
     * Les contributions apportees a la photographie, par genre, sans doublon.
     *
     * Des **traces d'audit** : elles disent ce que chaque evenement apporte. L'etat global reste
     * declare une seule fois par le service de fermeture.
     *
     * @return array<int, SnapshotContribution>
     */
    public function contributions(): array
    {
        $genres = [];

        foreach ($this->inTheSnapshot() as $event) {
            foreach ($event->event->contributions as $contribution) {
                $genres[$contribution->value] = $contribution;
            }
        }

        ksort($genres);

        return array_values($genres);
    }

    /**
     * L'etat reconcilie, sous une forme lisible dans un message d'essai.
     *
     * @return array<int, string>
     */
    public function describe(): array
    {
        return array_map(
            static fn (ReconciledEvent $event): string
                => $event->admission->value . ' | ' . $event->event->identity,
            $this->reconciled
        );
    }
}
