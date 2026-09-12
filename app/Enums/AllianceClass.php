<?php

namespace OGame\Enums;

/**
 * La classe d'une alliance : ce que ses membres gagnent tous ensemble.
 *
 * ## Les valeurs viennent de la vue, elles ne les inventent pas
 *
 * `resources/views/ingame/alliance/classes.blade.php` publiait deja `data-alliance-class-id` a
 * 1, 2 et 3 pour Guerriers, Commercants et Chercheurs, et un prix de 400 000 de matiere noire.
 * Cet enum reprend ces valeurs telles quelles : la page etait un decor, mais elle nommait deja
 * ce que le joueur achete, et changer ces nombres aurait casse le seul contrat existant.
 *
 * ## Le prix vit ici, et une seule fois
 *
 * Il etait ecrit en dur a trois endroits du gabarit (`data-alliance-class-price` et deux libelles).
 * Une valeur ecrite trois fois se met a diverger ; celle-ci est lue.
 */
enum AllianceClass: int
{
    case WARRIORS = 1;
    case TRADERS = 2;
    case RESEARCHERS = 3;

    /**
     * Ce que l'alliance paie, en matiere noire, pour prendre ou changer de classe.
     */
    public const int PRICE_IN_DARK_MATTER = 400000;

    /**
     * Le segment de clef de traduction de cette classe.
     *
     * Les libelles vivent dans `resources/lang/<locale>/t_ingame.php`, section alliance.
     */
    private function translationKey(): string
    {
        return match ($this) {
            self::WARRIORS => 'warriors',
            self::TRADERS => 'traders',
            self::RESEARCHERS => 'researchers',
        };
    }

    /**
     * Le nom affiche de la classe.
     */
    public function getName(): string
    {
        return trans('t_ingame.alliance.class_' . $this->translationKey());
    }

    /**
     * Le nom machine, qui sert de classe CSS au sprite de la page.
     *
     * **Ces trois noms sont ceux de la feuille de style du jeu**, pas une invention : le gabarit
     * ecrit `sprite allianceclass large warrior|trader|explorer`. Le chercheur s'appelle
     * « explorer » cote image, et c'est ainsi qu'il faut l'ecrire pour que l'icone existe.
     */
    public function getMachineName(): string
    {
        return match ($this) {
            self::WARRIORS => 'warrior',
            self::TRADERS => 'trader',
            self::RESEARCHERS => 'explorer',
        };
    }

    /**
     * Les bonus de cette classe, tels que le joueur les lit.
     *
     * **Les clefs existent depuis toujours** — `warrior_bonus_1` a `4`, `trader_bonus_1` a `5`,
     * `researcher_bonus_1` a `3` : ce sont les promesses que la page affichait deja quand elle
     * n'etait qu'un decor. Les recopier ailleurs en ferait deux verites ; elles sont lues ici, et
     * leur nombre est celui de la classe, pas un nombre rond.
     *
     * @return array<int, string>
     */
    public function bonusLabels(): array
    {
        $prefixe = match ($this) {
            self::WARRIORS => 'warrior',
            self::TRADERS => 'trader',
            self::RESEARCHERS => 'researcher',
        };

        $combien = match ($this) {
            self::WARRIORS => 4,
            self::TRADERS => 5,
            self::RESEARCHERS => 3,
        };

        $libelles = [];

        for ($rang = 1; $rang <= $combien; $rang++) {
            $libelles[] = trans('t_ingame.alliance.' . $prefixe . '_bonus_' . $rang);
        }

        return $libelles;
    }

    /**
     * La classe que designe cette valeur, ou rien si elle n'en designe aucune.
     *
     * **Une porte de confiance** : la valeur vient d'une requete HTTP ou d'une colonne. `tryFrom`
     * refuse ce qui n'est pas une classe au lieu de lever, et l'appelant decide de ce qu'il fait
     * d'un refus.
     */
    public static function tryFromValue(mixed $value): self|null
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        return self::tryFrom((int)$value);
    }
}
