<?php

namespace OGame\Combat\Support;

/**
 * Ce qui ne peut plus partir d'un corps celeste engage dans un combat.
 *
 * Conception pure : aucune table, aucun controleur touche. Ce fichier est la **liste de
 * reference** que chaque validation serveur devra consulter, et il existe avant elles pour
 * une raison — c'est en ecrivant la liste qu'on voit ce qu'on allait oublier.
 *
 * Le verrou porte sur le **corps celeste vise**, pas sur le joueur ni sur l'emplacement : une
 * attaque sur une planete bloque cette planete, une attaque sur sa lune bloque cette lune, et
 * l'autre continue sa vie normalement.
 *
 * **Ce qui continue pendant un combat** : constructions, recherches, productions, et toute
 * action qui ne fait rien partir. Les vaisseaux construits pendant la bataille n'y participent
 * pas — ils etaient absents de la photo — et deviennent disponibles a la fin.
 *
 * L'interface grisera ces actions avec le motif « Combat en cours ». Elle n'est jamais le
 * controle : un appel direct doit etre refuse exactement de la meme facon.
 *
 * **Cette liste n'est pas la protection.** Elle sert a deux choses : documenter la decision, et
 * permettre a un test de reperer une route de depart ajoutee sans qu'on ait tranche son cas.
 * Le refus lui-meme devra vivre dans un service central appele par chaque validation serveur —
 * un filet qui repose sur des noms de routes attraperait ce qui lui ressemble, jamais une
 * action qui partirait par un chemin qu'on n'a pas imagine.
 */
class CombatLockedActions
{
    /**
     * Les actions refusees au depart d'un corps celeste verrouille.
     *
     * La cle est le nom de la route que le serveur doit refuser ; la valeur explique pourquoi,
     * parce qu'un refus sans raison finit toujours par etre retire par quelqu'un qui ne sait
     * pas ce qu'il protege.
     *
     * @return array<string, string>
     */
    public static function refusedRoutes(): array
    {
        return [
            // Toutes les formes d'envoi de flotte passent par ces deux routes : espionnage,
            // attaque, transport, deploiement, ACS, recyclage, expedition, colonisation.
            // Les refuser toutes les deux couvre donc l'essentiel du verrou.
            'fleet.dispatch.sendfleet' => 'Une flotte ne peut pas quitter un corps celeste en combat.',
            'fleet.dispatch.sendminifleet' => 'Meme refus que l\'envoi normal : le raccourci ne doit pas etre une porte derobee.',

            // Le rappel d'une flotte deja engagee : refuse par la regle « un combat engage ne
            // se rappelle pas ». Le rappel d'une flotte partie d'ailleurs reste possible.
            'fleet.dispatch.recallfleet' => 'Une flotte engagee dans un combat ne se rappelle plus.',

            // Les missiles ne participent pas au combat de flotte, mais ils partent bien du
            // corps celeste : ils tombent donc sous le meme verrou.
            'galaxy.missile-attack' => 'Un missile ne peut pas etre tire depuis un corps celeste en combat.',

            // La porte de saut deplace une flotte sans la faire voyager : c'est precisement le
            // genre de sortie qu'un verrou naif laisserait passer.
            'jumpgate.execute' => 'La porte de saut ne peut pas evacuer une flotte engagee.',
        ];
    }

    /**
     * Les routes qui restent ouvertes, et pourquoi.
     *
     * Elles sont listees explicitement plutot que laissees dans le silence : un jour,
     * quelqu'un se demandera si l'oubli en etait un.
     *
     * @return array<string, string>
     */
    public static function allowedRoutes(): array
    {
        return [
            'fleet.index' => 'Consulter la page Flotte reste possible ; c\'est l\'envoi qui est refuse.',
            'fleet.movement' => 'Le joueur doit pouvoir suivre ses flottes, surtout pendant un combat.',
            'fleet.dispatch.checktarget' => 'Verifier une cible ne fait rien partir. Le refus vient a l\'envoi.',
            'galaxy.index' => 'La galaxie reste consultable : c\'est meme la qu\'on verra le combat.',
            'galaxy.ajax' => 'Meme raison.',
            'jumpgate.index' => 'Ouvrir la porte de saut ne fait rien partir.',
            'fleet.union.create' => 'Creer une union ne fait pas decoller de flotte.',
            'fleet.union.join' => 'Rejoindre une union non plus : c\'est l\'envoi qui suivra qui sera refuse.',
        ];
    }

