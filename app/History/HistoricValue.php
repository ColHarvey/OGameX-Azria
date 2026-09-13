<?php

namespace OGame\History;

use LogicException;

/**
 * Ce qu un historique sait d un instant passe : une valeur, ou l aveu qu il ne la sait pas.
 *
 * ## Pourquoi un type, et pas `null`
 *
 * `null` est une valeur legitime de ces historiques : aucune classe, aucune alliance. Le confondre avec
 * « inconnu » ferait lire « sans classe » la ou l on ne sait rien — exactement la substitution que la
 * regle interdit. Une valeur inconnue porte sa raison, et la lire comme connue leve.
 */
final readonly class HistoricValue
{
    private function __construct(
        private bool $known,
        private int|string|null $value,
        public string $reason,
    ) {
    }

    public static function known(int|string|null $value): self
    {
        return new self(true, $value, '');
    }

    public static function unknown(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function isKnown(): bool
    {
        return $this->known;
    }

    /**
     * La valeur, qui peut etre `null` — et un refus si elle est inconnue.
     */
    public function value(): int|string|null
    {
        if (!$this->known) {
            throw new LogicException('Une valeur historique inconnue a ete lue comme connue : ' . $this->reason);
        }

        return $this->value;
    }
}
