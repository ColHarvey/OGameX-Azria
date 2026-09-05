<?php

namespace OGame\Combat\MoonDestruction;

/**
 * La source des tirages d'une tentative de destruction de lune, dans la plage du jeu (1 a 100).
 *
 * Le plan gele conserve les **valeurs tirees**, jamais la source : un rejeu relit, il ne retire pas.
 * La source est resolue par le conteneur pour qu'un banc puisse la remplacer par une suite connue et
 * observer chaque issue — succes, echec, echec catastrophique — sans jouer au hasard.
 */
class MoonDestructionRolls
{
    public function roll(): int
    {
        return random_int(1, 100);
    }
}
