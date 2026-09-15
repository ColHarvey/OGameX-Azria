<?php

namespace OGame\Military;

/**
 * Ce que l evaluation d une bataille a conclu : des valeurs par participant, ou une attente qui dit pourquoi.
 *
 * ## Calcule n est pas credite
 *
 * `computed` porte **tous** les participants, comptes PNJ compris : leurs pertes entrent dans le partage et dans le
 * controle de conservation, pour que leurs adversaires soient credites de ce qu ils ont detruit. `credits()` ne rend
 * que ce qui s inscrit : les participants qui ont un proprietaire classe, et quelque chose a lui compter.
 */
final readonly class BattleTallyOutcome
{
    /**
     * @param array<string, array{owner: int|null, npc: bool, destroyed: int, lost: int}> $computed
     */
    private function __construct(
        public string|null $reason,
        public string $detail,
        public array $computed,
    ) {
    }

    public static function pending(string $reason, string $detail): self
    {
        return new self($reason, $detail, []);
    }

    /**
     * @param array<string, array{owner: int|null, npc: bool, destroyed: int, lost: int}> $computed
     */
    public static function computed(array $computed): self
    {
        ksort($computed);

        return new self(null, '', $computed);
    }

    public function isPending(): bool
    {
        return $this->reason !== null;
    }

    /**
     * @return array<string, array{owner: int, destroyed: int, lost: int}>
     */
    public function credits(): array
    {
        $credits = [];

        foreach ($this->computed as $clef => $valeurs) {
            if ($valeurs['owner'] === null || $valeurs['npc'] || ($valeurs['destroyed'] <= 0 && $valeurs['lost'] <= 0)) {
                continue;
            }

            $credits[$clef] = ['owner' => $valeurs['owner'], 'destroyed' => $valeurs['destroyed'], 'lost' => $valeurs['lost']];
        }

        return $credits;
    }
}
