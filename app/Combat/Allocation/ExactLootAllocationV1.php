<?php

namespace OGame\Combat\Allocation;

use InvalidArgumentException;
use OGame\Combat\Support\ExactDivision;
use OGame\Combat\Support\ExactRatio;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Models\Resources;

/**
 * La premiere regle de pillage persistable, entierement en arithmetique entiere.
 *
 * ## Pourquoi ce nom, et pourquoi pas l'ancien
 *
 * Le chemin precedent melait des flottants : le plafonnement par le fret repartissait la place
 * restante par `$reste / $nombreDeRessources`, ce qui produisait des montants comme
 * `17 794,666...`. Le moteur les transtypait ensuite en entiers, et **deux unites disparaissaient**
 * sans que rien ne le signale. La mesure l'a etabli : sur vingt mille repartitions, quarante pour
 * cent perdaient une ou deux unites de cette facon.
 *
 * Cette version-ci ne perd rien. Elle porte donc un nom different — jamais le meme identifiant avec
 * une formule changee : une version n'a de valeur que si sa formule ne bouge plus une fois utilisee.
 *
 * ## Ce que la version englobe
 *
 * 1. la conversion du stock photographie en unites entieres, au taux du combat ;
 * 2. le plafonnement par le fret total, et son partage entre metal, cristal et deuterium ;
 * 3. l'attribution entre les flottes, par plus forts restes ;
 * 4. tous les departages : initiateur d'abord, puis plus grand reste, puis plus grande capacite,
 *    puis identifiant de mission.
 *
 * ## Les regles d'arrondi, une par une
 *
 * - **Le stock est arrondi vers le bas avant le taux** : on ne prend pas une fraction d'unite qui
 *   n'existe pas encore. La borne reservee, elle, arrondit vers le haut, ce qui garantit qu'elle
 *   couvre toujours ce calcul.
 * - **Le plafond par ressource est le quotient entier du fret par trois.** Le reste de cette
 *   division est distribue ensuite, une unite a la fois.
 * - **La place restante va aux ressources non saturees dans l'ordre metal, cristal, deuterium.**
 *   L'ancien code la partageait a parts fractionnaires egales ; a parts entieres, il faut un ordre,
 *   et celui-ci est celui dans lequel le jeu enumere ses ressources partout ailleurs.
 */
final class ExactLootAllocationV1 implements LootAllocator
{
    /**
     * L'identifiant persiste avec chaque combat.
     */
    public const string VERSION = 'exact_loot_pipeline_v1';

    /**
     * Cent pour cent, en centiemes de pour-cent.
     */
    public const int FULL_RATE = 10_000;

    /**
     * Les trois ressources, dans l'ordre qui sert aux departages.
     */
    /**
     * Le moment fonctionnel par defaut : le pillage de la cible.
     *
     * Chaque site passe le sien. Deux incidents venus d etapes differentes doivent porter deux
     * identites differentes, sans quoi ils seraient fondus en une seule occurrence.
     */
    public const string PHASE_TARGET_LOOT = 'target_loot';

    private const array RESOURCES = ['metal', 'crystal', 'deuterium'];

    /**
     * @inheritDoc
     */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @inheritDoc
     */
    public function lootableAmount(
        float $inStock,
        int $rateInBasisPoints,
        string $phase,
        ResourceNormalizationDiagnostics &$diagnostics,
    ): int {
        if ($rateInBasisPoints < 0 || $rateInBasisPoints > self::FULL_RATE) {
            throw new InvalidArgumentException(
                'Un taux de pillage de ' . $rateInBasisPoints . ' points de base sort de la plage admise.'
            );
        }

        $normalise = ResourceBoundary::wholeUnitsOfLivingStock($inStock, 'stock', $phase);

        // **Les diagnostics de cette conversion doivent remonter.** Les laisser tomber ici perdrait
        // ce que la lecture du stock de la cible a rencontre : c est la premiere conversion du
        // chemin, et la seule qui voie le solde brut de la planete.
        $diagnostics = $diagnostics->mergedWith($normalise->diagnostics);

        return ExactRatio::floorOfProductOverDivisor($normalise->units, $rateInBasisPoints, self::FULL_RATE);
    }

