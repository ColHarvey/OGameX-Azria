<?php

namespace OGame\Lifeforms\Presentation;

use OGame\Facades\AppUtil;
use OGame\Lifeforms\Catalogue\LifeformBonus;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Species;
use OGame\Services\ObjectService;
use RuntimeException;

/**
 * Les effets d un batiment de forme de vie, tels que le joueur les lit : la valeur au niveau courant
 * et au niveau suivant, effet par effet.
 *
 * Les quantites demographiques (espace de vie, croissance, nourriture, stock, capacites de palier)
 * sont lues dans le profil de la planete calcule aux deux niveaux — ce que le joueur verra vraiment,
 * bonus des autres batiments compris ; les effets en pour cent viennent du catalogue.
 *
 * @phpstan-type Effet array{code: string, label: string, now: string, next: string}
 */
final class LifeformEffectPresenter
{
    /**
     * @param array<int, int> $levels
     * @return array<int, Effet>
     */
    public function effectsOf(LifeformObject $object, array $levels, Species $species, float $speed): array
    {
        $courant = $levels[$object->id] ?? 0;
        $suivant = $levels;
        $suivant[$object->id] = $courant + 1;
        $profilCourant = PlanetLifeformProfile::fromLevels($species, $levels, $speed);
        $profilSuivant = PlanetLifeformProfile::fromLevels($species, $suivant, $speed);

        $effets = [];
        foreach ($object->bonuses as $bonus) {
            if ($bonus->isUnassigned()) {
                continue;
            }
            [$maintenant, $apres] = match ($bonus->code) {
                LifeformEffect::LIVING_SPACE => [self::entier($profilCourant->livingSpace), self::entier($profilSuivant->livingSpace)],
                LifeformEffect::GROWTH_RATE, LifeformEffect::GROWTH_RATE_PERCENT => [self::parHeure($profilCourant->growthPerHour), self::parHeure($profilSuivant->growthPerHour)],
                LifeformEffect::FOOD_PRODUCTION, LifeformEffect::FOOD_PRODUCTION_PERCENT => [self::parHeure($profilCourant->foodProductionPerHour), self::parHeure($profilSuivant->foodProductionPerHour)],
                LifeformEffect::FOOD_STORAGE, LifeformEffect::FOOD_STORAGE_PERCENT => [self::entier((int)floor($profilCourant->foodStorage)), self::entier((int)floor($profilSuivant->foodStorage))],
                LifeformEffect::LIVING_SPACE_PERCENT => [self::entier($profilCourant->livingSpace), self::entier($profilSuivant->livingSpace)],
                LifeformEffect::TIER2_CAPACITY => [self::entier((int)floor($profilCourant->tier2Capacity)), self::entier((int)floor($profilSuivant->tier2Capacity))],
                LifeformEffect::TIER3_CAPACITY => [self::entier((int)floor($profilCourant->tier3Capacity)), self::entier((int)floor($profilSuivant->tier3Capacity))],
                LifeformEffect::POPULATION_PROTECTION => [self::pourcent($profilCourant->protectedShare * 100), self::pourcent($profilSuivant->protectedShare * 100)],
                default => LifeformEffect::isReduction($bonus->code)
                    ? [self::reduction(LifeformFormulas::buildingBonusPercent($bonus, $courant)), self::reduction(LifeformFormulas::buildingBonusPercent($bonus, $courant + 1))]
                    : [self::pourcent(LifeformFormulas::buildingBonusPercent($bonus, $courant)), self::pourcent(LifeformFormulas::buildingBonusPercent($bonus, $courant + 1))],
            };
            $effets[] = [
                'code' => $bonus->code,
                'label' => self::labelOf($bonus),
                'now' => $maintenant,
                'next' => $apres,
            ];
        }

        return $effets;
    }

    /**
     * Le libelle d un effet, cible nommee : un objet du jeu (vaisseau, defense…) ou une classe de personnage.
     *
     * Un seul ecrivain pour les fiches des batiments, des technologies et la page des bonus : trois copies rendaient
     * « Bonus de classe : t_resources.collector.title » (aucune clef pour une classe) et « … : :target — Chasseur lourd »
     * (remplacement jamais fait) — audit des effets, journal §157.
     */
    public static function labelOf(LifeformBonus $bonus): string
    {
        return __('t_lifeforms_ui.effects.' . $bonus->code, ['target' => $bonus->target === null ? '' : self::titleOfTarget($bonus->target)]);
    }

    /**
     * Le nom lisible d une cible : un objet du jeu, ou une classe de personnage.
     */
    public static function titleOfTarget(string $target): string
    {
        try {
            return ObjectService::getObjectByMachineName($target)->title;
        } catch (RuntimeException) {
            $classe = __('t_ingame.characterclass.' . $target . '.name');

            return is_string($classe) && !str_contains($classe, 't_ingame.') ? $classe : $target;
        }
    }

    private static function entier(int $valeur): string
    {
        return AppUtil::formatNumber($valeur);
    }

    private static function parHeure(float $valeur): string
    {
        return AppUtil::formatNumber((int)floor($valeur)) . '/h';
    }

    private static function pourcent(float $valeur): string
    {
        $arrondi = round($valeur, 2);
        $texte = rtrim(rtrim(number_format($arrondi, 2, '.', ''), '0'), '.');

        return ($arrondi > 0 ? '+' : '') . $texte . ' %';
    }

    /**
     * Une reduction s ecrit avec son signe : « −10 % » et non « +10 % » sous un libelle qui dit « Reduction » (audit §157).
     */
    private static function reduction(float $valeur): string
    {
        $arrondi = round($valeur, 2);
        $texte = rtrim(rtrim(number_format($arrondi, 2, '.', ''), '0'), '.');

        return ($arrondi > 0 ? '−' : '') . $texte . ' %';
    }
}
