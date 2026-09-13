<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Ce qu une flotte apportait a ses tirs a son admission ne peut pas etre etabli.
 *
 * Decision de Keven (13 septembre 2026) : pour une admission posterieure a la migration, un historique
 * manquant est une **anomalie**. Le traitement est suspendu sans pertes ni credits partiels, et
 * l administration est alertee. « Inconnu » n est jamais remplace par zero ni par la valeur actuelle.
 */
final class UnknownAdmissionHistory extends RuntimeException
{
}
