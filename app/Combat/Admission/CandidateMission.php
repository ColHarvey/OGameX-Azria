<?php

namespace OGame\Combat\Admission;

use InvalidArgumentException;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\FlightLeg;

/**
 * Une mission candidate, telle que la fermeture l'a relue sous verrou.
 *
 * ## Ce que le job n'apporte pas
 *
 * Rien de tout cela. Un message de file ne transporte qu'un identifiant ; c'est le service de
 * fermeture qui relit les lignes. Laisser le job transporter une alliance ou une heure reviendrait a
 * faire confiance a une photographie prise par l'emetteur.
 *
 * ## `allianceIdAtOpening`
 *
 * L'alliance du joueur **au moment de l'ouverture**, pas au moment de la lecture. Un joueur qui
 * change d'alliance pendant le ralliement ne doit ni y entrer ni en sortir : le combat a fige les
 * appartenances quand il s'est ouvert.
 */
final readonly class CandidateMission
{
    /**
     * @param int $missionId L'identifiant persiste de la mission.
     * @param int $userId Son proprietaire.
     * @param int|null $allianceIdAtOpening Son alliance a l'ouverture, ou null s'il n'en avait pas.
     * @param ActorKind $actor Joueur, PNJ ou compte systeme.
     * @param CombatMissionKind $mission Ce que la flotte etait partie faire.
     * @param FlightLeg $leg L'aller ou le retour : un retour ne rallie jamais un camp.
     * @param int $targetBodyId Le corps **exact** vise.
     * @param int $scheduledArrivalAt Son arrivee planifiee, en secondes.
     * @param bool $inFlightAtOpening Si elle volait deja quand le combat s'est ouvert.
     * @param bool $recalled Si elle a ete rappelee depuis.
     * @param int|null $unionId L'union sous laquelle elle vole, ou null si elle vole seule.
     *                          Une attaque ACS deja en vol arrive **ensemble** : c'est ce fait qui
     *                          permet de la regrouper, et donc de l'admettre ou de la renvoyer
     *                          entiere plutot que de couper une attaque coordonnee en deux.
     */
    public function __construct(
        public int $missionId,
        public int $userId,
        public int|null $allianceIdAtOpening,
        public ActorKind $actor,
        public CombatMissionKind $mission,
        public FlightLeg $leg,
        public int $targetBodyId,
        public int $scheduledArrivalAt,
        public bool $inFlightAtOpening,
        public bool $recalled,
        public int|null $unionId = null,
    ) {
        if ($missionId < 1 || $userId < 1) {
            throw new InvalidArgumentException(
                'Une candidate est une mission persistee d un joueur persiste : sans les deux identifiants, '
                . 'aucune admission ne serait reproductible au rejeu.'
            );
        }

        if ($targetBodyId < 1) {
            throw new InvalidArgumentException(
                'Une candidate vise un corps persiste. Des coordonnees ne suffisent pas : une planete et sa '
                . 'lune les partagent.'
            );
        }
    }
}
