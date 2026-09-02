<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\FlightLeg;

/**
 * Ce que la mission dit d'elle-meme, relu en base sous le verrou de la cible.
 *
 * **Des faits relus, pas un payload de tache.** Un worker ne transporte que l'identite de
 * l'evenement — un identifiant. A son execution il verrouille la cible, relit la mission, et
 * construit ces faits depuis la base. Une heure d'arrivee, un type ou une cible qui viendraient
 * du payload pourraient etre perimes ou forges ; ceux-ci ne le peuvent pas.
 *
 * Le nom dit ce que c'est : une **pretention**. Elle n'est certifiee qu'apres confrontation avec
 * `PersistedCombatOpener`, et c'est `VerifiedCombatOpener` qui en resulte.
 */
final readonly class CombatOpenerClaim
{
    /**
     * @param EffectOrderKey $eventKey L'identite de l'evenement, telle que relue.
     * @param string $targetBodyKey Le corps celeste reellement vise par la mission.
     * @param FlightLeg $leg Aller ou retour. Un retour n'ouvre jamais un combat.
     * @param CombatMissionKind $missionKind Le genre de mission.
     * @param ActorKind $actorKind Joueur, faction pilotee par le serveur, ou systeme.
     * @param int $plannedArrival Heure planifiee de l'arrivee, relue dans la mission.
     */
    public function __construct(
        public EffectOrderKey $eventKey,
        public string $targetBodyKey,
        public FlightLeg $leg,
        public CombatMissionKind $missionKind,
        public ActorKind $actorKind,
        public int $plannedArrival,
    ) {
    }
}
