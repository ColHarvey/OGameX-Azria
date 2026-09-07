<?php

namespace OGame\Observers;

use Illuminate\Support\Facades\DB;
use OGame\Events\GalaxySystemChanged;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;

/**
 * Une planete ou une lune apparait, disparait ou est detruite : le systeme l'annonce.
 *
 * ## Pourquoi un observateur
 *
 * Une colonie nait dans `PlanetServiceFactory::createPlanet()`, une lune aussi, une destruction
 * passe par `applyDestroyedFlag()` puis `save()`, une suppression definitive par `delete()`. Tous
 * ces chemins ecrivent le modele. L'observateur les couvre tous, y compris ceux de demain.
 *
 * ## Ce qui est annonce, et ce qui ne l'est pas
 *
 * Seuls les changements que la Galaxie montre a tout le monde : creation, destruction,
 * suppression. Un stock qui bouge, un batiment qui monte, un nom qui change ne sont pas des
 * evenements de systeme — la photographie suivante les portera, et les annoncer transformerait le
 * canal du systeme en journal de chaque planete.
 *
 * Apres la validation, jamais dedans : une colonisation qui echoue ne doit pas faire clignoter
 * une planete qui n'existera pas.
 */
class PlanetObserver
{
    public function created(Planet $planet): void
    {
        $this->announce($planet, 'created');
    }

    public function updated(Planet $planet): void
    {
        /* Seule la destruction est un evenement de systeme parmi les mises a jour. */
        if (!$planet->wasChanged('destroyed')) {
            return;
        }

        $this->announce($planet, 'destroyed');
    }

    public function deleted(Planet $planet): void
    {
        $this->announce($planet, 'deleted');
    }

    private function announce(Planet $planet, string $change): void
    {
        $galaxy = (int)$planet->galaxy;
        $system = (int)$planet->system;
        $position = (int)$planet->planet;
        $kind = (int)$planet->planet_type === PlanetType::Moon->value ? 'moon' : 'planet';

        if ($galaxy === 0 || $system === 0) {
            return;
        }

        DB::afterCommit(static function () use ($galaxy, $system, $position, $kind, $change): void {
            broadcast(new GalaxySystemChanged($galaxy, $system, $position, $kind, $change));
        });
    }
}
