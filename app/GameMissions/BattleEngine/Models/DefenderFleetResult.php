<?php

namespace OGame\GameMissions\BattleEngine\Models;

use OGame\GameObjects\Models\Units\UnitCollection;

/**
 * Tracks battle results for a specific defending fleet.
 */
class DefenderFleetResult
{
    /**
     * @var UnitCollection Units at the start of battle.
     */
    public UnitCollection $unitsStart;

    /**
     * @var UnitCollection Units remaining after battle.
     */
    public UnitCollection $unitsResult;

    /**
     * @var UnitCollection Units lost during battle.
     */
    public UnitCollection $unitsLost;

    /**
     * @var bool Whether this fleet was completely destroyed.
     */
    public bool $completelyDestroyed;

    /**
     * @var int La capacite de fret que cette flotte portait **au depart**, gelee a la cloture.
     */
    public int $startingCargoCapacity = 0;

    /**
     * @var int La capacite de fret **survivante**, gelee a la cloture.
     *
     * ## Pourquoi gelee, et non recalculee au reglement
     *
     * La cargaison d'un renfort survivant est reduite en proportion de ces deux capacites. Le
     * reglement les recalculait a l'echeance, sur le joueur **vivant** : une classe ou une
     * technologie d'hyperespace modifiee pendant la bataille changeait donc la proportion
     * appliquee a une cargaison pourtant gelee. Deux rejeux du meme combat ne rendaient pas la
     * meme cargaison, et personne ne pouvait dire pourquoi.
     */
    public int $survivingCargoCapacity = 0;

    /**
     * Create a new DefenderFleetResult.
     *
     * @param int $fleetMissionId
     * @param int $ownerId
     * @param UnitCollection $unitsStart
     */
    public function __construct(public int $fleetMissionId, public int $ownerId, UnitCollection $unitsStart)
    {
        $this->unitsStart = clone $unitsStart;
        $this->unitsResult = new UnitCollection();
        $this->unitsLost = new UnitCollection();
        $this->completelyDestroyed = false;
    }
}