    /**
     * Les actions d'administration refusees sur un corps celeste verrouille.
     *
     * Un administrateur ne doit pas pouvoir deplacer ou detruire une planete pendant une
     * bataille : le combat travaille sur une photo prise a l'arrivee, et le corps qu'il vise
     * doit encore exister a la resolution.
     *
     * @return array<string, string>
     */
    public static function refusedAdminActions(): array
    {
        return [
            'planet.move' => 'Deplacer un corps celeste en combat laisserait la bataille viser un endroit vide.',
            'planet.destroy' => 'Detruire un corps celeste en combat empecherait la resolution de s\'appliquer.',
            'moon.destroy' => 'Meme raison, pour une lune.',
        ];
    }

    /**
     * Les cas encore en suspens. **Il n'y en a plus.**
     *
     * Ce tableau a porte pendant tout le chantier le probleme des vagues : une attaque hostile
     * deja en vol vers un corps celeste qui entre en combat. Il est desormais tranche — voir
     * `CombatRallyWindow` — et le cas en est retire.
     *
     * Le tableau reste, vide, et le test qui le surveillait aussi : c'est ici que se declarera
     * la prochaine question a laquelle personne n'a repondu. Un cas ecrit ici est un cas qu'on
     * s'interdit de resoudre par un comportement par defaut.
     *
     * @return array<int, string>
     */
    public static function undecidedCases(): array
    {
        return [];
    }

    /**
     * La decision prise sur les vagues, et les quatre options ecartees.
     *
     * Ecrite ici parce qu'une decision de jeu qui ne survit pas a la memoire de celui qui l'a
     * prise n'a pas ete prise : elle a ete devinee.
     *
     * **Retenu — la fenetre de ralliement.** Le combat ne demarre pas a l'arrivee de la premiere
     * flotte : il s'ouvre pour soixante secondes fixes, pendant lesquelles les vagues du meme
     * attaquant et les membres de son alliance rejoignent la meme bataille. La photo est prise a
     * la fermeture, une seule fois.
     *
     * **Ni file d'attente ni second combat automatique.** Une premiere version faisait attendre
     * les flottes tardives au-dessus de la cible pour ouvrir le ralliement suivant ; elle a ete
     * ecartee parce qu'elle transformait une cible en file d'attente ou des flottes s'empilaient
     * sans que leur proprietaire puisse rien en faire. Une attaque arrivee trop tard, ou
     * etrangere a l'alliance attaquante, **fait demi-tour** par la mecanique normale de rappel :
     * vaisseaux et fret reviennent, sans second cout de carburant.
     *
     * Les quatre options ecartees, et pourquoi :
     *
     * 1. **Rejoindre un combat en cours.** Il faudrait rouvrir une photo deja prise, ce qui
     *    defait la garantie centrale du systeme.
     * 2. **Attendre la fin, puis ouvrir un nouveau combat.** Techniquement propre, mais une
     *    seconde vague lancee cinq secondes apres la premiere n'attaquerait que deux heures plus
     *    tard : la mecanique des vagues disparait. La fenetre garde cette idee pour les arrivees
     *    tardives, ou elle est le moindre mal, et l'evite pour les vagues rapprochees.
     * 3. **Refuser l'arrivee et renvoyer la flotte.** Le plus simple, et le plus brutal.
     * 4. **Resoudre round par round.** La seule option parfaitement fidele, et hors de portee :
     *    elle exige de rendre **les deux moteurs, PHP et Rust, reprenables apres chaque round**.
     *    C'est un autre projet, pas une variante de celui-ci.
     *
     * @return array<int, string>
     */
    public static function decidedCases(): array
    {
        return [
            'Vagues : fenetre de ralliement de 60 secondes fixes a partir de la premiere arrivee, photo unique a la fermeture, attaques tardives ou etrangeres a l alliance rappelees vers leur origine.',
        ];
    }
}
