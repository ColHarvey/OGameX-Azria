<?php

namespace OGame\Combat\Enums;

/**
 * Pourquoi un combat ne donne droit a aucun pillage.
 *
 * ## Pourquoi un refus nomme, et pas un taux a zero
 *
 * Un taux de zero se confond avec un taux calcule qui se trouve valoir zero — une cible vide, par
 * exemple. Le premier est une **regle**, le second un **resultat**. Les distinguer permet au
 * rapport de dire pourquoi rien n'a ete pris, et a un test d'exiger que chaque site de combat
 * declare intentionnellement son genre.
 *
 * ## Pourquoi un enum separe de `CombatReasonCode`
 *
 * Celui-la nomme les refus **d'admission** a un combat : ralliement ferme, limite de camp
 * atteinte, flotte deja engagee. Ce sont des raisons de ne pas entrer. Ici, il s'agit de raisons de
 * ne rien emporter alors qu'on se bat bel et bien. Melanger les deux axes dans une seule liste
 * rendrait ambigu le test qui exige qu'aucune case ne vive sans regle ecrite.
 */
enum NoLootReason: string
{
    /**
     * Un combat de contre-espionnage.
     *
     * Des sondes surprises au-dessus d'une planete se battent, mais ne pillent pas : le rapport
     * d'espionnage annonce deja un butin nul, et il dit vrai.
     */
    case CounterEspionage = 'counter_espionage';

    /**
     * Une rencontre d'expedition contre un acteur pilote par le serveur.
     *
     * **Ce cas ne se contente pas de valoir zero : il ferme un piege.** La planete PNJ d'une
     * expedition est synthetique, mais elle est construite sur l'identifiant de la planete de
     * **depart de l'attaquant**, et elle n'ecrase pas `getResources()`. Un contexte de pillage
     * ordinaire y lirait donc le stock du joueur lui-meme — et son proprietaire PNJ, dont la date
     * de derniere connexion ne bouge jamais, passerait pour une cible inactive.
     *
     * La mission jette aujourd'hui ce butin et n'en subit rien. Le refus nomme empeche qu'un futur
     * lecteur, ou une reservation de combat persistant, le prenne au serieux.
     */
    case NpcEncounter = 'npc_encounter';

    /**
     * Un combat monte par un outil de mesure.
     *
     * La commande de performance simule des batailles pour les chronometrer. Elle ne doit rien
     * prelever nulle part, et surtout pas sur la planete courante qui lui sert de decor.
     */
    case SyntheticBenchmark = 'synthetic_benchmark';

    /**
     * Aucun attaquant dans le camp.
     *
     * **Ce n est pas une regle de jeu, c est un repli operationnel.** Le selecteur a refuse ce camp,
     * et la mission a choisi de continuer sans butin plutot que d echouer.
     *
     * Pourquoi ce choix : une exception levee a chaque passage de l ordonnanceur laisserait la
     * mission non traitee et la ferait rejouer indefiniment. Un combat sans pillage, journalise en
     * `critical`, est une degradation visible ; une boucle d echec silencieuse ne l est pas.
     */
    case EmptyAttackingSide = 'empty_attacking_side';

    /**
     * Des joueurs et des acteurs pilotes par le serveur dans le meme camp.
     *
     * Meme repli operationnel, meme raison de le preferer a une exception. Sa presence dans un
     * resultat est un **signal d incoherence**, pas une issue normale.
     */
    case MixedPlayerNpcSide = 'mixed_player_npc_side';

    /**
     * Le compte systeme figure parmi les attaquants.
     *
     * Aucune mecanique de jeu ne s applique a lui : le voir attaquer signale une donnee ou un chemin
     * de code inattendu. Le combat se deroule sans butin, et la trace reste.
     */
    case SystemActorPresent = 'system_actor_present';

    /**
     * La raison, telle qu'un rapport peut l'afficher.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 't_battle.no_loot.' . $this->value;
    }
}
