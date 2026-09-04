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
 *
 * ## Pourquoi cette classe n'est pas finale
 *
 * La course « la destination bouge entre le choix et le verrou » ne se joue pas sous SQLite : deux
 * transactions concurrentes y sont impossibles. Un double qui rend un plan different au second
 * appel la reproduit exactement, et c'est le seul moyen d'eprouver le refus depuis ce poste.
 */
class ReturnPlanner
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
     * Les corps dont l'etat decide du recours retenu, pour cette mission.
     *
     * ## Pourquoi le gagnant ne suffit pas
     *
     * Le choix suit un ordre : corps d'origine, planete associee, premiere planete du compte. Tenir
     * la seule ligne finalement choisie rend cette ligne stable, mais pas **la raison pour laquelle
     * elle a ete choisie** : une origine absente qui reapparait, une planete associee qui change de
     * mains, une planete plus ancienne qui redevient eligible — chacune deplacerait le verdict sans
     * qu'aucune ligne tenue n'ait bouge.
     *
     * Cette liste est donc ce qu'il faut verrouiller **avant** de decider : le corps de depart, et
     * toutes les planetes du proprietaire, parmi lesquelles se trouvent la planete associee et la
     * planete mere.
     *
     * **Ce qu'elle ne couvre pas** : l'apparition d'une ligne qui n'existait pas au moment de la
     * lecture. Verrouiller une ligne existante n'empeche pas un fantome ; il y faut un verrou de
     * portee, et c'est une epreuve MariaDB.
     *
     * @return array<int, int> Identifiants, tries, sans doublon.
     */
    public function bodiesThatDecideFor(FleetMission $mission): array
    {
        $identifiants = [];

        if ($mission->planet_id_from !== null) {
            $identifiants[(int)$mission->planet_id_from] = true;
        }

        $planetes = Planet::query()
            ->where('user_id', (int)$mission->user_id)
            ->pluck('id')
            ->all();

        foreach ($planetes as $identifiant) {
            $identifiants[(int)$identifiant] = true;
        }

        // **La planete associee, quel que soit son proprietaire actuel.** Les planetes du
        // proprietaire ne la contiennent que si elle lui appartient deja. Or la propriete est
        // precisement un fait qui decide : apres la destruction d'une lune, la planete aux memes
        // coordonnees peut etre a quelqu'un d'autre, et redevenir eligible si elle est transferee
        // entre les deux passes. Ne pas la tenir, c'est laisser ce transfert changer le verdict
        // sans qu'aucune ligne tenue n'ait bouge. Le plan, lui, continue d'exiger le bon
        // proprietaire.
        $associee = $this->planetAtTheHistoricalCoordinatesOf($mission);

        if ($associee !== null) {
            $identifiants[$associee] = true;
        }

        $liste = array_keys($identifiants);
        sort($liste);

        return $liste;
    }

    /**
     * La ligne de type planete aux coordonnees de depart, si la flotte est partie d'une lune.
     *
     * La lune peut etre encore la, ou deja purgee : dans les deux cas les coordonnees existent —
     * sur la ligne, ou sur les faits que la mission porte.
     */
    private function planetAtTheHistoricalCoordinatesOf(FleetMission $mission): int|null
    {
        $origine = $mission->planet_id_from === null
            ? null
            : Planet::query()->whereKey($mission->planet_id_from)->first();

        $origineEtaitUneLune = $origine instanceof Planet
            ? $this->genreDe($origine) === PlanetType::Moon
            : (int)$mission->type_from === PlanetType::Moon->value;

        if (!$origineEtaitUneLune) {
            return null;
        }

        $coordonnees = $origine instanceof Planet
            ? $this->coordonneesDe($origine)
            : $this->coordonneesDuDepart($mission);

        if ($coordonnees === null) {
            return null;
        }

        $identifiant = Planet::query()
            ->where('galaxy', $coordonnees->galaxy)
            ->where('system', $coordonnees->system)
            ->where('planet', $coordonnees->position)
            ->where('planet_type', PlanetType::Planet->value)
            ->value('id');

        return $identifiant === null ? null : (int)$identifiant;
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
