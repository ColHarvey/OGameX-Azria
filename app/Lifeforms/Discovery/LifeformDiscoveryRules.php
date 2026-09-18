<?php

namespace OGame\Lifeforms\Discovery;

use Closure;
use InvalidArgumentException;
use OGame\Lifeforms\Species;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;

/**
 * Les regles des vols de decouverte — ce qui est officiel, et ce qui est une regle Azria.
 *
 * ## Officiel (FAQ Origin, annonce, guide)
 *
 * - Cout d un vol : 5 000 metal, 1 000 cristal, 500 deuterium.
 * - Quota : 50 vols par jour, cumulables sans limite de temps.
 * - Reserve d artefacts : 3 600 ; le dernier vol peut la depasser, le suivant ne credite plus.
 * - Une meme position ne se reexplore qu apres sept jours.
 * - Sans vaisseau : un vol se paie et prend du temps, il n expose aucune flotte.
 *
 * ## Regle Azria (rien n est publie)
 *
 * - **Ouverture** : le compte a choisi son espece et la planete de depart porte le centre de
 *   recherche de l espece au niveau 1 (le jeu officiel passe par les Emissaires des Humains, que
 *   seuls les Humains ont ; ici chaque espece ouvre ses decouvertes de la meme facon, et les
 *   Emissaires gardent leur avantage : la duree).
 * - **Duree** : 3 600 s × (1 + distance ÷ 10 000), distance du jeu (meme systeme 1 000 + 5 × Δpos ;
 *   meme galaxie 2 700 + 95 × Δsys ; sinon 20 000 × Δgal), reduite par les Emissaires (1 % par
 *   niveau) et divisee par le coefficient des decouvertes.
 * - **Issues**, tirees au lancement et scellees : rien 30 %, artefacts 45 % (8, ou 25 dans 8 % des
 *   cas, ou 50 dans 2 %), experience 22 % (40 a 80 points pour une espece decouverte tiree au sort,
 *   la sienne comprise), **espece nouvelle 3 %** (tant qu il en reste ; sinon ce poids va a
 *   l experience), avec 100 points de bienvenue.
 * - La chance d artefacts et les trois trouvailles sont un **reglage d administration**
 *   (`LifeformDiscoveryOdds`, journal §159) dont les valeurs de depart sont celles-ci ; un changement
 *   ne prend et ne rend qu a « rien », et ne vaut que pour les vols lances ensuite.
 */
final class LifeformDiscoveryRules
{
    public const int VERSION = 1;

    public const int QUOTA_PER_DAY = 50;

    public const int ARTIFACT_CAP = 3600;

    public const int REEXPLORATION_DELAY = 7 * 86400;

    public const int BASE_DURATION = 3600;

    public const int NEW_SPECIES_EXPERIENCE = 100;

    /**
     * Les deux parts qu aucun reglage ne bouge (voir `LifeformDiscoveryOdds`).
     */
    public const int EXPERIENCE_WEIGHT = 22;

    public const int SPECIES_WEIGHT = 3;

    /**
     * @var array<string, int> poids des issues de depart, en pour cent
     */
    public const array WEIGHTS = [
        LifeformDiscoveryOutcome::NOTHING => 30,
        LifeformDiscoveryOutcome::ARTIFACTS => 45,
        LifeformDiscoveryOutcome::EXPERIENCE => self::EXPERIENCE_WEIGHT,
        LifeformDiscoveryOutcome::SPECIES => self::SPECIES_WEIGHT,
    ];

    public static function cost(): Resources
    {
        return new Resources(5000, 1000, 500, 0);
    }

    /**
     * La distance du jeu entre deux coordonnees.
     */
    public static function distance(Coordinate $from, Coordinate $to): int
    {
        if ($from->galaxy !== $to->galaxy) {
            return 20000 * abs($from->galaxy - $to->galaxy);
        }
        if ($from->system !== $to->system) {
            return 2700 + 95 * abs($from->system - $to->system);
        }

        return 1000 + 5 * abs($from->position - $to->position);
    }

    /**
     * La duree d un vol, en secondes.
     *
     * @param float $reductionFraction la reduction des Emissaires, en fraction (0 a 0,99)
     */
    public static function duration(int $distance, float $reductionFraction, float $multiplier): int
    {
        if ($multiplier <= 0.0 || !is_finite($multiplier)) {
            throw new InvalidArgumentException("Coefficient de decouverte invalide ($multiplier).");
        }
        $secondes = self::BASE_DURATION * (1 + $distance / 10000) / $multiplier;
        $secondes *= 1 - max(0.0, min(0.99, $reductionFraction));

        return max(60, (int)floor($secondes));
    }

    /**
     * Tire une issue. `$draw(int $bound)` rend un entier de 0 a bound − 1 ; injectable pour les essais.
     * Sans cotes, celles de depart.
     *
     * @param array<int, Species> $discovered les especes deja decouvertes (la sienne comprise)
     * @param (Closure(int): int)|null $draw
     */
    public static function draw(array $discovered, Closure|null $draw = null, LifeformDiscoveryOdds|null $odds = null): LifeformDiscoveryOutcome
    {
        $draw ??= static fn (int $bound): int => random_int(0, $bound - 1);
        $odds ??= LifeformDiscoveryOdds::defaults();
        if ($discovered === []) {
            throw new InvalidArgumentException('Un compte sans espece ne decouvre rien.');
        }
        $restantes = array_values(array_filter(Species::cases(), fn (Species $s) => !in_array($s, $discovered, true)));

        $poids = $odds->weights();
        if ($restantes === []) {
            $poids[LifeformDiscoveryOutcome::EXPERIENCE] += $poids[LifeformDiscoveryOutcome::SPECIES];
            $poids[LifeformDiscoveryOutcome::SPECIES] = 0;
        }
        $tirage = $draw(100);
        $cumul = 0;
        $genre = LifeformDiscoveryOutcome::NOTHING;
        foreach ($poids as $candidat => $part) {
            $cumul += $part;
            if ($tirage < $cumul) {
                $genre = $candidat;
                break;
            }
        }

        return match ($genre) {
            LifeformDiscoveryOutcome::ARTIFACTS => new LifeformDiscoveryOutcome($genre, null, $odds->artifactsFound($draw(100)), 0),
            LifeformDiscoveryOutcome::EXPERIENCE => new LifeformDiscoveryOutcome($genre, self::oneOf($discovered, $draw(count($discovered))), 0, 40 + $draw(41)),
            LifeformDiscoveryOutcome::SPECIES => new LifeformDiscoveryOutcome($genre, self::oneOf($restantes, $draw(count($restantes))), 0, self::NEW_SPECIES_EXPERIENCE),
            default => new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::NOTHING, null, 0, 0),
        };
    }

    /**
     * L espece a l indice tire ; une liste vide ou un indice hors liste est une faute, jamais un repli.
     *
     * @param array<int, Species> $especes
     */
    private static function oneOf(array $especes, int $indice): Species
    {
        foreach (array_values($especes) as $i => $espece) {
            if ($i === $indice) {
                return $espece;
            }
        }

        throw new InvalidArgumentException("Indice $indice hors de la liste des especes.");
    }

    /**
     * Les artefacts d une trouvaille selon un tirage de 0 a 99, aux cotes de depart : 8 (90 %), 25 (8 %) ou 50 (2 %).
     */
    public static function artifactsFound(int $roll): int
    {
        return LifeformDiscoveryOdds::defaults()->artifactsFound($roll);
    }
}
