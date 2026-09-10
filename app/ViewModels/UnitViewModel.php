<?php

namespace OGame\ViewModels;

use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\Models\Resource;

class UnitViewModel
{
    public int $count;
    public GameObject $object;
    public int $amount;

    /**
     * Combien de ces unites sont endommagees (journal §118).
     *
     * **Distinct de `amount`, et les deux se lisent ensemble** : « 20 croiseurs, dont 8 endommages ».
     * Zero par defaut, ce qui est l etat du jeu tant que le chantier des coques est desarme — et
     * l etat de tout type qui n a jamais combattu.
     *
     * Le detail par palier ne vit **pas** ici : une vue de liste dirait une moyenne, et une moyenne
     * ferait passer douze intacts et huit a moitie detruits pour vingt vaisseaux a 80 %. Le detail
     * est au dock, qui a la place de le montrer honnetement.
     */
    public int $damaged = 0;
    public bool $requirements_met;
    public bool $character_class_met;
    public bool $enough_resources;
    public int $max_build_amount;
    public bool $currently_building;
    public int $currently_building_amount;

    /**
     * Wrap the amount inside a Resource instance and return it.
     */
    protected function getResource(): Resource
    {
        return new Resource($this->amount);
    }

    public function getFormatted(): string
    {
        return $this->getResource()->getFormatted();
    }

    public function getFormattedFull(): string
    {
        return $this->getResource()->getFormattedFull();
    }

    public function getFormattedLong(): string
    {
        return $this->getResource()->getFormattedLong();
    }
}
