<?php

namespace OGame\Combat\Services;

use OGame\Combat\Models\CombatDurationEstimate;
use OGame\Combat\Models\CombatRoundWork;
use OGame\GameMissions\BattleEngine\Models\BattleResult;

/**
 * Combien de temps une bataille deja simulee doit-elle durer ?
 *
 * Service **pur** : il recoit un `BattleResult`, rend une duree, et ne touche ni la base, ni
 * les reglages, ni l'heure. Le rythme et le minimum lui sont passes par l'appelant, qui seul
 * sait ou les lire. Deux consequences utiles : il se teste sans base de donnees, et la meme
 * entree rend toujours la meme duree — quel que soit le moteur, PHP ou Rust, qui a produit le
 * resultat.
 *
 * La regle, par round :
 *
 *     echanges bilateraux    = min(tirs attaquant, tirs defenseur)
 *     resistance opposee     = min(force attaquante, force defensive)
 *     equilibre              = racine(force faible / force forte)
 *     pression des boucliers = degats absorbes des deux cotes
 *
 *     travail = echanges x resistance x equilibre x pression
 *
 * Aucune tranche, aucun palier : une petite flotte ecrasee produit peu d'echanges equilibres
 * et un combat court ; deux forces comparables se resistent round apres round et le combat
 * s'allonge.
 *
 * **Le coefficient de rythme n'est pas derive du moteur.** Il convertit une intensite en
 * secondes, et ce nombre-la se choisit : la forme vient du combat, l'echelle est un choix de
 * jeu. C'est ce que le tableau de calibrage a servi a trancher.
 *
 * **Modele du prototype** : racine cubique, rythme 2083, plancher 5 s, aucun plafond.
 *
 * Un combat doit enregistrer, a son demarrage, la duree calculee **ainsi que le rythme et
 * l'amortissement qui l'ont produite**. Changer le reglage plus tard ne doit toucher que les
 * combats suivants : un combat deja engage garde le modele sous lequel il a commence.
 *
 * Aucune duree maximale n'est imposee, par decision explicite.
 */
class CombatDurationEstimator
{
    /**
     * Rythme du prototype : travail amorti consomme par seconde.
     *
     * Choisi sur le tableau de calibrage, pas au jugé. Il place un combat courant autour de
     * deux a cinq minutes, une grande bataille autour de vingt minutes, et deux armadas
     * equilibrees autour de deux heures.
     *
     * **C'est une cible de reglage, pas un invariant du combat.** Aucune regle ne depend de cette
     * valeur : la duree vient des rounds reels, et il n'existe aucun plafond. L'ecrire dans un
     * contrat — de butin, de gel ou de fenetre — en ferait une regle enfouie que personne
     * n'aurait decidee.
     *
     * Les deux voisins examines et ecartes : 1500 rendait un combat courant trop long, 3000
     * rendait les tres grandes batailles trop courtes pour etre impressionnantes.
     *
     * Ce nombre n'a de sens qu'avec l'amortissement ci-dessous : les deux forment un modele.
     */
    public const float DEFAULT_RATE = 2083.0;

    /**
     * Duree minimale par defaut, en secondes.
     */
    public const int DEFAULT_MINIMUM_SECONDS = 5;

    /**
     * Seuil d'alerte technique, en secondes : sept jours.
     *
     * Aucune duree n'est jamais rabotee — c'est une regle de jeu explicite. Mais une
     * bataille qui calcule plus d'une semaine signale un coefficient de rythme mal
     * calibre, et il vaut mieux l'apprendre par une alerte que par un joueur enferme.
     */
    public const int IMPLAUSIBLE_SECONDS = 604800;

    /**
     * Amortissement par defaut : aucun.
     *
     * La regle telle qu'elle a ete arretee multiplie quatre grandeurs qui croissent
     * chacune avec la taille des flottes. Leurs exposants s'additionnent, et le travail
     * s'etale sur **onze ordres de grandeur** entre un ecrasement et une bataille
     * d'armadas — mesure sur les scenarios de calibrage. Aucun coefficient de rythme
     * unique ne peut alors donner des durees sensees aux deux : les rythmes requis
     * s'etalent eux-memes sur huit ordres de grandeur.
     *
     * Prendre une racine du travail comprime cet ecart sans toucher a ce qui le produit :
     * l'ordre des scenarios est conserve, seule leur distance change. Une racine cubique
     * (amortissement 3) donne, sur les memes scenarios, la progression que le plan decrit :
     * quelques secondes, quelques minutes, quelques dizaines de minutes, quelques heures.
     *
     * La racine cubique a ete retenue pour le prototype. Elle traduit aussi quelque chose de
     * vrai : une immense flotte agit massivement en parallele. Son travail explose, son temps
     * reel non.
     *
     * Elle n'a pas ete choisie parce qu'elle rendait bien sur quatre exemples : les huit
     * proprietes de `CombatDurationPropertiesTest` tiennent avec elle comme sans elle.
     */
    public const float DEFAULT_DAMPING = 3.0;

