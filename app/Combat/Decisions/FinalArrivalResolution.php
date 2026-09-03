<?php

namespace OGame\Combat\Decisions;

use LogicException;
use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\InvariantCode;

/**
 * Le resultat ferme d'une arrivee : ce que le joueur verra, et ce que la photographie recoit.
 *
 * ## Ce que ce type existe pour rendre impossible
 *
 * `CombatDecisionMatrix` peut rendre « demande au selecteur » ou « demande a l'ordre causal ». Ce
 * sont des decisions — la matrice a tranche qu'il fallait deleguer — mais ce ne sont pas des
 * resultats. Tant qu'aucun type ne les distinguait de l'issue reelle, rien n'empechait un chemin
 * de journaliser `select_by_attack_admission` comme si c'etait le sort d'une flotte.
 *
 * Une resolution ne se construit donc **qu'apres** que chaque delegation a recu sa reponse.
 * `FinalCombatDecision` refuse deja tout ce qui porte une exigence ou un code d'invariant ; ce
 * type ajoute la seconde moitie — la photographie — et les faits internes qui expliquent le
 * resultat sans jamais atteindre un joueur.
 *
 * ## Deux exclusions qui ne disent pas la meme chose
 *
 * Un effet peut etre hors de la photographie parce qu'il est arrive trop tard, ou parce qu'il y
 * figure **deja** : un missile tombe avant l'ouverture a modifie les defenses que la photographie
 * lira, et les compter une seconde fois les retrancherait deux fois.
 *
 * `SnapshotDecision::exclude()` dit la meme chose des deux. La resolution porte donc l'admission
 * causale telle quelle, a cote : `AlreadyInOpeningState` et `OutsideSnapshot` restent lisibles
 * l'une de l'autre sans qu'il faille inventer un code destine au joueur pour un fait interne.
 */
final readonly class FinalArrivalResolution
{
    /**
     * @param FinalCombatDecision $decision Ce qui peut etre montre.
     * @param SnapshotDecision $snapshot Ce que la photographie recoit, ou pourquoi elle ne recoit rien.
     * @param CausalAdmission|null $causalAdmission La reponse de l'ordre causal, quand il a ete consulte.
     * @param bool $deferred Si le resultat vient d'un evenement differe pendant la resolution.
     * @param bool $alreadyApplied Si l'effet etait deja applique : il ne doit pas etre rejoue.
     * @param int|null $recoveredIntoBodyId Le corps ou des actifs sans destination ont ete deposes.
     * @param InvariantCode|null $alert Un defaut a journaliser en critique, sans arreter le monde.
     */
    private function __construct(
        public FinalCombatDecision $decision,
        public SnapshotDecision $snapshot,
        public CausalAdmission|null $causalAdmission,
        public bool $deferred,
        public bool $alreadyApplied,
        public int|null $recoveredIntoBodyId,
        public InvariantCode|null $alert,
    ) {
        if ($recoveredIntoBodyId !== null && $decision->action !== CombatMissionAction::CancelWithoutImpact) {
            throw new LogicException(
                'Une recuperation d actifs accompagne une mission annulee, et elle seule : la flotte ne se pose '
                . 'pas sur sa cible, ses actifs sont deposes ailleurs.'
            );
        }

        if ($snapshot->included && !$decision->action->mayTouchTheSnapshot()) {
            throw new LogicException(
                'Une arrivee qui repart ou qui est annulee ne figure dans aucune photographie : l inclure y '
                . 'ferait chercher des vaisseaux qui ne sont pas la.'
            );
        }
    }

    /**
     * La resolution d'une arrivee, une fois toutes les delegations consommees.
     */
    public static function of(
        FinalCombatDecision $decision,
        SnapshotDecision $snapshot,
        CausalAdmission|null $causalAdmission = null,
        bool $deferred = false,
        bool $alreadyApplied = false,
        int|null $recoveredIntoBodyId = null,
        InvariantCode|null $alert = null,
    ): self {
        return new self(
            $decision,
            $snapshot,
            $causalAdmission,
            $deferred,
            $alreadyApplied,
            $recoveredIntoBodyId,
            $alert
        );
    }

    /**
     * Ce qu'un rapport ou un message peut porter.
     *
     * @return array<string, string|int|null>
     */
    public function toPlayerFacingFacts(): array
    {
        return $this->decision->toPlayerFacingFacts();
    }

    /**
     * Ce qu'un journal de serveur retient, faits internes compris.
     *
     * @return array<string, string|int|bool|null>
     */
    public function toJournalFacts(): array
    {
        return $this->toPlayerFacingFacts() + [
            'included_in_snapshot' => $this->snapshot->included,
            'extends_rally_window' => $this->snapshot->extendsRallyWindow(),
            'causal_admission' => $this->causalAdmission?->value,
            'deferred' => $this->deferred,
            'already_applied' => $this->alreadyApplied,
            'recovered_into_body_id' => $this->recoveredIntoBodyId,
            'alert' => $this->alert?->value,
        ];
    }
}
