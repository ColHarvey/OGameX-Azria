<?php

namespace OGame\Lifeforms\Catalogue;

use InvalidArgumentException;
use OGame\Lifeforms\Catalogue\Data\Humans;
use OGame\Lifeforms\Catalogue\Data\Kaelesh;
use OGame\Lifeforms\Catalogue\Data\Mechas;
use OGame\Lifeforms\Catalogue\Data\Rocktal;
use OGame\Lifeforms\Species;

/**
 * Le catalogue des formes de vie : 48 batiments et 72 technologies, une version.
 *
 * ## Provenance
 *
 * Version 1 = le fichier maitre Gameforge `LFMaster_Global.xlsx` (feuille « PTS LF values
 * (current) »), qui concorde avec le calculateur communautaire vivant sur les 120 fiches, a la base
 * de duree du Repaire orbital pres (4 500 retenu, 4 140 observe). Les prerequis viennent de la
 * bibliotheque ouverte `alaingilbert/ogame`. Les codes d effet ont ete poses a la lecture des
 * descriptions officielles. Sources conservees dans `Desktop/formes-de-vie-sources/`.
 *
 * ## Une version, jamais des valeurs eparpillees
 *
 * Toute regle chiffree des formes de vie vit ici ou dans `LifeformFormulas` et
 * `DemographicRules`, jamais dans une vue ni dans un controleur. Changer une valeur, c est changer
 * la version : les files en cours gardent celle de leur lancement.
 *
 * ## Un catalogue a part
 *
 * Les identifiants officiels (11101 a 14218) ne recoupent aucun identifiant d `ObjectService`
 * (au plus 503). Les deux catalogues ne se melangent pas, et `LifeformCatalogueTest` le verifie.
 */
final class LifeformCatalogue
{
    public const int VERSION = 1;

    /**
     * @var array<int, LifeformObject>|null
     */
    private static array|null $parId = null;

    /**
     * @var array<string, LifeformObject>|null
     */
    private static array|null $parNomMachine = null;

    /**
     * Toutes les fiches, par identifiant croissant.
     *
     * @return array<int, LifeformObject>
     */
    public static function all(): array
    {
        if (self::$parId === null) {
            $objets = [...Humans::objects(), ...Rocktal::objects(), ...Mechas::objects(), ...Kaelesh::objects()];
            $parId = [];
            $parNom = [];
            foreach ($objets as $objet) {
                if (isset($parId[$objet->id])) {
                    throw new InvalidArgumentException("Identifiant en double dans le catalogue : $objet->id.");
                }
                if (isset($parNom[$objet->machineName])) {
                    throw new InvalidArgumentException("Nom machine en double dans le catalogue : $objet->machineName.");
                }
                $parId[$objet->id] = $objet;
                $parNom[$objet->machineName] = $objet;
            }
            ksort($parId);
            self::$parId = $parId;
            self::$parNomMachine = $parNom;
        }

        return self::$parId;
    }

    public static function byId(int $id): LifeformObject
    {
        $objet = self::all()[$id] ?? null;
        if ($objet === null) {
            throw new InvalidArgumentException("Aucune fiche de forme de vie ne porte l identifiant $id.");
        }

        return $objet;
    }

    public static function has(int $id): bool
    {
        return isset(self::all()[$id]);
    }

    public static function byMachineName(string $machineName): LifeformObject
    {
        self::all();
        $objet = self::$parNomMachine[$machineName] ?? null;
        if ($objet === null) {
            throw new InvalidArgumentException("Aucune fiche de forme de vie ne porte le nom machine $machineName.");
        }

        return $objet;
    }

    /**
     * Les douze batiments d une espece, par index croissant.
     *
     * @return array<int, LifeformObject>
     */
    public static function buildingsOf(Species $species): array
    {
        return self::of($species, LifeformKind::Building);
    }

    /**
     * Les dix-huit technologies d une espece, par index croissant.
     *
     * @return array<int, LifeformObject>
     */
    public static function technologiesOf(Species $species): array
    {
        return self::of($species, LifeformKind::Technology);
    }

    /**
     * Le batiment d une espece qui porte un effet (le logement, la ferme, le centre de recherche...).
     */
    public static function buildingWithEffect(Species $species, string $code): LifeformObject|null
    {
        foreach (self::buildingsOf($species) as $batiment) {
            if ($batiment->bonus($code) !== null) {
                return $batiment;
            }
        }

        return null;
    }

    /**
     * @return array<int, LifeformObject>
     */
    private static function of(Species $species, LifeformKind $kind): array
    {
        $resultat = [];
        foreach (self::all() as $objet) {
            if ($objet->species === $species && $objet->kind === $kind) {
                $resultat[] = $objet;
            }
        }
        usort($resultat, fn (LifeformObject $a, LifeformObject $b) => $a->index <=> $b->index);

        return $resultat;
    }
}
