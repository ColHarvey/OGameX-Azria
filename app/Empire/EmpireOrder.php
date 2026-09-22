<?php

namespace OGame\Empire;

use OGame\Models\User;

/**
 * **L ordre des colonnes de la vue Empire, tel que le joueur l a range.**
 *
 * Deux listes independantes — les planetes et les lunes —, gardees dans une seule colonne JSON du compte. C est une
 * **preference d affichage**, et elle est traitee comme telle : un identifiant qu elle nomme mais que le joueur ne
 * possede plus est ignore, et un corps qu elle ne nomme pas vient a la fin. Un ordre ne peut donc ni cacher un corps,
 * ni en faire apparaitre un.
 */
class EmpireOrder
{
    /**
     * L ordre enregistre pour un genre de corps, nettoye de ce qui n est plus lisible.
     *
     * @return array<int, int>
     */
    public static function of(User $user, bool $moons): array
    {
        $stored = $user->empire_order;
        if (!is_string($stored) || $stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [];
        }

        $list = $decoded[self::keyOf($moons)] ?? null;
        if (!is_array($list)) {
            return [];
        }

        // Zero est admis : c est la place de la colonne des totaux, qui se range comme les autres sans etre un corps.
        $ids = [];
        foreach ($list as $id) {
            if (is_int($id) && $id >= 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Enregistre l ordre d un genre de corps sans toucher a l autre.
     *
     * @param array<int, int> $ids identifiants **deja verifies** comme appartenant au joueur
     */
    public static function store(User $user, bool $moons, array $ids): void
    {
        $stored = is_string($user->empire_order) && $user->empire_order !== '' ? json_decode($user->empire_order, true) : [];
        if (!is_array($stored)) {
            $stored = [];
        }

        $stored[self::keyOf($moons)] = array_values($ids);
        $user->empire_order = (string)json_encode($stored);
        $user->save();
    }

    /**
     * Oublie l ordre d un genre de corps : les corps reprennent l ordre du compte.
     */
    public static function forget(User $user, bool $moons): void
    {
        self::store($user, $moons, []);
    }

    private static function keyOf(bool $moons): string
    {
        return $moons ? 'moons' : 'planets';
    }
}
