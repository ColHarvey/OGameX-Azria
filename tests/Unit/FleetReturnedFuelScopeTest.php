<?php

namespace Tests\Unit;

use OGame\Combat\Enums\CombatMissionKind;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use Tests\UnitTestCase;

/**
 * Le remboursement de la moitie du carburant, et les genres de mission qu'il touche.
 *
 * ## Pourquoi caracteriser plutot que documenter
 *
 * `FleetMissionService::getResources()` ajoute la moitie du carburant consomme a la cargaison d'une
 * flotte. Son commentaire annoncait « si deploiement » ; le code, lui, ne porte aucune condition sur
 * le genre de mission. Une regle documentee plus etroite que le code est une invitation a « corriger »
 * le code vers le commentaire — et a retirer en silence un remboursement que tous les genres
 * recoivent aujourd'hui.
 *
 * Cet essai epingle donc ce que le serveur fait **maintenant**, genre par genre. Il ne dit pas que
 * c'est la bonne regle : si un jour seul le deploiement doit la recevoir, c'est un changement de
 * regle a part entiere, et cet essai est exactement ce qui le rendra visible.
 *
 * C'est aussi la seule fraction legitime du calcul, celle que la frontiere economique laisse passer
 * quand elle refuse une fraction portee par une colonne.
 */
class FleetReturnedFuelScopeTest extends UnitTestCase
{
    /**
     * Tous les genres recoivent la moitie du carburant, sans exception.
     */
    public function testEveryMissionKindGetsHalfOfItsFuelBack(): void
    {
        $service = resolve(FleetMissionService::class);

        foreach (CombatMissionKind::byMissionType() as $type => $genre) {
            $mission = new FleetMission();
            $mission->mission_type = $type;
            $mission->metal = 100;
            $mission->crystal = 50;
            // Une consommation impaire : c'est elle qui rend la fraction observable.
            $mission->deuterium = 10;
            $mission->deuterium_consumption = 7;

            $ressources = $service->getResources($mission);

            $this->assertSame(
                13.5,
                $ressources->deuterium->get(),
                'The « ' . $genre->value . ' » mission kind did not get half of its fuel back.'
            );
            $this->assertSame(100.0, $ressources->metal->get(), 'The cargo of a « ' . $genre->value . ' » mission was changed.');
            $this->assertSame(50.0, $ressources->crystal->get(), 'The cargo of a « ' . $genre->value . ' » mission was changed.');
        }
    }

    /**
     * Une consommation paire ne produit aucune fraction : le demi n'apparait que sur l'impaire.
     *
     * Sans ce contrat, un essai qui n'observerait qu'une consommation paire ne distinguerait pas
     * « la moitie est rendue » de « rien n'est rendu et la valeur tombe juste ».
     */
    public function testAnEvenFuelConsumptionGivesBackAWholeNumber(): void
    {
        $mission = new FleetMission();
        $mission->mission_type = 1;
        $mission->metal = 0;
        $mission->crystal = 0;
        $mission->deuterium = 10;
        $mission->deuterium_consumption = 8;

        $this->assertSame(14.0, resolve(FleetMissionService::class)->getResources($mission)->deuterium->get());
    }
}
