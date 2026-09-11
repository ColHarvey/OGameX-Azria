<?php

namespace OGame\Patrol\Enums;

/**
 * Les etats d une patrouille entre deux vols, et ce que chacun permet.
 *
 * Le cycle nominal :
 *
 *     EnRoute ─→ Stationed ─→ EnRoute ─→ … ─→ Returning ─→ Finished
 *
 * `EnRoute` : un segment vole (aller vers un point, ou manoeuvre). `Stationed` : posee a son
 * point, elle consomme au temps reel et peut recevoir un ordre. `Returning` : le segment courant
 * rentre a la base d attache ; a l arrivee, unites, cargaison et reserve restante rejoignent la
 * planete et la patrouille est `Finished`. `Immobilised` : la reserve ne permet plus de rentrer,
 * meme a la vitesse la plus basse ; la patrouille reste posee et attaquable, et le secours borne
 * (revue 120) est le seul chemin qui la remette en route — jamais le sabordage seul.
 *
 * **L engagement dans un combat n est pas un etat.** Il se lit sur le segment courant, par
 * `EngagedFleetCheck`, comme pour toute flotte : deux sources de verite divergeraient.
 */
enum PatrolState: string
{
    case EnRoute = 'en_route';
    case Stationed = 'stationed';
    case Returning = 'returning';

    /**
     * Les unites sont parties attaquer depuis le point de la patrouille (revue 121, R7) ; le point
     * reste le sien, et la mission d attaque en est le segment courant. Elles y reviennent par le
     * retour normal de l attaque, sans rejoindre un combat qui s y deroulerait.
     */
    case Attacking = 'attacking';
    case Immobilised = 'immobilised';
    case Finished = 'finished';

    /**
     * La patrouille a ete detruite en espace libre.
     *
     * **Distinct de `Finished`, et ce n est pas une nuance de vocabulaire.** Une patrouille terminee
     * est rentree : ses vaisseaux sont sur un corps, sa reserve a ete rendue. Une patrouille
     * detruite n a rien rendu du tout — sa reserve est morte avec elle —, et le joueur doit lire la
     * difference. Les confondre ferait aussi mentir tout compteur qui distingue « rentrees » et
     * « perdues ».
     */
    case Destroyed = 'destroyed';

    /**
     * La patrouille existe encore dans l espace.
     */
    public function isAlive(): bool
    {
        return $this !== self::Finished;
    }

    /**
     * La patrouille est posee a un point : elle consomme au temps reel et porte une position.
     */
    public function isParked(): bool
    {
        return $this === self::Stationed || $this === self::Immobilised;
    }

    /**
     * La patrouille **tient un point du systeme** : ses unites y sont, ou y reviendront.
     *
     * `Attacking` en fait partie, et c est tout l objet de cette question : le point reste celui de
     * la patrouille pendant que ses unites frappent ailleurs, et c est la que leur retour se pose.
     * `isParked()` repond a une autre question — la patrouille consomme-t-elle au temps reel et
     * peut-elle recevoir un ordre — et repondre « non » aux deux pour une attaque en cours est juste
     * dans un cas, faux dans l autre. Deux questions, deux methodes.
     */
    public function holdsAPoint(): bool
    {
        return $this->isParked() || $this === self::Attacking;
    }

    /**
     * La patrouille peut recevoir un nouvel ordre de mouvement.
     *
     * Posee ou en vol dans son systeme (manoeuvre, revue 120). Pas en retour : le retour se
     * termine ou se rappelle par la regle des missions, il ne se redirige pas. Pas immobilisee :
     * sans carburant, seul le secours s applique.
     */
    public function acceptsMovementOrders(): bool
    {
        return $this === self::Stationed || $this === self::EnRoute;
    }
}
