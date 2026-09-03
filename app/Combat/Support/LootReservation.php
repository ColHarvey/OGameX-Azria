<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\LootReservationState;
use OGame\Combat\Enums\ReservationRaise;
use OGame\Combat\Exceptions\LootReservationRefused;

/**
 * ⚠ **MECANISME EXPLORATOIRE — INACTIF EN PREMIERE VERSION.**
 *
 * Rien dans le chemin de jeu n'ecrit ni ne lit cette table. Le texte qui suit decrit un mecanisme
 * qui **n'est pas celui du jeu** : il a ete concu, raccorde, puis retire.
 *
 * La regle de premiere version est l'inverse : **aucune ressource n'est immobilisee**. Le defenseur
 * depense librement pendant le combat, et le reglement se fait a la resolution, composante par
 * composante :
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * `LootReservationHasNoWriterTest` interdit tout appelant. Si ce mecanisme est repris un jour, ce
 * sera **une nouvelle decision de jeu**, pas la reprise d'une decision deja prise.
 * *
 * La part des ressources de la cible qu'un combat immobilise le temps de se derouler.
 *
 * ## Le probleme
 *
 * Le butin est calcule a la fermeture du ralliement, mais preleve a la resolution — parfois deux
 * heures plus tard. Entre les deux, le defenseur pourrait depenser exactement ce qui allait lui
 * etre pris. Le resultat serait juste, mais applique a une matiere qui n'existe plus.
 *
 * ## Pourquoi une borne, et pas le butin
 *
 * Reserver le butin **calcule** reviendrait a le reveler. Le defenseur verrait son solde
 * disponible baisser d'un montant qui depend du resultat, et saurait avant le rapport s'il a
 * perdu, et de combien. La reservation est donc une **borne superieure** du butin legalement
 * pillable, etablie sans rien connaitre de l'issue.
 *
 * Elle ignore volontairement la capacite de fret exacte de l'attaquant, ce qui la rend parfois
 * plus large que le butin reel — mais l'empeche de trahir la composition de la flotte ennemie.
 *
 * ## Pourquoi elle ne baisse jamais
 *
 * Une candidate rappelee, une exclusion par les limites du camp, des pertes, une defaite : rien de
 * tout cela ne diminue la reserve. Chacune de ces baisses serait un oracle. **Deux combats partis
 * du meme contexte doivent montrer le meme solde disponible avant le rapport**, que l'un soit
 * gagne et l'autre perdu.
 *
 * ## Les etats
 *
 *     OPEN ──→ SEALED ──→ SETTLED
 *       │
 *       └────→ CANCELLED
 *
 * `CANCELLED` n'est accessible que depuis `OPEN` : une reservation deja reglee ne peut pas etre
 * annulee, sans quoi le butin serait preleve **puis** les fonds liberes.
 *
 * ## Idempotence
 *
 * Sceller ou regler deux fois avec les memes donnees ne change rien. Les memes gestes avec des
 * donnees differentes sont **refuses** : ce n'est pas une reprise, c'est une contradiction, et la
 * corriger en silence reviendrait a choisir arbitrairement laquelle des deux verites garder.
 */
final class LootReservation
{
    /**
     * @param string $reservationId Identite stable de cette reservation.
     * @param int $combatInstanceId Le combat qu'elle protege.
     * @param string $targetBodyKey Le corps celeste concerne. Une planete et sa lune sont deux
     *                              cibles distinctes, donc deux reservations distinctes.
     * @param string $policyVersion La version de la regle de pillage qui a produit la borne.
     *                              Conservee pour que le reglage puisse verifier qu'il applique
     *                              bien la meme.
     * @param LootEnvelope $reserved La borne courante.
     * @param LootReservationState $state
     * @param string|null $sealedWithSnapshotHash L'empreinte de la photographie au scellement.
     * @param string|null $settledWithResolutionId L'identite du reglage.
     * @param string|null $settledWithResultHash L'empreinte du resultat applique.
     * @param LootEnvelope|null $actualLoot Le butin reellement preleve.
     * @param string|null $cancellationId L'identite de l'annulation.
     */
    private function __construct(
        public readonly string $reservationId,
        public readonly int $combatInstanceId,
        public readonly string $targetBodyKey,
        public readonly string $policyVersion,
        private LootEnvelope $reserved,
        private LootReservationState $state,
        private string|null $sealedWithSnapshotHash = null,
        private string|null $settledWithResolutionId = null,
        private string|null $settledWithResultHash = null,
        private LootEnvelope|null $actualLoot = null,
        private string|null $cancellationId = null,
    ) {
    }

    /**
     * Ouvre une reservation, des l'ouverture du ralliement.
     *
     * **Des l'ouverture, pas a la photographie.** Attendre la fermeture laisserait au defenseur la
     * duree du ralliement pour depenser ce qui allait etre photographie.
     */
    public static function open(
        string $reservationId,
        int $combatInstanceId,
        string $targetBodyKey,
        string $policyVersion,
        LootEnvelope $upperBound,
    ): self {
        return new self(
            $reservationId,
            $combatInstanceId,
            $targetBodyKey,
            $policyVersion,
            $upperBound,
            LootReservationState::Open,
        );
    }

