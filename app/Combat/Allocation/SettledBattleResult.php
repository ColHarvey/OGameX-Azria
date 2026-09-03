<?php

namespace OGame\Combat\Allocation;

use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\Resources;

/**
 * Le resultat d'une bataille, vu avec le butin reellement pris.
 *
 * ## Pourquoi une copie, et pas une retouche
 *
 * Le resultat calcule a la cloture est la trace figee de ce que le moteur a fait : c'est lui qui est
 * persiste, et un rejeu doit le retrouver **tel quel**. Le reglement, lui, a besoin d'un resultat ou
 * le butin est l'applique et ou chaque part est celle qu'il a repartie, pour le remettre a la
 * resolution existante sans lui apprendre qu'elle regle une bataille ancienne.
 *
 * Retoucher l'original ferait les deux d'un coup : la resolution appliquerait les bons nombres, et
 * la trace figee en memoire porterait desormais l'applique a la place du potentiel. Tant que
 * personne ne relit l'original apres coup, la faute reste invisible — c'est exactement le genre de
 * defaut qui se decouvre le jour ou quelqu'un ajoute cette relecture.
 *
 * ## Une copie superficielle, et c'est voulu
 *
 * Les unites, les manches et les debris sont les memes dans les deux batailles : seuls le butin et
 * les parts different, et ce sont les deux seules choses remplacees. Les objets partages ne sont
 * jamais modifies — ni ici, ni par la resolution, qui a son propre essai d'immutabilite.
 */
final class SettledBattleResult
{
    /**
     * Le resultat a regler, sans toucher celui qu'on lui donne.
     */
    public static function of(BattleResult $result, ExactLootAmounts $applied, AppliedLootShares $shares): BattleResult
    {
        $copie = clone $result;
        $copie->loot = new Resources($applied->metal, $applied->crystal, $applied->deuterium, 0);
        $copie->attackerFleetResults = array_map(
            static function (AttackerFleetResult $flotte) use ($shares): AttackerFleetResult {
                $part = $shares->forFleet($flotte->fleetMissionId);

                $reglee = clone $flotte;
                $reglee->lootShare = new Resources($part->metal, $part->crystal, $part->deuterium, 0);

                return $reglee;
            },
            $result->attackerFleetResults
        );

        return $copie;
    }
}
