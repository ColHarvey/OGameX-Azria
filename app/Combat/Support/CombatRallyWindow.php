<?php

namespace OGame\Combat\Support;

/**
 * L'echeance de la fenetre de ralliement : jusqu'a quand un combat reste ouvert.
 *
 * ## Le probleme qu'elle resout
 *
 * Dans OGame, on lance plusieurs attaques a quelques secondes d'intervalle sur la meme cible.
 * C'est un pilier du jeu. Un combat qui dure rend cette mecanique impossible : quand
 * la seconde vague arrive, la photo du champ de bataille est prise et le calcul fige.
 *
 * Quatre issues avaient ete envisagees, toutes mauvaises a leur facon — rouvrir la photo defait
 * la garantie centrale ; faire attendre toute la bataille supprime les vagues ; renvoyer toute flotte
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
 * ## Ce que cette classe decide, et ce qu'elle a cesse de decider
 *
 * Elle ne repond plus qu'a une question, et c'est une question de temps :
 *
 *     quand la fenetre se ferme-t-elle, et cette arrivee-la tombe-t-elle dedans ?
 *
 * Qui rejoint quel camp est decide ailleurs, et il faut savoir ou :
 *
 *     le mouvement de la flotte     -> CombatDecisionMatrix
 *     l'admission attaquante        -> AttackAdmissionSelector
 *     l'admission defensive         -> DefensiveAdmissionSelector
 *     l'appartenance a la photo     -> CausalOrderReconciler
 *
 * Une premiere version repondait ici aussi a la question de l'admission, a partir d'un objet de
 * sept booleens que l'appelant remplissait lui-meme. Rien ne garantissait que ces faits
 * eussent ete lus sous verrou, ni qu'ils dataient de l'ouverture, ni qu'ils fussent vrais. Les
 * selecteurs, eux, recoivent des lignes relues et figees, et tranchent **pour tout le camp en
 * une fois** : un budget se consomme, et deux workers ne doivent jamais prendre ensemble la
 * derniere place.
 *
 * ## Les regles d'echeance, telles qu'arretees
 *
 * - **Soixante secondes au maximum**, comptees depuis l'arrivee de la premiere attaque. La
 *   fenetre **ne se prolonge jamais** : sans cette regle, un attaquant la maintiendrait ouverte
 *   indefiniment en faisant arriver une sonde toutes les cinquante secondes. Elle se **raccourcit**
 *   en revanche des que la derniere flotte admissible attendue est arrivee, et tombe a zero s'il
 *   n'y en a aucune — c'est la protection contre le harcelement par une flotte insignifiante.
 * - Le corps celeste est **verrouille des la premiere arrivee**, pas a la fermeture.
 * - La borne est **semi-ouverte** : une arrivee prevue pile a la fermeture est en retard, et
 *   cette regle unique vaut pour tous les workers.
 * - L'instant compare est **l'heure planifiee**, jamais celle du traitement : le retard d'un
 *   worker est un fait du serveur, pas un choix du joueur.
 *
 * ## Pourquoi cette classe ne touche pas la base
 *
 * Elle ne recoit que des instants. La regle reste ainsi verifiable sans univers, sans joueur et
 * sans horloge — et c'est ce qui permet de l'eprouver exhaustivement.
 */
final class CombatRallyWindow
{
    /**
     * Duree **maximale** de la fenetre, en secondes.
     *
     * Soixante secondes couvrent les vagues telles qu'elles se lancent reellement — a quelques
     * secondes d'intervalle — sans faire attendre le premier attaquant de facon perceptible :
     * une minute face a une bataille qui dure.
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
