<?php

namespace OGame\Combat\Allocation;

use InvalidArgumentException;
use OGame\Combat\Exceptions\ContradictoryLootShares;

/**
 * Le butin applique, reparti entre les flottes survivantes exactement comme le moteur l'aurait fait.
 *
 * ## Ce qui se repartit : l'applique, jamais le potentiel
 *
 * Le moteur a reparti le potentiel a l'instant de l'issue. Le reglement differe repartit **ce qui
 * est effectivement pris** — le minimum du potentiel et du restant — avec le meme allocateur, les
 * memes poids et la meme place restante, tous geles avec l'issue. Quand la cible avait tout, les deux
 * repartitions sont identiques ; quand elle n'avait plus tout, chaque flotte recoit sa part reduite
 * dans la meme proportion, avec la meme priorite d'initiateur et le meme traitement des restes.
 *
 * ## L'invariant que cette classe fait respecter
 *
 *     somme des parts = butin applique, composante par composante
 *
 * L'allocateur plafonne par flotte, et une somme inferieure serait juste pour un potentiel qui
 * deborde les soutes. Elle ne l'est pas pour l'applique, qui ne les deborde jamais : un ecart ici
 * signale des faits geles qui ne se correspondent plus, et il s'arrete. Sans cela, le defenseur
 * serait debite de ce que personne ne recevrait.
 *
 * ## Rien n'est lu
 *
 * Ni modele, ni registre courant, ni horloge. L'allocateur vient de la version gelee du combat, les
 * capacites de l'issue gelee, le montant du reglement. C'est ce qui permet de rejouer la repartition
 * et d'obtenir les memes parts.
 */
final readonly class AppliedLootShares
{
    /**
     * @param array<int, ExactLootAmounts> $byFleet La part de chaque flotte, par identifiant de mission.
     * @param ExactLootAmounts $total Ce que les parts totalisent — l'applique, par invariant.
     */
    private function __construct(
        public array $byFleet,
        public ExactLootAmounts $total,
    ) {
    }

    /**
     * La repartition de ce butin applique entre ces flottes.
     *
     * @param ExactLootAmounts $applied Le minimum du potentiel et du restant.
     * @param array<int, SurvivingFleetCapacity> $fleets Les survivantes, avec leur fret et leur place.
     * @param int $initiatorFleetMissionId La flotte prioritaire en cas d'egalite de restes.
     * @param FrozenLootAllocation $allocation L'allocateur de la version gelee du combat.
     *
     * @throws ContradictoryLootShares Si les parts ne font pas la somme de l'applique.
     */
    public static function of(
        ExactLootAmounts $applied,
        array $fleets,
        int $initiatorFleetMissionId,
        FrozenLootAllocation $allocation,
    ): self {
        $poids = [];
        $place = [];

        foreach ($fleets as $flotte) {
            if (isset($poids[$flotte->fleetMissionId])) {
                throw new InvalidArgumentException(
                    'La flotte ' . $flotte->fleetMissionId . ' apparait deux fois : elle recevrait deux parts.'
                );
            }

            $poids[$flotte->fleetMissionId] = $flotte->survivingCapacity;
            $place[$flotte->fleetMissionId] = $flotte->remaining();
        }

        // Une table par ressource, chacune initialisee pour toutes les flottes : une flotte sans
        // part y figure a zero, jamais absente.
        $zero = array_fill_keys(array_keys($poids), 0);
        $attribuees = ['metal' => $zero, 'crystal' => $zero, 'deuterium' => $zero];

        // **Composante par composante, en consommant la place au fil des ressources**, comme le
        // moteur : le metal attribue reduit la place disponible pour le cristal, puis le deuterium.
        foreach (['metal', 'crystal', 'deuterium'] as $composante) {
            $montant = $applied->{$composante};

            $attribue = $allocation->allocator()->shareBetweenFleets(
                $montant,
                $poids,
                $place,
                $initiatorFleetMissionId
            );

            $total = 0;

            foreach ($attribue as $mission => $part) {
                if ($part <= 0) {
                    continue;
                }

                $attribuees[$composante][$mission] = $part;
                $place[$mission] -= $part;
                $total += $part;
            }

            if ($total !== $montant) {
                throw new ContradictoryLootShares($composante, $montant, $total);
            }
        }

        $parFlotte = [];

        foreach (array_keys($poids) as $mission) {
            $parFlotte[$mission] = ExactLootAmounts::of(
                $attribuees['metal'][$mission],
                $attribuees['crystal'][$mission],
                $attribuees['deuterium'][$mission],
            );
        }

        return new self($parFlotte, $applied);
    }

    /**
     * La part de cette flotte, ou rien si elle n'en a recu aucune.
     */
    public function forFleet(int $fleetMissionId): ExactLootAmounts
    {
        return $this->byFleet[$fleetMissionId] ?? ExactLootAmounts::nothing();
    }
}
