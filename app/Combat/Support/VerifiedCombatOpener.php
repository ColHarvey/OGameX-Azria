<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Exceptions\NotTheCombatOpener;

/**
 * La preuve qu'une mission est bien celle qui a ouvert ce combat.
 *
 * ## Pourquoi un objet plutot qu'un booleen
 *
 * Une premiere version passait `isCombatOpener: true` en parametre. Rendre le booleen obligatoire
 * forcait a se poser la question, mais laissait n'importe quel site d'appel y **repondre
 * faussement** : une seconde flotte franchissait alors toutes les barrieres du verrou causal, y
 * compris la limite du camp et l'interdiction de rappel.
 *
 * Un booleen simplement emballe dans une classe n'aurait rien change. Cet objet **certifie des
 * identites**, et il ne peut naitre que d'une confrontation reussie entre ce que l'instance dit
 * de son initiateur et ce que la mission dit d'elle-meme — les deux relus en base, sous le verrou
 * de la cible.
 *
 * ## Ce qu'il certifie
 *
 * - l'evenement est bien celui enregistre comme initiateur de cette instance ;
 * - la mission vise bien le corps celeste verrouille — une planete et sa lune sont deux cibles ;
 * - la mission est sur sa **branche aller** : un retour n'ouvre jamais un combat ;
 * - son genre peut reellement en ouvrir un. Un transport, un recyclage, un missile ou une Defense
 *   ACS ne le peuvent pas, et ne produiront jamais cet objet ;
 * - son heure d'arrivee planifiee est celle relue en base, pas celle d'un payload de tache.
 *
 * ## Ce qu'il n'est pas
 *
 * Il n'existe aucune fabrique de confiance, aucun `fromTrustedData()`. Le constructeur est prive
 * et `verify()` est le seul chemin. Un objet de ce type ne doit **jamais** etre serialise dans le
 * payload d'une tache : il perdrait ce qui fait sa valeur, a savoir d'avoir ete etabli sous
 * verrou, au moment ou la decision se prend.
 *
 * ## L'initiateur dans les regles du combat
 *
 * Il entre exactement une fois dans la photographie. Il compte comme une flotte **et** comme un
 * joueur dans le budget de son camp. Il n'est jamais exclu comme dix-septieme participant — il
 * etait la avant qu'il y ait un camp. Il ne prolonge pas la fenetre a lui seul, et il n'est plus
 * rappelable des l'ouverture produite.
 */
final readonly class VerifiedCombatOpener
{
    /**
     * @param int $combatInstanceId
     * @param EffectOrderKey $openerEventKey
     * @param string $targetBodyKey
     * @param ActorKind $actorKind
     * @param CombatMissionKind $missionKind
     * @param int $plannedArrival
     * @param int $openingDecisionOrder
     */
    private function __construct(
        public int $combatInstanceId,
        public EffectOrderKey $openerEventKey,
        public string $targetBodyKey,
        public ActorKind $actorKind,
        public CombatMissionKind $missionKind,
        public int $plannedArrival,
        public int $openingDecisionOrder,
    ) {
    }

    /**
     * Confronte ce que dit l'instance et ce que dit la mission.
     *
     * **A appeler sous le verrou de la cible, sur des donnees relues en base.** Les deux jeux de
     * faits doivent venir de la meme transaction : les comparer a des instants differents
     * reviendrait a certifier un etat qui n'a peut-etre jamais existe simultanement.
     *
     * @param PersistedCombatOpener $instance Ce que l'instance de combat enregistre.
     * @param CombatOpenerClaim $claim Ce que la mission relue affirme.
     * @return self
     * @throws NotTheCombatOpener Si l'une des identites ne correspond pas.
     */
    public static function verify(PersistedCombatOpener $instance, CombatOpenerClaim $claim): self
    {
        if (!$claim->eventKey->equals($instance->openerEventKey)) {
            throw NotTheCombatOpener::becauseTheEventIsNotTheRecordedOpener($instance->combatInstanceId);
        }

        if ($claim->targetBodyKey !== $instance->targetBodyKey) {
            throw NotTheCombatOpener::becauseTheTargetDiffers($claim->targetBodyKey, $instance->targetBodyKey);
        }

        if ($claim->leg !== FlightLeg::Outbound) {
            throw NotTheCombatOpener::becauseAReturnNeverOpensACombat();
        }

        if (!$claim->missionKind->opensCombat()) {
            throw NotTheCombatOpener::becauseThisMissionKindCannotOpenACombat($claim->missionKind);
        }

        if ($claim->plannedArrival !== $instance->plannedArrival) {
            throw NotTheCombatOpener::becauseTheArrivalTimeDiffers($claim->plannedArrival, $instance->plannedArrival);
        }

        return new self(
            $instance->combatInstanceId,
            $instance->openerEventKey,
            $instance->targetBodyKey,
            $claim->actorKind,
            $claim->missionKind,
            $instance->plannedArrival,
            $instance->openingDecisionOrder,
        );
    }
}
