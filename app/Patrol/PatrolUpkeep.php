<?php

namespace OGame\Patrol;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\SettingsService;

/**
 * Ce qu une patrouille brule a rester en place, et jusqu a quand elle peut le faire.
 *
 * ## La regle, et la raison de chacune de ses moities
 *
 * Le taux est celui du jeu — la formule d attente que la Defense ACS et l expedition emploient deja,
 * `Σ(carburant × nombre) ÷ diviseur` par heure — avec le diviseur que la revue 121 a fixe a 20.
 *
 * La facturation est **au prorata de la seconde**, et c est ce qui la rend juste. Facturer a l heure
 * entiere aurait deux defauts, tous deux exploitables : une patrouille qui repart avant l heure
 * n aurait rien paye, et un joueur qui donne un ordre toutes les cinquante-neuf minutes stationnerait
 * gratuitement pour toujours. La revue 120 l a dit ainsi : « pas d heure gratuite recreee a chaque
 * deplacement ou ordre ».
 *
 * ## Ce que ce calcul ne fait pas
 *
 * Il ne debite rien. Il rend des nombres ; l ecriture appartient au service des ordres, qui la fait
 * sous verrou avec l horodatage qu il avance. Separer les deux est ce qui permet a un devis d annoncer
 * exactement ce qu un ordre prelevera.
 */
final class PatrolUpkeep
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Le cout d une heure de stationnement pour cette flotte, avant tout bonus.
     *
     * Le carburant brut du vaisseau, comme la formule d attente du jeu : c est la valeur de base,
     * pas celle que les technologies modifient — elles jouent sur la vitesse, pas sur la soif.
     */
    public function perHour(UnitCollection $units): float
    {
        $total = 0.0;

        foreach ($units->units as $entree) {
            $total += $entree->unitObject->properties->fuel->rawValue * $entree->amount;
        }

        return $total / $this->settings->patrolUpkeepDivisor();
    }

    /**
     * Ce qui est du pour un stationnement, au prorata exact de la seconde.
     *
     * Un intervalle nul ou renverse ne doit rien : le curseur de facturation n avance jamais dans le
     * passe, et un appelant qui le lirait a l envers ne creerait pas de credit.
     */
    public function dueBetween(UnitCollection $units, int $depuis, int $jusqua): float
    {
        $secondes = max(0, $jusqua - $depuis);

        return $this->perHour($units) * $secondes / 3600;
    }

    /**
     * Combien de temps cette reserve tient, une fois le retour de securite mis de cote.
     *
     * Rend `null` quand la flotte ne consomme rien — une patrouille de sondes, dont le carburant de
     * base vaut zero. Le stationnement est alors gratuit et sans terme : c est un fait du jeu, pas
     * une division par zero a masquer.
     */
    public function autonomySeconds(UnitCollection $units, float $reserve, float $coutDuRetour): int|null
    {
        $taux = $this->perHour($units);

        if ($taux <= 0.0) {
            return null;
        }

        $disponible = $reserve - $coutDuRetour;

        if ($disponible <= 0.0) {
            return 0;
        }

        return (int)floor($disponible * 3600 / $taux);
    }

    /**
     * L instant ou le retour de securite doit partir : celui ou la reserve atteint le cout du retour.
     *
     * **Avant d entamer l indispensable**, jamais apres : la revue 120 l exige, et c est ce que le
     * plancher a zero garantit — une reserve deja sous le seuil part maintenant.
     */
    public function safetyReturnAt(UnitCollection $units, float $reserve, float $coutDuRetour, int $paidAt): int
    {
        $autonomie = $this->autonomySeconds($units, $reserve, $coutDuRetour);

        if ($autonomie === null) {
            return PHP_INT_MAX;
        }

        return $paidAt + $autonomie;
    }
}
