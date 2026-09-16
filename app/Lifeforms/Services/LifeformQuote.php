<?php

namespace OGame\Lifeforms\Services;

use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Rules\LifeformSpeeds;
use OGame\Models\Resources;

/**
 * Le devis d un niveau : prix, energie et duree, avec les reductions que les batiments **de la planete**
 * accordent (Megalithe pour les batiments, centre de recherche pour les technologies).
 *
 * Les reductions se lisent sur les batiments que la planete porte, jamais sur ceux de l espece de l objet :
 * une technologie d une autre espece, prise contre des artefacts, est reduite par le centre de recherche
 * de la planete qui la recherche — il n existe pas d autre centre sur cette planete (relance de Codex,
 * journal §155.18).
 *
 * Un devis est **calcule au serveur, au moment du depart du travail**, et fige dans la file. Il ne
 * lit que le catalogue, les niveaux de la planete, ses accelerateurs classiques et les vitesses en
 * vigueur.
 */
final readonly class LifeformQuote
{
    private function __construct(
        public Resources $price,
        public int $energy,
        public int $duration,
        public float $costReduction,
        public float $timeReduction,
    ) {
    }

    /**
     * @param array<int, int> $buildingLevels niveaux des batiments de formes de vie de la planete
     */
    public static function for(LifeformObject $object, int $targetLevel, array $buildingLevels, int $robotics, int $nanites, LifeformSpeeds $speeds): self
    {
        [$cout, $temps] = self::reductionsFor($object, $buildingLevels);
        $prix = LifeformFormulas::cost($object, $targetLevel, $cout);
        if ($object->kind === LifeformKind::Building) {
            $duree = LifeformFormulas::buildingDuration($object, $targetLevel, $robotics, $nanites, $speeds->building(), $temps);
        } else {
            $duree = LifeformFormulas::technologyDuration($object, $targetLevel, $speeds->technology(), $temps);
        }

        return new self($prix, LifeformFormulas::energy($object, $targetLevel), $duree, $cout, $temps);
    }

    /**
     * Les fractions de reduction (cout, duree) que la planete accorde a cet objet — lues sur les batiments
     * qu elle porte, quelle que soit l espece de l objet.
     *
     * @param array<int, int> $buildingLevels
     * @return array{0: float, 1: float}
     */
    private static function reductionsFor(LifeformObject $object, array $buildingLevels): array
    {
        [$codeCout, $codeTemps] = $object->kind === LifeformKind::Building
            ? [LifeformEffect::LF_BUILDING_COST_REDUCTION, LifeformEffect::LF_BUILDING_TIME_REDUCTION]
            : [LifeformEffect::LF_RESEARCH_COST_REDUCTION, LifeformEffect::LF_RESEARCH_TIME_REDUCTION];
        $cout = 0.0;
        $temps = 0.0;
        foreach ($buildingLevels as $id => $niveau) {
            if ($niveau <= 0 || !LifeformCatalogue::has((int)$id)) {
                continue;
            }
            $batiment = LifeformCatalogue::byId((int)$id);
            if ($batiment->kind !== LifeformKind::Building) {
                continue;
            }
            $bonusCout = $batiment->bonus($codeCout);
            if ($bonusCout !== null) {
                $cout += LifeformFormulas::buildingBonusPercent($bonusCout, $niveau) / 100;
            }
            $bonusTemps = $batiment->bonus($codeTemps);
            if ($bonusTemps !== null) {
                $temps += LifeformFormulas::buildingBonusPercent($bonusTemps, $niveau) / 100;
            }
        }

        return [min(0.99, $cout), min(0.99, $temps)];
    }
}
