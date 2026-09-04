<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\CombatMissionKind;
use OGame\Models\FleetMission;
use ReflectionClass;
use Tests\UnitTestCase;

/**
 * Ce qui passe par la porte des mouvements, et ce qui n'a pas a y passer.
 *
 * ## La liste qui avait remplace la matrice
 *
 * Le travailleur des pages portait une liste de genres — attaque, attaque groupee, Defense ACS — et
 * laissait dehors **tous les retours**, ainsi que le transport, le deploiement, le missile et la
 * destruction de lune. Une arrivee de n'importe lequel d'entre eux pendant un ralliement touche
 * pourtant le corps que la barriere tient : elle peut composer la photographie, ou la deranger.
 * Cette liste etait une seconde matrice, qui aurait diverge de la vraie au premier genre ajoute.
 *
 * Le verdict vient maintenant de `CombatSituation::scopeOf()`. Cet essai epingle le resultat genre
 * par genre, aller et retour, interrupteur arme et desarme : une case qui changerait sans qu'on le
 * veuille se voit ici.
 *
 * ## Pourquoi les trois exclus le restent
 *
 * Un recyclage vise un champ de debris, une colonisation une position vide, une expedition l'espace
 * profond. Aucun des trois ne touche le corps celeste, meme quand ses coordonnees sont celles d'une
 * planete assiegee — c'est exactement la distinction que `TargetScope` existe pour porter.
 */
class MovementGateCoverageTest extends UnitTestCase
{
    /**
     * Interrupteur arme : tout ce qui touche un corps celeste passe par la porte.
     */
    public function testWithPersistentCombatArmedEveryArrivalOnABodyIsGoverned(): void
    {
        $this->armThePersistentCombat(true);

        $attendus = [
            // Genre => [aller gouverne, retour gouverne]
            'attack' => [true, true],
            'acs_attack' => [true, true],
            'moon_destruction' => [true, true],
            'acs_defend' => [true, true],
            'transport' => [true, true],
            'deployment' => [true, true],
            'espionage' => [true, true],
            'missile' => [true, true],
            // Ceux qui ne touchent pas le corps a l'aller — mais dont le retour s'y pose.
            'recycle' => [false, true],
            'colonisation' => [false, true],
            'expedition' => [false, true],
        ];

        foreach (CombatMissionKind::byMissionType() as $type => $genre) {
            [$aller, $retour] = $attendus[$genre->value];

            $this->assertSame($aller, $this->isGoverned($type, null), 'The outbound leg of « ' . $genre->value .' » is misplaced.');
            $this->assertSame($retour, $this->isGoverned($type, 1), 'The return leg of « ' . $genre->value . ' » is misplaced.');
        }
    }

    /**
     * Interrupteur desarme : seule la Defense ACS a l'aller passe par la porte.
     *
     * Sans combat durable, aucune barriere n'existe : l'arrivee d'une attaque est la bataille
     * instantanee, et le traitement de page la protege comme il l'a toujours fait. Elargir la porte
     * ici changerait le chemin de toutes les missions du serveur pour fermer une course qui ne peut
     * pas s'y produire. La Defense ACS, elle, y passe toujours : sa retenue et son demi-tour s'y
     * decident.
     */
    public function testWithPersistentCombatDisarmedOnlyTheAcsDefenceOutboundIsGoverned(): void
    {
        $this->armThePersistentCombat(false);

        foreach (CombatMissionKind::byMissionType() as $type => $genre) {
            $this->assertSame(
                $genre === CombatMissionKind::AcsDefend,
                $this->isGoverned($type, null),
                'The outbound leg of « ' . $genre->value . ' » is misplaced while persistent combat is off.'
            );
            $this->assertFalse($this->isGoverned($type, 1), 'The return leg of « ' . $genre->value . ' » is governed while persistent combat is off.');
        }
    }

    /**
     * Le verdict du travailleur pour une mission de ce genre et de cette etape de vol.
     */
    private function isGoverned(int $missionType, int|null $parentId): bool
    {
        $mission = new FleetMission();
        $mission->mission_type = $missionType;
        $mission->parent_id = $parentId;

        $methode = (new ReflectionClass($this->playerService))->getMethod('isGovernedByTheCombatGate');

        return (bool)$methode->invoke($this->playerService, $mission);
    }

    private function armThePersistentCombat(bool $arme): void
    {
        $this->settingsService->set('persistent_combat_enabled', $arme ? '1' : '0');
    }
}
