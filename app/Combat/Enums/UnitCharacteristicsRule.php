<?php

namespace OGame\Combat\Enums;

use OGame\Combat\Exceptions\UnknownUnitCharacteristicsRule;
use OGame\Models\CombatInstance;

/**
 * La regle qui compose les unites d un combat durable a sa cloture.
 *
 * ## Les deux regles
 *
 * - **Premiere regle** (`v1`) : les tirs lisent les niveaux de recherche **vivants** a la cloture, et le bonus
 *   de classe ne porte que sur le niveau rapporte. C est ce que le jeu faisait avant les decisions de Keven
 *   des 12 et 13 septembre 2026, et un combat engage sous elle la garde.
 * - **Gel a l admission** (`v2`) : chaque flotte tire avec les niveaux et le bonus de ses classes **de son
 *   instant d admission** ; la garnison, avec ceux de la photographie d ouverture.
 *
 * ## Quelle regle a l ouverture
 *
 * Le gel lit l historique des classes, qui commence a sa ligne de base. Un combat ouvert **a cet instant
 * ou avant** — une flotte arrivee pendant la maintenance du deploiement, traitee apres — garde donc la
 * premiere regle, explicitement : on n invente pas l historique qui manque. Toute admission d un combat
 * tombe au plus tot a son ouverture, si bien que ce seul choix couvre toutes ses flottes.
 *
 * ## Une porte de relecture
 *
 * `fromInstance()` refuse une colonne vide, un nom inconnu ou une valeur qui n est pas une chaine. Une
 * regle interpretee par defaut ferait jouer a un combat une bataille sous des regles que personne ne lui a
 * donnees.
 */
enum UnitCharacteristicsRule: string
{
    case FirstRule = 'v1';

    case FrozenAtEntry = 'v2';

    /**
     * La regle la plus recente.
     */
    public static function current(): self
    {
        return self::FrozenAtEntry;
    }

    /**
     * La regle d un combat qui s ouvre a cet instant.
     *
     * @param int|null $historyBaselineAt L instant de la ligne de base des historiques de classe, ou `null`
     *                                    si la migration n a trouve aucun compte (tout compte est alors ne
     *                                    apres elle, avec sa ligne de creation).
     */
    public static function forOpeningAt(int $openedAt, int|null $historyBaselineAt): self
    {
        if ($historyBaselineAt !== null && $openedAt <= $historyBaselineAt) {
            return self::FirstRule;
        }

        return self::current();
    }

    /**
     * La regle d un combat, ou un refus.
     */
    public static function fromInstance(CombatInstance $combat): self
    {
        $valeur = $combat->getAttributes()['unit_characteristics_version'] ?? null;

        if (!is_string($valeur)) {
            throw new UnknownUnitCharacteristicsRule(
                'Le combat ' . $combat->id . ' porte une regle de composition des unites qui est un '
                . get_debug_type($valeur) . ' et non un nom : il ne se compose sous aucune regle par defaut.'
            );
        }

        $regle = self::tryFrom($valeur);

        if ($regle === null) {
            throw new UnknownUnitCharacteristicsRule(
                'Le combat ' . $combat->id . ' porte la regle de composition des unites « ' . $valeur
                . ' », que ce code ne connait pas.'
            );
        }

        return $regle;
    }
}
