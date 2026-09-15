<?php

namespace OGame\Lifeforms\Combat;

use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Bonuses\LifeformBonusSet;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Photographie ce que les formes de vie apportent a un combat, **au moment ou on le lui demande** :
 * a l admission d une flotte, a l ouverture d un ralliement, a l arrivee d une attaque instantanee.
 *
 * Les niveaux des formes de vie n ont pas d historique ramenable a un instant (contrairement aux
 * recherches, que le registre rembobine par leurs files) : la lecture est celle du compte tel qu il est,
 * et c est **le gel qui suit** qui protege la bataille — rien n est relu pendant qu elle dure.
 */
final class LifeformCombatPhotographer
{
    public function __construct(
        private readonly LifeformBonusResolver $resolver,
        private readonly LifeformLevels $levels,
        private readonly LifeformRuleRevisions $revisions,
    ) {
    }

    /**
     * Ce qu une flotte de ce joueur apporte a ses tirs : ses unites, rien du corps.
     *
     * `$at` est l instant d admission : les niveaux y sont **ramenes par la file des travaux**, pour qu une
     * recherche achevee entre l arrivee et son traitement n arme pas cette flotte (revue de Codex, §155.9).
     */
    public function ofPlayer(PlayerService $player, int|null $at = null): FrozenLifeformCombatBonuses
    {
        return new FrozenLifeformCombatBonuses($this->unitStatsOf($this->resolver->forPlayer($player->getId(), $at)), null, 0.0, 0.0, 0.0);
    }

    /**
     * Ce qu un corps apporte a sa defense : les unites de son proprietaire, et — pour une planete qui
     * porte une forme de vie — la protection de sa population, la lune, les debris et les epaves.
     */
    public function ofBody(PlanetService $body, int|null $at = null): FrozenLifeformCombatBonuses
    {
        $proprietaire = $body->getPlayer();
        $unites = $proprietaire === null ? [] : $this->unitStatsOf($this->resolver->forPlayer($proprietaire->getId(), $at));
        if (!$body->isPlanet()) {
            return new FrozenLifeformCombatBonuses($unites, null, 0.0, 0.0, 0.0);
        }
        $etat = LifeformPlanet::query()->where('planet_id', $body->getPlanetId())->first();
        if ($etat === null) {
            return new FrozenLifeformCombatBonuses($unites, null, 0.0, 0.0, 0.0);
        }
        $niveaux = $this->levels->buildingLevelsOf($body->getPlanetId());
        $profil = PlanetLifeformProfile::fromLevels(Species::from((int)$etat->species), $niveaux, $this->revisions->live()->demography());
        $planete = $this->resolver->forPlanet($body->getPlanetId());

        return new FrozenLifeformCombatBonuses(
            $unites,
            max(0.0, min(1.0, $profil->protectedShare)),
            $planete->fraction(LifeformEffect::MOON_CHANCE),
            $planete->fraction(LifeformEffect::DEBRIS_RECOVERY),
            $planete->fraction(LifeformEffect::WRECK_RECOVERY),
        );
    }

    /**
     * @return array<string, float>
     */
    private function unitStatsOf(LifeformBonusSet $bonus): array
    {
        $unites = [];
        foreach (ObjectService::getShipObjects() as $vaisseau) {
            $pourcent = $bonus->fraction(LifeformEffect::SHIP_STATS, $vaisseau->machine_name) * 100;
            if ($pourcent > 0) {
                $unites[$vaisseau->machine_name] = $pourcent;
            }
        }
        $defenses = $bonus->fraction(LifeformEffect::DEFENCE_STATS) * 100;
        if ($defenses > 0) {
            $unites[FrozenLifeformCombatBonuses::DEFENCE] = $defenses;
        }

        return $unites;
    }
}
