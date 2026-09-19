<?php

namespace OGame\Lifeforms\Catalogue;

use OGame\Lifeforms\Bonuses\LifeformBonusResolver;

/**
 * Un objet dont l effet n est pas applique ne se vend pas.
 *
 * Tant qu un effet n est pas applique, l objet qui ne porte que cet effet est **indisponible** : la file refuse de le
 * construire ou de le rechercher, le choix d un emplacement refuse de le placer, le tirage au sort ne le tire pas, et
 * les pages le disent sans promettre un bonus (releve de Codex, journal §155.26).
 *
 * **La liste du resolveur est vide depuis le 19 septembre 2026** : Keven a tranche les deux derniers effets en attente
 * — les cases de planete du Bio-modificateur et le carburant rendu au rappel du Pilote automatique a fronde sont
 * appliques (journal §165), et les deux objets sont ouverts. La regle demeure, pour l effet neuf que personne n aurait
 * raccorde : elle derive de la liste, pour qu y poser un code ferme l objet, et l en retirer le rouvre, sans rien
 * d autre a changer.
 */
final class LifeformAvailability
{
    public static function isAvailable(LifeformObject $object): bool
    {
        return self::isAvailableGiven($object, LifeformBonusResolver::NOT_YET_APPLIED);
    }

    /**
     * La meme regle, contre une liste d effets en attente donnee.
     *
     * Elle existe pour etre eprouvee : la liste du jeu etant vide, un temoin qui passerait par `isAvailable()` ne
     * pourrait plus distinguer la regle juste de la fausse — tout objet serait disponible dans les deux cas. Il pose
     * donc l effet en attente lui-meme.
     *
     * @param array<int, string> $notYetApplied
     */
    public static function isAvailableGiven(LifeformObject $object, array $notYetApplied): bool
    {
        if ($object->bonuses === []) {
            return true;
        }
        foreach ($object->bonuses as $bonus) {
            if (!in_array($bonus->code, $notYetApplied, true)) {
                return true;
            }
        }

        return false;
    }
}
