<?php

namespace OGame\Lifeforms;

/**
 * Les quatre especes des formes de vie.
 *
 * La valeur entiere est celle du jeu officiel (elle prefixe les identifiants d objets : 11xxx pour
 * les Humains, 12xxx pour les Rock’tal, 13xxx pour les Mechas, 14xxx pour les Kaelesh), et c est
 * elle qui est stockee en base.
 *
 * **Regle Azria (Keven, 15 septembre 2026)** : l espece se choisit une seule fois par compte, parmi
 * les quatre, et vaut pour toutes les planetes du compte, presentes et futures. Il n y a pas de
 * changement d espece.
 */
enum Species: int
{
    case Humans = 1;
    case Rocktal = 2;
    case Mechas = 3;
    case Kaelesh = 4;

    /**
     * Le nom machine, employe par les clefs de traduction et les classes CSS heritees.
     */
    public function machineName(): string
    {
        return match ($this) {
            self::Humans => 'humans',
            self::Rocktal => 'rocktal',
            self::Mechas => 'mechas',
            self::Kaelesh => 'kaelesh',
        };
    }

    /**
     * Le prefixe des identifiants d objets de l espece (deux premiers chiffres).
     */
    public function idPrefix(): int
    {
        return 10 + $this->value;
    }

    /**
     * L espece qui porte un identifiant d objet, ou null si l identifiant n en designe aucune.
     */
    public static function ofObjectId(int $objectId): self|null
    {
        $prefixe = intdiv($objectId, 1000);
        foreach (self::cases() as $espece) {
            if ($espece->idPrefix() === $prefixe) {
                return $espece;
            }
        }

        return null;
    }
}
