<?php

namespace OGame\Combat\Enums;

use InvalidArgumentException;

/**
 * Les missions du jeu, telles que le combat persistant a besoin de les distinguer.
 *
 * Une enumeration plutot que les entiers de `GameMissionFactory` : `$mission_type === 5` ne dit
 * rien a qui lit une regle de combat, et une faute de frappe sur un entier ne se voit pas.
 *
 * **La correspondance est exhaustive et sans defaut silencieux.** `fromMissionType()` refuse un
 * type inconnu au lieu de retomber sur une valeur plausible : le jour ou une mission sera ajoutee
 * au jeu, elle devra etre classee ici, et un test le verifie. Une matrice qui reponde « attaque »
 * a une mission qu'elle ne connait pas serait pire que pas de matrice du tout.
 *
 * Le **retour** n'y figure pas : ce n'est pas un genre mais une etape de vol, portee par
 * `FlightLeg`. Une flotte qui rentre garde le genre de ce qu'elle etait partie faire.
 */
enum CombatMissionKind: string
{
    /**
     * Attaque ordinaire. `mission_type` 1.
     */
    case Attack = 'attack';

    /**
     * Attaque groupee. `mission_type` 2.
     */
    case AcsAttack = 'acs_attack';

    /**
     * Transport de ressources. `mission_type` 3.
     */
    case Transport = 'transport';

    /**
     * Deploiement vers une planete que l'on possede. `mission_type` 4.
     */
    case Deployment = 'deployment';

    /**
     * Defense groupee : un renfort exterieur. `mission_type` 5.
     */
    case AcsDefend = 'acs_defend';

    /**
     * Espionnage. `mission_type` 6.
     */
    case Espionage = 'espionage';

    /**
     * Colonisation d'une position vide. `mission_type` 7.
     */
    case Colonisation = 'colonisation';

    /**
     * Recyclage d'un champ de debris. `mission_type` 8.
     */
    case Recycle = 'recycle';

    /**
     * Destruction de lune. `mission_type` 9.
     */
    case MoonDestruction = 'moon_destruction';

    /**
     * Tir de missiles interplanetaires. `mission_type` 10.
     */
    case Missile = 'missile';

    /**
     * Expedition en espace profond. `mission_type` 15.
     */
    case Expedition = 'expedition';

    /**
     * La correspondance avec les types de `GameMissionFactory`.
     *
     * @return array<int, self>
     */
    public static function byMissionType(): array
    {
        return [
            1 => self::Attack,
            2 => self::AcsAttack,
            3 => self::Transport,
            4 => self::Deployment,
            5 => self::AcsDefend,
            6 => self::Espionage,
            7 => self::Colonisation,
            8 => self::Recycle,
            9 => self::MoonDestruction,
            10 => self::Missile,
            15 => self::Expedition,
        ];
    }

    /**
     * Le genre correspondant a un `mission_type`, ou une erreur.
     *
     * @param int $missionType
     * @return self
     */
    public static function fromMissionType(int $missionType): self
    {
        return self::byMissionType()[$missionType]
            ?? throw new InvalidArgumentException(
                'Le type de mission ' . $missionType . ' n est pas classe pour le combat persistant. '
                . 'Toute mission doit avoir une regle explicite : la classer ici, et decider sa ligne de matrice.'
            );
    }

    /**
     * Si ce genre porte une intention hostile envers le corps celeste vise.
     *
     * L'attaque et la destruction de lune, oui. Les missiles frappent mais n'engagent pas de
     * flotte au combat : ils ont leur propre regle. L'espionnage est hostile au sens du jeu, mais
     * il ne dispute pas la possession du corps celeste.
     *
     * @return bool
     */
    public function opensCombat(): bool
    {
        return match ($this) {
            self::Attack, self::AcsAttack, self::MoonDestruction => true,
            self::Transport, self::Deployment, self::AcsDefend, self::Espionage,
            self::Colonisation, self::Recycle, self::Missile, self::Expedition => false,
        };
    }
}
