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
     * @param array{author: string, owner: int|null, npc: bool, destroyed: int}|null $hamill L evenement nomme de la
     *        manoeuvre de Hamill : l Etoile prise, creditee en « detruits » a l auteur, **et nulle part ailleurs** —
     *        elle ne fait partie d aucun round, et la victime la compte une seule fois en « perdus ».
     */
    private function __construct(
        public string|null $reason,
        public string $detail,
        public array $computed,
        public array|null $hamill,
    ) {
    }

    public static function pending(string $reason, string $detail): self
    {
        return new self($reason, $detail, [], null);
    }

    /**
     * @param array<string, array{owner: int|null, npc: bool, destroyed: int, lost: int}> $computed
     * @param array{author: string, owner: int|null, npc: bool, destroyed: int}|null $hamill
     */
    public static function computed(array $computed, array|null $hamill = null): self
    {
        ksort($computed);

        return new self(null, '', $computed, $hamill);
    }

    /**
     * Le credit de la manoeuvre de Hamill, s il s inscrit : un auteur classe, et une Etoile a compter.
     *
     * @return array{author: string, owner: int, destroyed: int}|null
     */
    public function hamillCredit(): array|null
    {
        if ($this->hamill === null || $this->hamill['owner'] === null || $this->hamill['npc'] || $this->hamill['destroyed'] <= 0) {
            return null;
        }

        return ['author' => $this->hamill['author'], 'owner' => $this->hamill['owner'], 'destroyed' => $this->hamill['destroyed']];
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
