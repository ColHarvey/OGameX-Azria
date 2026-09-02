<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\CombatArrivalOutcome;
use OGame\Combat\Enums\CombatSide;
use OGame\Combat\Enums\CombatState;

/**
 * La regle de la fenetre de ralliement : qui rejoint un combat, et qui rentre chez lui.
 *
 * ## Le probleme qu'elle resout
 *
 * Dans OGame, on lance plusieurs attaques a quelques secondes d'intervalle sur la meme cible.
 * C'est un pilier du jeu. Un combat qui dure deux heures rend cette mecanique impossible : quand
 * la seconde vague arrive, la photo du champ de bataille est prise et le calcul fige.
 *
 * Quatre issues avaient ete envisagees, toutes mauvaises a leur facon — rouvrir la photo defait
 * la garantie centrale ; faire attendre deux heures supprime les vagues ; renvoyer toute flotte
 * est brutal ; resoudre round par round exige de rendre les deux moteurs reprenables, ce qui est
 * un autre projet.
 *
 * La fenetre de ralliement est la cinquieme voie. Le combat ne demarre pas a l'arrivee de la
 * premiere flotte : il **s'ouvre** pour soixante secondes. Ce qui arrive pendant ce delai et y a
 * droit se bat dans la meme bataille, et la photo n'est prise qu'a la fermeture. Un instantane,
 * un calcul, un resultat — la garantie centrale tient — et les vagues d'OGame, lancees a
 * quelques secondes d'intervalle, tombent dedans.
 *
 * **Le ralliement est une phase d'admission, pas un combat commence.** C'est la distinction dont
 * tout le reste decoule : rien n'est calcule tant que la fenetre est ouverte, et rien ne bouge
 * plus une fois qu'elle est fermee.
 *
 * ## Les regles, telles qu'arretees
 *
 * - **Soixante secondes fixes**, comptees depuis l'arrivee de la premiere attaque. La fenetre
 *   **ne se prolonge jamais** : sans cette regle, un attaquant la maintiendrait ouverte
 *   indefiniment en faisant arriver une sonde toutes les cinquante secondes.
 * - Le corps celeste est **verrouille des la premiere arrivee**, pas a la fermeture.
 * - **Seules des flottes deja en vol** peuvent rejoindre. Une attaque lancee apres l'ouverture
 *   est refusee au depart, pas a l'arrivee — c'est le role de `CombatLockedActions`.
 * - Cote attaquant : les **autres flottes du meme joueur** rejoignent ; celles d'un autre joueur
 *   seulement s'il appartenait a la **meme alliance que l'attaquant initial au moment de
 *   l'ouverture**. Si l'attaquant initial n'a pas d'alliance, **personne** ne le rejoint.
 * - Les **limites ACS** tiennent : cinq joueurs et seize flottes au maximum.
 * - Cote defenseur : une flotte **deja en route** — defense ACS, deploiement, retour — defend si
 *   elle arrive avant la fermeture. Les renforts se preparent avant l'attaque, pas pendant.
 * - Un combat contre un **compte pilote par le serveur** ne se rejoint pas : personne ne vient
 *   preter main-forte a un pirate.
 * - **Ni file d'attente ni second combat automatique.** Une attaque arrivee trop tard, ou
 *   etrangere a l'alliance attaquante, **fait demi-tour** par la mecanique normale de rappel.
 * - Un **retour ou un deploiement** arrive apres la fermeture se pose quand meme, mais ne
 *   combat pas et ne repart qu'a la fin.
 *
 * ## Pourquoi cette classe ne touche pas la base
 *
 * Elle ne recoit que des faits deja etablis, rassembles dans `CombatArrival`. Les lire est le
 * travail de l'appelant ; les arbitrer est le sien. La regle reste ainsi verifiable sans univers,
 * sans joueur et sans horloge — et c'est ce qui permet de l'eprouver exhaustivement.
 */
final class CombatRallyWindow
{
    /**
     * Duree de la fenetre, en secondes.
     *
     * Soixante secondes couvrent les vagues telles qu'elles se lancent reellement — a quelques
     * secondes d'intervalle — sans faire attendre le premier attaquant de facon perceptible :
     * une minute face a une bataille de deux heures.
     */
    public const int WINDOW_SECONDS = 60;

    /**
     * Nombre maximal de joueurs d'un meme cote.
     *
     * Reprise de la limite ACS du jeu : la fenetre de ralliement ne doit pas devenir une facon
     * de contourner une regle qui existe deja.
     */
    public const int MAX_PLAYERS_PER_SIDE = 5;

    /**
     * Nombre maximal de flottes d'un meme cote.
     */
    public const int MAX_FLEETS_PER_SIDE = 16;

