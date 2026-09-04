<?php

namespace OGame\Combat\Presentation;

/**
 * Une perte devoilee a un instant : a qui, de quoi, combien, et son rang dans le fil.
 *
 * Valeur immuable, produite par une regle de presentation depuis le resultat gele. Deux
 * projections du meme resultat rendent des evenements egaux champ par champ — c'est ce que
 * l'ecrivain compare avant de refuser une contradiction.
 */
final readonly class PresentationEvent
{
    public function __construct(
        public int $sequence,
        public int $visibleAt,
        public string $participantKey,
        public string $side,
        public string $unit,
        public int $amount,
    ) {
    }

    /**
     * Les colonnes de la ligne, dans l'ordre canonique.
     *
     * @return array{sequence: int, visible_at: int, participant_key: string, side: string, unit: string, amount: int}
     */
    public function toRow(): array
    {
        return [
            'sequence' => $this->sequence,
            'visible_at' => $this->visibleAt,
            'participant_key' => $this->participantKey,
            'side' => $this->side,
            'unit' => $this->unit,
            'amount' => $this->amount,
        ];
    }
}
