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

        // **Une lune disparue ramene sur sa planete** : memes coordonnees, meme proprietaire.
        //
        // Les coordonnees viennent de la ligne quand elle existe encore, et **des faits que la
        // mission porte** quand elle a deja ete purgee. Dependre de la seule ligne vivante ferait
        // sauter directement a la planete mere des que la suppression definitive est passee — alors
        // que la planete associee, elle, est toujours la.
        $origineEtaitUneLune = $origine instanceof Planet
            ? $this->genreDe($origine) === PlanetType::Moon
            : (int)$mission->type_from === PlanetType::Moon->value;

        if ($origineEtaitUneLune) {
            $coordonnees = $origine instanceof Planet
                ? $this->coordonneesDe($origine)
                : $this->coordonneesDuDepart($mission);

            if ($coordonnees !== null) {
                $associee = Planet::query()
                    ->where('galaxy', $coordonnees->galaxy)
                    ->where('system', $coordonnees->system)
                    ->where('planet', $coordonnees->position)
                    ->where('planet_type', PlanetType::Planet->value)
                    ->where('user_id', $proprietaire)
                    ->first();

                if ($associee instanceof Planet && !$this->estDetruit($associee)) {
                    return ReturnPlan::toAssociatedPlanet((int)$associee->id, $this->coordonneesDe($associee), $proprietaire);
                }
            }
        }

        // La planete mere : la premiere du compte, celle qui reste quand tout le reste a disparu.
        $mere = Planet::query()
            ->where('user_id', $proprietaire)
            ->where('planet_type', PlanetType::Planet->value)
            // Meme regle que le modele : la colonne porte un horodatage, donc « pas detruit »
            // veut dire nul ou zero, jamais « different de un ».
            ->where(function ($requete): void {
                $requete->whereNull('destroyed')->orWhere('destroyed', '<=', 0);
            })
            ->orderBy('id')
            ->first();

        if ($mere instanceof Planet) {
            return ReturnPlan::toHomeworld((int)$mere->id, $this->coordonneesDe($mere), $proprietaire);
        }

        return ReturnPlan::cannotReturn(CombatReasonCode::NoReturnDestination);
    }

    /**
     * Les coordonnees de depart que la mission porte, quand la ligne du corps n'existe plus.
     */
    private function coordonneesDuDepart(FleetMission $mission): Coordinate|null
    {
        if ($mission->galaxy_from === null || $mission->system_from === null || $mission->position_from === null) {
            return null;
        }

        return new Coordinate((int)$mission->galaxy_from, (int)$mission->system_from, (int)$mission->position_from);
    }

    private function coordonneesDe(Planet $corps): Coordinate
    {
        return new Coordinate((int)$corps->galaxy, (int)$corps->system, (int)$corps->planet);
    }

    /**
     * Un corps marque detruit n'accueille plus rien : il attend sa suppression definitive.
     *
     * La regle est celle du modele, et il n'y en a qu'une : la colonne porte l'horodatage de la
     * destruction, pas un drapeau. La comparer a `1` ne reconnaitrait qu'un corps detruit pendant
     * la premiere seconde de 1970 — et laisserait donc une flotte revenir sur une lune rasee.
     */
    private function estDetruit(Planet $corps): bool
    {
        return $corps->isDestroyed();
    }

    private function genreDe(Planet $corps): PlanetType
    {
        return PlanetType::from((int)$corps->planet_type);
    }
}
