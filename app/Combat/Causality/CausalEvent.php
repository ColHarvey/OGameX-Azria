<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\TargetScope;

/**
 * Un fait deja relu sous verrou, tel que le reconciliateur le recoit.
 *
 * ## Le job n'apporte rien de tout cela
 *
 * Un message de file ne transporte qu'un **identifiant**. C'est le service de fermeture qui relit
 * les lignes sous verrou et construit ces faits. Laisser le job transporter un montant, une heure
 * ou un etat reviendrait a faire confiance a une photographie prise par l'emetteur, a un moment que
 * personne ne controle.
 *
 * ## L'empreinte des faits relus
 *
 * Deux lectures du meme evenement doivent donner le meme contenu. L'empreinte permet de le
 * constater : une meme identite portant deux contenus differents est refusee par la tranche, parce
 * qu'elle signifie que quelque chose a change entre deux lectures qui se croyaient equivalentes.
 *
 * ## Ce que cette classe ne fait pas
 *
 * Elle n'applique rien. Le reconciliateur **selectionne et ordonne** ; les effets sont appliques par
 * leurs gestionnaires canoniques, rendus idempotents. Refaire ici la livraison d'un transport
 * creerait une seconde implementation, et les deux divergeraient.
 */
final readonly class CausalEvent
{
    /**
     * @param string $identity L'identite stable de l'evenement, unique dans tout le jeu.
     * @param string $kindVersion Le genre versionne : la forme du contenu peut evoluer.
     * @param DecisionOrder $decision Quand l'engagement est devenu irrevocable.
     * @param EffectOrderKey $effect Quand l'effet se produit, et son rang.
     * @param int $targetBodyId Le corps **exact** vise : planete et lune ne se confondent pas.
     * @param TargetScope $targetScope Ce que l'evenement touche reellement.
     * @param array<int, SnapshotContribution> $contributions Ce que l'evenement apporte a la photographie.
     * @param string $payloadFingerprint L'empreinte des faits relus, pour constater une divergence.
     * @param string $effectFingerprint L'empreinte canonique de l'effet que cet evenement appliquerait.
     *                                  Distincte de la precedente : deux lectures peuvent differer par
     *                                  des faits qui ne changent rien a l'effet, et c'est l'effet que la
     *                                  provenance compare.
     * @param bool $stillValid Si l'evenement n'a ete ni annule ni remplace depuis.
     * @param bool $alreadyApplied S'il a deja produit son effet dans le monde.
     * @param bool $isFoundingInitiator S'il est l'engagement qui a ouvert le combat.
     */
    public function __construct(
        public string $identity,
        public string $kindVersion,
        public DecisionOrder $decision,
        public EffectOrderKey $effect,
        public int $targetBodyId,
        public TargetScope $targetScope,
        public array $contributions,
        public string $payloadFingerprint,
        public string $effectFingerprint,
        public bool $stillValid = true,
        public bool $alreadyApplied = false,
        public bool $isFoundingInitiator = false,
    ) {
        if ($identity === '') {
            throw new InvalidArgumentException(
                'Un evenement causal sans identite stable ne peut etre ni deduplique, ni retrouve dans la '
                . 'provenance de l etat d ouverture : il serait compte deux fois.'
            );
        }

        if ($kindVersion === '') {
            throw new InvalidArgumentException(
                'Un evenement causal sans genre versionne serait relu sous une forme qui n est peut-etre plus '
                . 'la sienne.'
            );
        }

        if ($targetBodyId < 1) {
            throw new InvalidArgumentException(
                'Un evenement causal vise un corps persiste. Des coordonnees ne suffisent pas : une planete et '
                . 'sa lune les partagent, et un combat sur l une ne concerne pas l autre.'
            );
        }

        if ($payloadFingerprint === '') {
            throw new InvalidArgumentException(
                'Sans empreinte, deux lectures divergentes du meme evenement passeraient pour identiques.'
            );
        }

        if ($effectFingerprint === '') {
            throw new InvalidArgumentException(
                'Sans empreinte d effet, la provenance ne pourrait comparer que des identifiants : une '
                . 'mission dont la cargaison a change serait declaree deja appliquee.'
            );
        }
    }

    /**
     * Si cet evenement peut modifier la photographie.
     *
     * **Sans poser de flotte, pour certains.** Un missile modifie des defenses, un chantier acheve
     * ajoute des unites, une recherche change des caracteristiques de combat. Limiter la question aux
     * arrivees qui deposent des vaisseaux laisserait ces effets-la entrer sans decision.
     */
    public function touchesTheSnapshot(): bool
    {
        return $this->contributions !== [];
    }

    /**
     * Si deux lectures decrivent bien le meme evenement.
     *
     * @param self $other
     * @return bool
     */
    public function agreesWith(self $other): bool
    {
        return $this->identity === $other->identity
            && $this->kindVersion === $other->kindVersion
            && $this->payloadFingerprint === $other->payloadFingerprint
            && $this->effectFingerprint === $other->effectFingerprint
            && $this->targetBodyId === $other->targetBodyId
            && $this->effect->compareTo($other->effect) === 0
            && $this->decision->compareTo($other->decision) === 0;
    }
}
