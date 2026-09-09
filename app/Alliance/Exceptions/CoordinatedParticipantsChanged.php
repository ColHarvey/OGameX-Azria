<?php

namespace OGame\Alliance\Exceptions;

use RuntimeException;

/**
 * Les joueurs concernes ne sont plus ceux dont le rendez-vous a ete pris.
 *
 * ## Ce qu elle impose a celui qui l attrape
 *
 * **Quitter la transaction**, ce qui relache tout, puis relire les participants et reprendre leurs
 * barrieres dans l ordre. Ajouter les verrous manquants au milieu du traitement donnerait une
 * coordination qui croit coordonner : elle tiendrait les rendez-vous d hier et travaillerait sur les
 * joueurs d aujourd hui.
 *
 * La reprise est bornee. Une mission qui changerait sans cesse doit laisser l operation
 * reessayable, jamais a moitie faite — et c est la transaction annulee qui le garantit, pas l ordre
 * des lignes.
 */
final class CoordinatedParticipantsChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Les joueurs concernes ont change entre l apercu et le verrou.');
    }
}