    /**
     * @inheritDoc
     */
    public function capByCargo(Resources $loot, int $totalCargoCapacity, string $phase = self::PHASE_TARGET_LOOT, string $subject = ''): CappedLoot
    {
        // **La conversion en unites entieres a lieu ici, a la frontiere.** Les appelants passent des
        // ressources flottantes — les colonnes du jeu sont des `double` —, et tout ce qui suit
        // travaille en entiers.
        $disponible = [];
        $diagnostics = ResourceNormalizationDiagnostics::none();

        foreach (self::RESOURCES as $ressource) {
            $normalise = ResourceBoundary::wholeUnitsOfLivingStock($loot->{$ressource}->get(), $ressource, $phase, $subject);
            $disponible[$ressource] = $normalise->units;
            $diagnostics = $diagnostics->mergedWith($normalise->diagnostics);
        }

        $fret = max(0, $totalCargoCapacity);
        $total = array_sum($disponible);

        if ($fret >= $total) {
            // Tout tient : rien a plafonner, et le resultat est deja entier.
            return new CappedLoot($this->resourcesFrom($disponible), $diagnostics);
        }

        // Chacun recoit d'abord le tiers entier du fret, sans depasser ce qu'il y a a prendre.
        $part = intdiv($fret, count(self::RESOURCES));
        $attribue = [];

        foreach (self::RESOURCES as $ressource) {
            $attribue[$ressource] = min($disponible[$ressource], $part);
        }

        // Puis la place restante, une unite a la fois, aux ressources qui n'ont pas tout recu.
        //
        // L'ancien code la partageait par une division flottante, ce qui donnait des montants
        // fractionnaires que le moteur transtypait ensuite : les unites tombees dans la fraction
        // etaient perdues. Ici, chaque unite trouve preneur ou reste sur la cible.
        $restant = $fret - array_sum($attribue);

        while ($restant > 0) {
            $servie = false;

            foreach (self::RESOURCES as $ressource) {
                if ($restant <= 0) {
                    break;
                }

                if ($attribue[$ressource] >= $disponible[$ressource]) {
                    continue;
                }

                $attribue[$ressource]++;
                $restant--;
                $servie = true;
            }

            if (!$servie) {
                // Plus rien a prendre nulle part : le fret excedentaire repart vide.
                break;
            }
        }

        return new CappedLoot($this->resourcesFrom($attribue), $diagnostics);
    }

    /**
     * @inheritDoc
     */
    public function shareBetweenFleets(
        int $amount,
        array $weights,
        array $remainingCapacity,
        int $initiatorFleetMissionId,
    ): array {
        $attribue = [];

        foreach (array_keys($weights) as $flotte) {
            $attribue[$flotte] = 0;
        }

        if ($amount <= 0) {
            return $attribue;
        }

        $restant = $amount;

        while ($restant > 0) {
            $eligibles = [];
            $poidsTotal = 0;

            foreach ($weights as $flotte => $poids) {
                if ($poids <= 0 || ($remainingCapacity[$flotte] ?? 0) <= 0) {
                    continue;
                }

                $eligibles[] = $flotte;
                $poidsTotal += $poids;
            }

            if ($poidsTotal <= 0 || count($eligibles) === 0) {
                return $attribue;
            }

            // Les parts sont calculees en entiers exacts : le quotient et le reste sortent du meme
            // calcul, sans qu'aucun produit trop grand ne soit forme.
            $divisions = [];
            $attribueCettePasse = 0;

            foreach ($eligibles as $flotte) {
                $division = ExactRatio::multiplyDivideWithRemainder($restant, $weights[$flotte], $poidsTotal);
                $part = min($division->quotient, $remainingCapacity[$flotte]);

                $divisions[$flotte] = $division;

                if ($part > 0) {
                    $attribue[$flotte] += $part;
                    $remainingCapacity[$flotte] -= $part;
                    $attribueCettePasse += $part;
                }
            }

            $restant -= $attribueCettePasse;

            if ($restant <= 0) {
                return $attribue;
            }

            $classees = $eligibles;
            usort($classees, function (int $gauche, int $droite) use ($divisions, $weights, $initiatorFleetMissionId): int {
                $gaucheInitiatrice = $gauche === $initiatorFleetMissionId;
                $droiteInitiatrice = $droite === $initiatorFleetMissionId;

                if ($gaucheInitiatrice !== $droiteInitiatrice) {
                    return $droiteInitiatrice <=> $gaucheInitiatrice;
                }

                // Un reste n'a de sens que rapporte a son denominateur. Ceux d'une meme passe le
                // partagent par construction, mais la condition est verifiee plutot que supposee.
                if ($this->comparable($divisions[$gauche], $divisions[$droite])
                    && $divisions[$gauche]->remainder !== $divisions[$droite]->remainder) {
                    return $divisions[$droite]->remainder <=> $divisions[$gauche]->remainder;
                }

                if ($weights[$gauche] !== $weights[$droite]) {
                    return $weights[$droite] <=> $weights[$gauche];
                }

                return $gauche <=> $droite;
            });

            $attribueEnPlus = 0;

            foreach ($classees as $flotte) {
                if ($restant <= 0) {
                    break;
                }

                if (($remainingCapacity[$flotte] ?? 0) <= 0) {
                    continue;
                }

                $attribue[$flotte]++;
                $remainingCapacity[$flotte]--;
                $restant--;
                $attribueEnPlus++;
            }

            if ($attribueCettePasse === 0 && $attribueEnPlus === 0) {
                return $attribue;
            }
        }

        return $attribue;
    }

    /**
     * Si deux restes se comparent legitimement.
     *
     * @param ExactDivision $gauche
     * @param ExactDivision $droite
     * @return bool
     */
    private function comparable(ExactDivision $gauche, ExactDivision $droite): bool
    {
        return $gauche->isComparableWith($droite);
    }

    /**
     * Les trois montants, sous la forme que le jeu manipule.
     *
     * @param array<string, int> $montants
     * @return Resources
     */
    private function resourcesFrom(array $montants): Resources
    {
        return new Resources($montants['metal'], $montants['crystal'], $montants['deuterium'], 0);
    }
}
