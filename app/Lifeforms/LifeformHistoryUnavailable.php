<?php

namespace OGame\Lifeforms;

use RuntimeException;

/**
 * Ce que les formes de vie apportaient **a un instant passe** ne peut pas etre etabli.
 *
 * Deux choses peuvent manquer.
 *
 * - La **population** de la planete a cet instant, qui decide qu un emplacement de recherche est ouvert. Les
 *   niveaux reviennent par la file des travaux, l occupation des emplacements par son historique, l experience
 *   par les vols deja regles ; la population, elle, se rejoue depuis l etat d ou le dernier passage est parti,
 *   et cet etat ne couvre pas tous les instants.
 * - **Une colonie purgee** : abandonnee, puis supprimee au moins vingt-quatre heures plus tard, elle emporte en
 *   cascade tous ses faits. Si elle existait a l instant demande, l apport du compte ne peut plus etre
 *   reconstruit — la trace de sa purge le dit (`LifeformPurgedBodies`, journal §167).
 *
 * **Ce qui n est pas connu n est jamais remplace.** Ni par zero, ni par la valeur courante : l une
 * desarmerait la flotte, l autre l armerait, et les deux changeraient une bataille apres son arrivee. La
 * seule conduite juste est celle que le socle des combats applique deja pour un historique de classe
 * manquant — `UnknownAdmissionHistory` : **suspendre**, sans perte ni credit partiel, sans fermer les pages
 * du joueur, avec une raison explicite. Une premiere version retombait sur la valeur courante en se
 * contentant d un avertissement ; la relance de Codex l a relevee, et elle avait raison : un avertissement
 * ne rend pas une valeur juste (journal §155.12).
 *
 * `LifeformCombatPhotographer` la traduit en `UnknownAdmissionHistory` a la frontiere du combat, pour que
 * les chemins de suspension existants la reconnaissent sans rien apprendre des formes de vie.
 */
final class LifeformHistoryUnavailable extends RuntimeException
{
}
