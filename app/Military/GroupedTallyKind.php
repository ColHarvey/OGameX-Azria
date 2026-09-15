<?php

namespace OGame\Military;

/**
 * Un genre d evenements qui vivent en groupe : ecrits ensemble sur les memes faits, differes ensemble, repris
 * ensemble — jamais l un sans l autre.
 *
 * La reprise ne connait ni les faits ni la regle d un genre : elle demande au genre de relire les faits gardes, de
 * nommer les prefixes sous lesquels ses membres sont inscrits, et de dire, pour une version de ponderation, quels
 * evenements le groupe doit compter — ou qu il ne peut pas encore etre evalue entier. Elle fait le reste : verrouiller
 * les membres, exiger qu ils portent tous les memes faits, qu ils recouvrent exactement ce qui est attendu, et tout
 * appliquer ou rien.
 */
interface GroupedTallyKind
{
    /**
     * Les genres (`payload.kind`) que ce groupe porte.
     *
     * @return list<string>
     */
    public function kinds(): array;

    /**
     * Relit les faits gardes dans la charge d un membre ; rien si le document n a pas la forme attendue.
     */
    public function readFacts(mixed $facts): object|null;

    /**
     * Les prefixes de clef sous lesquels les membres de ce groupe sont inscrits, pour ces faits.
     *
     * @return list<string>
     */
    public function memberPrefixes(object $facts): array;

    /**
     * Les evenements que ce groupe doit compter, par clef, pour cette version — ou rien s il ne peut pas encore etre
     * evalue entier.
     *
     * @return array<string, array{kind: string, owner: int, built: int, destroyed: int, lost: int}>|null
     */
    public function expectedEvents(object $facts, string $version): array|null;

    /**
     * La forme gardee des faits, pour comparer les membres entre eux : deux membres d un meme groupe portent les
     * memes faits, a l octet pres.
     *
     * @return array<string, mixed>
     */
    public function storageOf(object $facts): array;
}
