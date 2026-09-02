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
 * - **Soixante secondes au maximum**, comptees depuis l'arrivee de la premiere attaque. La
 *   fenetre **ne se prolonge jamais** : sans cette regle, un attaquant la maintiendrait ouverte
 *   indefiniment en faisant arriver une sonde toutes les cinquante secondes. Elle se **raccourcit**
 *   en revanche des que la derniere flotte admissible attendue est arrivee, et tombe a zero s'il
 *   n'y en a aucune — c'est la protection contre le harcelement par une flotte insignifiante.
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
     * Duree **maximale** de la fenetre, en secondes.
     *
     * Soixante secondes couvrent les vagues telles qu'elles se lancent reellement — a quelques
     * secondes d'intervalle — sans faire attendre le premier attaquant de facon perceptible :
     * une minute face a une bataille de deux heures.
     *
     * C'est un plafond, pas une duree garantie : voir `closesAt()`, ou la fenetre se ferme des
     * que la derniere flotte admissible attendue est arrivee.
     */
    public const int WINDOW_SECONDS = 60;

    /**
     * Le pas de temps du jeu, en secondes.
     *
     * **La precision metier est la seconde, et c'est un choix explicite.** Tout OGame fonctionne
     * a cette echelle : les heures d'arrivee, les comptes a rebours affiches, les evenements
     * planifies. Concevoir une precision plus fine n'apporterait rien a un joueur et compliquerait
     * chaque comparaison.
     *
     * Ce pas est nomme plutot que suppose. Le calcul de l'echeance s'exprime en fonction de lui,
     * de sorte qu'une eventuelle migration vers une autre precision se fasse a un seul endroit —
     * au lieu de laisser des `+ 1` disperses signifier autre chose du jour au lendemain.
     */
    public const int TICK_SECONDS = 1;

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
     * L'instant ou la fenetre se fermera, calcule **une seule fois, a l'ouverture**.
     *
     * ## Pourquoi la fenetre n'est pas toujours de soixante secondes
     *
     * Une duree fixe se retourne en outil de harcelement. Le corps celeste est verrouille des la
     * premiere arrivee : un unique chasseur leger, envoye en boucle, immobiliserait une minute
     * les departs et les ressources d'une planete, indefiniment et pour un cout derisoire.
     *
     * D'ou la regle : **la fenetre s'arrete des que la derniere flotte admissible deja en vol est
     * arrivee**, et soixante secondes restent la limite haute. Si aucune flotte admissible n'est
     * en vol au moment de l'ouverture, il n'y a rien a attendre — le combat commence
     * immediatement.
     *
     * L'attaquant isole n'obtient donc plus une minute de verrou : il obtient un combat, tout de
     * suite. Celui qui a reellement prepare des vagues garde sa fenetre.
     *
     * **La liste est figee a l'ouverture.** Une flotte lancee apres ne la rallonge pas — elle est
     * de toute facon refusee au depart.
     *
     * **L'echeance rendue ici est calculee une fois et persistee avec le combat**, sous le nom
     * `initial_closes_at`. Aucun changement de regle, de plafond ou de precision ne doit la
     * deplacer : une flotte partie sous une regle serait sinon jugee sous une autre.
     *
     * Elle n'est pas immuable pour autant, et la formulation « jamais recalculee » etait fausse.
     * Un rappel retire une candidate, et l'echeance doit alors pouvoir se **raccourcir** — voir
     * `closesAfterWithdrawal()`. La regle exacte tient en deux phrases :
     *
     * - **la configuration ne la deplace jamais** ;
     * - **le retrait d'une candidate peut la raccourcir, jamais la prolonger**.
     *
     * @param int $openedAt Horodatage d'ouverture, en secondes.
     * @param array<int, int> $admissibleArrivalsInFlight Heures d'arrivee **planifiees** des
     *                                                    flottes deja en vol qui seraient
     *                                                    admises. L'heure planifiee, jamais
     *                                                    celle du traitement : un worker en
     *                                                    retard ne doit pas raccourcir la
     *                                                    fenetre de quelqu'un.
     * @return int
     */
    public static function closesAt(int $openedAt, array $admissibleArrivalsInFlight = []): int
    {
        $limite = $openedAt + self::WINDOW_SECONDS;

        // Une arrivee n'est retenable que si la fenetre peut se fermer **apres elle** sans
        // depasser le plafond. Exprimee avec le pas de temps plutot qu'avec un `< $limite`, la
        // condition dit ce qu'elle veut dire : il faut qu'il reste la place de l'inclure.
        $attendues = array_filter(
            $admissibleArrivalsInFlight,
            static fn (int $arrivee): bool => $arrivee >= $openedAt && $arrivee + self::TICK_SECONDS <= $limite
        );

        if ($attendues === []) {
            // Personne a attendre : le ralliement se ferme a l'instant meme ou il s'ouvre, et le
            // combat demarre. C'est la protection anti-harcelement.
            return $openedAt;
        }

        // La fenetre se ferme **un pas de temps apres** la derniere arrivee attendue, et pas un
        // instant plus tard. Deux proprietes, et il faut les deux :
        //
        // - **surete** : sans ce decalage, cette flotte arriverait a l'instant exact de la
        //   fermeture — donc trop tard, par la regle de la borne — alors que c'est precisement
        //   elle qui a fixe l'echeance ;
        // - **minimalite** : la fenetre se ferme des que la derniere attendue est la. Un
        //   decalage plus large respecterait encore le plafond tant qu'on en est loin, tout en
        //   verrouillant la cible pour rien.
        //
        // Le plafond n'a pas a etre reapplique ici : le filtre ci-dessus a deja ecarte toute
        // arrivee qui ne laisserait pas la place au decalage.
        return max($attendues) + self::TICK_SECONDS;
    }

    /**
     * L'echeance apres le retrait d'une candidate, qui ne peut que se raccourcir.
     *
     * Un joueur peut rappeler une flotte tant qu'elle n'est pas arrivee. Si c'etait elle qui
     * fixait l'echeance, la fenetre n'a plus de raison de rester ouverte jusque-la : le
     * ralliement attendrait quelqu'un qui ne viendra pas, en gardant la cible verrouillee pour
     * rien.
     *
     * **Le sens unique est la garantie.** L'echeance se raccourcit, jamais ne s'allonge. Sans ce
     * garde-fou, un rappel suivi d'un nouveau lancement — ou une candidate reintroduite par un
     * evenement rejoue — rallongerait une fenetre deja ouverte, et rendrait au harcelement ce que
     * la fenetre dynamique lui a retire.
     *
     * Le `min()` n'est donc pas une precaution decorative : il est la regle. Il tient meme si le
     * recalcul recoit, par erreur ou par rejeu, une candidate qui n'aurait pas du revenir.
     *
     * @param int $openedAt Horodatage d'ouverture, inchange.
     * @param array<int, int> $remainingArrivals Heures planifiees des candidates **restantes**,
     *                                           celle qui vient d'etre retiree exclue.
     * @param int $currentClosesAt L'echeance en vigueur avant le retrait.
     * @return int
     */
    public static function closesAfterWithdrawal(int $openedAt, array $remainingArrivals, int $currentClosesAt): int
    {
        return min($currentClosesAt, self::closesAt($openedAt, $remainingArrivals));
    }

    /**
     * Si une arrivee prevue a cet instant tombe dans la fenetre.
     *
     * **L'instant compare est l'heure planifiee de l'arrivee, pas celle du traitement.** Un
     * worker en retard ne doit pas refuser une flotte qui devait arriver a temps : le retard est
     * un fait du serveur, pas un choix du joueur.
     *
     * La fenetre est un intervalle **semi-ouvert** : ouverte a l'instant d'ouverture, fermee a
     * l'instant de fermeture. Une arrivee prevue pile a la fermeture est donc en retard, et cette
     * unique regle vaut pour tous les workers.
     *
     * @param int $closesAt Horodatage de fermeture, tel que calcule a l'ouverture.
     * @param int $scheduledArrival Heure planifiee de l'arrivee, en secondes.
     * @return bool
     */
    public static function admitsArrivalAt(int $closesAt, int $scheduledArrival): bool
    {
        return $scheduledArrival < $closesAt;
    }

    /**
     * Le temps qu'il reste avant la fermeture, jamais negatif.
     *
     * Sert l'affichage « Ralliement en cours — debut du combat dans 00:42 ».
     *
     * @param int $closesAt Horodatage de fermeture, tel que calcule a l'ouverture.
     * @param int $now Horodatage courant, en secondes.
     * @return int
     */
    public static function secondsRemaining(int $closesAt, int $now): int
    {
        return max(0, $closesAt - $now);
    }
}
