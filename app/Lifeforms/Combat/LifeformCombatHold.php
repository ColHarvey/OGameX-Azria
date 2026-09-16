<?php

namespace OGame\Lifeforms\Combat;

use OGame\Combat\Enums\CombatState;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;

/**
 * L instant auquel une bataille non reglee **tient** l horloge des formes de vie d un corps.
 *
 * ## Une seule lecture, pour l horloge et pour le joueur
 *
 * `LifeformPlanetUpdater` ne depasse jamais cet instant : la bataille est une coupe que le passage
 * demographique ne franchit pas, sans quoi un travail demarrerait sur une population qu elle allait tuer,
 * et deux joueurs obtiendraient des possibilites differentes selon la vitesse du serveur (journal §155.15).
 * Les pages de formes de vie lisent la meme reponse pour dire au joueur que son developpement est suspendu
 * en attendant la resolution — plutot qu une page qui semble defectueuse (relance de Codex, §155.16).
 *
 * ## Deux frontieres, complementaires
 *
 * - **Bataille datee et non reglee** (`ends_at`, etats `Active` puis `Resolving`) : l horloge s arrete a
 *   l echeance de la bataille. Ces etats verrouillent deja le corps (`CombatState::locksTargetBody()`).
 * - **Ralliement echu et non cloture** (`Rallying`, barriere dont `owned_through_effect_at` est passe) : la
 *   bataille est a venir sans instant connu — la cloture la datera de cette echeance, que la barriere porte
 *   depuis l ouverture et ne deplace jamais. L horloge s arrete donc **la**, en attendant l avanceur, puis
 *   avancera jusqu a l echeance de la bataille. Sans cette frontiere, un proprietaire qui charge une page
 *   entre l echeance du ralliement et sa cloture faisait franchir la bataille a sa planete.
 *
 * Un combat mis de cote apres cinq echecs tient donc la planete jusqu a ce que l exploitation le reprenne ou
 * l annule — coherent avec la barriere, et affiche au joueur.
 *
 * `min()` est ecrit par prudence : la barriere du corps (`target_body_id` unique) interdit deux combats
 * ouverts a la fois sur un meme corps, donc il n y a jamais qu une echeance a retenir. La mutation
 * `min` → `max` est **equivalente par construction de la base**, et declaree telle.
 */
final class LifeformCombatHold
{
    /**
     * L instant qui tient le corps, ou null si rien ne le tient a cet instant.
     */
    public function until(int $planetId, int $now): int|null
    {
        $bataille = CombatInstance::query()
            ->where('target_planet_id', $planetId)
            ->whereIn('status', [CombatState::Active->value, CombatState::Resolving->value])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->min('ends_at');

        $ralliement = CelestialBodyCombatBarrier::query()
            ->where('target_body_id', $planetId)
            ->where('owned_through_effect_at', '<=', $now)
            ->whereIn('combat_instance_id', CombatInstance::query()
                ->where('status', CombatState::Rallying->value)
                ->select('id'))
            ->min('owned_through_effect_at');

        $retenues = array_map(
            static fn (mixed $v): int => (int)$v,
            array_filter([$bataille, $ralliement], static fn (mixed $v): bool => is_numeric($v))
        );

        return $retenues === [] ? null : min($retenues);
    }
}
