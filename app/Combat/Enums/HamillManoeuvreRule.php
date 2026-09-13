<?php

namespace OGame\Combat\Enums;

use OGame\Combat\Exceptions\UnknownHamillManoeuvreRule;
use OGame\Models\CombatInstance;

/**
 * Ce que la manoeuvre de Hamill fait a l Etoile de la mort qu elle vise.
 *
 * ## Les deux regles
 *
 * - **Telle que livree** (`v1`) : le moteur PHP retire l Etoile de la bataille — elle ne tire pas, et elle
 *   est comptee perdue — tandis que le moteur Rust ne la retire que du **decompte de depart** : elle tire
 *   encore, et n apparait dans aucune perte. Les deux moteurs ne jouent donc pas la meme bataille. Un combat
 *   ouvert sous cette regle la garde : la corriger sous lui changerait une bataille deja engagee.
 * - **Effective** (`v2`) : dans les deux moteurs, la manoeuvre retire l Etoile **des unites qui se battent**
 *   et la laisse au depart, donc comptee perdue. C est la regle que le jeu annonce depuis toujours, et celle
 *   que le moteur PHP appliquait deja.
 *
 * ## Ou la regle est choisie
 *
 * A l ouverture d un combat durable, ecrite sur son instance. Une bataille instantanee — attaque sans
 * combat durable, expedition, espionnage, destruction de lune, espace libre — se decide a son arrivee : elle
 * prend la regle courante, et il n y a rien a proteger puisqu il n y a pas d intervalle.
 *
 * ## Une porte de relecture
 *
 * `fromInstance()` refuse une colonne vide, un nom inconnu ou une valeur qui n est pas une chaine : une
 * regle interpretee par defaut ferait jouer a un combat une bataille sous des regles que personne ne lui a
 * donnees.
 */
enum HamillManoeuvreRule: string
{
    case AsDelivered = 'v1';

    case Effective = 'v2';

    /**
     * La regle la plus recente.
     */
    public static function current(): self
    {
        return self::Effective;
    }

    /**
     * La regle d un combat, ou un refus.
     */
    public static function fromInstance(CombatInstance $combat): self
    {
        $valeur = $combat->getAttributes()['hamill_rule_version'] ?? null;

        if (!is_string($valeur)) {
            throw new UnknownHamillManoeuvreRule(
                'Le combat ' . $combat->id . ' porte une regle de manoeuvre de Hamill qui est un '
                . get_debug_type($valeur) . ' et non un nom : sa bataille ne se calcule sous aucune regle par defaut.'
            );
        }

        $regle = self::tryFrom($valeur);

        if ($regle === null) {
            throw new UnknownHamillManoeuvreRule(
                'Le combat ' . $combat->id . ' porte la regle de manoeuvre de Hamill « ' . $valeur
                . ' », que ce code ne connait pas.'
            );
        }

        return $regle;
    }
}
