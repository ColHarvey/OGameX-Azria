<?php

namespace OGame\Observers;

use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Models\Lifeforms\LifeformPlanet;

/**
 * La population decide quelles technologies sont actives : toute ecriture de la population invalide la
 * memoire des bonus.
 *
 * ## Pourquoi sur le modele, et pas chez chaque ecrivain
 *
 * Les niveaux, les emplacements et les decouvertes invalident la memoire dans le service qui les ecrit.
 * La population, elle, a deux ecrivains — la croissance du passage demographique et les morts au combat —
 * et aucun des deux ne le faisait : des bonus deja calcules dans le processus restaient actifs apres une
 * chute de population, ou absents apres le franchissement d un seuil, jusqu a une minute dans un
 * travailleur persistant (relance de Codex, journal §155.18). Le modele est le seul point que tout
 * ecrivain Eloquent traverse ; une invalidation posee chez chaque ecrivain serait a reposer chez le
 * prochain, et rien ne le rappellerait.
 *
 * ## Ce qui invalide
 *
 * La population, et l ancre (`previous_*`) que les morts au combat ramenent : une lecture datee, memorisee
 * avant un reglement tardif, aurait ete calculee sur une population que la bataille a ensuite reduite. Une
 * ecriture qui ne change ni l une ni l autre — l horloge seule, sur une population stationnaire — ne vide
 * rien, pour que la memoire serve encore a ce pour quoi elle existe. La ligne disparait avec sa planete par
 * la clef etrangere en cascade, sans evenement Eloquent : ce cas-la reste borne par la minute.
 *
 * Une ecriture faite hors d Eloquent (autre processus, requete brute) n y passe pas : la minute de la memoire
 * reste la borne de ce retard-la, et c est dit dans `LifeformBonusCache`.
 *
 * ## Une equivalence declaree, et pourquoi
 *
 * La mutation « seule la population courante invalide, pas l ancre » survit a tout temoin, **par construction
 * de la coupe** (`LifeformCombatHold`, journal §155.15-16) : l horloge d un corps ne depasse jamais une bataille
 * non reglee, `applyIfAttackerWon()` amene le monde a l instant de la bataille, et la population ecrite est donc
 * toujours celle des survivants — differente de celle d avant des qu il y a des morts. Aucune ecriture ne change
 * l ancre sans changer la population. L ancre reste dans la liste pour le jour ou un chemin ramenerait une
 * bataille dans le passe de l horloge (la branche de recroissance de `LifeformCombatLosses`) ; ce jour-la,
 * l equivalence tombe et un temoin est du.
 */
class LifeformPlanetObserver
{
    public function saved(LifeformPlanet $etat): void
    {
        if ($etat->wasChanged(['population', 'previous_population', 'previous_calculated_at'])) {
            LifeformBonusCache::invalidate();
        }
    }
}
