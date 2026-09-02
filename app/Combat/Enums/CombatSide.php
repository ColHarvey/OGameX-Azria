<?php

namespace OGame\Combat\Enums;

/**
 * De quel cote une flotte se presente a un combat.
 *
 * Deux camps, et **aucun troisieme**. C'est une decision, pas une simplification : sur un corps
 * celeste en combat, une flotte hostile qui n'appartient ni a l'attaquant ni a son union ne
 * devient pas pour autant un second attaquant du meme combat. Elle attend son tour.
 *
 * Faire cohabiter deux attaquants independants dans une meme bataille reviendrait a les rendre
 * allies sans que ni l'un ni l'autre ne l'ait voulu : leurs pertes seraient calculees ensemble,
 * le butin partage, et le rapport les montrerait cote a cote. C'est exactement ce que l'union
 * ACS sert a decider explicitement.
 */
enum CombatSide: string
{
    /**
     * La flotte vient prendre le corps celeste.
     */
    case Attacker = 'attacker';

    /**
     * La flotte defend le corps celeste : garnison, renforts, alliance.
     */
    case Defender = 'defender';

    /**
     * Get the opposing side.
     */
    public function opponent(): self
    {
        return match ($this) {
            self::Attacker => self::Defender,
            self::Defender => self::Attacker,
        };
    }
}