    /**
     * S'assure que la borne couvre au moins celle-ci, composante par composante.
     *
     * Le nom dit ce que la methode garantit, et non ce qu'elle fait : **aucune ressource n'est
     * jamais liberee par cette operation**. `raiseTo()` laissait croire qu'une valeur plus basse
     * ferait descendre la borne.
     *
     * Sert quand une cargaison admissible rejoint la cible avant la fermeture : la base logique
     * grossit, donc le maximum pillable aussi.
     *
     * **Une borne plus basse est ignoree, et ce n'est pas une faute.** Le taux pondere par le fret
     * peut reculer pendant le ralliement — une immense flotte sans Decouvreur qui rejoint une
     * attaque ouverte par un Decouvreur ramene le taux de 75 % vers 50 %. Laisser le solde
     * disponible du defenseur remonter lui annoncerait que la composition adverse a change.
     *
     * @param LootEnvelope $atLeast
     * @return ReservationRaise Ce qui s'est reellement passe, pour le journal d'audit.
     */
    public function ensureAtLeast(LootEnvelope $atLeast): ReservationRaise
    {
        if (!$this->state->acceptsARaise()) {
            throw LootReservationRefused::becauseTheBoundIsFrozen($this->state);
        }

        $releve = $this->reserved->raisedTo($atLeast);

        if ($releve->equals($this->reserved)) {
            return ReservationRaise::Unchanged;
        }

        $this->reserved = $releve;

        return ReservationRaise::Raised;
    }

    /**
     * Fige la borne au moment ou la photographie est prise.
     *
     * Rejouable a l'identique. Un second scellement portant une autre empreinte est refuse : deux
     * photographies differentes du meme combat ne peuvent pas etre vraies toutes les deux.
     */
    public function seal(string $snapshotHash): void
    {
        if ($this->state === LootReservationState::Sealed) {
            if ($this->sealedWithSnapshotHash === $snapshotHash) {
                return;
            }

            throw LootReservationRefused::becauseASecondSealDiffers($this->sealedWithSnapshotHash ?? '', $snapshotHash);
        }

        $this->guardTransition(LootReservationState::Sealed);

        $this->state = LootReservationState::Sealed;
        $this->sealedWithSnapshotHash = $snapshotHash;
    }

    /**
     * Preleve le butin reel et libere le reliquat.
     *
     * Rejouable a l'identique. Un second reglage portant une autre identite ou un autre resultat
     * est refuse plutot que corrige : choisir en silence laquelle des deux verites garder serait
     * pire que s'arreter.
     */
    public function settle(LootEnvelope $actualLoot, string $resolutionId, string $resultHash): void
    {
        if ($this->state === LootReservationState::Settled) {
            if ($this->settledWithResolutionId === $resolutionId && $this->settledWithResultHash === $resultHash) {
                return;
            }

            throw LootReservationRefused::becauseASecondSettlementDiffers(
                $this->settledWithResolutionId ?? '',
                $resolutionId
            );
        }

        $this->guardTransition(LootReservationState::Settled);

        if (!$this->reserved->covers($actualLoot)) {
            throw LootReservationRefused::becauseTheLootExceedsTheReservation();
        }

        $this->state = LootReservationState::Settled;
        $this->actualLoot = $actualLoot;
        $this->settledWithResolutionId = $resolutionId;
        $this->settledWithResultHash = $resultHash;
    }

    /**
     * Libere tout, le combat n'ayant pas eu lieu.
     *
     * Accessible depuis `OPEN` seulement. Une defaillance survenue apres le scellement ne s'annule
     * pas : elle conserve le verrou et reprend la resolution.
     */
    public function cancel(string $cancellationId): void
    {
        if ($this->state === LootReservationState::Cancelled) {
            if ($this->cancellationId === $cancellationId) {
                return;
            }

            throw LootReservationRefused::becauseASecondCancellationDiffers(
                $this->cancellationId ?? '',
                $cancellationId
            );
        }

        $this->guardTransition(LootReservationState::Cancelled);

        $this->state = LootReservationState::Cancelled;
        $this->cancellationId = $cancellationId;
    }

    /**
     * La borne courante.
     */
    public function reserved(): LootEnvelope
    {
        return $this->reserved;
    }

    /**
     * Ce qui reste immobilise.
     *
     * Zero une fois reglee ou annulee : dans les deux cas, plus rien n'est retenu.
     */
    public function stillHeld(): LootEnvelope
    {
        return match ($this->state) {
            LootReservationState::Open, LootReservationState::Sealed => $this->reserved,
            LootReservationState::Settled, LootReservationState::Cancelled => LootEnvelope::nothing(),
        };
    }

    /**
     * Le butin reellement preleve, ou null tant que rien ne l'a ete.
     */
    public function actualLoot(): LootEnvelope|null
    {
        return $this->actualLoot;
    }

    public function state(): LootReservationState
    {
        return $this->state;
    }

    /**
     * @param LootReservationState $target
     * @return void
     */
    private function guardTransition(LootReservationState $target): void
    {
        if (!$this->state->canTransitionTo($target)) {
            throw LootReservationRefused::becauseTheTransitionIsForbidden($this->state, $target);
        }
    }
}
