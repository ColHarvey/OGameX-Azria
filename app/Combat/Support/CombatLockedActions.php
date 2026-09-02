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
     * Le cas qui n'est **pas** tranche, et qui ne doit pas l'etre par accident.
     *
     * Une attaque hostile deja en vol vers un corps celeste qui entre en combat : c'est le
     * probleme des vagues. Les combats longs et les vagues successives a quelques secondes
     * d'intervalle — un pilier d'OGame — entrent en conflit direct.
     *
     * Quatre options existent, aucune n'est neutre :
     *
     * 1. **Rejoindre le combat en cours.** Fidele a l'esprit des vagues, mais la photo est
     *    deja prise : il faudrait la rouvrir, ce qui defait la garantie centrale du systeme.
     * 2. **Attendre la fin, puis ouvrir un nouveau combat.** Techniquement propre, mais une
     *    seconde vague lancee cinq secondes apres la premiere arriverait deux heures plus tard :
     *    la mecanique des vagues disparait.
     * 3. **Refuser l'arrivee et renvoyer la flotte.** Le plus simple, et le plus brutal.
     * 4. **Resoudre le combat round par round**, une vague arrivee entre deux rounds rejoignant
     *    le round suivant. C'est la seule option qui preserve vraiment les vagues — et de loin
     *    la plus lourde : il faudrait renoncer a calculer et figer le resultat complet a
     *    l'arrivee, donc rendre **les deux moteurs, PHP et Rust, reprenables apres chaque
     *    round**. C'est un autre projet, pas une variante de celui-ci.
     *
     * Les trois premieres sont mauvaises chacune a leur facon ; la quatrieme est bonne et hors
     * de portee du prototype. Aucune n'est implementee. Ce commentaire existe pour que le
     * comportement ne surgisse pas d'un choix par defaut que personne n'aurait fait.
     *
     * @return array<int, string>
     */
    public static function undecidedCases(): array
    {
        return [
            'Une attaque hostile deja en vol vers un corps celeste qui entre en combat.',
        ];
    }
}