    /**
     * Decide ce qu'il advient d'une flotte qui arrive sur sa cible.
     *
     * @param CombatState|null $currentState L'etat du combat en cours sur le corps celeste, ou
     *                                       null si aucun combat ne s'y deroule.
     * @param bool $windowStillOpen Si la fenetre de ralliement court encore. Faux des qu'elle
     *                              est fermee, meme d'une seconde.
     * @param CombatArrival $arrival Les faits etablis sur la flotte qui arrive.
     * @return CombatArrivalOutcome
     */
    public static function decideArrival(
        CombatState|null $currentState,
        bool $windowStillOpen,
        CombatArrival $arrival,
    ): CombatArrivalOutcome {
        // Aucun combat ici, ou le precedent est termine : cette arrivee ouvre la fenetre.
        if ($currentState === null || !$currentState->locksTargetBody()) {
            return CombatArrivalOutcome::OpensRally;
        }

        $rallyOpen = $currentState === CombatState::Rallying && $windowStillOpen;

        if (!$rallyOpen) {
            // La photo est prise. Un retour ou un deploiement se pose quand meme — le renvoyer
            // serait absurde, il rentre chez lui — mais il ne combat pas et reste au sol
            // jusqu'a la resolution. Tout le reste fait demi-tour.
            return $arrival->isReturningOrDeploying
                ? CombatArrivalOutcome::ArrivesWithoutJoining
                : CombatArrivalOutcome::RecalledToOrigin;
        }

        return self::admitDuringRally($arrival);
    }

    /**
     * Arbitre une arrivee pendant que la fenetre est ouverte.
     *
     * @param CombatArrival $arrival
     * @return CombatArrivalOutcome
     */
    private static function admitDuringRally(CombatArrival $arrival): CombatArrivalOutcome
    {
        if (self::sideIsFull($arrival)) {
            return CombatArrivalOutcome::RecalledToOrigin;
        }

        if ($arrival->side === CombatSide::Defender) {
            // Un renfort defenseur deja en route compte toujours : il defend chez lui. Le cas
            // d'une defense **lancee** apres l'ouverture ne se presente pas ici — elle est
            // refusee au depart.
            return CombatArrivalOutcome::JoinsRally;
        }

        // Un combat contre un compte pilote par le serveur ne se rejoint pas. La regle est
        // ecrite ici plutot que supposee : le jour ou une faction PNJ devra pouvoir etre
        // renforcee, c'est cette ligne qu'on viendra changer, et on saura pourquoi.
        if ($arrival->targetIsNpcHeld && !$arrival->belongsToInitiator) {
            return CombatArrivalOutcome::RecalledToOrigin;
        }

        // Une autre vague du meme joueur : le coeur de la fenetre.
        if ($arrival->belongsToInitiator) {
            return CombatArrivalOutcome::JoinsRally;
        }

        // Un autre joueur n'a qu'un titre : l'alliance de l'attaquant initial, telle qu'elle
        // etait a l'ouverture. Deux ennemis independants ne deviennent jamais allies par
        // accident — leurs pertes seraient calculees ensemble et leur butin partage.
        if ($arrival->initiatorHasAlliance && $arrival->sharesInitiatorAlliance) {
            return CombatArrivalOutcome::JoinsRally;
        }

        return CombatArrivalOutcome::RecalledToOrigin;
    }

    /**
     * Si le camp a atteint l'une de ses limites ACS.
     *
     * Les deux limites ne mesurent pas la meme chose et ne se refusent pas de la meme facon.
     *
     * **Seize flottes** est un plafond absolu : toute flotte de plus est refusee, y compris une
     * vague de l'attaquant initial.
     *
     * **Cinq joueurs distincts, attaquant initial compris**, ne s'oppose qu'a une arrivee qui
     * ferait entrer quelqu'un de nouveau. Si A, B, C, D et E se battent, la limite est atteinte :
     * F est refuse, mais A comme B peuvent encore envoyer une autre vague tant qu'il reste de la
     * place en flottes. Compter « l'initiateur plus cinq allies » ferait six joueurs et
     * contournerait la regle.
     *
     * @param CombatArrival $arrival
     * @return bool
     */
    private static function sideIsFull(CombatArrival $arrival): bool
    {
        if ($arrival->fleetsAlreadyJoined >= self::MAX_FLEETS_PER_SIDE) {
            return true;
        }

        return $arrival->bringsANewPlayer() && $arrival->playersAlreadyJoined >= self::MAX_PLAYERS_PER_SIDE;
    }

    /**
     * L'instant ou la fenetre se ferme, a partir de l'ouverture.
     *
     * Une fonction plutot qu'une addition recopiee : c'est le seul endroit qui decide de cette
     * echeance, et l'unique facon de garantir qu'aucun code n'aille la repousser.
     *
     * @param int $openedAt Horodatage d'ouverture, en secondes.
     * @return int
     */
    public static function closesAt(int $openedAt): int
    {
        return $openedAt + self::WINDOW_SECONDS;
    }

    /**
     * Si la fenetre ouverte a cet instant court encore.
     *
     * @param int $openedAt Horodatage d'ouverture, en secondes.
     * @param int $now Horodatage courant, en secondes.
     * @return bool
     */
    public static function isOpenAt(int $openedAt, int $now): bool
    {
        return $now < self::closesAt($openedAt);
    }

    /**
     * Le temps qu'il reste avant la fermeture, jamais negatif.
     *
     * Sert l'affichage « Ralliement en cours — debut du combat dans 00:42 ».
     *
     * @param int $openedAt Horodatage d'ouverture, en secondes.
     * @param int $now Horodatage courant, en secondes.
     * @return int
     */
    public static function secondsRemaining(int $openedAt, int $now): int
    {
        return max(0, self::closesAt($openedAt) - $now);
    }
}
