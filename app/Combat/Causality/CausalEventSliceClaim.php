<?php

namespace OGame\Combat\Causality;

use OGame\Combat\Exceptions\ContradictoryCausalEvent;

/**
 * Des evenements assembles, et les sources qu'on affirme avoir interrogees.
 *
 * ## Une revendication, pas une preuve
 *
 * Ce type peut **dire** qu'une tranche est complete ; il ne peut pas le prouver. La preuve exige la
 * base, le verrou et la barriere de partition — trois choses qu'un objet pur n'a pas. Une premiere
 * version melangeait les deux, et une fabrique publique permettait de declarer arbitrairement une
 * tranche complete : la garantie n'etait plus qu'une convention de nommage.
 *
 *     CausalEventSliceClaim      -> ce qu'on a assemble, et ce qu'on affirme
 *     VerifiedCompleteEventSlice -> ce qui a ete verifie sous verrou
 *
 * Seul `VerifiedCompleteEventSlice` entre dans le reconciliateur.
 *
 * ## Deduplication et contradiction
 *
 * Le meme evenement peut etre livre deux fois : une file rejoue, un worker se reveille en double.
 * Deux lectures **identiques** se reduisent a une, sans bruit. Deux lectures qui portent la meme
 * identite avec un contenu different sont refusees : elles signifient que quelque chose a change
 * entre deux lectures qui se croyaient equivalentes, et choisir l'une des deux serait arbitraire.
 */
final readonly class CausalEventSliceClaim
{
    /**
     * @param array<string, CausalEvent> $events Les evenements, par identite.
     * @param array<string, CausalEventSource> $sources Les sources interrogees, par valeur.
     */
    private function __construct(
        private array $events,
        private array $sources,
    ) {
    }

    /**
     * Les evenements assembles, avec les sources qu'on affirme avoir interrogees.
     *
     * @param array<int, CausalEvent> $events
     * @param array<int, CausalEventSource> $queriedSources
     * @return self
     */
    public static function assembledFrom(array $events, array $queriedSources): self
    {
        $parIdentite = [];

        foreach ($events as $event) {
            $deja = $parIdentite[$event->identity] ?? null;

            if ($deja === null) {
                $parIdentite[$event->identity] = $event;

                continue;
            }

            // Un doublon identique se reduit a un, sans bruit : une file rejoue, et c'est normal.
            if ($deja->agreesWith($event)) {
                continue;
            }

            throw new ContradictoryCausalEvent(
                'Deux lectures de l evenement « ' . $event->identity . ' » se contredisent. Choisir l une des '
                . 'deux serait arbitraire, et la photographie dependrait de celle qu on a gardee.'
            );
        }

        $sources = [];

        foreach ($queriedSources as $source) {
            $sources[$source->value] = $source;
        }

        return new self($parIdentite, $sources);
    }

    /**
     * Les evenements, dans l'ordre ou ils ont ete lus.
     *
     * L'ordre de lecture n'a aucune valeur : c'est le reconciliateur qui ordonne, par
     * `EffectOrderKey`.
     *
     * @return array<int, CausalEvent>
     */
    public function all(): array
    {
        return array_values($this->events);
    }

    /**
     * Combien d'evenements distincts la revendication porte.
     */
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Les sources qui n'ont pas ete interrogees.
     *
     * @return array<int, CausalEventSource>
     */
    public function missingSources(): array
    {
        $manquantes = [];

        foreach (CausalEventSource::cases() as $source) {
            if (!isset($this->sources[$source->value])) {
                $manquantes[] = $source;
            }
        }

        return $manquantes;
    }
}
