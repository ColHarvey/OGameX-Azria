<?php

namespace OGame\Lifeforms\Presentation;

use OGame\Facades\AppUtil;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Species;

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
                default => [self::pourcent(LifeformFormulas::buildingBonusPercent($bonus, $courant)), self::pourcent(LifeformFormulas::buildingBonusPercent($bonus, $courant + 1))],
            };
            $effets[] = [
                'code' => $bonus->code,
                'label' => __('t_lifeforms_ui.effects.' . $bonus->code, ['target' => $bonus->target === null ? '' : __('t_resources.' . $bonus->target . '.title')]),
                'now' => $maintenant,
                'next' => $apres,
            ];
        }

        return $effets;
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
}
