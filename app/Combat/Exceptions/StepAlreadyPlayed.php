<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Cette etape de bataille a deja ete jouee et ecrite.
 *
 * ## Ce que la contrainte d unicite garantit, et ce qu elle ne garantit pas
 *
 * La cle unique `(combat, index d etape)` garantit qu il n existe **qu une** ligne par etape : deux
 * travailleurs qui joueraient le meme round ne peuvent pas tous deux l ecrire. Le second echoue, et
 * c est cette exception qu il rencontre.
 *
 * Elle ne dit pas que le second aurait ecrit la meme chose. Une etape se rejoue a l identique quand
 * elle repart du meme etat et de la meme bande de tirages — l idempotence vient de la, pas de la
 * cle. La cle empeche seulement qu un round soit **compte deux fois**, ce qui, dans une bataille,
 * voudrait dire tirer deux fois.
 *
 * ## Ce que l appelant doit en faire
 *
 * La lire comme « quelqu un est passe avant moi », relacher, et repartir de l etat courant : c est
 * le patron du depot pour les decisions ecrites une fois — `FleetDispositionRegistry`,
 * `CombatEffectLedger`. **Jamais la rattraper en silence** : elle signale une course que l ordre des
 * verrous devrait avoir fermee.
 */
class StepAlreadyPlayed extends RuntimeException
{
    public function __construct(public readonly int $combatInstanceId, public readonly int $stepIndex)
    {
        parent::__construct(
            'The step ' . $stepIndex . ' of combat ' . $combatInstanceId . ' is already written: '
            . 'a round is never played twice.'
        );
    }
}
