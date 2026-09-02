<?php

namespace OGame\Combat\Exceptions;

use OGame\Combat\Enums\LootReservationState;
use RuntimeException;

/**
 * Une reservation de butin a recu un ordre qu'elle ne peut pas honorer.
 *
 * **S'arreter vaut mieux que corriger.** Chacun de ces cas signale une contradiction : deux
 * photographies differentes du meme combat, deux reglages du meme resultat, un butin plus grand
 * que ce qui etait immobilise. Ramener silencieusement la valeur dans les clous — plafonner le
 * butin, garder le dernier scellement — reviendrait a choisir arbitrairement laquelle des deux
 * verites conserver, et le desaccord ne serait jamais examine.
 *
 * La resolution doit donc s'interrompre **en conservant le verrou**, pour qu'un humain regarde.
 */
class LootReservationRefused extends RuntimeException
{
    /**
     * La borne est figee : le scellement est passe.
     */
    public static function becauseTheBoundIsFrozen(LootReservationState $state): self
    {
        return new self(
            'La borne d une reservation « ' . $state->value . ' » ne se releve plus : '
            . 'elle est figee des que la photographie est prise.'
        );
    }

    /**
     * Un passage d'etat qui n'existe pas.
     */
    public static function becauseTheTransitionIsForbidden(LootReservationState $from, LootReservationState $to): self
    {
        return new self(
            'Une reservation ne passe pas de « ' . $from->value . ' » a « ' . $to->value . ' ». '
            . 'En particulier, une reservation reglee ne s annule pas : le butin serait preleve puis les fonds liberes.'
        );
    }

    /**
     * Deux scellements portant des photographies differentes.
     */
    public static function becauseASecondSealDiffers(string $first, string $second): self
    {
        return new self(
            'Cette reservation a deja ete scellee sur la photographie « ' . $first . ' », et on lui en presente une autre ('
            . $second . '). Deux photographies du meme combat ne peuvent pas etre vraies toutes les deux.'
        );
    }

    /**
     * Deux reglages portant des resultats differents.
     */
    public static function becauseASecondSettlementDiffers(string $first, string $second): self
    {
        return new self(
            'Cette reservation a deja ete reglee par « ' . $first . ' », et on lui en presente un autre (' . $second . '). '
            . 'Rejouer un reglage identique est sans effet ; en appliquer un different serait payer deux fois.'
        );
    }

    /**
     * Deux annulations differentes.
     */
    public static function becauseASecondCancellationDiffers(string $first, string $second): self
    {
        return new self(
            'Cette reservation a deja ete annulee par « ' . $first . ' », et on lui en presente une autre (' . $second . ').'
        );
    }

    /**
     * Le butin depasse ce qui avait ete immobilise.
     *
     * Signe que la borne et le butin n'ont pas ete calcules avec la meme regle de pillage, ou que
     * la base logique a change entre les deux. Plafonner le butin masquerait le desaccord.
     */
    public static function becauseTheLootExceedsTheReservation(): self
    {
        return new self(
            'Le butin depasse la reservation sur au moins une ressource. La borne et le butin doivent venir de la meme '
            . 'regle de pillage : plafonner silencieusement masquerait le desaccord au lieu de le signaler.'
        );
    }
}
