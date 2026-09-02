<?php

namespace OGame\Combat\Causality;

use OGame\Combat\Exceptions\ContradictoryCausalEvent;
use OGame\Combat\Exceptions\IncompleteEventSlice;

/**
 * Tous les evenements candidats d'une fermeture, declares complets.
 *
 * ## Pourquoi la completude est declaree, et pas supposee
 *
 * Un reconciliateur qui accepterait une tranche partielle produirait une photographie **plausible et
 * fausse** : il manquerait une livraison, et personne ne le saurait. Le service de fermeture, qui
 * seul a relu sous verrou, est le seul a pouvoir affirmer que la tranche est complete — et il doit
 * le dire explicitement, pas par omission.
 *
 * ## Deduplication et contradiction
 *
 * Le meme evenement peut etre livre deux fois : une file rejoue, un worker se reveille en double.
 * Deux lectures **identiques** se reduisent a une, sans bruit. Deux lectures qui portent la meme
 * identite avec un contenu different sont refusees : elles signifient que quelque chose a change
 * entre deux lectures qui se croyaient equivalentes, et choisir l'une des deux serait arbitraire.
 */
final readonly class CompleteEventSlice
{
    /**
     * @param array<string, CausalEvent> $events Les evenements, par identite.
     */
    private function __construct(
        private array $events,
    ) {
    }

    /**
     * La tranche relue sous verrou, declaree complete.
     *
     * @param array<int, CausalEvent> $events
     * @param bool $readUnderLock Ce que le service de fermeture affirme.
     * @return self
     */
    public static function readUnderLock(array $events, bool $readUnderLock = true): self
    {
        if (!$readUnderLock) {
            throw new IncompleteEventSlice(
                'Une tranche incomplete produirait une photographie plausible et fausse : il y manquerait un '
                . 'effet, et rien ne le signalerait. Seul le service de fermeture, qui a relu sous verrou, '
                . 'peut affirmer la completude.'
            );
        }

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

        return new self($parIdentite);
    }

    /**
     * Les evenements, dans l'ordre ou ils ont ete lus.
     *
     * L'ordre de lecture n'a aucune valeur : c'est le reconciliateur qui ordonne, par
     * `EffectOrderKey`. Cette methode ne sert qu'a les parcourir.
     *
     * @return array<int, CausalEvent>
     */
    public function all(): array
    {
        return array_values($this->events);
    }

    /**
     * Combien d'evenements distincts la tranche porte.
     */
    public function count(): int
    {
        return count($this->events);
    }
}
