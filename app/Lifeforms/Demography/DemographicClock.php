<?php

namespace OGame\Lifeforms\Demography;

use InvalidArgumentException;
use RuntimeException;

/**
 * L horloge demographique : avance un etat d un instant a un autre sous des taux constants.
 *
 * ## Par intervalles, jamais seconde par seconde
 *
 * Entre deux evenements les taux sont constants et les courbes connues : la population croit
 * lineairement (jusqu a l espace de vie), le stock de nourriture suit une parabole (la consommation
 * croit avec la population). L horloge calcule l instant du prochain evenement — espace de vie
 * atteint, stock vide, stock plein, fin du surplus — avance exactement jusque la, applique
 * l evenement, et recommence. Un compte absent six mois coute une poignee d etapes.
 *
 * C est aussi ce qui rend l avance **composable** : avancer d une heure ou quatre fois d un quart
 * d heure donne le meme etat, a l arrondi flottant pres — `DemographicClockTest` l exige.
 *
 * ## Les regles appliquees (voir `DemographicRules`)
 *
 * - la croissance n a lieu que si le stock est positif ou la production depasse la consommation ;
 * - le stock se plafonne, et reste plein tant que le surplus dure ;
 * - un stock vide sous deficit ramene aussitot la population a ce que la production nourrit, jamais
 *   sous la population de base.
 *
 * L appelant decoupe lui-meme l intervalle a chaque changement de taux (fin d un travail,
 * revision de vitesse) : l horloge ne connait qu un profil a la fois.
 */
final class DemographicClock
{
    private const int MAX_STEPS = 64;

    private const float EPSILON = 1e-9;

    public function advance(DemographicState $state, PlanetLifeformProfile $profile, int $until): DemographicState
    {
        if ($until < $state->calculatedAt) {
            throw new InvalidArgumentException("L horloge ne recule pas ($until avant $state->calculatedAt).");
        }
        $restant = ($until - $state->calculatedAt) / 3600;
        $p = $state->population;
        $f = min($state->food, $profile->foodStorage);
        $S = (float)$profile->livingSpace;
        $g = $profile->growthPerHour;
        $P = $profile->foodProductionPerHour;
        $c = $profile->foodPerInhabitantPerHour;
        $F = $profile->foodStorage;
        $B = $profile->basePopulation;

        for ($etape = 0; $etape < self::MAX_STEPS; $etape++) {
            // Famine : stock vide et deficit — la population redescend aussitot, la croissance s arrete.
            $net = $P - $c * $p;
            if ($f <= self::EPSILON && $net < -self::EPSILON) {
                $f = 0.0;
                $nourris = $c > 0.0 ? $P / $c : $p;
                $p = max($B, min($p, $nourris));
                $net = $P - $c * $p;
                if ($net < -self::EPSILON) {
                    // A la population de base et toujours en deficit : plus rien ne bouge.
                    break;
                }
            }
            if ($restant <= self::EPSILON) {
                break;
            }

            $croit = $p < $S - self::EPSILON && $g > 0.0 && ($f > self::EPSILON || $net > self::EPSILON);
            $vitesse = $croit ? $g : 0.0;
            $plein = $f >= $F - self::EPSILON;

            // Prochain evenement, en heures a partir de maintenant.
            $t = $restant;
            $evenement = 'fin';
            if ($croit) {
                $tCap = ($S - $p) / $g;
                if ($tCap < $t) {
                    $t = $tCap;
                    $evenement = 'plafond';
                }
            }
            if ($plein && $net >= -self::EPSILON) {
                // Le stock reste plein tant que le surplus dure ; il finit quand la population le rattrape.
                if ($croit && $c > 0.0) {
                    $tFinSurplus = ($P / $c - $p) / $g;
                    if ($tFinSurplus > self::EPSILON && $tFinSurplus < $t) {
                        $t = $tFinSurplus;
                        $evenement = 'fin_du_surplus';
                    }
                }
            } else {
                $a = $net;
                $b = $c * $vitesse / 2;
                $tVide = self::premiereRacine($b, -$a, -$f);
                if ($tVide !== null && $tVide < $t) {
                    $t = $tVide;
                    $evenement = 'vide';
                }
                $tPlein = self::premiereRacine($b, -$a, $F - $f);
                if ($tPlein !== null && $tPlein < $t) {
                    $t = $tPlein;
                    $evenement = 'plein';
                }
            }

            // Avancer de t heures.
            $p = min($S, $p + $vitesse * $t);
            if (!($plein && $net >= -self::EPSILON)) {
                $f = $f + $net * $t - ($c * $vitesse / 2) * $t * $t;
            }
            $f = max(0.0, min($F, $f));
            $restant -= $t;

            if ($evenement === 'plafond') {
                $p = $S;
            } elseif ($evenement === 'vide') {
                $f = 0.0;
            } elseif ($evenement === 'plein') {
                $f = $F;
            }
            if ($evenement === 'fin') {
                $restant = 0.0;
            }
        }

        if ($etape >= self::MAX_STEPS) {
            throw new RuntimeException('L horloge demographique ne converge pas.');
        }

        return new DemographicState(max(0.0, $p), max(0.0, $f), $until);
    }

    /**
     * La plus petite racine strictement positive de b·t² + q·t + r = 0, ou null.
     */
    private static function premiereRacine(float $b, float $q, float $r): float|null
    {
        if (abs($b) <= self::EPSILON) {
            if (abs($q) <= self::EPSILON) {
                return null;
            }
            $t = -$r / $q;

            return $t > self::EPSILON ? $t : null;
        }
        $disc = $q * $q - 4 * $b * $r;
        if ($disc < 0.0) {
            return null;
        }
        $racine = sqrt($disc);
        $candidats = [(-$q - $racine) / (2 * $b), (-$q + $racine) / (2 * $b)];
        sort($candidats);
        foreach ($candidats as $t) {
            if ($t > self::EPSILON) {
                return $t;
            }
        }

        return null;
    }
}
