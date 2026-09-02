<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\CombatSide;

/**
 * Les faits etablis sur une flotte qui arrive, au moment ou elle arrive.
 *
 * Un objet plutot qu'une liste de booleens en parametres : la regle d'admission en compte sept,
 * et sept booleens a la file dans un appel sont une invitation permanente a en intervertir deux.
 * Nommes, ils se lisent sur le site d'appel et ne peuvent plus se croiser.
 *
 * **Ce sont des faits deja lus, pas des questions a poser.** Les etablir — interroger l'alliance,
 * compter les flottes deja admises, reconnaitre un retour d'un deploiement — est le travail de
 * l'appelant. Les arbitrer est celui de `CombatRallyWindow`. C'est cette separation qui rend la
 * regle verifiable sans univers, sans joueur et sans horloge.
 */
final readonly class CombatArrival
{
    /**
     * @param CombatSide $side De quel cote la flotte se presente.
     * @param bool $belongsToInitiator Si la flotte appartient au joueur qui a ouvert le combat.
     *                                 C'est le cas d'une vague.
     * @param bool $sharesInitiatorAlliance Si son proprietaire appartenait a la meme alliance que
     *                                      l'attaquant initial **au moment de l'ouverture**.
     *                                      L'alliance est photographiee avec le reste : la
     *                                      quitter ou la rejoindre pendant les soixante secondes
     *                                      ne change rien.
     * @param bool $initiatorHasAlliance Si l'attaquant initial est dans une alliance. S'il n'y
     *                                   en a pas, personne d'autre que lui ne peut rejoindre.
     * @param bool $isReturningOrDeploying Si la flotte rentre chez elle ou se deploie. Ces
     *                                     flottes-la se posent meme apres la fermeture, au lieu
     *                                     d'etre renvoyees.
     * @param bool $targetIsNpcHeld Si le combat oppose un compte pilote par le serveur. Aucun
     *                              joueur ne rejoint le camp d'un pirate.
     * @param bool $ownerAlreadyJoined Si le proprietaire de cette flotte compte **deja** parmi
     *                                 les joueurs de ce camp. Une flotte de plus d'un joueur
     *                                 deja present n'amene personne de nouveau, et la limite de
     *                                 joueurs ne la concerne donc pas. Vrai pour l'attaquant
     *                                 initial des sa deuxieme vague, et pour tout allie qui en
     *                                 envoie une seconde.
     * @param int $playersAlreadyJoined Nombre de joueurs **distincts** deja engages de ce cote,
     *                                  **l'attaquant initial compris**. Un combat ouvert par A
     *                                  seul compte donc un joueur, pas zero — sans quoi la
     *                                  limite laisserait passer « l'initiateur plus cinq
     *                                  allies », soit six joueurs.
     * @param int $fleetsAlreadyJoined Nombre de flottes deja engagees de ce cote.
     */
    public function __construct(
        public CombatSide $side,
        public bool $belongsToInitiator = false,
        public bool $sharesInitiatorAlliance = false,
        public bool $initiatorHasAlliance = false,
        public bool $isReturningOrDeploying = false,
        public bool $targetIsNpcHeld = false,
        public bool $ownerAlreadyJoined = false,
        public int $playersAlreadyJoined = 0,
        public int $fleetsAlreadyJoined = 0,
    ) {
    }

    /**
     * Si cette arrivee ferait entrer un joueur de plus dans le camp.
     *
     * Seule cette question interesse la limite de joueurs. L'attaquant initial y repond non des
     * sa deuxieme vague, et un allie deja engage aussi : ni l'un ni l'autre n'amene quelqu'un de
     * nouveau.
     *
     * @return bool
     */
    public function bringsANewPlayer(): bool
    {
        return !$this->belongsToInitiator && !$this->ownerAlreadyJoined;
    }
}
