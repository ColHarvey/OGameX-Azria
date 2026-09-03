<?php

namespace OGame\Combat\Allocation;

use InvalidArgumentException;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;

/**
 * Ce qu'une flotte survivante peut encore emporter, fige avec l'issue du moteur.
 *
 * ## Deux nombres, et leur difference
 *
 *     capacite survivante   le fret des vaisseaux encore la, apres la bataille
 *     deja a bord           la cargaison qu'elle transportait, survivante au meme taux
 *
 * La place restante est la difference. C'est elle qui plafonne la part de butin de cette flotte, et
 * le fret survivant qui sert de poids dans la repartition. Le moteur calcule ces deux nombres pour
 * l'issue instantanee ; ils sont repris ici tels quels, en entiers, pour que le reglement differe
 * repartisse exactement comme le moteur l'aurait fait.
 *
 * ## Pourquoi un type, et non deux tableaux
 *
 * Le moteur passe a l'allocateur deux tableaux indexes par mission — poids et place restante — et
 * les tient synchronises a la main. Une flotte presente dans l'un et absente de l'autre y serait
 * traitee comme sans place, en silence. Ici, les deux faits voyagent ensemble.
 */
final readonly class SurvivingFleetCapacity
{
    /**
     * Le moment fonctionnel de la conversion, pour situer un diagnostic.
     */
    public const string PHASE = 'surviving_capacity';

    /**
     * @param int $fleetMissionId La mission, identite stable de la flotte.
     * @param int $survivingCapacity Le fret des survivants, en unites entieres.
     * @param int $alreadyAboard Ce qu'elle transportait deja, survivant au meme taux.
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que la conversion a rencontre.
     */
    private function __construct(
        public int $fleetMissionId,
        public int $survivingCapacity,
        public int $alreadyAboard,
        public ResourceNormalizationDiagnostics $diagnostics,
    ) {
        if ($fleetMissionId < 1) {
            throw new InvalidArgumentException(
                'Une capacite survivante appartient a une mission persistee : sans identifiant, la part '
                . 'de butin qu elle recevrait ne pourrait etre rendue a personne.'
            );
        }

        if ($survivingCapacity < 0 || $alreadyAboard < 0) {
            throw new InvalidArgumentException(
                'Une capacite ou une cargaison negative n a pas de sens : la flotte ' . $fleetMissionId
                . ' porterait moins que rien.'
            );
        }
    }

    /**
     * Des faits explicites, pour les essais et les rejeux.
     */
    public static function of(int $fleetMissionId, int $survivingCapacity, int $alreadyAboard): self
    {
        return new self($fleetMissionId, $survivingCapacity, $alreadyAboard, ResourceNormalizationDiagnostics::none());
    }

    /**
     * Les faits d'une flotte, tels que le moteur les a figes dans son issue.
     *
     * La cargaison a bord est un `Resources` a composantes flottantes ; elle passe la frontiere des
     * faits geles une fois, composante par composante, et sa somme est faite en entiers.
     */
    public static function fromFleetResult(AttackerFleetResult $result): self
    {
        $diagnostics = ResourceNormalizationDiagnostics::none();
        $aBord = 0;

        foreach (['metal', 'crystal', 'deuterium'] as $composante) {
            $normalise = ResourceBoundary::wholeUnitsOfFrozenFact(
                $result->survivingCargo->{$composante}->get(),
                $composante,
                self::PHASE,
                (string)$result->fleetMissionId
            );

            $aBord += $normalise->units;
            $diagnostics = $diagnostics->mergedWith($normalise->diagnostics);
        }

        return new self($result->fleetMissionId, $result->survivingCargoCapacity, $aBord, $diagnostics);
    }

    /**
     * La place encore libre, jamais negative.
     *
     * Une cargaison qui depasserait la capacite survivante — vaisseaux perdus, soutes pleines —
     * laisse zero place, pas une place negative qui ferait retirer du butin.
     */
    public function remaining(): int
    {
        return max(0, $this->survivingCapacity - $this->alreadyAboard);
    }
}
