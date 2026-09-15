<?php

namespace OGame\Lifeforms\Presentation;

use OGame\Lifeforms\Combat\LifeformCombatPhotographer;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Services\PlanetService;

/**
 * Ce qu une sonde rapporte des formes de vie d un corps (journal §155.7).
 *
 * **Des faits, pas une phrase** : le nom machine de l espece, la population, la part que le Bouclier
 * planetaire protege. La phrase est traduite a la lecture, dans la langue du lecteur — figer le texte a
 * l envoi le figerait dans celle du serveur.
 *
 * **Ce que l attaquant y gagne** : de quoi prevoir les pertes civiles d une attaque reussie, exactement
 * ce que la tranche 6 a mis en jeu. Une section absente veut dire « la sonde n en a pas vu assez » ; une
 * section presente sans espece veut dire « il n y a rien ici ». Les deux ne se confondent pas.
 *
 * L interrupteur n est pas consulte : il bloque les ordres nouveaux, pas la lecture de ce qui existe. Une
 * population qui vit et qui meurt au combat se voit depuis l orbite, meme le robinet ferme (journal §155.9).
 */
final class LifeformEspionage
{
    public function __construct(private readonly LifeformCombatPhotographer $photographer)
    {
    }

    /**
     * Les faits du corps, toujours presents : c est la **sonde** qui decide de les rapporter ou non, par son
     * seuil, et l absence de section veut alors dire « pas assez vu ». Un corps sans forme de vie rend une
     * section sans espece — « il n y a rien ici », qui n est pas la meme chose.
     *
     * @return array{species: string, population: int, protected_percent: int}
     */
    public function factsOf(PlanetService $planet): array
    {
        $etat = $planet->isPlanet() ? LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->first() : null;
        if ($etat === null) {
            return ['species' => '', 'population' => 0, 'protected_percent' => 0];
        }
        $part = $this->photographer->ofBody($planet)->protectedShare ?? 0.0;

        return [
            'species' => Species::from((int)$etat->species)->machineName(),
            'population' => (int)floor((float)$etat->population),
            'protected_percent' => (int)round($part * 100),
        ];
    }

    /**
     * Les faits traduits pour l affichage, ou null quand la sonde n en a pas vu assez.
     *
     * @param mixed $stored la colonne du rapport
     * @return array{species: string, population: int, protected_percent: int, has_species: bool}|null
     */
    public static function presented(mixed $stored): array|null
    {
        if (!is_array($stored)) {
            return null;
        }
        $nom = $stored['species'] ?? '';
        $espece = is_string($nom) && $nom !== '' ? __('t_lifeforms.species.' . $nom) : '';

        return [
            'species' => is_string($espece) ? $espece : '',
            'population' => (int)($stored['population'] ?? 0),
            'protected_percent' => (int)($stored['protected_percent'] ?? 0),
            'has_species' => is_string($nom) && $nom !== '',
        ];
    }
}
