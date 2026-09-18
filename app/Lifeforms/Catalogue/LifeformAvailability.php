<?php

namespace OGame\Lifeforms\Catalogue;

use OGame\Lifeforms\Bonuses\LifeformBonusResolver;

/**
 * Un objet dont l effet n est pas applique ne se vend pas.
 *
 * Deux effets du catalogue attendent une decision de jeu (`LifeformBonusResolver::NOT_YET_APPLIED`) : les cases de
 * planete du Bio-modificateur et le carburant rendu au rappel du Pilote automatique a fronde. Tant que l effet n est
 * pas applique, l objet qui ne porte que cet effet est **indisponible** : la file refuse de le construire ou de le
 * rechercher, le choix d un emplacement refuse de le placer, le tirage au sort ne le tire pas, et les pages le disent
 * sans promettre un bonus (releve de Codex, journal §155.26).
 *
 * La regle derive de la liste du resolveur, pour qu appliquer l effet un jour rouvre l objet sans rien d autre.
 */
final class LifeformAvailability
{
    public static function isAvailable(LifeformObject $object): bool
    {
        if ($object->bonuses === []) {
            return true;
        }
        foreach ($object->bonuses as $bonus) {
            if (!in_array($bonus->code, LifeformBonusResolver::NOT_YET_APPLIED, true)) {
                return true;
            }
        }

        return false;
    }
}
