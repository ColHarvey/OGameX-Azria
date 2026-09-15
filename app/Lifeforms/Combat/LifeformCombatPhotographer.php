<?php

namespace OGame\Lifeforms\Combat;

use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Bonuses\LifeformBonusSet;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
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
 * ## Tout ce qui est photographie est ramene a l instant demande
 *
 * Un travailleur traite une arrivee bien apres l avoir datee, et ce que le joueur change entre les deux ne
 * doit ni armer ni desarmer cette bataille. Les **niveaux** reviennent par la file des travaux
 * (`LifeformLevels::levelsAt()`), l**occupation des emplacements** par son historique
 * (`LifeformSlotHistory`). Le gel qui suit fait le reste : rien n est relu pendant que la bataille dure.
 *
 * Deux choses ne se remontent pas et ne le pretendent pas : la **population**, qui decide qu un emplacement
 * est ouvert et dont l horloge demographique ne recule pas, et l**experience** d une espece, qui bouge par
 * les decouvertes. Journal §155.10.
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
     *
     * `$at` est l instant photographie : le Bouclier planetaire, la lune, les debris et les epaves y sont
     * **ramenes** comme les unites le sont. Un Bouclier acheve entre l ouverture d un ralliement et le
     * passage du travailleur sauvait sinon une population qu il ne couvrait pas encore (revue de Codex).
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
        $niveaux = $at === null
            ? $this->levels->buildingLevelsOf($body->getPlanetId())
            : $this->levels->levelsAt($body->getPlanetId(), LifeformKind::Building, $at);
        $profil = PlanetLifeformProfile::fromLevels(Species::from((int)$etat->species), $niveaux, $this->revisions->live()->demography());
        $planete = $this->resolver->forPlanet($body->getPlanetId(), $at);

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
