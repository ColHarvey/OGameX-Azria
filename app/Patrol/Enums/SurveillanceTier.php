<?php

namespace OGame\Patrol\Enums;

/**
 * Ce qu un Reseau de surveillance sait d une patrouille etrangere, selon son niveau.
 *
 * ## Deux effets par niveau, et un seul niveau qui compte
 *
 * Chaque niveau agit sur deux choses a la fois : **combien de temps** une patrouille doit s attarder
 * dans le systeme avant d etre reperee, et **ce que le contact revele** une fois acquis. Les faits
 * s empilent — un reseau de niveau 3 revele aussi ce que reveleraient les niveaux 1 et 2.
 *
 * La visibilite d un joueur dans un systeme est celle de son **meilleur** reseau en service, jamais
 * la somme de plusieurs : deux planetes de niveau 2 ne valent pas un niveau 4. C est ce que
 * `bestOf()` impose, et c est une decision de jeu, pas une commodite de calcul.
 *
 * ## Ce qu aucun niveau ne revele
 *
 * Ni la composition d une flotte, ni sa reserve de carburant, ni sa cargaison. Ces faits n ont pas
 * de palier : ils ne voyagent jamais. Un niveau qui les rendrait ferait de la surveillance un
 * espionnage sans sonde et sans risque.
 *
 * ## Les durees sont une adaptation d Azria
 *
 * Le jeu d origine n a pas de reseau de surveillance : ces cinq durees n imitent rien et ne se
 * presentent pas comme officielles. Elles tiennent en un seul endroit — ici — pour qu un
 * reequilibrage soit une modification de cette table et de son temoin, sans toucher a la mecanique.
 * L intention : une patrouille qui **traverse** un systeme ne se voit pas, une patrouille qui s y
 * **installe** finit par se voir, et un reseau developpe raccourcit franchement cette attente.
 */
enum SurveillanceTier: int
{
    /**
     * Un contact et sa position dans le systeme. Rien de plus : ni qui, ni ou il va.
     */
    case Contact = 1;

    /**
     * S y ajoute le proprietaire de la patrouille.
     */
    case Identity = 2;

    /**
     * S y ajoute sa direction a l interieur du systeme.
     */
    case Heading = 3;

    /**
     * S y ajoute un ordre de grandeur de sa taille, jamais un effectif exact.
     */
    case Estimate = 4;

    /**
     * S y ajoute l effectif exact.
     */
    case Strength = 5;

    /**
     * Le delai d acquisition, en secondes : le temps de sejour avant qu un contact devienne visible.
     */
    public function acquisitionSeconds(): int
    {
        return match ($this) {
            self::Contact => 30 * 60,
            self::Identity => 20 * 60,
            self::Heading => 12 * 60,
            self::Estimate => 7 * 60,
            self::Strength => 3 * 60,
        };
    }

    /**
     * Ce niveau revele-t-il ce fait ?
     *
     * L empilement est porte ici, une fois : chaque fait nomme le niveau a partir duquel il
     * voyage, et la comparaison fait le reste. Une liste par niveau se serait desynchronisee au
     * premier fait ajoute.
     */
    public function reveals(SurveillanceFact $fact): bool
    {
        return $this->value >= $fact->fromTier()->value;
    }

    /**
     * Tous les faits que ce niveau revele, dans l ordre des paliers.
     *
     * @return array<int, SurveillanceFact>
     */
    public function revealedFacts(): array
    {
        return array_values(array_filter(
            SurveillanceFact::cases(),
            fn (SurveillanceFact $fait): bool => $this->reveals($fait)
        ));
    }

    /**
     * Le niveau qui gouverne la visibilite parmi ceux-ci : le meilleur, jamais leur somme.
     *
     * Rend `null` quand la liste est vide ou ne contient aucun niveau valable — **aucun detecteur,
     * aucun renseignement**. Un niveau zero ou negatif n est pas un reseau : c est l absence de
     * reseau, et elle ne se convertit pas en palier le plus bas.
     *
     * @param array<int, int> $levels Les niveaux des reseaux en service.
     */
    public static function bestOf(array $levels): self|null
    {
        $meilleur = null;

        foreach ($levels as $niveau) {
            $palier = self::fromLevel($niveau);

            if ($palier !== null && ($meilleur === null || $palier->value > $meilleur->value)) {
                $meilleur = $palier;
            }
        }

        return $meilleur;
    }

    /**
     * Le palier d un niveau de batiment, ou `null` si ce niveau n en ouvre aucun.
     *
     * Un niveau superieur au dernier palier garde le dernier : construire au-dela ne revele pas
     * davantage, et ne doit pas non plus faire disparaitre le contact.
     */
    public static function fromLevel(int $level): self|null
    {
        if ($level < self::Contact->value) {
            return null;
        }

        return self::tryFrom($level) ?? self::Strength;
    }
}
