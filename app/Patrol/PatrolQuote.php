<?php

namespace OGame\Patrol;

/**
 * Le devis d un segment de patrouille : ce que le joueur lit avant de confirmer, et ce que le
 * serveur prelevera s il confirme.
 *
 * ## Pourquoi un objet, et pourquoi il porte sa propre version
 *
 * Le joueur confirme un devis, pas une intention. Entre l affichage et la confirmation, la patrouille
 * a pu bouger, bruler du carburant, ou recevoir un autre ordre : la revue 121 exige que position,
 * cout, disponibilite **et version** soient revalides, et qu un devis perime demande une nouvelle
 * confirmation au lieu d un debit different en silence. La version d ordre voyage donc dans le devis
 * et revient avec la confirmation ; le service la compare a celle que porte la patrouille.
 *
 * ## Ce que le devis annonce, et que la revue 120 rend obligatoire
 *
 * Le cout du segment, la reserve qui restera a l arrivee, le cout et la duree du retour de securite
 * depuis la destination, et l autonomie qui en decoule. Un joueur doit pouvoir lire « j arrive avec
 * tant, je peux rester tant, et rentrer me coutera tant » sans faire lui-meme la soustraction.
 *
 * `refusal` porte la clef de traduction du refus quand le segment est impossible ; les autres champs
 * decrivent alors ce qui a ete calcule jusqu au refus, pour que l interface puisse expliquer.
 */
final readonly class PatrolQuote
{
    public function __construct(
        public PatrolDestination $destination,
        public int $distance,
        public int $durationSeconds,
        public float $speedPercent,
        public int $fuelCost,
        public float $reserveOnArrival,
        public int $safetyReturnCost,
        public int $safetyReturnSeconds,
        public int|null $autonomySeconds,
        public int $orderVersion,
        public string|null $refusal = null,
    ) {
    }

    public function isPossible(): bool
    {
        return $this->refusal === null;
    }

    /**
     * Le meme devis, refuse pour cette raison.
     *
     * Les chiffres deja calcules sont conserves : un refus qui n annonce aucun nombre laisse le
     * joueur sans moyen de comprendre ce qui manque.
     */
    public function refusedBecause(string $cle): self
    {
        return new self(
            $this->destination,
            $this->distance,
            $this->durationSeconds,
            $this->speedPercent,
            $this->fuelCost,
            $this->reserveOnArrival,
            $this->safetyReturnCost,
            $this->safetyReturnSeconds,
            $this->autonomySeconds,
            $this->orderVersion,
            $cle
        );
    }

    /**
     * @return array<string, mixed> Ce que la carte et la page Flotte recoivent.
     */
    public function toArray(): array
    {
        return [
            'destination' => [
                'galaxy' => $this->destination->galaxy,
                'system' => $this->destination->system,
                'orbit' => $this->destination->orbit,
                'type' => $this->destination->type->value,
                'body_id' => $this->destination->bodyId,
                'x' => $this->destination->point->x,
                'y' => $this->destination->point->y,
            ],
            'distance' => $this->distance,
            'duration_seconds' => $this->durationSeconds,
            'speed_percent' => $this->speedPercent,
            'fuel_cost' => $this->fuelCost,
            'reserve_on_arrival' => round($this->reserveOnArrival, 2),
            'safety_return_cost' => $this->safetyReturnCost,
            'safety_return_seconds' => $this->safetyReturnSeconds,
            'autonomy_seconds' => $this->autonomySeconds,
            'order_version' => $this->orderVersion,
            'refusal' => $this->refusal,
        ];
    }
}
