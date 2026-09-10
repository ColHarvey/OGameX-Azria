<?php

namespace OGame\Patrol\Combat;

use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Un combattant reduit a ce que le moteur lit de lui : ses niveaux, geles.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CETTE CLASSE EXISTE
 *
 * Le moteur transforme une flotte en unites de combat en calculant, pour chaque type de
 * vaisseau, sa coque, son bouclier et sa puissance — et ces trois calculs demandent un
 * `PlayerService` pour lire trois niveaux de recherche. C est le seul endroit ou le monde
 * vivant entre dans la composition d une bataille.
 *
 * Tant qu on passe le joueur reel, une recherche terminee **pendant** le combat change des
 * unites deja engagees : les tirs du troisieme round seraient plus forts que ceux du
 * premier, sans que rien ne l ait decide. Le defenseur etait deja protege par sa
 * photographie ; l attaquant ne l etait pas.
 *
 * Cette classe est le pendant, cote attaquant, de `SpatialCombatSite` cote lieu : un
 * **adaptateur de lecture** sur des valeurs figees, qui n emprunte aucune ligne de la base.
 *
 * ------------------------------------------------------------------------------------
 * CE QUI FAIT QUE LE GEL TIENT ENSUITE TOUT SEUL
 *
 * Une fois les unites construites, elles portent des **nombres** — coque, bouclier,
 * puissance — et plus aucune reference a un joueur. Le codec de l etat de champ les
 * persiste tels quels. Le gel n est donc pas une discipline a maintenir round apres round :
 * il est **structurel** des que le champ est ecrit. Cette classe ne sert qu au moment ou le
 * champ se compose — l ouverture pour les premiers, l admission pour chaque renfort.
 *
 * ------------------------------------------------------------------------------------
 * CE QU ELLE NE CHANGE PAS, ET C EST VOULU
 *
 * `getResearchLevel()` rend les niveaux **bruts**, sans y ajouter le bonus de classe. Ce
 * n est pas un oubli : c est exactement ce que le chemin vivant lit, `getResearchLevel()`
 * n interrogeant que `user_tech`. Le bonus de classe, lui, n entre aujourd hui que dans les
 * niveaux **rapportes** (`BattleEngine`, ligne des `attackerWeaponLevel`).
 *
 * Geler ne doit pas corriger : si ce bonus doit un jour porter sur les unites, c est une
 * decision de jeu, elle vaudra pour les deux camps et pour les deux moteurs. Le noter ici
 * evite qu un lecteur prenne l ecart pour un defaut de cette classe. Le bonus est donc
 * **porte** — il fait partie de ce qui est gele — et rendu par `classCombatBonus()`, pret
 * pour le jour ou le rapport le demandera.
 */
final class FrozenCombatant extends PlayerService
{
    /**
     * @param int $ownerId Le proprietaire reel, conserve pour l identite des unites.
     * @param int $weaponLevel Le niveau d armes gele.
     * @param int $shieldLevel Le niveau de boucliers gele.
     * @param int $armorLevel Le niveau de blindage gele.
     * @param int $classCombatBonus Le bonus de classe, gele lui aussi, mais non additionne ici.
     */
    public function __construct(
        private readonly int $ownerId,
        private readonly int $weaponLevel,
        private readonly int $shieldLevel,
        private readonly int $armorLevel,
        private readonly int $classCombatBonus,
    ) {
        // Le zero est la seule forme du constructeur parent qui ne charge aucun compte : elle
        // fabrique un utilisateur fictif, et rien n est lu en base.
        parent::__construct(0);
    }

    /**
     * L identifiant du proprietaire reel.
     *
     * Les unites de combat le portent (`BattleUnit::$ownerId`), et le reglement s en sert pour
     * savoir a qui rendre des survivants. Rendre zero ici ferait perdre cette trace.
     */
    public function getId(): int
    {
        return $this->ownerId;
    }

    /**
     * Le bonus de classe gele — porte, mais non ajoute aux niveaux ci-dessous.
     */
    public function classCombatBonus(): int
    {
        return $this->classCombatBonus;
    }

    /**
     * Les trois niveaux que le moteur lit, et **aucun autre**.
     *
     * Une quatrieme recherche demandee ici serait une lecture que le gel ne couvre pas : la
     * refuser bruyamment vaut mieux que de rendre zero, qui se glisserait dans un calcul sans
     * que personne ne le voie.
     */
    public function getResearchLevel(string $machine_name): int
    {
        return match ($machine_name) {
            'weapon_technology' => $this->weaponLevel,
            'shielding_technology' => $this->shieldLevel,
            'armor_technology' => $this->armorLevel,
            default => throw new RuntimeException(
                'A frozen combatant was asked for « ' . $machine_name .' », which its photograph does '
                . 'not carry: only weapon, shielding and armor levels are frozen for a battle.'
            ),
        };
    }

    /**
     * Un combattant gele ne se modifie pas : il decrit un instant passe.
     */
    public function setResearchLevel(string $machine_name, int $level, bool $save_to_db = true): void
    {
        throw new RuntimeException(
            'A frozen combatant was asked to change its « ' . $machine_name . ' » level: a photograph '
            . 'describes an instant that has already happened.'
        );
    }
}