    /**
     * Estimate how long a simulated battle should take.
     *
     * @param BattleResult $result Bataille deja simulee, non appliquee.
     * @param float $rate Travail consomme par seconde.
     * @param int $minimumSeconds Duree plancher d'une bataille qui a eu lieu.
     * @param float $damping Racine appliquee au travail avant conversion. 1 = aucune.
     * @return CombatDurationEstimate
     */
    public function estimate(
        BattleResult $result,
        float $rate = self::DEFAULT_RATE,
        int $minimumSeconds = self::DEFAULT_MINIMUM_SECONDS,
        float $damping = self::DEFAULT_DAMPING,
    ): CombatDurationEstimate {
        // Une bataille sans round n'a rien a faire durer : c'est une retraite tactique, ou un
        // camp qui n'avait rien a opposer. Elle se resout immediatement, et le minimum ne
        // s'applique pas — il est fait pour les batailles qui ont eu lieu.
        if ($result->rounds === []) {
            return new CombatDurationEstimate(0, 0.0, false, 0.0, $rate, $minimumSeconds, false, true, []);
        }

        $travaux = [];
        $total = 0.0;

        foreach ($result->rounds as $index => $round) {
            $forteAttaque = (float)$round->fullStrengthAttacker;
            $forteDefense = (float)$round->fullStrengthDefender;

            $faible = min($forteAttaque, $forteDefense);
            $forte = max($forteAttaque, $forteDefense);

            // Un camp sans force n'oppose aucune resistance : le round ne coute rien, et c'est
            // ce qui rend un ecrasement instantane.
            $equilibre = $forte > 0.0 ? sqrt($faible / $forte) : 0.0;

            $echanges = (float)min($round->hitsAttacker, $round->hitsDefender);
            $pression = (float)($round->absorbedDamageAttacker + $round->absorbedDamageDefender);

            $travail = $echanges * $faible * $equilibre * $pression;
            $total += $travail;

            $travaux[] = [
                'numero' => $index + 1,
                'tirsAttaquant' => $round->hitsAttacker,
                'tirsDefenseur' => $round->hitsDefender,
                'echanges' => $echanges,
                'resistance' => $faible,
                'equilibre' => $equilibre,
                'pression' => $pression,
                'travail' => $travail,
            ];
        }

        // L'amortissement s'applique au travail, avant la conversion en secondes : il change
        // la distance entre deux batailles, jamais leur ordre.
        $amorti = $damping > 1.0 && $total > 0.0 ? $total ** (1.0 / $damping) : $total;

        $brute = $rate > 0.0 ? $amorti / $rate : 0.0;
        $minimumApplique = $brute < $minimumSeconds;

        // La duree brute reste un float : le produit de quatre grandeurs qui croissent
        // toutes avec la taille des flottes depasse vite ce qu'un entier contient. On ne la
        // rabote pas, on la conserve telle quelle — et la valeur exploitable, qui doit servir
        // d'horodatage, est bornee par la seule limite du langage.
        $exploitable = max((float)$minimumSeconds, $brute);
        $secondes = $exploitable >= (float)PHP_INT_MAX ? PHP_INT_MAX : (int)round($exploitable);

        return new CombatDurationEstimate(
            $secondes,
            $exploitable,
            $exploitable > (float)self::IMPLAUSIBLE_SECONDS,
            $total,
            $rate,
            $minimumSeconds,
            $minimumApplique,
            false,
            $this->repartir($travaux, $secondes, $total),
        );
    }

    /**
     * Spread the total duration across the rounds, in proportion to their work.
     *
     * La duree d'un round depend de son intensite reelle, pas de son numero : un round ou les
     * deux camps se sont vraiment resistes dure plus longtemps que celui qui a acheve un
     * adversaire deja brise.
     *
     * Quand aucun round n'a produit de travail — deux camps qui se manquent — la duree est
     * repartie egalement : il faut bien que le calendrier couvre le combat.
     *
     * @param array<int, array<string, float|int>> $travaux
     * @param int $secondes
     * @param float $total
     * @return array<int, CombatRoundWork>
     */
    private function repartir(array $travaux, int $secondes, float $total): array
    {
        $rounds = [];
        $cumul = 0;
        $dernier = count($travaux) - 1;

        foreach ($travaux as $i => $t) {
            if ($i === $dernier) {
                // Le dernier round absorbe l'arrondi : la somme des parts fait exactement la
                // duree annoncee, sans quoi le compte a rebours et le calendrier divergeraient.
                $part = $secondes - $cumul;
            } elseif ($total > 0.0) {
                $part = (int)floor($secondes * ((float)$t['travail'] / $total));
            } else {
                $part = (int)floor($secondes / count($travaux));
            }

            $cumul += $part;

            $rounds[] = new CombatRoundWork(
                (int)$t['numero'],
                (int)$t['tirsAttaquant'],
                (int)$t['tirsDefenseur'],
                (float)$t['echanges'],
                (float)$t['resistance'],
                (float)$t['equilibre'],
                (float)$t['pression'],
                (float)$t['travail'],
                $part,
            );
        }

        return $rounds;
    }
}
