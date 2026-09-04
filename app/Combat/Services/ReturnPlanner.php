<?php

namespace OGame\Combat\Services;

use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Support\ReturnPlan;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;

/**
 * Ou une flotte renvoyee se posera, decide sous verrou et une seule fois.
 *
 * ## Les recours, dans l'ordre que le jeu fixe
 *
 * 1. **le corps d'origine**, s'il existe encore et appartient toujours au proprietaire de la flotte ;
 * 2. **la planete associee**, quand l'origine etait une lune : elle occupe les memes coordonnees, et
 *    c'est la que le jeu ramene deja les flottes d'une lune detruite ;
 * 3. **la planete mere**, c'est-a-dire la premiere planete du compte : le dernier corps qui reste
 *    quand le point de depart a disparu ;
 * 4. **aucune destination**, et alors la flotte n'est pas rendue : c'est un cas de recuperation
 *    d'actifs, pas une disparition.
 *
 * `ReturnPlan` decrit ces quatre issues ; ce service est ce qui les lit dans la base. Il ne cree
 * rien et ne verrouille rien : l'appelant tient deja les verrous, et decide ce qu'il fait d'un plan
 * impossible — typiquement s'arreter avant de rendre un combat final.
 *
 * ## Pourquoi le proprietaire compte autant que les coordonnees
 *
 * Un corps peut avoir change de mains : une planete abandonnee puis recolonisee occupe les memes
 * coordonnees sans etre le meme foyer. Une flotte de repli ne se pose jamais chez quelqu'un
 * d'autre, meme si ce corps est exactement la ou elle allait.
 */
final class ReturnPlanner
{
    /**
     * Le plan de retour de cette mission, lu maintenant.
     */
    public function planFor(FleetMission $mission): ReturnPlan
    {
        $proprietaire = (int)$mission->user_id;

        $origine = $mission->planet_id_from === null
            ? null
            : Planet::query()->whereKey($mission->planet_id_from)->first();

        if ($origine instanceof Planet && (int)$origine->user_id === $proprietaire && !$this->estDetruit($origine)) {
            return ReturnPlan::toOriginalBody(
                (int)$origine->id,
                $this->coordonneesDe($origine),
                $this->genreDe($origine),
                $proprietaire
            );
        }

        // Une lune disparue ramene sur sa planete : memes coordonnees, meme proprietaire.
        if ($origine instanceof Planet && $this->genreDe($origine) === PlanetType::Moon) {
            $associee = Planet::query()
                ->where('galaxy', $origine->galaxy)
                ->where('system', $origine->system)
                ->where('planet', $origine->planet)
                ->where('planet_type', PlanetType::Planet->value)
                ->where('user_id', $proprietaire)
                ->first();

            if ($associee instanceof Planet && !$this->estDetruit($associee)) {
                return ReturnPlan::toAssociatedPlanet((int)$associee->id, $this->coordonneesDe($associee), $proprietaire);
            }
        }

        // La planete mere : la premiere du compte, celle qui reste quand tout le reste a disparu.
        $mere = Planet::query()
            ->where('user_id', $proprietaire)
            ->where('planet_type', PlanetType::Planet->value)
            ->where(function ($requete): void {
                $requete->whereNull('destroyed')->orWhere('destroyed', 0);
            })
            ->orderBy('id')
            ->first();

        if ($mere instanceof Planet) {
            return ReturnPlan::toHomeworld((int)$mere->id, $this->coordonneesDe($mere), $proprietaire);
        }

        return ReturnPlan::cannotReturn(CombatReasonCode::NoReturnDestination);
    }

    private function coordonneesDe(Planet $corps): Coordinate
    {
        return new Coordinate((int)$corps->galaxy, (int)$corps->system, (int)$corps->planet);
    }

    /**
     * Un corps marque detruit n'accueille plus rien : il attend sa suppression definitive.
     */
    private function estDetruit(Planet $corps): bool
    {
        return (int)($corps->destroyed ?? 0) === 1;
    }

    private function genreDe(Planet $corps): PlanetType
    {
        return PlanetType::from((int)$corps->planet_type);
    }
}
