<?php

namespace OGame\Military;

use OGame\Models\User;

/**
 * Les comptes PNJ parmi des identifiants : calcules dans une bataille, jamais credites d un cumul.
 */
final class NpcAccounts
{
    /**
     * @param list<int> $ids
     * @return list<int>
     */
    public static function among(array $ids): array
    {
        $candidats = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($candidats === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int)$id,
            User::query()->whereIn('id', $candidats)->where('is_npc', true)->pluck('id')->all()
        ));
    }
}
