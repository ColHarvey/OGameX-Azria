<?php

namespace OGame\Combat\Admission;

use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Exceptions\ContradictoryAdmissionInput;

/**
 * Un renfort exterieur candidat : une Defense ACS en aller, et rien d'autre.
 *
 * ## Pourquoi un type, et non un controle dans le selecteur
 *
 * Le selecteur defensif verifiait tardivement le genre et le sens de vol, et rendait
 * `NoCombatEffect` pour un retour ou un deploiement personnel. La phrase est vraie — ces mouvements
 * ne consomment effectivement aucun emplacement — mais elle est rendue au mauvais endroit : elle
 * transforme **un defaut d'integration en message anodin**. Une matrice qui se mettrait a deleguer
 * des retours au selecteur ne serait signalee par rien.
 *
 * Le controle remonte donc dans le type. Une valeur de cette classe ne peut pas exister pour une
 * autre forme ; le selecteur n'a plus a s'en mefier, et il n'a plus de raison anodine a rendre.
 *
 * ## Ce qui n'est pas une candidate
 *
 * La garnison locale, un retour, un deploiement vers sa propre planete : **ils ne sont pas
 * refuses, ils ne sont pas candidats**. Ils restent traites par la matrice et le resolveur final,
 * et ne passent jamais par ici. La difference compte : un refus se raconte au joueur, une
 * non-candidature n'a rien a raconter.
 *
 * ## Les deux portes, et leur difference
 *
 * `from()` **leve** : l'appelant affirmait tenir un renfort, il se trompait. `ofAll()` **filtre** :
 * c'est l'aiguillage, il trie un lot mele sans juger personne — le pendant exact de
 * `RallyGrouping::fightingShapesOnly()` du cote attaquant.
 */
final readonly class DefensiveRallyCandidate
{
    /**
     * @param CandidateMission $mission La mission relue sous verrou, dont la forme est deja verifiee.
     */
    private function __construct(
        public CandidateMission $mission,
    ) {
    }

    /**
     * Ce renfort, ou une contradiction.
     *
     * @throws ContradictoryAdmissionInput Si la mission n'est pas une Defense ACS en aller.
     */
    public static function from(CandidateMission $mission): self
    {
        if (!self::isAReinforcement($mission)) {
            throw new ContradictoryAdmissionInput(
                'selecteur defensif',
                $mission->mission->value . ' ' . $mission->leg->value,
                'mission ' . $mission->missionId
            );
        }

        return new self($mission);
    }

    /**
     * Les renforts d'un lot mele, les autres formes ecartees sans jugement.
     *
     * @param array<int, CandidateMission> $candidates
     * @return array<int, self>
     */
    public static function ofAll(array $candidates): array
    {
        $renforts = [];

        foreach ($candidates as $candidate) {
            if (self::isAReinforcement($candidate)) {
                $renforts[] = new self($candidate);
            }
        }

        return $renforts;
    }

    /**
     * La seule forme que la matrice delegue au selecteur defensif.
     */
    private static function isAReinforcement(CandidateMission $mission): bool
    {
        return $mission->mission === CombatMissionKind::AcsDefend
            && $mission->leg === FlightLeg::Outbound;
    }
}
